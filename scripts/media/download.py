"""Private media worker. Stdin is a server-created manifest; stdout is sanitized NDJSON.

No CLI passthrough, user-selected paths, shell, external downloader or remote components.
The PHP owner renews a lease; this supervisor owns and terminates each local codec process.
"""

import contextlib
import http.client
import importlib.metadata
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import signal
import socket
import ssl
import stat
import struct
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


INPUT_MAX = 128 * 1024 * 1024
OUTPUT_MAX = 256 * 1024 * 1024
MIME = {
    "mp4": "video/mp4", "webm": "video/webm", "mp3": "audio/mpeg",
    "wav": "audio/wav", "flac": "audio/flac", "png": "image/png",
    "jpg": "image/jpeg", "webp": "image/webp", "gif": "image/gif",
}
IMAGE_FORMATS = {"png", "jpg", "webp"}
IMAGE_DEMUXERS = {"png_pipe", "jpeg_pipe", "webp_pipe"}
CODECS = "h264,hevc,av1,libdav1d,libaom-av1,vp8,vp9,mpeg4,mpeg2video,aac,mp3,mp3float,opus,libopus,vorbis,libvorbis,flac,pcm_s16le,pcm_s24le,pcm_s32le,pcm_f32le,pcm_f64le,pcm_u8,png,mjpeg,webp,gif"
SAFE_PROTOCOLS = {"http", "https", "m3u8_native", "m3u8", "http_dash_segments"}
# Many public hosts and CDNs reject requests without a browser User-Agent with
# HTTP 403. The private opener clears urllib's default header, so set an explicit
# modern desktop UA on every request; without it, every download fails as "source".
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
QUALITY_HEIGHTS = {"1080": 1080, "720": 720, "480": 480, "360": 360}
RESOLUTIONS = {"1080": (1920, 1080), "720": (1280, 720), "480": (854, 480), "360": (640, 360)}
ENCODINGS = {"high": ("18", "5000k", "3200k"), "balanced": ("23", "2500k", "1800k"), "small": ("28", "1200k", "900k")}
AUDIO_BITRATES = {128, 192, 320}
GIF_MAX_SECONDS = 15


def options_of(manifest):
    """Server-validated processing options; anything unexpected is treated as absent."""
    raw = manifest.get("options")
    if not isinstance(raw, dict):
        return {}
    options = {}
    if raw.get("quality") in QUALITY_HEIGHTS:
        options["quality"] = raw["quality"]
    if raw.get("resolution") in RESOLUTIONS:
        options["resolution"] = raw["resolution"]
    if raw.get("encoding") in ENCODINGS:
        options["encoding"] = raw["encoding"]
    if raw.get("audio_bitrate") in AUDIO_BITRATES and not isinstance(raw.get("audio_bitrate"), bool):
        options["audio_bitrate"] = int(raw["audio_bitrate"])
    for key in ("trim_start", "trim_end"):
        value = numeric(raw.get(key))
        if value is not None and 0 <= value <= 600:
            options[key] = value
    if "trim_start" in options and "trim_end" in options and options["trim_end"] <= options["trim_start"]:
        del options["trim_end"]
    return options


class MediaError(Exception):
    def __init__(self, code="failed"):
        self.code = code
        super().__init__(code)


def emit(event, **values):
    print(json.dumps({"event": event, **values}, separators=(",", ":"), allow_nan=False), flush=True)


def bounded_int(value, maximum):
    if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= maximum:
        raise MediaError("runtime")
    return value


def numeric(value):
    try:
        result = float(value)
        return result if math.isfinite(result) else None
    except (ValueError, TypeError):
        return None


