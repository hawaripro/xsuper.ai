"""Private background-removal worker for the UltrAI media pipeline.

Stdin carries a server-created JSON manifest; stdout is sanitized NDJSON. The
runner reads the uploaded image at ``input`` (cwd is a per-job ``work`` dir),
removes the background with rembg, and writes ``result.png`` (RGBA). No CLI
passthrough, no network beyond the one-time model fetch, no user paths.
"""

import json
import os
import sys

INPUT_MAX = 128 * 1024 * 1024
OUTPUT_MAX = 256 * 1024 * 1024
DIMENSION_MAX = 4096
PIXEL_MAX = 4096 * 4096
IMAGE_SIGNATURES = (b"\x89PNG\r\n\x1a\n", b"\xff\xd8\xff", b"GIF87a", b"GIF89a", b"RIFF", b"BM", b"II*\x00", b"MM\x00*")


def emit(event, **values):
    sys.stdout.write(json.dumps({"event": event, **values}, separators=(",", ":")) + "\n")
    sys.stdout.flush()


class ToolError(Exception):
    def __init__(self, code="failed"):
        super().__init__(code)
        self.code = code


def bounded_int(value, maximum):
    try:
        number = int(value)
    except (TypeError, ValueError):
        return maximum
    if number < 1 or number > maximum:
        return maximum
    return number


def looks_like_image(path):
    with open(path, "rb") as handle:
        header = handle.read(16)
    if header.startswith(b"RIFF") and b"WEBP" not in open(path, "rb").read(16):
        return False
    return any(header.startswith(signature) for signature in IMAGE_SIGNATURES)


def run(manifest):
    input_limit = bounded_int(manifest.get("input_limit"), INPUT_MAX)
    output_limit = bounded_int(manifest.get("output_limit"), OUTPUT_MAX)
    dimension_limit = bounded_int(manifest.get("dimension_limit"), DIMENSION_MAX)
    pixel_limit = bounded_int(manifest.get("pixel_limit"), PIXEL_MAX)

    model_dir = manifest.get("model_dir")
    if isinstance(model_dir, str) and os.path.isabs(model_dir) and os.path.isdir(model_dir):
        os.environ["U2NET_HOME"] = model_dir
        os.environ["NUMBA_CACHE_DIR"] = model_dir
        os.environ["MPLCONFIGDIR"] = model_dir

    source = os.path.join(os.getcwd(), "input")
    if not os.path.isfile(source) or os.path.islink(source):
        raise ToolError("failed")
    size = os.path.getsize(source)
    if size < 1 or size > input_limit:
        raise ToolError("input_limit")
    if not looks_like_image(source):
        raise ToolError("invalid_media")

    emit("progress", stage="probing", progress=None)
    from PIL import Image

    Image.MAX_IMAGE_PIXELS = pixel_limit
    try:
        with Image.open(source) as probe:
            probe.verify()
        with Image.open(source) as image:
            width, height = image.size
            if width < 1 or height < 1 or width > dimension_limit or height > dimension_limit or (width * height) > pixel_limit:
                raise ToolError("dimensions")
            frame = image.convert("RGBA")
    except ToolError:
        raise
    except Exception:
        raise ToolError("invalid_media")

    emit("progress", stage="converting", progress=None)
    try:
        from rembg import new_session, remove

        session = new_session("u2net")
        result = remove(frame, session=session, post_process_mask=True)
    except ToolError:
        raise
    except Exception:
        raise ToolError("failed")

    emit("progress", stage="saving", progress=None)
    output = os.path.join(os.getcwd(), "result.png")
    try:
        result.convert("RGBA").save(output, format="PNG", optimize=True)
    except Exception:
        raise ToolError("failed")

    out_size = os.path.getsize(output)
    if out_size < 1 or out_size > output_limit:
        raise ToolError("output_limit")

    emit("result", format="png", mime_type="image/png", size_bytes=out_size, duration=None)


def check():
    try:
        import rembg  # noqa: F401

        print(json.dumps({"rembg": True}))
    except Exception:
        print(json.dumps({"rembg": False}))


def main():
    command = sys.argv[1] if len(sys.argv) > 1 else "run"
    if command == "check":
        check()
        return 0

    try:
        manifest = json.loads(sys.stdin.buffer.read(65536) or b"{}")
    except Exception:
        emit("error", code="failed")
        return 1
    if not isinstance(manifest, dict):
        emit("error", code="failed")
        return 1

    try:
        run(manifest)
    except ToolError as error:
        emit("error", code=error.code)
        return 1
    except Exception:
        emit("error", code="failed")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
