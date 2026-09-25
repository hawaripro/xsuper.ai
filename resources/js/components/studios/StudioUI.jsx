import { useEffect, useRef, useState } from "react";
import { errorMessage } from "../member/MemberUI";
import { useLocale } from "../../contexts/LocaleContext";
import { modelBrand, modelKind } from "./modelBrands";
import { safeLogoUrl } from "./studioPalette";
import "./studios.css";

const stageLabels = { pending: "Menunggu antrean", queued: "Menunggu antrean", preparing: "Menyiapkan input", submitting: "Mengirim permintaan", processing: "Sedang diproses", generating: "Sedang diproses", rendering: "Sedang diproses", saving: "Menyimpan hasil", save_failed: "Hasil belum tersimpan", uncertain: "Penerimaan belum terkonfirmasi", submission_uncertain: "Penerimaan belum terkonfirmasi", result_uncertain: "Hasil menunggu tinjauan", cancel_requested: "Pembatalan diminta", completed: "Selesai", rejected: "Prompt ditolak", failed: "Proses gagal", cancelled: "Dibatalkan" };
const mediaErrors = {
    "The selected image model is unavailable.": "Model gambar yang dipilih tidak tersedia.",
    "The selected image options are not supported by this model.": "Pilihan gambar ini tidak didukung oleh model.",
    "Image token pricing is unavailable.": "Harga token gambar belum tersedia.",
    "The AI image provider returned an invalid response.": "Penyedia gambar AI mengembalikan respons yang tidak valid.",
    "Image generation cannot be cancelled after submission. Closing the page does not stop generation or refund tokens.": "Pembuatan gambar tidak dapat dibatalkan setelah dikirim. Menutup halaman tidak menghentikan proses atau mengembalikan token.",
    "The selected video model is unavailable.": "Model video yang dipilih tidak tersedia.",
    "This provider does not support video generation.": "Penyedia ini tidak mendukung pembuatan video.",
    "The quantity exceeds this model limit.": "Jumlah melebihi batas model ini.",
    "The aspect ratio is not supported by this model.": "Rasio aspek tidak didukung oleh model ini.",
    "The duration is not supported by this model.": "Durasi tidak didukung oleh model ini.",
    "Video token pricing is unavailable.": "Harga token video belum tersedia.",
    "This prompt involves sexual content with minors and cannot be processed.": "Prompt ini melibatkan konten seksual dengan anak di bawah umur dan tidak dapat diproses.",
    "Explicit sexual content is not supported for video generation.": "Konten seksual eksplisit tidak didukung untuk pembuatan video.",
    "This prompt requests graphic violence and cannot be processed.": "Prompt ini meminta kekerasan grafis dan tidak dapat diproses.",
    "This prompt encourages self-harm and cannot be processed.": "Prompt ini mendorong tindakan menyakiti diri dan tidak dapat diproses.",
    "This prompt targets people with hateful abuse or threats.": "Prompt ini menargetkan orang dengan ujaran kebencian atau ancaman.",
    "This prompt requests dangerous or criminal instructions.": "Prompt ini meminta petunjuk berbahaya atau kriminal.",
    "The safety review could not be completed. No video request was sent.": "Peninjauan keamanan tidak dapat diselesaikan. Permintaan video tidak dikirim.",
    "The video provider rejected this request.": "Penyedia video menolak permintaan ini.",
    "Video generation could not be completed. No automatic resubmission was made.": "Pembuatan video tidak dapat diselesaikan. Tidak ada pengiriman ulang otomatis.",
    "Video generation timed out. Reserved tokens have been returned.": "Pembuatan video kehabisan waktu. Token yang dicadangkan telah dikembalikan.",
    "The video provider could not complete this request.": "Penyedia video tidak dapat menyelesaikan permintaan ini.",
    "The video status could not be verified. Reserved tokens have been returned.": "Status video tidak dapat diverifikasi. Token yang dicadangkan telah dikembalikan.",
    "The video status response was invalid.": "Respons status video tidak valid.",
    "The video request was interrupted. It was not automatically resubmitted.": "Permintaan video terputus. Permintaan tidak dikirim ulang secara otomatis.",
    "Video generation was cancelled. Reserved credit has been returned.": "Pembuatan video dibatalkan. Kredit yang dicadangkan telah dikembalikan.",
    "Video submission has started. It cannot be cancelled and this action does not refund tokens.": "Pengiriman video sudah dimulai. Video tidak dapat dibatalkan dan tindakan ini tidak mengembalikan token.",
    "This video has already completed and cannot be cancelled.": "Video ini sudah selesai dan tidak dapat dibatalkan.",
    "This video has already failed and cannot be cancelled.": "Video ini sudah gagal dan tidak dapat dibatalkan. Periksa status tagihannya di riwayat.",
    "This video request is already cancelled.": "Permintaan video ini sudah dibatalkan.",
    "Cancellation is unavailable for this video. Refresh its status before taking another action.": "Pembatalan tidak tersedia untuk video ini. Perbarui status sebelum melakukan tindakan lain.",
};
export const mediaError = (error) => { const value = typeof error === "string" ? error : errorMessage(error); return mediaErrors[value] || value; };