class Supervisor:
    def __init__(self, manifest):
        self.work = Path.cwd()
        self.root = self.work.parent
        if self.work.name != "work" or self.work.is_symlink() or self.root.is_symlink():
            raise MediaError("runtime")
        try:
            uuid.UUID(self.root.name)
        except ValueError:
            raise MediaError("runtime") from None
        self.inspect = manifest.get("kind") == "inspect"
        self.lease = self.root / "lease"
        self.token = manifest.get("lease")
        if not self.inspect and (not isinstance(self.token, str) or not re.fullmatch(r"[a-f0-9]{64}", self.token)):
            raise MediaError("runtime")
        self.input_limit = bounded_int(manifest.get("input_limit"), INPUT_MAX)
        self.output_limit = bounded_int(manifest.get("output_limit"), OUTPUT_MAX)
        self.duration_limit = bounded_int(manifest.get("duration_limit"), 600)
        self.dimension_limit = bounded_int(manifest.get("dimension_limit"), 4096)
        self.pixel_limit = bounded_int(manifest.get("pixel_limit"), 16777216)
        self.deadline = time.monotonic() + bounded_int(manifest.get("timeout"), 360)
        self.child = None
        self.child_deadline = None
        self.child_lock = threading.RLock()
        self.stopped = threading.Event()
        self.downloading = False
        self.last_progress = 0.0
        for sig in (signal.SIGTERM, signal.SIGINT):
            signal.signal(sig, lambda *_: self.abort("cancelled"))
        self.check()
        threading.Thread(target=self.watch, daemon=True).start()

    def check(self):
        if time.monotonic() >= self.deadline:
            raise MediaError("timeout")
        if self.child is not None and self.child_deadline is not None and self.child.poll() is None and time.monotonic() >= self.child_deadline:
            raise MediaError("timeout")
        if (self.root / "cancel").exists():
            raise MediaError("cancelled")
        if not self.inspect:
            try:
                if self.lease.is_symlink() or self.lease.read_text(encoding="ascii") != self.token or time.time() - self.lease.stat().st_mtime > 15:
                    raise MediaError("timeout")
            except OSError:
                raise MediaError("timeout") from None
        total = 0
        count = 0
        for entry in self.work.iterdir():
            count += 1
            try:
                details = entry.stat(follow_symlinks=False)
            except FileNotFoundError:
                continue
            if count > 4096 or not stat.S_ISREG(details.st_mode):
                raise MediaError("invalid_media")
            size = details.st_size
            total += size
            if entry.name.startswith("result.") and size > self.output_limit:
                raise MediaError("output_limit")
        if total > (self.input_limit + 8 * 1024 * 1024 if self.downloading else self.input_limit + self.output_limit + 8 * 1024 * 1024):
            raise MediaError("input_limit" if self.downloading else "output_limit")

    def watch(self):
        while not self.stopped.wait(0.25):
            try:
                self.check()
            except MediaError as error:
                self.abort(error.code)
            except Exception:
                self.abort("failed")

    def stop_child(self):
        with self.child_lock:
            child = self.child
            if child is not None and child.poll() is None:
                with contextlib.suppress(OSError):
                    child.terminate()
                try:
                    child.wait(timeout=1)
                except subprocess.TimeoutExpired:
                    with contextlib.suppress(OSError):
                        child.kill()
                    with contextlib.suppress(subprocess.TimeoutExpired):
                        child.wait(timeout=1)

    def abort(self, code):
        self.stop_child()
        with contextlib.suppress(Exception):
            os.write(1, (json.dumps({"event": "error", "code": code}) + "\n").encode("ascii"))
        os._exit(1)

    def spawn(self, argv, stdin=subprocess.DEVNULL, timeout=None):
        self.check()
        with self.child_lock:
            self.child = subprocess.Popen(
                argv, stdin=stdin, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                cwd=self.work, executable=argv[0], shell=False,
                creationflags=subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0,
            )
            self.child_deadline = None if timeout is None else time.monotonic() + timeout
            return self.child

    def progress(self, stage, value=None, force=False):
        now = time.monotonic()
        if force or now - self.last_progress >= 0.5:
            emit("progress", stage=stage, progress=None if value is None else round(max(0, min(99, value)), 1))
            self.last_progress = now


def executable(value):
    if not isinstance(value, str) or not Path(value).is_absolute() or not Path(value).is_file():
        raise MediaError("runtime")
    return str(Path(value).resolve())


def node_version(node, supervisor=None):
    argv = [executable(node), "--version"]
    if supervisor is None:
        raw = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=4, check=True).stdout
    else:
        child = supervisor.spawn(argv, timeout=4)
        raw = child.stdout.read(1025)
        if child.wait() != 0:
            raise MediaError("runtime")
    match = re.fullmatch(rb"v(\d+)\.(\d+)\.(\d+)\s*", raw)
    if match is None or tuple(map(int, match.groups())) < (18, 0, 0):
        raise MediaError("runtime")
    return tuple(map(int, match.groups()))


def _version_tuple(raw):
    match = re.search(rb"version\s+n?(\d+)\.(\d+)", raw)
    return tuple(map(int, match.groups())) if match else None


def runtime_check(manifest):
    # Capability-based, not version-pinned. Pinning exact builds (Python 3.14,
    # FFmpeg 9.0.1) made every host that was not the dev machine report the
    # tools as unavailable, even when every required encoder was present.
    reasons = []
    if sys.version_info[:2] < (3, 11):
        reasons.append("python<3.11")
    ffmpeg = executable(manifest.get("ffmpeg"))
    ffprobe = executable(manifest.get("ffprobe"))
    for label, binary in (("ffmpeg", ffmpeg), ("ffprobe", ffprobe)):
        version = subprocess.run([binary, "-version"], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=4, check=True).stdout[:1024]
        parsed = _version_tuple(version)
        if parsed is None or parsed < (6, 0):
            reasons.append(f"{label}<6.0")
    encoders = subprocess.run([ffmpeg, "-hide_banner", "-encoders"], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=4, check=True).stdout
    names = {match.group(1).decode("ascii") for match in re.finditer(rb"^ [VAS][A-Z.]{5}\s+(\S+)", encoders, re.MULTILINE)}
    required = {"libx264", "libvpx-vp9", "aac", "libopus", "libmp3lame", "pcm_s16le", "flac", "png", "mjpeg", "libwebp"}
    missing = sorted(required - names)
    if missing:
        reasons.append("encoders:" + ",".join(missing))
    ready = not reasons
    downloader = False
    download_reasons = []
    try:
        node_version(manifest.get("node"))
    except (MediaError, OSError, subprocess.SubprocessError):
        download_reasons.append("node")
    try:
        importlib.metadata.version("yt-dlp")
    except importlib.metadata.PackageNotFoundError:
        download_reasons.append("yt-dlp")
    try:
        importlib.metadata.version("yt-dlp-ejs")
    except importlib.metadata.PackageNotFoundError:
        download_reasons.append("yt-dlp-ejs")
    downloader = not download_reasons
    print(json.dumps({
        "convert": ready,
        "download": ready and downloader,
        "reasons": reasons + download_reasons,
    }), flush=True)


def checked_url(value):
    if not isinstance(value, str) or len(value) > 8192 or re.search(r"[\x00-\x20\x7f\\]", value):
        raise MediaError("source")
    try:
        parsed = urllib.parse.urlsplit(value)
        if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username is not None or parsed.password is not None or parsed.port not in (None, 80, 443):
            raise MediaError("source")
        host = parsed.hostname.rstrip(".")
        if "%" in host or not host:
            raise MediaError("source")
        host.encode("idna")
        with contextlib.suppress(ValueError):
            public_address(host)
        return parsed
    except (ValueError, UnicodeError):
        raise MediaError("source") from None


