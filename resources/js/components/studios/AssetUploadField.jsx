import { useEffect, useRef, useState } from "react";
import { StudioButton, StudioField } from "./StudioUI";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";

const formats = {
    image: { mime: ["image/jpeg", "image/png", "image/webp"], max: 15, hint: "JPG, PNG, atau WebP hingga 15 MB." },
    audio: { mime: ["audio/mpeg", "audio/wav", "audio/x-wav", "audio/mp4", "audio/webm"], max: 30, hint: "MP3, WAV, M4A, atau WebM hingga 30 MB." },
    video: { mime: ["video/mp4", "video/webm", "video/quicktime"], max: 100, hint: "MP4, WebM, atau MOV hingga 100 MB." },
};

// Only owned asset IDs leave this control. Both the upload and the final admission validate
// bytes/type/ownership; a previous input is cleared before an asynchronous replacement starts.
export default function AssetUploadField({ input, meta = {}, value, error, disabled = false, idPrefix = "cap", onChange }) {
    const { t, locale } = useLocale();
    const id = `${idPrefix}-${input.key}`;
    const type = input.role === "speech_audio" ? "audio" : input.role === "reference_video" ? "video" : "image";
    const format = formats[type];
    const [busy, setBusy] = useState(false);
    const [localError, setLocalError] = useState(null);
    const [library, setLibrary] = useState(null);
    const [loading, setLoading] = useState(false);
    const [source, setSource] = useState("");
    const sequence = useRef(0);
    const changed = useRef(onChange);
    changed.current = onChange;
    useEffect(() => () => { sequence.current += 1; }, []);

    const replace = async (request) => {
        const current = ++sequence.current;
        changed.current("");
        setLocalError(null);
        setBusy(true);
        try {
            const data = await request();
            if (sequence.current !== current) return;
            if (!data?.asset?.id) throw new Error(t("Unggahan gagal. Coba lagi."));
            changed.current(data.asset.id);
            setLibrary(null);
            setSource("");
        } catch (failure) {
            if (sequence.current === current) setLocalError(failure.message || t("Unggahan gagal. Coba lagi."));
        } finally {
            if (sequence.current === current) setBusy(false);
        }
    };
    const pick = (event) => {
        const file = event.target.files?.[0];
        event.target.value = "";
        if (!file) return;
        if (!format.mime.includes(file.type) || file.size > format.max * 1048576) {
            setLocalError(t(format.hint));
            return;
        }
        const form = new FormData();
        form.append("file", file);
        form.append("role", input.role);
        replace(() => apiRequest("/api/media/assets", { method: "POST", body: form }));
    };
    const loadLibrary = async (page = 1) => {
        setLoading(true);
        setLocalError(null);
        try {
            const [uploads, audio] = await Promise.all([
                apiRequest(`/api/media/assets?media_type=${type}&page=${page}`),
                type === "audio" ? apiRequest("/api/audio") : Promise.resolve(null),
            ]);
            setLibrary({ ...uploads, generated: (audio?.jobs || []).filter((job) => job.status === "completed" && job.mode === "speech") });
            setSource("");
        } catch (failure) { setLocalError(failure.message || t("Riwayat gagal dimuat.")); }
        finally { setLoading(false); }
    };
    const selectOwned = () => {
        if (source.startsWith("asset:")) {
            changed.current(source.slice(6));
            setLocalError(null);
            setLibrary(null);
        } else if (source.startsWith("audio:")) {
            const [, jobId, index] = source.split(":");
            replace(() => apiRequest(`/api/audio/${encodeURIComponent(jobId)}/reference`, { method: "POST", body: { index: Number(index) } }));
        }
    };
    const clear = () => { sequence.current += 1; changed.current(""); setBusy(false); setLocalError(null); };
    const unavailable = () => { setLocalError(t("Referensi tidak tersedia. Pilih atau unggah ulang.")); changed.current(""); };
    const preview = value ? `/api/media/assets/${encodeURIComponent(value)}` : null;
    const label = meta.label || (input.role === "avatar_photo" ? "Foto wajah" : input.role === "speech_audio" ? "Audio ucapan" : input.key);

    return <StudioField id={id} label={label} error={localError || error} hint={meta.help ? t(meta.help) : t(format.hint)}>
        {preview ? <div className={`studio-reference-preview studio-asset-${type}`}>
            {type === "image" ? <img src={preview} alt={t("Pratinjau referensi")} onError={unavailable} />
                : type === "audio" ? <audio src={preview} controls preload="metadata" aria-label={t(label)} onError={unavailable} />
                    : <video src={preview} controls playsInline preload="metadata" aria-label={t(label)} onError={unavailable} />}
            <span className="studio-help">{t("Referensi siap")}</span>
            <StudioButton icon="close" disabled={disabled || busy} onClick={clear}>{t("Ganti")}</StudioButton>
        </div> : <>
            <input id={id} type="file" accept={format.mime.join(",")} disabled={disabled || busy} aria-invalid={Boolean(error || localError)} aria-describedby={`${id}-hint`} onChange={pick} />
            {busy && <p className="studio-help" role="status">{t("Menyiapkan referensi…")}</p>}
            <StudioButton icon="history" disabled={disabled || busy || loading} onClick={() => library ? setLibrary(null) : loadLibrary()}>{t(loading ? "Memuat…" : library ? "Tutup pilihan" : "Pilih dari koleksi")}</StudioButton>
        </>}
        {library && !preview && <div className="studio-asset-library">
            <label htmlFor={`${id}-owned`}>{t("Referensi milik Anda")}</label>
            <select id={`${id}-owned`} value={source} onChange={(event) => setSource(event.target.value)} disabled={disabled || busy || loading}>
                <option value="">{t("Pilih referensi")}</option>
                <optgroup label={t("Unggahan")}>
                    {(library.assets || []).map((asset) => <option key={asset.id} value={`asset:${asset.id}`}>{new Intl.DateTimeFormat(locale, { dateStyle: "short", timeStyle: "short" }).format(new Date(asset.created_at))} · {asset.mime} · {(asset.size_bytes / 1048576).toFixed(1)} MB</option>)}
                </optgroup>
                {type === "audio" && <optgroup label={t("Hasil Studio audio")}>
                    {library.generated.flatMap((job) => (job.outputs || []).map((output, index) => <option key={`${job.job_id}:${index}`} value={`audio:${job.job_id}:${index}`}>{job.model} · {(job.prompt || "").slice(0, 60)} · {t("Track")} {index + 1}</option>))}
                </optgroup>}
            </select>
            <div className="studio-toolbar">
                <StudioButton disabled={!source || disabled || busy || loading} onClick={selectOwned}>{t("Gunakan referensi")}</StudioButton>
                {library.page > 1 && <StudioButton disabled={loading} onClick={() => loadLibrary(library.page - 1)}>{t("Sebelumnya")}</StudioButton>}
                {library.page < library.last_page && <StudioButton disabled={loading} onClick={() => loadLibrary(library.page + 1)}>{t("Berikutnya")}</StudioButton>}
            </div>
            {!library.assets?.length && !library.generated.length && <p className="studio-help">{t("Belum ada referensi tersimpan. Unggah berkas terlebih dahulu.")}</p>}
        </div>}
    </StudioField>;
}