export function StudioIcon({ name, className = "" }) {
    const paths = {
        image: <><rect x="3" y="3" width="18" height="18" rx="3" /><circle cx="8" cy="8" r="1.5" /><path d="m3 17 5-5 4 4 4-6 5 7" /></>,
        video: <><rect x="3" y="5" width="13" height="14" rx="2" /><path d="m16 10 5-3v10l-5-3" /></>,
        audio: <><path d="M4 10v4m4-9v14m4-17v20m4-17v14m4-9v4" /></>,
        model3d: <><path d="m12 2 9 5v10l-9 5-9-5V7Zm-9 5 9 5 9-5M12 12v10" /></>,
        voice: <><rect x="9" y="2" width="6" height="13" rx="3" /><path d="M5 10v2a7 7 0 0 0 14 0v-2m-7 9v3m-4 0h8" /></>,
        music: <><path d="M9 18V5l11-2v13M9 9l11-2" /><ellipse cx="6" cy="18" rx="3" ry="3" /><ellipse cx="17" cy="16" rx="3" ry="3" /></>,
        prompt: <><path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14Z" /></>,
        product: <><path d="m12 2 9 5v10l-9 5-9-5V7Zm-9 5 9 5 9-5M12 12v10M7 4.8l9 5" /></>,
        ugc: <><circle cx="12" cy="8" r="4" /><path d="M4 21v-2a8 8 0 0 1 16 0v2M2 3h3M19 3h3" /></>,
        download: <><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" /></>,
        refresh: <><path d="M20 7v5h-5M4 17v-5h5M6 6a8 8 0 0 1 13 3M5 15a8 8 0 0 0 13 3" /></>,
        history: <><path d="M3 4v5h5M3.5 9a9 9 0 1 1-.5 7M12 7v5l3 2" /></>,
        settings: <><path d="M4 6h16M4 12h16M4 18h16" /><circle cx="8" cy="6" r="2" /><circle cx="16" cy="12" r="2" /><circle cx="10" cy="18" r="2" /></>,
        arrow: <path d="M4 12h16m-6-6 6 6-6 6" />,
        close: <path d="m6 6 12 12M6 18 18 6" />,
        upload: <><path d="M12 16V3m-5 5 5-5 5 5M4 16v5h16v-5" /></>,
        compare: <><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M12 2v20m-5-9 2-2-2-2m10 4-2-2 2-2" /></>,
        play: <path d="m8 4 12 8-12 8Z" />,
        pause: <><path d="M8 4v16M16 4v16" /></>,
        volume: <><path d="m3 9 5 0 5-5v16l-5-5H3ZM17 8a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14" /></>,
        tokens: <><ellipse cx="12" cy="6" rx="8" ry="3" /><path d="M4 6v12c0 4 16 4 16 0V6M4 12c0 4 16 4 16 0" /></>,
        pro: <><path d="m12 2 3 6 7 1-5 5 1 8-6-4-6 4 1-8-5-5 7-1Z" /></>,
        check: <path d="m5 12 4 4L19 6" />,
        star: <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z" />,
        copy: <><rect x="8" y="8" width="13" height="13" rx="2" /><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3" /></>,
        info: <><circle cx="12" cy="12" r="9" /><path d="M12 11v6m0-9.5v.5" /></>,
        grid: <><rect x="3" y="3" width="7.5" height="7.5" rx="1.5" /><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5" /><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5" /><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5" /></>,
        list: <><rect x="3" y="4" width="5" height="5" rx="1" /><rect x="3" y="15" width="5" height="5" rx="1" /><path d="M11 6.5h10M11 17.5h10" /></>,
        thumbs: <><rect x="3" y="3" width="5" height="5" rx="1" /><rect x="9.5" y="3" width="5" height="5" rx="1" /><rect x="16" y="3" width="5" height="5" rx="1" /><rect x="3" y="9.5" width="5" height="5" rx="1" /><rect x="9.5" y="9.5" width="5" height="5" rx="1" /><rect x="16" y="9.5" width="5" height="5" rx="1" /><rect x="3" y="16" width="5" height="5" rx="1" /><rect x="9.5" y="16" width="5" height="5" rx="1" /></>,
        dice: <><rect x="3" y="3" width="18" height="18" rx="4" /><circle cx="8.5" cy="8.5" r="1" /><circle cx="15.5" cy="15.5" r="1" /><circle cx="15.5" cy="8.5" r="1" /><circle cx="8.5" cy="15.5" r="1" /></>,
        code: <path d="m8 7-5 5 5 5m8-10 5 5-5 5M14 4l-4 16" />,
        external: <path d="M14 4h6v6m0-6-9 9M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5" />,
        sun: <><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></>,
        moon: <path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z" />,
        user: <><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></>,
        chevron: <path d="m6 9 6 6 6-6" />,
        back: <path d="M20 12H4m6-6-6 6 6 6" />,
        trash: <path d="M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3" />,
        search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></>,
        spark: <><path d="M12 3.5 13.9 8.6 19 10.5l-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9Z" /><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8Z" /></>,
        layers: <><path d="m12 3 9 5-9 5-9-5 9-5Z" /><path d="m3 13 9 5 9-5" /></>,
        bolt: <path d="M13 2 4 14h7l-1 8 9-12h-7Z" />,
        alert: <><path d="M12 3.5 2.5 20h19Z" /><path d="M12 10v4m0 3v.5" /></>,
        sliders: <><path d="M4 7h10m4 0h2M4 17h4m4 0h8" /><circle cx="16" cy="7" r="2" /><circle cx="10" cy="17" r="2" /></>,
    };
    return <svg className={`studio-icon ${className}`} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{paths[name === "avatar" ? "video" : name] || paths.settings}</svg>;
}