def public_address(value):
    address = ipaddress.ip_address(value)
    if not address.is_global or address.is_multicast or address.is_unspecified or address.is_loopback or address.is_link_local or address.is_reserved:
        raise MediaError("source")
    if isinstance(address, ipaddress.IPv6Address) and (address.ipv4_mapped is not None or address.sixtofour is not None or address.teredo is not None or address in ipaddress.ip_network("64:ff9b::/96") or address in ipaddress.ip_network("64:ff9b:1::/48")):
        raise MediaError("source")
    return address


def guarded_connection(address, timeout=15, source_address=None, **kwargs):
    host, port = address
    if port not in (80, 443) or source_address is not None or "%" in host:
        raise MediaError("source")
    # Resolve once, reject mixed public/private answers, then connect only to a checked
    # numeric sockaddr. TLS still uses the original hostname for SNI and verification.
    entries = socket.getaddrinfo(host, port, family=socket.AF_UNSPEC, type=socket.SOCK_STREAM)
    if not entries or len(entries) > 32:
        raise MediaError("source")
    for family, kind, protocol, _, sockaddr in entries:
        if family not in (socket.AF_INET, socket.AF_INET6) or kind != socket.SOCK_STREAM:
            raise MediaError("source")
        public_address(sockaddr[0])
    for family, kind, protocol, _, sockaddr in entries:
        connection = socket.socket(family, kind, protocol)
        connection.settimeout(min(15, timeout if isinstance(timeout, (int, float)) else 15))
        try:
            connection.connect(sockaddr)
            return connection
        except OSError:
            connection.close()
    raise MediaError("source")


class PinnedHTTPConnection(http.client.HTTPConnection):
    def connect(self):
        if self._tunnel_host:
            raise MediaError("source")
        self.sock = guarded_connection((self.host, self.port), self.timeout)


class PinnedHTTPSConnection(http.client.HTTPSConnection):
    def connect(self):
        if self._tunnel_host or self._context.verify_mode != ssl.CERT_REQUIRED or not self._context.check_hostname:
            raise MediaError("source")
        self.sock = guarded_connection((self.host, self.port), self.timeout)
        self.sock = self._context.wrap_socket(self.sock, server_hostname=self.host)


class NetworkBudget:
    def __init__(self, maximum):
        self.remaining = maximum + 8 * 1024 * 1024
        self.requests = 0


class BoundedResponse:
    def __init__(self, response, budget):
        self.response = response
        self.budget = budget

    def __getattr__(self, name):
        return getattr(self.response, name)

    def read(self, amount=None):
        # Extractor pages/manifests are bounded even when they request read-all.
        unlimited = amount is None or amount < 0
        limit = min(self.budget.remaining, 8 * 1024 * 1024 if unlimited else amount)
        data = self.response.read(limit + 1 if unlimited else limit)
        self.budget.remaining -= len(data)
        if self.budget.remaining < 0 or (unlimited and len(data) > limit) or (limit <= 0 and data):
            self.response.close()
            raise MediaError("input_limit")
        if self.budget.remaining == 0:
            raise MediaError("input_limit")
        return data

    def close(self):
        return self.response.close()

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()


class GuardRequest(urllib.request.BaseHandler):
    handler_order = 50

    def __init__(self, budget):
        self.budget = budget

    def http_request(self, request):
        checked_url(request.full_url)
        self.budget.requests += 1
        if self.budget.requests > 2000:
            raise MediaError("source")
        for name in ("Authorization", "Proxy-Authorization", "Cookie", "Host", "Ytdl-socks-proxy"):
            request.remove_header(name)
        request.add_header("Accept-Encoding", "identity")
        return request

    def http_response(self, request, response):
        if response.headers.get("Content-Encoding", "identity").lower() not in ("", "identity"):
            raise MediaError("source")
        length = response.headers.get("Content-Length")
        if length is not None and (not length.isdigit() or int(length) > self.budget.remaining):
            raise MediaError("input_limit")
        return BoundedResponse(response, self.budget)

    https_request = http_request
    https_response = http_response


class GuardHTTPHandler(urllib.request.HTTPHandler):
    def http_open(self, request):
        return self.do_open(PinnedHTTPConnection, request)


class GuardHTTPSHandler(urllib.request.HTTPSHandler):
    def https_open(self, request):
        return self.do_open(PinnedHTTPSConnection, request, context=self._context)


class GuardRedirect(urllib.request.HTTPRedirectHandler):
    max_redirections = 5
    max_repeats = 2

    def redirect_request(self, request, fp, code, message, headers, newurl):
        target = checked_url(newurl)
        if urllib.parse.urlsplit(request.full_url).scheme == "https" and target.scheme != "https":
            raise MediaError("source")
        return super().redirect_request(request, fp, code, message, headers, newurl)


