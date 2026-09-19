import { useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../../contexts/AuthContext";
import { useLocale } from "../../contexts/LocaleContext";
import { useTheme } from "../../contexts/ThemeContext";
import MediaActionDialog from "../MediaActionDialog";
import { errorMessage, formatLocalDate, validationErrors } from "../member/MemberUI";
import useMediaToolQueue, { isActiveJob } from "./useMediaToolQueue";
import "./media-tools.css";

const FORMAT_INFO = {
    mp4: { type: "video", label: "Video" },
    webm: { type: "video", label: "Video" },
    gif: { type: "video", label: "GIF animasi", output: "gif" },
    mp3: { type: "audio", label: "Audio" },
    wav: { type: "audio", label: "Audio" },
    flac: { type: "audio", label: "Audio" },
    png: { type: "image", label: "Gambar" },
    jpg: { type: "image", label: "Gambar" },
    webp: { type: "image", label: "Gambar" },
};
const QUALITIES = [["best", "Terbaik yang tersedia"], ["1080", "Maksimal 1080p"], ["720", "Maksimal 720p"], ["480", "Maksimal 480p"], ["360", "Maksimal 360p"]];
const RESOLUTIONS = [["source", "Ikuti sumber (maks. 1080p)"], ["1080", "1080p"], ["720", "720p"], ["480", "480p"], ["360", "360p"]];
const ENCODINGS = [["high", "Kualitas tinggi", "File lebih besar"], ["balanced", "Seimbang", "Bawaan"], ["small", "Hemat ukuran", "Kompresi kuat"]];
const AUDIO_BITRATES = ["128", "192", "320"];
const GIF_MAX_SECONDS = 15;
const STAGE_LABELS = {
    pending: "Menunggu antrean", queued: "Menunggu antrean", processing: "Sedang diproses",
    downloading: "Mengunduh sumber", probing: "Memeriksa media", converting: "Mengonversi media",
    saving: "Menyimpan hasil", cancelling: "Menunggu pembatalan", completed: "Selesai",
    failed: "Proses gagal", cancelled: "Dibatalkan",
};
const ACCEPTED_FILES = ".mp4,.mov,.m4v,.mkv,.webm,.mp3,.wav,.flac,.ogg,.oga,.ogv,.ts,.mts,.m2ts,.jpg,.jpeg,.png,.webp";

function clockToSeconds(value) {
    const text = String(value ?? "").trim();
    if (!text) return null;
    if (/^\d+(?:[.,]\d+)?$/.test(text)) return Number(text.replace(",", "."));
    const parts = text.split(":").map((part) => part.trim());
    if (parts.length > 3 || parts.some((part) => !/^\d+(?:[.,]\d+)?$/.test(part))) return NaN;
    return parts.reduce((total, part) => total * 60 + Number(part.replace(",", ".")), 0);
}

const TOOL_ICON_PATHS = {
        download: <><path d="M12 3v12m-5-5 5 5 5-5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4" /></>,
        convert: <><path d="M4 8h15l-4-4m5 12H5l4 4M4 8v4m16 4v-4" /></>,
        upload: <><path d="M12 16V3m-5 5 5-5 5 5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4" /></>,
        file: <><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z" /><path d="M14 3v6h6M8 14h8m-8 3h5" /></>,
        video: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m10 9 5 3-5 3Z" /></>,
        audio: <><path d="M9 18V5l11-2v13M9 9l11-2" /><ellipse cx="6" cy="18" rx="3" ry="3" /><ellipse cx="17" cy="16" rx="3" ry="3" /></>,
        image: <><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8" cy="8" r="1.5" /><path d="m21 15-5-5L5 21" /></>,
        link: <><path d="m10 13 4-4m-6 6-1 1a4.24 4.24 0 0 1-6-6l4-4a4.24 4.24 0 0 1 6 0m2 2 1-1a4.24 4.24 0 0 1 6 6l-4 4a4.24 4.24 0 0 1-6 0" transform="translate(1 1)" /></>,
        shield: <><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" /><path d="m8 12 3 3 5-6" /></>,
        lock: <><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2" /></>,
        history: <><path d="M3 11a9 9 0 1 1 2.7 7.4M3 4v7h7m2-5v6l4 2" /></>,
        refresh: <><path d="M20 8a8 8 0 0 0-14-3L3 8m0-6v6h6M4 16a8 8 0 0 0 14 3l3-3m0 6v-6h-6" /></>,
        close: <path d="m6 6 12 12M6 18 18 6" />,
        stop: <rect x="6" y="6" width="12" height="12" rx="2" />,
        check: <path d="m5 12 4 4L19 6" />,
        arrow: <path d="M4 12h16m-6-6 6 6-6 6" />,
        warning: <><path d="m12 3 10 18H2L12 3Z" /><path d="M12 9v5m0 3v.1" /></>,
        clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
        trash: <><path d="M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3" /></>,
        search: <><circle cx="11" cy="11" r="6.5" /><path d="m16 16 5 5" /></>,
        settings: <><path d="M4 7h10m4 0h2M4 17h4m4 0h8" /><circle cx="16" cy="7" r="2" /><circle cx="10" cy="17" r="2" /></>
};

function ToolIcon({ name, className = "" }) {
    return <svg className={`mt-icon ${className}`} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{TOOL_ICON_PATHS[name] || TOOL_ICON_PATHS.file}</svg>;
}

function Notice({ tone = "warning", children, action }) {
    return <div className={`mt-notice mt-notice--${tone}`} role={tone === "error" ? "alert" : "status"}>
        <ToolIcon name={tone === "info" ? "clock" : "warning"} />
        <div>{children}{action && <div className="mt-notice-action">{action}</div>}</div>
    </div>;
}

function bytes(value, locale) {
    if (!Number.isFinite(value) || value < 0) return "—";
    const unit = value >= 1024 ** 3 ? 3 : value >= 1024 ** 2 ? 2 : value >= 1024 ? 1 : 0;
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: unit ? 1 : 0 }).format(value / (1024 ** unit))} ${["B", "KiB", "MiB", "GiB"][unit]}`;
}

function duration(value) {
    if (typeof value !== "number" || !Number.isFinite(value) || value < 0) return null;
    const seconds = Math.round(value);
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

function progressOf(job) {
    return typeof job.progress === "number" && Number.isFinite(job.progress) && job.progress >= 0 && job.progress <= 100
        ? Math.round(job.progress) : null;
}

function sourceName(value) {
    try { return new URL(value).hostname; } catch { return null; }
}

function sourceType(file) {
    if (!file) return null;
    const extension = file.name.split(".").pop()?.toLowerCase();
    if (/^image\//.test(file.type) || ["jpg", "jpeg", "png", "webp"].includes(extension)) return "image";
    if (/^audio\//.test(file.type) || ["mp3", "wav", "flac", "ogg", "oga"].includes(extension)) return "audio";
    if (/^video\//.test(file.type) || ["mp4", "mov", "m4v", "mkv", "webm", "ogv", "ts", "mts", "m2ts"].includes(extension)) return "video";
    return null;
}

function urlProblem(value) {
    if (!value.trim()) return "Masukkan tautan video terlebih dahulu.";
    try {
        const parsed = new URL(value.trim());
        if (!["http:", "https:"].includes(parsed.protocol) || !parsed.hostname) return "Gunakan tautan publik yang diawali http:// atau https://.";
        if (parsed.username || parsed.password) return "Tautan dengan nama pengguna atau kata sandi tidak diizinkan.";
        if (parsed.port && !["80", "443"].includes(parsed.port)) return "Tautan hanya dapat menggunakan port HTTP atau HTTPS standar.";
        if (value.trim().length > 2048) return "Tautan terlalu panjang. Gunakan maksimal 2048 karakter.";
    } catch {
        return "Tautan belum valid. Periksa alamat lengkapnya.";
    }
    return null;
}

function privateAsset(job) {
    if (job.status !== "completed" || typeof job.result_url !== "string") return null;
    const path = `/api/media-tools/${encodeURIComponent(job.job_id)}/asset`;
    try {
        const url = new URL(job.result_url, window.location.origin);
        return url.origin === window.location.origin && url.pathname === path && !url.username && !url.password ? path : null;
    } catch { return null; }
}

function fieldMessage(errors, name) {
    const value = errors?.[name];
    return Array.isArray(value) ? value.find((item) => typeof item === "string") : typeof value === "string" ? value : null;
}

function JobStatus({ job, t, live = false }) {
    return <span className={`mt-status mt-status--${job.status}`} role={live ? "status" : undefined}>
        <ToolIcon name={job.status === "completed" ? "check" : job.status === "failed" ? "warning" : job.status === "cancelled" ? "stop" : "clock"} />
        {t(STAGE_LABELS[job.stage] || STAGE_LABELS[job.status] || "Status belum tersedia")}
    </span>;
}

function JobResult({ job, queue, t, locale }) {
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [playbackError, setPlaybackError] = useState(false);
    const [previewVersion, setPreviewVersion] = useState(0);
    const mediaElement = useRef(null);
    const active = isActiveJob(job);
    const progress = progressOf(job);
    const asset = privateAsset(job);
    const type = job.mime_type?.startsWith("video/") ? "video"
        : job.mime_type?.startsWith("audio/") ? "audio"
            : job.mime_type?.startsWith("image/") ? "image" : null;
    const title = job.title || job.input_name || t(job.kind === "download" ? "Unduhan video" : "Konversi media");
    const cancelError = queue.cancelError?.jobId === job.job_id ? queue.cancelError.error : null;
    const cancelBusy = queue.cancelling === job.job_id;
    const retryPreview = () => { setPlaybackError(false); setPreviewVersion((value) => value + 1); };
    const previewLabel = `${t("Pratinjau hasil")}: ${title}`;

    useEffect(() => {
        const element = mediaElement.current;
        return () => {
            if (!element) return;
            element.pause();
        };
    }, [asset, type, previewVersion]);

    return <>
        <div className="mt-monitor-toolbar"><h2>{t("Pratinjau hasil")}</h2><JobStatus job={job} t={t} live /></div>
        <div className={`mt-stage ${asset && type === "video" ? "mt-stage--video" : ""} ${asset && type === "image" ? "mt-stage--image" : ""}`}>
            {asset && type === "video" && <video ref={mediaElement} key={previewVersion} src={asset} controls playsInline preload="metadata" aria-label={previewLabel} onError={() => setPlaybackError(true)} />}
            {asset && type === "image" && <img key={previewVersion} src={asset} alt={previewLabel} onError={() => setPlaybackError(true)} />}
            {asset && type === "audio" && <div className="mt-audio-preview"><ToolIcon name="audio" /><span>{t("Hasil audio")}</span><audio ref={mediaElement} key={previewVersion} src={asset} controls preload="metadata" aria-label={previewLabel} onError={() => setPlaybackError(true)} /></div>}
            {!asset && <div className="mt-stage-message">
                <ToolIcon name={active ? "clock" : job.status === "failed" ? "warning" : job.status === "cancelled" ? "stop" : "file"} />
                <h3>{t(active ? "Media sedang disiapkan" : job.status === "cancelled" ? "Proses dibatalkan" : job.status === "failed" ? "Media belum berhasil diproses" : "Hasil belum tersedia")}</h3>
                <p>{t(active ? "Proses berjalan di server. Anda dapat meninggalkan halaman setelah pekerjaan masuk ke riwayat." : job.status === "cancelled" ? "Tidak ada file hasil dari pekerjaan ini. Sumber asli tidak diubah." : job.status === "failed" ? "Baca detail kesalahan di bawah, lalu perbaiki sumber atau format sebelum mengirim pekerjaan baru." : "Perbarui status untuk memeriksa ketersediaan file.")}</p>
            </div>}
            {asset && !type && <div className="mt-stage-message"><ToolIcon name="file" /><h3>{t("File siap diunduh")}</h3><p>{t("Format ini tidak memiliki pratinjau di browser. Unduh file untuk membukanya.")}</p></div>}
        </div>
        <div className="mt-result-details">
            <div className="mt-result-title"><h3 title={title}>{title}</h3>{job.format && <span className="mt-extension">{job.format.toUpperCase()}</span>}</div>
            <div className="mt-result-meta">
                {typeof job.size_bytes === "number" && <span>{bytes(job.size_bytes, locale)}</span>}
                {duration(job.duration) && <span>{duration(job.duration)}</span>}
                {sourceName(job.source_url) && <span>{sourceName(job.source_url)}</span>}
                <time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time>
            </div>
            {active && <div className="mt-job-progress">
                <div><span>{t(STAGE_LABELS[job.stage] || "Sedang diproses")}</span><span>{progress == null ? t("Belum ada pengukuran") : `${new Intl.NumberFormat(locale).format(progress)}%`}</span></div>
                <progress max="100" {...(progress == null ? {} : { value: progress })} aria-label={t("Progres yang dilaporkan server")} />
                <p>{t("Progres mengikuti laporan proses di server, bukan perkiraan waktu.")}</p>
            </div>}
            {job.error && <Notice tone={job.status === "cancelled" ? "info" : "error"}>{t(job.error)}</Notice>}
            {playbackError && <Notice action={<button type="button" className="mt-text-button" onClick={retryPreview}>{t("Muat ulang pratinjau")}</button>}>{t("Pratinjau tidak dapat dimuat di browser ini. Unduh file aslinya atau perbarui status.")}</Notice>}
            {cancelError && <Notice tone="error">{t(errorMessage(cancelError))}</Notice>}
            {queue.deepError && <Notice tone="error">{t(errorMessage(queue.deepError))}</Notice>}
            <div className="mt-result-actions">
                {asset && <a className="mt-button mt-button--primary" href={`${asset}?download=1`} download><ToolIcon name="download" />{t("Unduh file")}</a>}
                <button type="button" className="mt-button mt-button--secondary" disabled={queue.deepLoading} onClick={() => queue.loadJob(job.job_id)}><ToolIcon name="refresh" />{t(queue.deepLoading ? "Memperbarui…" : "Perbarui status")}</button>
                {active && job.can_cancel && !confirmCancel && <button type="button" className="mt-button mt-button--quiet" onClick={() => setConfirmCancel(true)}><ToolIcon name="stop" />{t("Batalkan proses")}</button>}
            </div>
            {active && job.can_cancel && confirmCancel && <div className="mt-cancel-confirm" role="group" aria-label={t("Konfirmasi pembatalan")}>
                <p>{t("Hentikan pekerjaan ini? File hasil yang belum selesai akan dibuang.")}</p>
                <div><button type="button" className="mt-button mt-button--danger" disabled={cancelBusy} onClick={async () => { await queue.cancel(job); setConfirmCancel(false); }}>{t(cancelBusy ? "Membatalkan…" : "Ya, batalkan")}</button><button type="button" className="mt-button mt-button--quiet" disabled={cancelBusy} onClick={() => setConfirmCancel(false)}>{t("Lanjutkan proses")}</button></div>
            </div>}
            <p className="mt-private-note"><ToolIcon name="lock" />{t("Hasil tersimpan secara privat untuk akun Anda.")}</p>
        </div>
    </>;
}

function FileInput({ file, onFile, onRemove, disabled, removeDisabled, maxBytes, error, t, locale, inputRef }) {
    const [dragging, setDragging] = useState(false);
    const depth = useRef(0);
    const group = sourceType(file);
    const hasFiles = (event) => Array.from(event.dataTransfer?.types || []).includes("Files");
    return <div className="mt-upload-field">
        <label className="mt-field-label" htmlFor="media-tool-file">{t("File sumber")}</label>
        <div className={`mt-dropzone ${dragging ? "is-dragging" : ""} ${file ? "has-file" : ""}`} data-disabled={disabled || undefined}
            onDragEnter={(event) => { if (hasFiles(event)) { event.preventDefault(); depth.current += 1; if (!disabled) setDragging(true); } }}
            onDragOver={(event) => { if (hasFiles(event)) { event.preventDefault(); event.dataTransfer.dropEffect = disabled ? "none" : "copy"; } }}
            onDragLeave={(event) => { event.preventDefault(); depth.current = Math.max(0, depth.current - 1); if (depth.current === 0) setDragging(false); }}
            onDrop={(event) => { event.preventDefault(); depth.current = 0; setDragging(false); if (!disabled) onFile(Array.from(event.dataTransfer.files)); }}>
            <input ref={inputRef} id="media-tool-file" type="file" accept={ACCEPTED_FILES} className="mt-file-input" disabled={disabled}
                aria-describedby={`media-tool-file-help${error ? " media-tool-file-error" : ""}`} aria-invalid={Boolean(error)}
                onChange={(event) => { const files = Array.from(event.target.files || []); if (files.length) onFile(files); event.target.value = ""; }} />
            {file ? <div className="mt-file-details"><ToolIcon name={group || "file"} className={`mt-tone--${group || "file"}`} /><strong title={file.name}>{file.name}</strong><span>{bytes(file.size, locale)}</span><span>{file.type || t("Jenis file akan diperiksa server")}</span></div>
                : <><ToolIcon name="upload" /><strong>{t("Tarik satu file ke sini")}</strong><p>{t("Video, audio, atau gambar dari perangkat Anda.")}</p></>}
            <label htmlFor="media-tool-file" className="mt-button mt-button--secondary mt-file-choice">{t(file ? "Ganti file" : "Pilih file")}</label>
        </div>
        {file && <button type="button" className="mt-text-button mt-remove-file" disabled={removeDisabled} onClick={onRemove}><ToolIcon name="close" />{t("Hapus file")}</button>}
        <p id="media-tool-file-help" className="mt-help">{maxBytes ? `${t("Maksimal")} ${bytes(maxBytes, locale)}. ` : ""}{t("Satu file per pekerjaan. Jenis dan isi file diperiksa oleh server.")}</p>
        {error && <p id="media-tool-file-error" className="mt-field-error" role="alert">{t(error)}</p>}
    </div>;
}

function WorkspaceSession({ kind, user }) {
    const { t, locale, localizedPath } = useLocale();
    const { theme } = useTheme();
    const location = useLocation();
    const navigate = useNavigate();
    const queryJob = new URLSearchParams(location.search).get("job") || "";
    const queue = useMediaToolQueue({ kind, userId: user.id, jobId: queryJob });
    const [url, setUrl] = useState("");
    const [file, setFile] = useState(null);
    const [format, setFormat] = useState("");
    const [localErrors, setLocalErrors] = useState({});
    const [submittedInputs, setSubmittedInputs] = useState([]);
    const [options, setOptions] = useState({ quality: "best", resolution: "source", encoding: "balanced", audio_bitrate: "192", trim_start: "", trim_end: "" });
    const [preview, setPreview] = useState({ url: "", data: null, loading: false, error: null });
    const [pendingDelete, setPendingDelete] = useState(null);
    const previewRequest = useRef(null);
    const fileInput = useRef(null);
    const urlInput = useRef(null);
    const resultRef = useRef(null);
    const mounted = useRef(true);
    const locationRef = useRef(location);
    locationRef.current = location;
    const download = kind === "download";
    const permission = download ? "video_downloader" : "media_converter";
    const isAdmin = user.role === "admin";
    const canUse = isAdmin || user.permissions?.[permission] !== false;
    const expired = !isAdmin && user.expires_at && Date.parse(user.expires_at) <= Date.now();
    const inactive = !isAdmin && user.is_active === false;
    const available = queue.capabilities?.available?.[kind] === true;
    const uploadLimit = Number(queue.capabilities?.limits?.max_upload_bytes) || 0;
    const sourceGroup = sourceType(file);
    const formats = (queue.capabilities?.[`${kind}_formats`] || []).filter((value) => FORMAT_INFO[value]
        && (download || !sourceGroup || sourceGroup === "video" || FORMAT_INFO[value].type === sourceGroup));
    const chosenFormat = formats.includes(format) ? format : formats[0] || "";
    const sourceKey = download ? url.trim() : file ? `${file.name}:${file.size}:${file.lastModified}` : "";
    const duplicateJob = queue.jobs.find((job) => isActiveJob(job) && submittedInputs.some((input) => input.jobId === job.job_id && input.sourceKey === sourceKey && input.format === chosenFormat));
    const busy = queue.submitting || Boolean(queue.recovery);
    const formDisabled = busy || !available || !canUse || expired || inactive || !queue.historyLoaded || Boolean(queue.capabilityError);
    const serverErrors = validationErrors(queue.submitError);
    const errors = { ...serverErrors, ...localErrors };
    const urlError = fieldMessage(errors, "url");
    const fileError = fieldMessage(errors, "file");
    const formatError = fieldMessage(errors, "format");
    // Open fresh: an explicit deep link or an in-flight job shows; finished history never auto-opens.
    const selected = queryJob ? queue.jobs.find((job) => job.job_id.toLowerCase() === queryJob.toLowerCase()) : (queue.jobs.find(isActiveJob) || null);
    const activeCount = queue.jobs.filter(isActiveJob).length;
    const otherAllowed = isAdmin || user.permissions?.[download ? "media_converter" : "video_downloader"] !== false;

    useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

    const chooseJob = (id, scroll = false) => {
        const currentLocation = locationRef.current;
        const params = new URLSearchParams(currentLocation.search);
        if (id) params.set("job", id); else params.delete("job");
        navigate({ pathname: currentLocation.pathname, search: params.toString() ? `?${params}` : "", hash: currentLocation.hash });
        if (scroll) {
            resultRef.current?.scrollIntoView({ block: "start", behavior: "auto" });
            resultRef.current?.focus({ preventScroll: true });
        }
    };
    const editSource = () => { setLocalErrors({}); queue.clearSubmitError(); };
    const chooseFile = (files) => {
        editSource();
        if (files.length !== 1) { setLocalErrors({ file: "Pilih satu file saja untuk setiap pekerjaan." }); return; }
        const next = files[0];
        if (!next.size) { setLocalErrors({ file: "File kosong tidak dapat dikonversi. Pilih file lain." }); return; }
        if (!uploadLimit || next.size > uploadLimit) { setLocalErrors({ file: "Ukuran file melebihi batas unggah yang tersedia." }); return; }
        setFile(next);
    };
    const removeFile = () => { setFile(null); editSource(); fileInput.current?.focus(); };
    const setOption = (name, value) => setOptions((current) => ({ ...current, [name]: value }));
    const outputType = chosenFormat ? FORMAT_INFO[chosenFormat].type : null;
    const isGif = chosenFormat === "gif";
    const trimmable = !download && chosenFormat && outputType !== "image";
    const trimStart = clockToSeconds(options.trim_start);
    const trimEnd = clockToSeconds(options.trim_end);
    const trimProblem = !trimmable ? null
        : Number.isNaN(trimStart) || Number.isNaN(trimEnd) ? "Gunakan detik atau format mm:ss untuk waktu potong."
            : trimStart != null && trimEnd != null && trimEnd <= trimStart ? "Akhir potongan harus setelah awal potongan."
                : isGif && (trimEnd ?? GIF_MAX_SECONDS + 1) - (trimStart ?? 0) > GIF_MAX_SECONDS ? "GIF dibatasi 15 detik. Tentukan awal dan akhir potongan yang lebih pendek." : null;
    const inspectSource = async () => {
        const problem = urlProblem(url);
        if (problem) { setLocalErrors({ url: problem }); urlInput.current?.focus(); return; }
        previewRequest.current?.abort();
        const controller = new AbortController();
        previewRequest.current = controller;
        setPreview({ url: url.trim(), data: null, loading: true, error: null });
        try {
            const data = await queue.inspect(url.trim(), controller.signal);
            if (!controller.signal.aborted && mounted.current) setPreview({ url: url.trim(), data: data?.source || null, loading: false, error: null });
        } catch (error) {
            if (!controller.signal.aborted && mounted.current) setPreview({ url: url.trim(), data: null, loading: false, error });
        }
    };
    const submit = async (event) => {
        event.preventDefault();
        if (formDisabled || duplicateJob) return;
        const invalid = {};
        if (download) {
            const problem = urlProblem(url);
            if (problem) invalid.url = problem;
        } else if (!file) invalid.file = "Pilih file sumber terlebih dahulu.";
        else if (!uploadLimit || file.size > uploadLimit) invalid.file = "Ukuran file melebihi batas unggah yang tersedia.";
        if (!chosenFormat) invalid.format = "Pilih format hasil yang tersedia.";
        if (trimProblem) invalid.trim_end = trimProblem;
        setLocalErrors(invalid);
        if (Object.keys(invalid).length) {
            if (invalid.url) urlInput.current?.focus();
            else if (invalid.file) fileInput.current?.focus();
            return;
        }
        let body;
        if (download) {
            body = { url: url.trim(), format: chosenFormat, ...(outputType === "video" && options.quality !== "best" ? { quality: options.quality } : {}) };
        } else {
            body = new FormData();
            body.append("file", file);
            body.append("format", chosenFormat);
            if (outputType === "video" && !isGif) { body.append("resolution", options.resolution); body.append("encoding", options.encoding); }
            if (chosenFormat === "mp3") body.append("audio_bitrate", options.audio_bitrate);
            if (trimmable && trimStart != null) body.append("trim_start", String(trimStart));
            if (trimmable && trimEnd != null) body.append("trim_end", String(trimEnd));
        }
        const job = await queue.submit(body, chosenFormat, file?.name || null);
        if (job && mounted.current) {
            setSubmittedInputs((current) => [
                ...current.filter((input) => queue.jobs.some((entry) => entry.job_id === input.jobId && isActiveJob(entry))),
                { jobId: job.job_id, sourceKey, format: chosenFormat },
            ]);
            chooseJob(job.job_id);
        }
    };
    const confirmDelete = async () => {
        if (!pendingDelete) return;
        const ok = pendingDelete === "all" ? (await queue.clear()) !== null : await queue.remove(pendingDelete);
        if (ok && mounted.current) {
            setPendingDelete(null);
            if (pendingDelete === "all" || pendingDelete.job_id === selected?.job_id) chooseJob(null);
        }
    };
    const deletableCount = queue.jobs.filter((job) => !isActiveJob(job)).length;
    const title = t(download ? "Download Video" : "Konverter Media");
    const runtimeUnavailable = !queue.capabilityLoading && !queue.capabilityError && !available;

    return <div className={`media-tools-workspace media-tools-workspace--${kind}`} data-theme={theme}>
        <header className="mt-page-heading">
            <div className="mt-page-title"><span className="mt-title-icon"><ToolIcon name={kind} /></span><div><h1>{title}</h1><p>{t(download ? "Simpan video atau audio dari tautan publik, dalam format yang Anda perlukan." : "Ubah format video, audio, dan gambar tanpa mengubah file sumber.")}</p></div></div>
            <div className="mt-page-actions">
                {otherAllowed && <Link className="mt-button mt-button--secondary" to={localizedPath(download ? "/converter" : "/downloads")}><ToolIcon name={download ? "convert" : "download"} />{t(download ? "Konverter Media" : "Download Video")}</Link>}
                <button type="button" className="mt-button mt-button--secondary" disabled={queue.historyLoading} onClick={queue.refresh}><ToolIcon name="refresh" />{t("Muat ulang")}</button>
            </div>
        </header>
        {!canUse && <Notice tone="error">{t("Akun Anda tidak memiliki izin untuk membuat pekerjaan baru di alat ini. Riwayat milik Anda tetap dapat diperiksa.")}</Notice>}
        {(expired || inactive) && <Notice tone="warning">{t(expired ? "Masa aktif akun berakhir. Perpanjang akses untuk membuat pekerjaan baru; hasil lama tetap dapat diperiksa." : "Akun tidak aktif. Hubungi dukungan untuk memulihkan akses.")}</Notice>}
        {queue.capabilityError && <Notice tone="error" action={<button type="button" className="mt-text-button" disabled={queue.capabilityLoading} onClick={queue.loadCapabilities}>{t("Coba periksa lagi")}</button>}>{t(errorMessage(queue.capabilityError))}</Notice>}
        {runtimeUnavailable && <Notice action={<button type="button" className="mt-text-button" onClick={queue.loadCapabilities}>{t("Periksa ketersediaan")}</button>}><strong>{t("Alat belum tersedia di server ini.")}</strong><p>{queue.capabilities.message ? t(queue.capabilities.message) : t("Runtime belum siap. Tidak ada pekerjaan baru yang dikirim. Riwayat dan hasil yang sudah tersedia tetap dapat dibuka.")}</p></Notice>}
        <div className="mt-workbench">
            <section className="mt-composer" aria-labelledby="media-tool-source-heading">
                <div className="mt-panel-heading"><h2 id="media-tool-source-heading">{t(download ? "Tautan & format" : "File & format")}</h2><ToolIcon name={download ? "link" : "convert"} /></div>
                <form onSubmit={submit} noValidate>
                    <div className="mt-form-fields">
                        {download ? <div><label className="mt-field-label" htmlFor="media-tool-url">{t("Tautan video")}</label><div className="mt-url-control"><input ref={urlInput} id="media-tool-url" type="url" inputMode="url" autoComplete="off" spellCheck="false" maxLength={2048} placeholder="https://…" value={url} disabled={formDisabled}
                            aria-invalid={Boolean(urlError)} aria-describedby={`media-tool-url-help${urlError ? " media-tool-url-error" : ""}`}
                            onChange={(event) => { setUrl(event.target.value); editSource(); }} onBlur={() => { if (url.trim()) setLocalErrors((current) => ({ ...current, url: urlProblem(url) })); }} />
                            {url && <button type="button" className="mt-clear-source" disabled={busy} aria-label={t("Hapus tautan")} onClick={() => { setUrl(""); editSource(); urlInput.current?.focus(); }}><ToolIcon name="close" /></button>}</div>
                            <p id="media-tool-url-help" className="mt-help">{t("Tautan publik HTTP(S), satu video per pekerjaan. Konten berbayar, login, DRM, playlist, dan siaran langsung tidak didukung.")}</p>
                            {urlError && <p className="mt-field-error" id="media-tool-url-error" role="alert">{t(urlError)}</p>}
                            <div className="mt-inspect-row"><button type="button" className="mt-button mt-button--secondary" disabled={formDisabled || !url.trim() || preview.loading} onClick={inspectSource}><ToolIcon name="search" />{t(preview.loading ? "Memeriksa tautan…" : "Periksa tautan")}</button><span className="mt-help">{t("Lihat judul, durasi, dan kualitas yang tersedia sebelum mengunduh.")}</span></div>
                            {preview.error && preview.url === url.trim() && <p className="mt-field-error" role="alert">{t(errorMessage(preview.error))}</p>}
                            {preview.data && preview.url === url.trim() && <div className="mt-source-preview" role="status">
                                {preview.data.thumbnail ? <img src={preview.data.thumbnail} alt="" loading="lazy" referrerPolicy="no-referrer" /> : <span className="mt-source-thumb"><ToolIcon name="video" /></span>}
                                <div className="mt-source-details">
                                    <strong>{preview.data.title || t("Judul tidak tersedia")}</strong>
                                    <span>{[preview.data.uploader, preview.data.source_host, duration(preview.data.duration)].filter(Boolean).join(" · ")}</span>
                                    <span>{preview.data.heights?.length ? `${t("Kualitas tersedia")}: ${preview.data.heights.map((height) => `${height}p`).join(", ")}` : t(preview.data.has_video ? "Kualitas ditentukan server dari sumber." : "Sumber ini hanya memiliki audio.")}</span>
                                </div>
                            </div>}
                        </div> : <FileInput file={file} onFile={chooseFile} onRemove={removeFile} disabled={formDisabled} removeDisabled={busy} maxBytes={uploadLimit} error={fileError} t={t} locale={locale} inputRef={fileInput} />}
                        <fieldset className="mt-format-field" disabled={formDisabled || !formats.length} aria-describedby={`media-tool-format-help${formatError ? " media-tool-format-error" : ""}`}>
                            <legend>{t("Format hasil")}</legend>
                            {queue.capabilityLoading && !queue.capabilities ? <div className="mt-format-loading" role="status">{t("Memeriksa format yang tersedia…")}</div>
                                : formats.length ? <div className="mt-formats">{formats.map((value) => <label className={`mt-format-option ${chosenFormat === value ? "is-selected" : ""}`} key={value}>
                                    <input type="radio" name="media-tool-format" value={value} checked={chosenFormat === value} onChange={() => { setFormat(value); editSource(); }} />
                                    <ToolIcon name={FORMAT_INFO[value].type} className={`mt-tone--${FORMAT_INFO[value].type}`} /><strong>{value.toUpperCase()}</strong><span>{t(FORMAT_INFO[value].label)}</span>
                                </label>)}</div> : <p className="mt-help">{t("Belum ada format hasil yang tersedia.")}</p>}
                            {formatError && <p className="mt-field-error" id="media-tool-format-error" role="alert">{t(formatError)}</p>}
                        </fieldset>
                        <p id="media-tool-format-help" className="mt-help">{t(download ? "Format yang tidak tersedia pada sumber akan ditolak. Audio MP3 hanya tersedia jika sumber memiliki audio." : chosenFormat && FORMAT_INFO[chosenFormat].type === "image" ? "Dari video, hasil gambar menggunakan frame pertama. Format sumber sebenarnya diperiksa server." : chosenFormat && FORMAT_INFO[chosenFormat].type === "audio" ? "Sumber harus memiliki jalur audio. Format dan jalur media sebenarnya diperiksa server." : "Hasil video memerlukan sumber video. Format dan jalur media sebenarnya diperiksa server.")}</p>
                        {chosenFormat && (download ? outputType === "video" : outputType !== "image") && <fieldset className="mt-options" disabled={formDisabled}>
                            <legend><ToolIcon name="settings" />{t("Pengaturan hasil")}</legend>
                            {download && outputType === "video" && <div className="mt-option-field"><label htmlFor="media-tool-quality">{t("Kualitas video")}</label><select id="media-tool-quality" value={options.quality} onChange={(event) => setOption("quality", event.target.value)}>{QUALITIES.map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</select><p className="mt-help">{t("Server memilih stream tertinggi di bawah batas ini. Audio selalu disertakan bila tersedia.")}</p></div>}
                            {!download && outputType === "video" && !isGif && <>
                                <div className="mt-option-field"><label htmlFor="media-tool-resolution">{t("Resolusi")}</label><select id="media-tool-resolution" value={options.resolution} onChange={(event) => setOption("resolution", event.target.value)}>{RESOLUTIONS.map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</select></div>
                                <div className="mt-option-field"><span className="mt-field-label">{t("Kompresi")}</span><div className="mt-choice-row" role="radiogroup" aria-label={t("Kompresi")}>{ENCODINGS.map(([value, label, detail]) => <label key={value} className={`mt-choice ${options.encoding === value ? "is-selected" : ""}`}><input type="radio" name="media-tool-encoding" value={value} checked={options.encoding === value} onChange={() => setOption("encoding", value)} /><strong>{t(label)}</strong><span>{t(detail)}</span></label>)}</div></div>
                            </>}
                            {!download && chosenFormat === "mp3" && <div className="mt-option-field"><label htmlFor="media-tool-bitrate">{t("Bitrate audio")}</label><select id="media-tool-bitrate" value={options.audio_bitrate} onChange={(event) => setOption("audio_bitrate", event.target.value)}>{AUDIO_BITRATES.map((value) => <option key={value} value={value}>{value} kbps</option>)}</select></div>}
                            {trimmable && <div className="mt-option-field"><span className="mt-field-label">{t("Potong durasi")}</span><div className="mt-trim-row"><label><span>{t("Mulai")}</span><input type="text" inputMode="decimal" placeholder="0:00" value={options.trim_start} aria-invalid={Boolean(trimProblem)} onChange={(event) => { setOption("trim_start", event.target.value); editSource(); }} /></label><label><span>{t("Selesai")}</span><input type="text" inputMode="decimal" placeholder={isGif ? `0:${String(GIF_MAX_SECONDS).padStart(2, "0")}` : t("akhir")} value={options.trim_end} aria-invalid={Boolean(trimProblem)} onChange={(event) => { setOption("trim_end", event.target.value); editSource(); }} /></label></div><p className="mt-help">{t(isGif ? "Detik atau mm:ss. GIF maksimal 15 detik, 12 fps, lebar 480 px." : "Detik atau mm:ss. Kosongkan untuk memakai seluruh durasi.")}</p>{(trimProblem || fieldMessage(errors, "trim_end") || fieldMessage(errors, "trim_start")) && <p className="mt-field-error" role="alert">{t(trimProblem || fieldMessage(errors, "trim_end") || fieldMessage(errors, "trim_start"))}</p>}</div>}
                        </fieldset>}
                        {queue.capabilities?.limits && <dl className="mt-limits">
                            <div><dt>{t("Durasi maksimal")}</dt><dd>{duration(Number(queue.capabilities.limits.max_duration_seconds)) || "—"}</dd></div>
                            <div><dt>{t("Ukuran hasil maksimal")}</dt><dd>{bytes(Number(queue.capabilities.limits.max_output_bytes), locale)}</dd></div>
                        </dl>}
                        {download && <div className="mt-rights-notice"><ToolIcon name="shield" /><p>{t("Unduh hanya konten milik Anda atau yang Anda memiliki izin untuk gunakan. Patuhi hak cipta dan ketentuan sumber.")}</p></div>}
                        {!download && <details className="mt-supported-sources"><summary>{t("Format sumber yang didukung")}</summary><p>MP4 / MOV, Matroska / WebM, MPEG-TS, MP3 / WAV / FLAC / OGG, JPEG / PNG / WebP.</p><p>{t("Arsip, SVG, playlist, dan referensi file atau jaringan eksternal tidak didukung.")}</p></details>}
                    </div>
                    <div className="mt-submit-area">
                        {queue.submitError && !queue.recovery && <Notice tone="error">{t(errorMessage(queue.submitError))}</Notice>}
                        {queue.submitting && <div className="mt-upload-status" role="status"><strong>{t(download ? "Mengirim permintaan…" : "Mengunggah file…")}</strong><progress aria-label={t(download ? "Mengirim permintaan" : "Mengunggah file")} /><p>{t("Tunggu konfirmasi server sebelum mengirim pekerjaan lain. Persentase unggah belum tersedia.")}</p></div>}
                        {queue.recovery && !queue.submitting && <Notice action={<button type="button" className="mt-text-button" disabled={queue.historyLoading} onClick={queue.refresh}>{t("Periksa riwayat, jangan kirim ulang")}</button>}><strong>{t("Permintaan belum terkonfirmasi.")}</strong><p>{t("Koneksi terputus sebelum jawaban server diterima. Pengiriman baru ditahan untuk mencegah pekerjaan ganda. Periksa riwayat; jika tetap tidak ditemukan, hubungi dukungan.")}</p></Notice>}
                        {duplicateJob && <p className="mt-help" role="status">{t("Sumber dan format ini sudah memiliki pekerjaan yang berjalan.")} <button type="button" className="mt-text-button" onClick={() => chooseJob(duplicateJob.job_id, true)}>{t("Lihat pekerjaan yang berjalan")}</button></p>}
                        <button type="submit" className="mt-button mt-button--primary mt-submit-button" disabled={formDisabled || Boolean(duplicateJob) || !chosenFormat || (download ? !url.trim() : !file)}><ToolIcon name={download ? "download" : "convert"} />{t(queue.submitting ? "Menunggu konfirmasi…" : download ? "Mulai unduh" : "Konversi file")}<ToolIcon name="arrow" /></button>
                        <p className="mt-submit-note">{t("Utilitas media, bukan generasi AI. Tidak menggunakan token generator.")}</p>
                    </div>
                </form>
            </section>
            <section ref={resultRef} tabIndex={-1} className="mt-monitor" aria-label={t("Pratinjau dan status pekerjaan")}>
                {queue.deepError && queryJob ? <><div className="mt-monitor-toolbar"><h2>{t("Pratinjau hasil")}</h2></div><div className="mt-empty-error"><Notice tone="error" action={<><button type="button" className="mt-text-button" onClick={() => queue.loadJob(queryJob)}>{t("Coba lagi")}</button><button type="button" className="mt-text-button" onClick={() => chooseJob(null)}>{t("Tampilkan hasil terbaru")}</button></>}>{t(errorMessage(queue.deepError))}</Notice></div></>
                    : selected ? <JobResult key={selected.job_id} job={selected} queue={queue} t={t} locale={locale} />
                        : <><div className="mt-monitor-toolbar"><h2>{t("Pratinjau hasil")}</h2><span className="mt-private-note"><ToolIcon name="lock" />{t("Privat")}</span></div><div className="mt-stage"><div className="mt-stage-message"><ToolIcon name={queue.historyLoading || queue.deepLoading ? "clock" : download ? "download" : "convert"} /><h3>{t(queue.historyLoading || queue.deepLoading ? "Memuat pekerjaan Anda…" : download ? "Dari tautan ke file Anda" : "Format baru, sumber tetap utuh")}</h3><p>{t(queue.historyLoading || queue.deepLoading ? "Mengambil status terbaru dari server." : download ? "Masukkan tautan dan pilih format. Hasil yang selesai dapat diputar atau diunduh di sini." : "Pilih file dan format hasil. Pratinjau akan menggunakan file hasil konversi yang sebenarnya.")}</p></div></div><div className="mt-empty-footer"><ToolIcon name="history" /><p>{t("Pekerjaan dan hasil tersimpan di riwayat akun, termasuk ketika Anda meninggalkan halaman.")}</p></div></>}
            </section>
        </div>
        <section className="mt-history" aria-labelledby="media-tool-history-heading">
            <div className="mt-history-heading"><div><h2 id="media-tool-history-heading"><ToolIcon name="history" />{t(download ? "Riwayat unduhan" : "Riwayat konversi")}</h2><p>{t("Pilih pekerjaan untuk melihat status dan hasil aslinya.")}{activeCount > 0 && <> {new Intl.NumberFormat(locale).format(activeCount)} {t("pekerjaan sedang berjalan.")}</>}</p></div>
                <div className="mt-history-actions">
                    <button type="button" className="mt-button mt-button--secondary" disabled={queue.historyLoading} onClick={queue.refresh}><ToolIcon name="refresh" />{t(queue.historyLoading ? "Memperbarui…" : "Perbarui riwayat")}</button>
                    {deletableCount > 0 && <button type="button" className="mt-button mt-button--danger" disabled={Boolean(queue.deleting)} onClick={() => { queue.clearDeleteError(); setPendingDelete("all"); }}><ToolIcon name="trash" />{t("Bersihkan riwayat")}</button>}
                </div>
            </div>
            {queue.historyError && <Notice tone="error" action={<button type="button" className="mt-text-button" disabled={queue.historyLoading} onClick={queue.refresh}>{t("Coba lagi")}</button>}>{t(errorMessage(queue.historyError))}<p>{t("Status yang ditampilkan mungkin belum terbaru. Pembaruan tidak mengirim pekerjaan baru.")}</p></Notice>}
            {queue.deleteError && !pendingDelete && <Notice tone="error">{t(errorMessage(queue.deleteError.error))}</Notice>}
            {queue.jobs.length > 0 ? <ul className="mt-history-list">{queue.jobs.map((job) => <li key={job.job_id}><div className="mt-history-row"><button type="button" className="mt-history-choice" aria-pressed={selected?.job_id === job.job_id} onClick={() => chooseJob(job.job_id, true)}>
                <span className={`mt-history-file mt-tone--${FORMAT_INFO[job.format]?.type || "file"}`}><ToolIcon name={FORMAT_INFO[job.format]?.type || "file"} /><span>{(job.format || "").toUpperCase()}</span></span>
                <span className="mt-history-content"><strong>{job.title || job.input_name || t(download ? "Unduhan video" : "Konversi media")}</strong><span>{sourceName(job.source_url) || job.input_name || t("Pekerjaan media")}<span aria-hidden="true"> · </span><time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time></span></span>
                <span className="mt-history-state"><JobStatus job={job} t={t} />{isActiveJob(job) && progressOf(job) != null && <span>{new Intl.NumberFormat(locale).format(progressOf(job))}%</span>}{job.status === "completed" && typeof job.size_bytes === "number" && <span>{bytes(job.size_bytes, locale)}</span>}</span><ToolIcon name="arrow" />
            </button>{!isActiveJob(job) && <button type="button" className="mt-history-delete" aria-label={`${t("Hapus")} ${job.title || job.input_name || job.job_id}`} title={t("Hapus dari riwayat")} disabled={Boolean(queue.deleting)} onClick={() => { queue.clearDeleteError(); setPendingDelete(job); }}><ToolIcon name="trash" /></button>}</div></li>)}</ul> : <div className="mt-history-empty" role="status"><ToolIcon name="history" /><div><strong>{t(queue.historyLoading ? "Memuat riwayat…" : queue.historyError ? "Riwayat belum dapat dimuat" : download ? "Belum ada unduhan" : "Belum ada konversi")}</strong><p>{t("Pekerjaan pertama Anda akan muncul di sini setelah diterima server.")}</p></div></div>}
        </section>
        {pendingDelete && <MediaActionDialog
            title={t(pendingDelete === "all" ? "Bersihkan riwayat?" : "Hapus dari riwayat?")}
            description={t(pendingDelete === "all" ? "Semua pekerjaan yang sudah selesai, gagal, atau dibatalkan beserta file hasilnya akan dihapus permanen. Pekerjaan yang sedang berjalan tetap dipertahankan." : "Pekerjaan ini beserta file hasilnya akan dihapus permanen dari akun Anda.")}
            closeLabel={t("Batal")} confirmLabel={t(pendingDelete === "all" ? "Hapus semua" : "Hapus")} busyLabel={t("Menghapus…")}
            busy={Boolean(queue.deleting)} error={queue.deleteError ? t(errorMessage(queue.deleteError.error)) : null}
            onConfirm={confirmDelete} onClose={() => { if (!queue.deleting) { setPendingDelete(null); queue.clearDeleteError(); } }} />}
    </div>;
}

export default function MediaToolWorkspace({ kind }) {
    const { user, loading } = useAuth();
    const { t } = useLocale();
    if (!user?.id) return <div className="media-tools-workspace"><div className="mt-history-empty" role="status"><ToolIcon name="lock" /><p>{t(loading ? "Memuat akun…" : "Masuk untuk menggunakan alat media.")}</p></div></div>;
    return <WorkspaceSession key={`${user.id}:${kind}`} kind={kind} user={user} />;
}