export function StudioButton({ children, icon, primary = false, className = "", type = "button", ...props }) {
    return <button type={type} className={`studio-button ${primary ? "studio-button-primary" : ""} ${className}`} {...props}>{icon && <StudioIcon name={icon} />}{children}</button>;
}

// Geometry drawn for each studio kind: what a result looks like before or without a preview.
const PLATES = {
    image: <><rect x="20" y="24" width="56" height="48" rx="4" /><circle cx="35" cy="39" r="4.5" /><path d="m20 62 15-14 12 12 10-13 19 19" /></>,
    video: <><rect x="16" y="26" width="64" height="44" rx="3" /><path d="M16 36h64M16 60h64M26 26v10M40 26v10M56 26v10M70 26v10M26 60v10M40 60v10M56 60v10M70 60v10" /><path d="m43 41 12 7-12 7Z" /></>,
    audio: <path d="M14 48v0M22 42v12M30 36v24M38 40v16M46 28v40M54 34v28M62 42v12M70 31v34M78 44v8" strokeWidth="4" />,
    avatar: <><circle cx="42" cy="38" r="11" /><path d="M23 74a19 19 0 0 1 38 0" /><path d="M68 34a10 10 0 0 1 0 14M73 28a17 17 0 0 1 0 26" /></>,
    model3d: <><path d="M48 20 74 34v28L48 76 22 62V34Z" /><path d="M22 34l26 14 26-14M48 48v28" /><path d="m48 48-26 14M48 48l26 14" strokeDasharray="3 4" opacity=".55" /></>,
    other: <><path d="M36 26c-6 0-8 2-8 8v8c0 3-2 6-6 6 4 0 6 3 6 6v8c0 6 2 8 8 8M60 26c6 0 8 2 8 8v8c0 3 2 6 6 6-4 0-6 3-6 6v8c0 6-2 8-8 8" /><path d="M42 40h12M42 48h12M42 56h8" /></>,
};
export function KindPlate({ kind = "other", operation = "", className = "" }) {
    const plate = operation === "text_to_speech" ? "audio" : PLATES[kind] ? kind : "other";
    return <svg className={`sw-plate ${className}`} aria-hidden="true" viewBox="0 0 96 96" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" strokeLinejoin="round">{PLATES[plate]}</svg>;
}