def download_media(manifest, supervisor):
    os.environ["YTDLP_NO_PLUGINS"] = "1"
    # Presence, not an exact build: pinning the patch release turned a working
    # host into a hard failure every time upstream published a new yt-dlp.
    try:
        importlib.metadata.version("yt-dlp")
        importlib.metadata.version("yt-dlp-ejs")
    except importlib.metadata.PackageNotFoundError as error:
        raise MediaError("runtime") from error
    import yt_dlp
    import yt_dlp.globals
    from yt_dlp.networking._urllib import UrllibRH, UrllibResponseAdapter
    from yt_dlp.networking.exceptions import HTTPError
    from yt_dlp.downloader.external import FFmpegFD
    from yt_dlp.downloader import get_suitable_downloader
    from yt_dlp.downloader.http import HttpFD
    from yt_dlp.downloader.dash import DashSegmentsFD
    from yt_dlp.downloader.hls import HlsFD
    from yt_dlp.postprocessor.ffmpeg import FFmpegPostProcessor
    from yt_dlp.extractor.youtube.jsc._builtin.node import NodeJCP
    from yt_dlp.utils._jsruntime import JsRuntimeInfo, NodeJsRuntime
    import certifi

    yt_dlp.globals.plugin_dirs.value = []
    node = executable(manifest.get("node"))
    version = node_version(node, supervisor)
    # Node renamed --experimental-permission to --permission (v22.13/v23.5); older builds reject --permission,
    # which silently breaks YouTube signature/n-challenge solving. Probe the actual build and use what it accepts.
    permission_flag = "--experimental-permission"
    try:
        probe = subprocess.run([node, "--permission", "-e", "0"], capture_output=True, timeout=5)
        if probe.returncode == 0 and b"bad option" not in probe.stderr:
            permission_flag = "--permission"
    except Exception:
        pass
    node_argv = [node, permission_flag, "--max-old-space-size=256", "-"]
    # Avoid yt-dlp's unbounded version probe: reuse the already supervised result.
    NodeJsRuntime._info = lambda self: JsRuntimeInfo(
        name="node", path=node, version=".".join(map(str, version)), version_tuple=version,
        supported=self._path == node,
    )

    def solve_js(provider, script):
        if not isinstance(script, str):
            raise MediaError("source")
        script_bytes = script.encode("utf-8")
        if len(script_bytes) > 16 * 1024 * 1024:
            raise MediaError("source")
        child = supervisor.spawn(node_argv, stdin=subprocess.PIPE, timeout=25)

        def feed():
            try:
                child.stdin.write(script_bytes)
                child.stdin.close()
            except (OSError, ValueError):
                pass

        writer = threading.Thread(target=feed, daemon=True)
        writer.start()
        output = child.stdout.read(8 * 1024 * 1024 + 1)
        if len(output) > 8 * 1024 * 1024:
            supervisor.stop_child()
            raise MediaError("source")
        if child.wait() != 0:
            raise MediaError("source")
        writer.join(timeout=1)
        return output.decode("utf-8")

    NodeJCP._run_js_runtime = solve_js
    active = {"download": True}

    def audit(event, args):
        if not active["download"]:
            return
        if event == "socket.connect":
            connection, address = args
            if connection.family not in (socket.AF_INET, socket.AF_INET6) or connection.type != socket.SOCK_STREAM or not isinstance(address, tuple) or address[1] not in (80, 443):
                raise MediaError("source")
            # Hostnames are never accepted here: only the pinned numeric connection.
            try:
                public_address(address[0])
            except ValueError:
                raise MediaError("source") from None
        elif event == "subprocess.Popen":
            binary, argv, cwd, environment = args
            expected = subprocess.list2cmdline(node_argv) if os.name == "nt" else node_argv
            if binary != node or argv != expected or (environment is not None and environment != dict(os.environ)):
                raise MediaError("source")
        elif event == "os.posix_spawn":
            binary, argv, environment = args
            if binary != node or list(argv) != node_argv or dict(environment) != dict(os.environ):
                raise MediaError("source")
        elif event in ("socket.sendto", "socket.sendmsg", "os.system", "os.exec"):
            raise MediaError("source")

    sys.addaudithook(audit)
    budget = NetworkBudget(supervisor.input_limit)

    class GuardedUrllibRH(UrllibRH):
        _SUPPORTED_URL_SCHEMES = ("http", "https")
        _SUPPORTED_PROXY_SCHEMES = ()
        RH_KEY = "Urllib"

        def _create_instance(self, proxies, cookiejar, legacy_ssl_support=None):
            opener = urllib.request.OpenerDirector()
            context = ssl.create_default_context(cafile=certifi.where())
            for handler in (GuardRequest(budget), urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(cookiejar), GuardHTTPHandler(), GuardHTTPSHandler(context=context), GuardRedirect(), urllib.request.HTTPDefaultErrorHandler(), urllib.request.HTTPErrorProcessor(), urllib.request.UnknownHandler()):
                opener.add_handler(handler)
            opener.addheaders = []
            return opener

        def _get_proxies(self, request):
            return {}

        def _prepare_headers(self, request, headers):
            headers["Accept-Encoding"] = "identity"
            headers.setdefault("User-Agent", USER_AGENT)

        def _send(self, request):
            checked_url(request.url)
            try:
                return super()._send(request)
            except urllib.error.HTTPError as error:
                if not isinstance(error.fp, BoundedResponse):
                    raise
                # Retain the bounded response when ownership passes to yt-dlp.
                error._closer.close_called = True
                raise HTTPError(UrllibResponseAdapter(error.fp), redirect_loop="redirect error" in str(error)) from error

    class SafeYoutubeDL(yt_dlp.YoutubeDL):
        def build_request_director(self, handlers, preferences=None):
            return super().build_request_director([GuardedUrllibRH], set())

        def process_ie_result(self, result, download=True, extra_info=None):
            if result.get("_type", "video") in ("playlist", "multi_video") or result.get("is_live") or result.get("live_status") in ("is_live", "is_upcoming", "post_live") or result.get("has_drm"):
                raise MediaError("source")
            duration = numeric(result.get("duration"))
            if duration is not None and duration > supervisor.duration_limit:
                raise MediaError("duration_limit")
            return super().process_ie_result(result, download, extra_info)

        def dl(self, name, info, subtitle=False, test=False):
            if subtitle or test or Path(name).resolve() not in (supervisor.work / "input", supervisor.work / "audio"):
                raise MediaError("source")
            if info.get("protocol") not in SAFE_PROTOCOLS or get_suitable_downloader(info, self.params) not in (HttpFD, HlsFD, DashSegmentsFD):
                raise MediaError("source")
            return super().dl(name, info, subtitle, test)

        def post_process(self, filename, info, files_to_move=None):
            # Never invoke extractor-provided postprocessors, exec hooks or FFmpeg.
            info["filepath"] = filename
            return info

        def run_pp(self, pp, infodict):
            raise MediaError("source")

    def deny_external(*args, **kwargs):
        raise MediaError("source")

    original_can_download = HlsFD.can_download

    def unencrypted_hls(cls, text, info, allow_unplayable_formats=False):
        if re.search(r"#EXT-X-(?:SESSION-)?KEY:.*METHOD=(?!NONE(?:,|\s|$))", text):
            return False
        return original_can_download(text, info, False)

    # HlsFD otherwise silently falls back to FFmpeg for unsupported manifests.
    HlsFD.can_download = classmethod(unencrypted_hls)
    FFmpegFD.available = classmethod(lambda cls, *args: False)
    FFmpegFD.real_download = deny_external
    FFmpegPostProcessor.run_ffmpeg = deny_external
    FFmpegPostProcessor.run_ffmpeg_multiple_files = deny_external

    options_ = options_of(manifest)

    def safe_candidates(formats):
        candidates = []
        for item in reversed(formats):
            if item.get("protocol") not in SAFE_PROTOCOLS or item.get("has_drm") or item.get("is_live"):
                continue
            if any(item.get(key) is not None for key in ("section_start", "section_end")):
                continue
            if (numeric(item.get("width")) or 0) > supervisor.dimension_limit or (numeric(item.get("height")) or 0) > supervisor.dimension_limit:
                continue
            if (numeric(item.get("filesize")) or 0) > supervisor.input_limit:
                continue
            checked_url(item.get("url"))
            candidates.append(item)
        return candidates

    def choose_formats(formats):
        candidates = safe_candidates(formats)
        audio = [item for item in candidates if item.get("acodec") != "none"]
        video = [item for item in candidates if item.get("vcodec") != "none"]
        audio.sort(key=lambda item: item.get("vcodec") != "none")
        target_height = QUALITY_HEIGHTS.get(options_.get("quality"))
        if target_height:
            # Highest format at or under the requested height; otherwise the smallest available.
            fitting = [item for item in video if (numeric(item.get("height")) or 0) <= target_height]
            pool = fitting or sorted(video, key=lambda item: numeric(item.get("height")) or 0)[:1]
            video = sorted(pool, key=lambda item: numeric(item.get("height")) or 0, reverse=True)
        if manifest["format"] == "mp3":
            if audio:
                return [audio[0]]
        else:
            combined = [item for item in video if item.get("acodec") != "none"]
            if combined:
                return [combined[0]]
            if video and audio:
                return [video[0], audio[0]]
            if video:
                return [video[0]]
        raise MediaError("source")

    def inspection(info):
        candidates = safe_candidates(info.get("formats") or [info])
        heights = sorted({int(numeric(item.get("height")) or 0) for item in candidates if item.get("vcodec") != "none" and numeric(item.get("height"))}, reverse=True)
        thumbnail = info.get("thumbnail")
        if not isinstance(thumbnail, str) or not thumbnail.startswith("https://") or len(thumbnail) > 2000:
            thumbnail = None
        title = info.get("title") if isinstance(info.get("title"), str) else None
        uploader = info.get("uploader") if isinstance(info.get("uploader"), str) else None
        emit("result", title=(title or "")[:200], duration=numeric(info.get("duration")), thumbnail=thumbnail,
             uploader=(uploader or "")[:120], heights=heights[:12],
             has_video=any(item.get("vcodec") != "none" for item in candidates),
             has_audio=any(item.get("acodec") != "none" for item in candidates))

    def select_format(context):
        # Metadata selection only. Up to two native streams are downloaded explicitly
        # below; yt-dlp never owns a merger or any external process.
        yield choose_formats(context.get("formats", []))[0]

    download_state = {"index": 0, "count": 1, "bytes": 0}

    def progress(info):
        supervisor.check()
        received = numeric(info.get("downloaded_bytes")) or 0
        total = numeric(info.get("total_bytes"))
        if download_state["bytes"] + received > supervisor.input_limit or (total is not None and download_state["bytes"] + total > supervisor.input_limit):
            raise MediaError("input_limit")
        if total and total > 0:
            value = (download_state["index"] + received / total) / download_state["count"] * 45
        elif info.get("fragment_count"):
            value = (download_state["index"] + (numeric(info.get("fragment_index")) or 0) / info["fragment_count"]) / download_state["count"] * 45
        else:
            value = None
        supervisor.progress("downloading", value)

    class SilentLogger:
        def debug(self, *args, **kwargs):
            pass
        info = debug
        warning = debug
        error = debug

    options = {
        "format": select_format, "outtmpl": {"default": "input"},
        "paths": {"home": str(supervisor.work), "temp": str(supervisor.work)},
        "quiet": True, "no_warnings": True, "noprogress": True, "logger": SilentLogger(),
        "progress_hooks": [progress], "cachedir": False, "noplaylist": True,
        "ignoreerrors": False,
        "overwrites": False, "continuedl": False, "nopart": False,
        "socket_timeout": 15, "retries": 1, "fragment_retries": 1, "extractor_retries": 1,
        "file_access_retries": 0, "concurrent_fragment_downloads": 1,
        "skip_unavailable_fragments": False, "max_filesize": supervisor.input_limit,
        "buffersize": 65536, "http_chunk_size": 1024 * 1024,
        "external_downloader": {"default": "native"}, "hls_prefer_native": True,
        "fixup": "never", "postprocessors": [], "allow_unplayable_formats": False,
        "enable_file_urls": False, "proxy": "", "geo_verification_proxy": "",
        "cookiefile": None, "cookiesfrombrowser": None, "usenetrc": False,
        "username": None, "password": None, "videopassword": None,
        "nocheckcertificate": False, "legacyserverconnect": False,
        "js_runtimes": {"node": {"path": node}}, "remote_components": set(), "check_formats": False,
        "writeinfojson": False, "writethumbnail": False, "writesubtitles": False,
        "writeautomaticsub": False, "allow_multiple_video_streams": False,
        "allow_multiple_audio_streams": False, "ffmpeg_location": str(supervisor.work / "disabled"),
    }
    # Authenticated cookies for sites (e.g. YouTube) that block anonymous datacenter
    # requests. The server writes a Netscape cookies.txt into the private work dir.
    if manifest.get("cookies") == "cookies.txt":
        cookies_path = supervisor.work / "cookies.txt"
        if cookies_path.is_file() and not cookies_path.is_symlink():
            options["cookiefile"] = str(cookies_path)
    supervisor.downloading = True
    supervisor.progress("downloading", force=True)
    try:
        with SafeYoutubeDL(options) as downloader:
            info = downloader.extract_info(manifest["url"], download=False)
            if not info or info.get("_type", "video") != "video":
                raise MediaError("source")
            if supervisor.inspect:
                inspection(info)
                return
            selected = choose_formats(info.get("formats") or [info])
            download_state["count"] = len(selected)
            for index, stream in enumerate(selected):
                download_state["index"] = index
                target = supervisor.work / ("input" if index == 0 else "audio")
                item = {**info, **stream}
                for key in ("formats", "requested_formats", "requested_downloads", "entries"):
                    item.pop(key, None)
                success, _ = downloader.dl(str(target), item)
                if not success or target.is_symlink() or not target.is_file():
                    raise MediaError("source")
                download_state["bytes"] += target.stat().st_size
                if download_state["bytes"] > supervisor.input_limit:
                    raise MediaError("input_limit")
    except MediaError:
        raise
    except Exception:
        raise MediaError("source") from None
    finally:
        supervisor.downloading = False
        active["download"] = False


def inspect_mov(path):
    # Reject external QuickTime data/reference movies before libavformat sees them.
    containers = {b"moov", b"trak", b"mdia", b"minf", b"dinf", b"edts", b"mvex", b"moof", b"traf"}
    atoms = 0
    with path.open("rb") as source:
        def walk(start, end, depth=0):
            nonlocal atoms
            if depth > 16:
                raise MediaError("invalid_media")
            offset = start
            while offset < end:
                source.seek(offset)
                header = source.read(8)
                if len(header) != 8:
                    raise MediaError("invalid_media")
                size, kind = struct.unpack(">I4s", header)
                header_size = 8
                if size == 1:
                    raw = source.read(8)
                    if len(raw) != 8:
                        raise MediaError("invalid_media")
                    size = struct.unpack(">Q", raw)[0]
                    header_size = 16
                elif size == 0:
                    size = end - offset
                atoms += 1
                if atoms > 100000 or size < header_size or offset + size > end or kind in (b"cmov", b"rmra", b"rmda", b"rdrf"):
                    raise MediaError("invalid_media")
                payload = offset + header_size
                if kind in containers:
                    walk(payload, offset + size, depth + 1)
                elif kind == b"dref":
                    source.seek(payload)
                    full = source.read(8)
                    if len(full) != 8:
                        raise MediaError("invalid_media")
                    count = struct.unpack(">I", full[4:])[0]
                    if count > 64 or payload + 8 + count * 12 != offset + size:
                        raise MediaError("invalid_media")
                    for _ in range(count):
                        item = source.read(12)
                        if len(item) != 12 or struct.unpack(">I", item[:4])[0] != 12 or item[4:8] != b"url " or item[8:] != b"\x00\x00\x00\x01":
                            raise MediaError("invalid_media")
                offset += size
        walk(0, path.stat().st_size)