const DIGITS = "0123456789";
// A number whose digits roll to their new value. The digit strips are drawn by CSS, so the element's
// text is only the number itself (for assistive tech, copy and tests); static under reduced motion.
export function RollingNumber({ value, className = "" }) {
    const text = value == null ? "—" : String(value);
    const previous = useRef(text);
    const [settled, setSettled] = useState(true);
    useEffect(() => {
        if (previous.current === text) return undefined;
        previous.current = text;
        setSettled(false);
        const timer = setTimeout(() => setSettled(true), 520);
        return () => clearTimeout(timer);
    }, [text]);
    return <span className={`sw-roll${settled ? "" : " is-rolling"} ${className}`}>
        <span className="studio-visually-hidden">{text}</span>
        <span className="sw-roll-face" aria-hidden="true">{Array.from(text).map((char, index) => DIGITS.includes(char)
            ? <span key={index} className="sw-roll-digit"><span className="sw-roll-strip" style={{ "--n": Number(char) }} /></span>
            : <span key={index} className="sw-roll-char" data-char={char} />)}</span>
    </span>;
}
const MARK_ICONS = { image: "image", video: "video", audio: "audio", avatar: "ugc", model3d: "model3d", other: "spark" };
// Runware fills catalog logos with its creator images; the maker's vector mark reads better at these sizes.
const importedLogo = (url) => url.startsWith("https://assets.runware.ai/");
// A model's mark: a logo an administrator set, else the maker's official mark, else its studio kind's icon.
// Monochrome marks are masks so they take the theme's ink (white in dark mode); colored marks stay as drawn.
export function ModelMark({ model, kind = "", className = "" }) {
    const [failed, setFailed] = useState("");
    const brand = modelBrand(model);
    const custom = safeLogoUrl(model?.logo_url);
    const logo = custom && failed !== custom && !(brand && importedLogo(custom)) ? custom : null;
    const markKind = modelKind(model, kind);
    const variant = logo ? "image" : brand ? (brand.mono ? "mono" : "color") : "kind";
    return <span className={`sw-model-mark ${className}`} data-kind={markKind} data-mark={variant} aria-hidden="true">
        {logo ? <img src={logo} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setFailed(logo)} />
            : brand ? (brand.mono ? <span className="sw-mark-glyph" style={{ "--mark": `url("${brand.logo}")` }} /> : <img src={brand.logo} alt="" loading="lazy" />)
                : <StudioIcon name={MARK_ICONS[markKind] || "spark"} />}
    </span>;
}
export function StudioField({ id, label, hint, error, children, className = "" }) {
    const { t } = useLocale();
    const message = Array.isArray(error) ? error[0] : error;
    return <div className={`studio-field ${className}`}><label htmlFor={id}>{t(label)}</label>{children}{hint && <p id={`${id}-hint`} className="studio-help">{hint}</p>}{message && <p id={`${id}-error`} className="studio-field-error" role="alert">{t(mediaError(message))}</p>}</div>;
}
export function StudioNotice({ children, error = false, action }) {
    return <div className={`studio-notice ${error ? "studio-notice-error" : ""}`} role={error ? "alert" : "status"}><div>{children}</div>{action}</div>;
}
export function StudioEmpty({ icon, title, description }) {
    const { t } = useLocale();
    return <div className="studio-empty"><StudioIcon name={icon} /><h3>{t(title)}</h3><p>{t(description)}</p></div>;
}
export function StudioStatus({ job }) {
    const { t } = useLocale();
    const stage = job?.stage || job?.status;
    return <span className={`studio-status studio-status-${["cancelled", "rejected"].includes(stage) ? stage : job?.status || "pending"}`}>{t(stageLabels[stage] || stage || "Status belum tersedia")}</span>;
}
// Determinate only when the provider reports progress; otherwise the stage is the whole truth.
export const jobProgress = (job) => typeof job?.progress === "number" && Number.isFinite(job.progress) ? Math.max(0, Math.min(100, Math.round(job.progress))) : null;
export function StudioProgressBar({ value }) {
    const { t } = useLocale();
    return <div className="studio-progress-bar" role="progressbar" aria-label={t("Kemajuan proses")} aria-valuemin={0} aria-valuemax={100} aria-valuenow={value}>
        <span style={{ "--p": value / 100 }} /><small>{value}%</small></div>;
}
export const stageLabel = (job) => stageLabels[job?.stage || job?.status] || "Sedang diproses";
export function StudioProgress({ job, submitting = false, synchronous = false }) {
    const { t } = useLocale();
    const progress = submitting ? null : jobProgress(job);
    return <div className="studio-progress" role="status"><span className="studio-spinner" aria-hidden="true" /><strong>{t(submitting ? "Mengirim permintaan…" : stageLabel(job))}</strong><p>{t(synchronous ? "Menunggu hasil dari model. Jangan kirim ulang permintaan yang sama." : "Diproses di server, meskipun halaman ditutup.")}</p>
        {progress != null ? <StudioProgressBar value={progress} /> : <small>{t("Status diperbarui dari server. Tidak ada perkiraan persentase.")}</small>}</div>;
}