def sniff(path, maximum):
    if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= maximum:
        raise MediaError("input_limit")
    with path.open("rb") as source:
        header = source.read(1024)
    if header.startswith(b"\x89PNG\r\n\x1a\n"):
        return "png_pipe"
    if header.startswith(b"\xff\xd8\xff"):
        return "jpeg_pipe"
    if header.startswith(b"RIFF") and header[8:12] == b"WEBP":
        return "webp_pipe"
    if header.startswith((b"GIF87a", b"GIF89a")):
        return "gif"
    if header.startswith(b"RIFF") and header[8:12] == b"WAVE":
        return "wav"
    if header.startswith(b"fLaC"):
        return "flac"
    if header.startswith(b"OggS"):
        return "ogg"
    if header.startswith(b"\x1aE\xdf\xa3"):
        return "matroska"
    if header[4:8] in (b"ftyp", b"moov", b"mdat", b"free", b"wide", b"skip"):
        inspect_mov(path)
        return "mov"
    if header.startswith(b"ID3") or (len(header) > 2 and header[0] == 255 and header[1] & 0xE6 == 0xE2):
        return "mp3"
    if any(len(header) > offset + 2 * stride and header[offset] == header[offset + stride] == header[offset + 2 * stride] == 0x47 for offset, stride in ((0, 188), (4, 192), (0, 204))):
        return "mpegts"
    # Deliberately no image2, concat, playlist, SDP, SVG or auto-probing demuxer.
    raise MediaError("invalid_media")


def input_options(demuxer, supervisor):
    options = ["-protocol_whitelist", "file,pipe", "-format_whitelist", demuxer,
               "-codec_whitelist", CODECS, "-probesize", "5242880", "-analyzeduration", "3000000",
               "-max_pixels", str(supervisor.pixel_limit), "-threads", "2", "-f", demuxer]
    if demuxer == "mov":
        options += ["-enable_drefs", "0", "-use_absolute_path", "0"]
    return options


def probe(path, binary, supervisor, maximum):
    demuxer = sniff(path, maximum)
    child = supervisor.spawn([binary, "-v", "error", "-max_alloc", "67108864", *input_options(demuxer, supervisor),
                              "-show_entries", "format=duration:stream=codec_type,codec_name,width,height,duration,sample_rate,channels:stream_disposition=attached_pic",
                              "-of", "json", "-i", str(path)])
    raw = child.stdout.read(1024 * 1024 + 1)
    if len(raw) > 1024 * 1024:
        supervisor.stop_child()
        raise MediaError("invalid_media")
    if child.wait() != 0:
        raise MediaError("invalid_media")
    try:
        data = json.loads(raw)
    except (ValueError, UnicodeError):
        raise MediaError("invalid_media") from None
    streams = data.get("streams", [])
    if not streams or len(streams) > 8:
        raise MediaError("invalid_media")
    video = [item for item in streams if item.get("codec_type") == "video" and not item.get("disposition", {}).get("attached_pic")]
    audio = [item for item in streams if item.get("codec_type") == "audio"]
    pixels = 0
    for stream in video:
        width, height = numeric(stream.get("width")), numeric(stream.get("height"))
        if width is None or height is None or not 0 < width <= supervisor.dimension_limit or not 0 < height <= supervisor.dimension_limit:
            raise MediaError("dimensions")
        pixels += width * height
    if pixels > supervisor.pixel_limit:
        raise MediaError("dimensions")
    for stream in audio:
        if not 0 < (numeric(stream.get("sample_rate")) or 0) <= 192000 or not 0 < (numeric(stream.get("channels")) or 0) <= 8:
            raise MediaError("invalid_media")
    image = demuxer in IMAGE_DEMUXERS
    durations = [numeric(data.get("format", {}).get("duration")), *(numeric(item.get("duration")) for item in streams)]
    duration = max((value for value in durations if value is not None), default=None)
    if not image and (duration is None or not 0 < duration <= supervisor.duration_limit):
        raise MediaError("duration_limit")
    if not video and not audio:
        raise MediaError("invalid_media")
    return {"demuxer": demuxer, "video": bool(video), "audio": bool(audio), "image": image, "duration": None if image else duration}


def convert_media(manifest, supervisor):
    ffmpeg, ffprobe = executable(manifest.get("ffmpeg")), executable(manifest.get("ffprobe"))
    format_ = manifest["format"]
    source = supervisor.work / "input"
    offset = 45 if manifest["kind"] == "download" else 0
    supervisor.progress("probing", offset, force=True)
    info = probe(source, ffprobe, supervisor, supervisor.input_limit)
    secondary = supervisor.work / "audio"
    audio_info = None
    if manifest["kind"] == "download" and secondary.exists():
        audio_info = probe(secondary, ffprobe, supervisor, supervisor.input_limit)
        if not audio_info["audio"] or source.stat().st_size + secondary.stat().st_size > supervisor.input_limit:
            raise MediaError("invalid_media")
        if info["duration"] and audio_info["duration"] and audio_info["duration"] < info["duration"] - 1:
            raise MediaError("invalid_media")
    if format_ in ("mp4", "webm", "gif") and (not info["video"] or info["image"]):
        raise MediaError("incompatible")
    if format_ in ("mp3", "wav", "flac") and not info["audio"]:
        raise MediaError("incompatible")
    if format_ in IMAGE_FORMATS and not info["video"]:
        raise MediaError("incompatible")
    options_ = options_of(manifest)
    trim_start = options_.get("trim_start") if not info["image"] and format_ not in IMAGE_FORMATS else None
    trim_end = options_.get("trim_end") if not info["image"] and format_ not in IMAGE_FORMATS else None
    if trim_start is not None and info["duration"] and trim_start >= info["duration"]:
        raise MediaError("incompatible")
    # Expected output length after trimming; GIF clips are additionally capped.
    available = (info["duration"] - (trim_start or 0)) if info["duration"] else None
    clip = min(value for value in (available, (trim_end - (trim_start or 0)) if trim_end is not None else None, supervisor.duration_limit + 1) if value is not None)
    if format_ == "gif":
        clip = min(clip, GIF_MAX_SECONDS)
    output = supervisor.work / ("result." + format_)
    command = [ffmpeg, "-hide_banner", "-v", "error", "-nostdin", "-n", "-max_alloc", "67108864"]
    seek = ["-ss", f"{trim_start:.3f}"] if trim_start else []
    command += [*input_options(info["demuxer"], supervisor), *seek, "-i", str(source)]
    if audio_info:
        command += [*input_options(audio_info["demuxer"], supervisor), *seek, "-i", str(secondary)]
    command += ["-map_metadata", "-1", "-map_chapters", "-1", "-sn", "-dn", "-threads", "2",
                "-filter_threads", "1", "-t", f"{clip:.3f}",
                "-fs", str(supervisor.output_limit), "-progress", "pipe:1", "-stats_period", "0.5"]
    if format_ in ("mp4", "webm"):
        width, height = RESOLUTIONS.get(options_.get("resolution"), (1920, 1080))
        crf, maxrate, vp9_rate = ENCODINGS.get(options_.get("encoding"), ENCODINGS["balanced"])
        command += ["-map", "0:v:0", "-map", "1:a:0" if audio_info else "0:a:0?", "-vf", f"scale=w='min({width},iw)':h='min({height},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1", "-r", "30", "-pix_fmt", "yuv420p", "-ac", "2", "-ar", "48000"]
        if format_ == "mp4":
            command += ["-c:v", "libx264", "-preset", "veryfast", "-crf", crf, "-maxrate", maxrate, "-bufsize", "5000k", "-c:a", "aac", "-b:a", "128k", "-movflags", "+faststart", "-f", "mp4"]
        else:
            command += ["-c:v", "libvpx-vp9", "-deadline", "realtime", "-cpu-used", "6", "-row-mt", "1", "-b:v", vp9_rate, "-maxrate", maxrate, "-bufsize", "5000k", "-c:a", "libopus", "-b:a", "128k", "-f", "webm"]
    elif format_ == "gif":
        command += ["-map", "0:v:0", "-an", "-vf", "fps=12,scale=w='min(480,iw)':h=-2:flags=lanczos,split[a][b];[a]palettegen=max_colors=192:stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=3", "-loop", "0", "-f", "gif"]
    elif format_ in ("mp3", "wav", "flac"):
        bitrate = f"{options_.get('audio_bitrate', 192)}k"
        command += ["-map", "0:a:0", "-vn", "-ac", "2", "-ar", "48000"]
        command += {"mp3": ["-c:a", "libmp3lame", "-b:a", bitrate, "-f", "mp3"],
                    "wav": ["-c:a", "pcm_s16le", "-f", "wav"],
                    "flac": ["-c:a", "flac", "-sample_fmt", "s16", "-f", "flac"]}[format_]
    else:
        command += ["-map", "0:v:0", "-an", "-frames:v", "1"]
        command += {"png": ["-c:v", "png", "-f", "image2", "-update", "1"],
                    "jpg": ["-c:v", "mjpeg", "-q:v", "2", "-f", "image2", "-update", "1"],
                    "webp": ["-c:v", "libwebp", "-quality", "85", "-f", "webp"]}[format_]
    command += ["-protocol_whitelist", "file,pipe", str(output)]
    supervisor.progress("converting", offset if not info["image"] else None, force=True)
    child = supervisor.spawn(command)
    processed = 0.0
    expected = clip if not info["image"] and format_ not in IMAGE_FORMATS else None
    while True:
        line = child.stdout.readline(4097)
        if not line:
            break
        if len(line) > 4096:
            supervisor.stop_child()
            raise MediaError("failed")
        if line.startswith(b"out_time_us="):
            elapsed = numeric(line.partition(b"=")[2])
            if elapsed is not None and elapsed >= 0:
                processed = max(processed, elapsed / 1000000)
                if expected:
                    supervisor.progress("converting", offset + min(1, processed / expected) * (95 - offset))
        supervisor.check()
    if child.wait() != 0:
        raise MediaError("failed")
    supervisor.progress("saving", 95, force=True)
    if not output.is_file() or output.stat().st_size >= supervisor.output_limit:
        raise MediaError("output_limit")
    result = probe(output, ffprobe, supervisor, supervisor.output_limit)
    if expected and (result["duration"] is None or result["duration"] < expected - max(1, expected * 0.01)):
        raise MediaError("output_limit")
    supervisor.check()
    emit("result", mime_type=MIME[format_], size_bytes=output.stat().st_size, duration=result["duration"])


def main():
    manifest = json.loads(sys.stdin.buffer.read(16385))
    if not isinstance(manifest, dict) or sys.version_info[:2] < (3, 11):
        raise MediaError("runtime")
    environment = {key: value for key, value in os.environ.items() if key.upper() in {"SYSTEMROOT", "WINDIR", "TEMP", "TMP"}}
    os.environ.clear()
    os.environ.update(environment)
    os.environ.update({"HOME": str(Path.cwd()), "USERPROFILE": str(Path.cwd()), "APPDATA": str(Path.cwd()), "PATH": "", "YTDLP_NO_PLUGINS": "1"})
    if sys.argv[1:] == ["check"]:
        runtime_check(manifest)
        return
    if sys.argv[1:] == ["inspect"] and manifest.get("kind") == "inspect":
        supervisor = Supervisor(manifest)
        try:
            checked_url(manifest.get("url"))
            download_media({**manifest, "format": "mp4"}, supervisor)
        finally:
            supervisor.stopped.set()
            supervisor.stop_child()
        return
    if sys.argv[1:] != ["run"] or manifest.get("format") not in MIME or manifest.get("kind") not in ("download", "convert"):
        raise MediaError("runtime")
    if manifest["kind"] == "download" and manifest["format"] not in ("mp4", "webm", "mp3"):
        raise MediaError("runtime")
    supervisor = Supervisor(manifest)
    try:
        if manifest["kind"] == "download":
            checked_url(manifest.get("url"))
            download_media(manifest, supervisor)
        convert_media(manifest, supervisor)
    finally:
        supervisor.stopped.set()
        supervisor.stop_child()


if __name__ == "__main__":
    try:
        main()
    except MediaError as error:
        emit("error", code=error.code)
        sys.exit(1)
    except Exception:
        emit("error", code="failed")
        sys.exit(1)
