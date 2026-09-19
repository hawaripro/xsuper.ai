import { useRef, useState } from "react";
import { Link } from "react-router-dom";
import MediaActionDialog from "../MediaActionDialog";
import { errorMessage, formatLocalDate, formatUsdMicros } from "../member/MemberUI";
import { useLocale } from "../../contexts/LocaleContext";
import { isPending } from "./useMediaStudio";
import "./studios.css";

const stageLabels = { pending: "Menunggu antrean", queued: "Menunggu antrean", submitting: "Mengirim permintaan", processing: "Sedang diproses", generating: "Sedang diproses", rendering: "Sedang diproses", saving: "Menyimpan hasil", completed: "Selesai", rejected: "Prompt ditolak", failed: "Proses gagal", cancelled: "Dibatalkan" };
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
    };
    return <svg className={`studio-icon ${className}`} aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{paths[name] || paths.settings}</svg>;
}

export function StudioButton({ children, icon, primary = false, className = "", type = "button", ...props }) {
    return <button type={type} className={`studio-button ${primary ? "studio-button-primary" : ""} ${className}`} {...props}>{icon && <StudioIcon name={icon} />}{children}</button>;
}
export function StudioHeader({ kind, title, description, balance, onRefresh, busy }) {
    const { t, locale, localizedPath } = useLocale();
    return <header className="studio-header"><div className="studio-heading"><span className={`studio-title-icon studio-color-${kind}`}><StudioIcon name={kind} /></span><div><h1>{t(title)}</h1><p>{t(description)}</p></div></div><div className="studio-header-actions"><Link to={localizedPath("/token-usage")} className="studio-balance" aria-label={t("Lihat saldo dan riwayat token")}><StudioIcon name="tokens" /><span>{t("Saldo token")}<strong>{balance == null ? "—" : new Intl.NumberFormat(locale).format(balance)}</strong></span></Link><StudioButton icon="refresh" onClick={onRefresh} disabled={busy}>{t("Muat ulang")}</StudioButton></div></header>;
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
    return <span className={`studio-status studio-status-${["cancelled", "rejected"].includes(stage) ? stage : job?.status || "pending"}`}>{t(stageLabels[stage] || "Status belum tersedia")}</span>;
}
export function StudioProgress({ job, submitting = false, synchronous = false }) {
    const { t } = useLocale();
    return <div className="studio-progress" role="status"><span className="studio-spinner" aria-hidden="true" /><strong>{t(submitting ? "Mengirim permintaan…" : stageLabels[job?.stage || job?.status] || "Sedang diproses")}</strong><p>{t(synchronous ? "Menunggu hasil dari model. Jangan kirim ulang permintaan yang sama." : "Diproses di server, meskipun halaman ditutup.")}</p><small>{t("Status diperbarui dari server. Tidak ada perkiraan persentase.")}</small></div>;
}
export function StudioBilling({ job }) {
    const { t, locale } = useLocale();
    if (!job) return null;
    if (job.billing_mode === "admin") return <p className="studio-help">{t("Gratis admin (riwayat lama)")}</p>;
    const cost = job.billing_mode === "tokens" && job.tokens_reserved != null
        ? `${new Intl.NumberFormat(locale).format(Number(job.tokens_reserved))} ${t("token")}`
        : (job.cost_microusd ?? job.billing_reserved_microusd) != null ? formatUsdMicros(job.cost_microusd ?? job.billing_reserved_microusd) : null;
    return cost && <p className="studio-billing"><StudioIcon name="tokens" />{cost}<span>·</span>{t(({ reserved: "Dicadangkan", settled: "Dibebankan", released: "Dikembalikan" })[job.billing_status] || "Status tagihan belum tersedia")}</p>;
}
export function StudioQuote({ total, unit, count = 1, pro = false, balance }) {
    const { t, locale, localizedPath } = useLocale();
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    return <div className="studio-quote"><div><span>{t("Estimasi total")}</span><strong>{total == null ? "—" : format(total)} <small>{t("token")}</small></strong></div>{unit != null && <p>{format(unit)} × {count}{pro ? " × 2 (Pro)" : ""}</p>}{total != null && balance != null && balance < total && <StudioNotice error>{t("Saldo token tidak cukup untuk jumlah ini.")} <Link to={localizedPath("/deposit")}>{t("Isi saldo")}</Link></StudioNotice>}<p>{t("Token dicadangkan saat dikirim. Permintaan yang gagal dikembalikan sesuai status tagihan.")}</p></div>;
}
export function StudioCatalog({ studio, id, value, onChange, models = studio.models, error }) {
    const { t } = useLocale();
    const chosen = models.find((model) => model.id === value);
    return <><StudioField id={id} label="Model" error={error}><select id={id} value={chosen ? value : ""} onChange={(event) => onChange(event.target.value)} disabled={studio.submitting || studio.modelLoading || !models.length} aria-invalid={Boolean(error)}><option value="" disabled>{t(studio.modelLoading ? "Memuat…" : "Pilih model")}</option>{models.map((model) => <option key={model.id} value={model.id}>{model.name || model.id}</option>)}</select>{chosen && <p className="studio-help">{[chosen.id, chosen.tier].filter(Boolean).join(" · ")}</p>}</StudioField>{studio.modelError && <StudioNotice error action={<StudioButton onClick={studio.loadModels} disabled={studio.modelLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.modelError))}</StudioNotice>}</>;
}
export function StudioHistory({ studio, kind, title }) {
    const { t, locale } = useLocale();
    const [pendingDelete, setPendingDelete] = useState(null);
    const deletable = typeof studio.remove === "function";
    const finishedCount = studio.jobs.filter((job) => !isPending(job)).length;

    const confirmDelete = async () => {
        const ok = pendingDelete === "all" ? (await studio.clear()) !== null : await studio.remove(pendingDelete.job_id);
        if (ok !== false) setPendingDelete(null);
    };

    return <section className="studio-history" aria-label={t(title)}><div className="studio-section-heading"><h2><StudioIcon name="history" />{t(title)}</h2><div className="studio-history-tools"><StudioButton onClick={studio.loadHistory} disabled={studio.historyLoading} icon="refresh">{t("Perbarui riwayat")}</StudioButton>{deletable && studio.clear && finishedCount > 0 && <StudioButton className="studio-danger" onClick={() => setPendingDelete("all")} disabled={Boolean(studio.deleting)} icon="close">{t("Bersihkan riwayat")}</StudioButton>}</div></div>{studio.historyError && <StudioNotice error>{t("Riwayat belum dapat diperbarui. Periksa kembali sebelum mengirim permintaan baru.")}</StudioNotice>}{studio.deleteError && !pendingDelete && <StudioNotice error>{t(errorMessage(studio.deleteError.error, "Riwayat tidak dapat dihapus."))}</StudioNotice>}{studio.historyLoading && !studio.jobs.length ? <p className="studio-loading" role="status">{t("Memuat riwayat…")}</p> : !studio.jobs.length ? <StudioEmpty icon="history" title="Belum ada riwayat" description="Permintaan dan hasil pada akun Anda akan muncul di sini." /> : <div className="studio-history-list">{studio.jobs.map((job) => <div className="studio-history-row" key={job.job_id}><button className="studio-history-item" type="button" aria-pressed={studio.activeId === job.job_id} onClick={() => studio.selectJob(job)}><span className={`studio-history-thumb studio-color-${kind}`}>{kind === "image" && job.result_urls?.[0] ? <img src={job.result_urls[0]} alt="" loading="lazy" /> : kind === "video" && job.thumbnail_url ? <img src={job.thumbnail_url} alt="" loading="lazy" /> : <StudioIcon name={kind === "audio" ? job.mode === "speech" ? "voice" : "music" : kind} />}</span><span className="studio-history-copy"><strong>{job.prompt || t("Prompt tidak tersedia")}</strong><small>{job.model} · {formatLocalDate(job.created_at, { locale })}{job.pro_mode ? " · Pro" : ""}</small></span><StudioStatus job={job} /></button>{deletable && !isPending(job) && <button type="button" className="studio-history-delete" aria-label={`${t("Hapus dari riwayat")}: ${job.prompt || job.job_id}`} title={t("Hapus dari riwayat")} disabled={Boolean(studio.deleting)} onClick={() => setPendingDelete(job)}><StudioIcon name="close" /></button>}</div>)}</div>}{pendingDelete && <MediaActionDialog
        title={t(pendingDelete === "all" ? "Bersihkan riwayat?" : "Hapus dari riwayat?")}
        description={t(pendingDelete === "all" ? "Semua pekerjaan yang sudah selesai atau gagal beserta file hasilnya akan dihapus permanen. Pekerjaan yang sedang berjalan tetap dipertahankan." : "Pekerjaan ini beserta file hasilnya akan dihapus permanen dari akun Anda.")}
        closeLabel={t("Batal")} confirmLabel={t(pendingDelete === "all" ? "Hapus semua" : "Hapus")} busyLabel={t("Menghapus…")}
        busy={Boolean(studio.deleting)} error={studio.deleteError ? t(errorMessage(studio.deleteError.error, "Riwayat tidak dapat dihapus.")) : null}
        onConfirm={confirmDelete} onClose={() => { if (!studio.deleting) setPendingDelete(null); }} />}</section>;
}
export function StudioJobMeta({ job, onCheck, checking, onCancel }) {
    const { t, locale } = useLocale();
    if (!job) return null;
    return <div className="studio-job-meta"><div className="studio-toolbar"><StudioStatus job={job} /><time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time></div><p className="studio-result-prompt">{job.prompt}</p><p className="studio-help">{[job.model, job.size, job.aspect_ratio, job.duration ? `${job.duration} ${t("detik")}` : null, job.pro_mode ? "Pro" : null].filter(Boolean).join(" · ")}</p><StudioBilling job={job} />{job.error && <StudioNotice error={job.stage !== "cancelled"}>{t(mediaError(job.error))}</StudioNotice>}{job.status === "completed" && !(job.video_url || job.audio_url || job.result_urls?.length) && <StudioNotice>{t("Proses selesai, tetapi hasil belum tersedia. Periksa status kembali.")}</StudioNotice>}<div className="studio-toolbar"><StudioButton icon="refresh" onClick={onCheck} disabled={checking}>{t(checking ? "Memeriksa…" : "Periksa status")}</StudioButton>{onCancel && isPending(job) && <StudioButton onClick={() => onCancel(job)}>{t(job.can_cancel ? "Batalkan permintaan" : "Tidak bisa dibatalkan")}</StudioButton>}</div>{job.improved_prompt && job.improved_prompt !== job.prompt && <details><summary>{t("Lihat prompt hasil peninjauan lama")}</summary><p className="studio-result-prompt">{job.improved_prompt}</p></details>}</div>;
}
export function StudioCancellation({ job, onCancel, onClose }) {
    const { t } = useLocale();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [refusal, setRefusal] = useState(null);
    const lock = useRef(false);
    const unavailable = job.can_cancel !== true || Boolean(refusal);
    const cancel = async () => {
        if (lock.current || unavailable) return;
        lock.current = true;
        setBusy(true);
        setError(null);
        try {
            const data = await onCancel(job.job_id);
            if (data?.job?.stage === "cancelled" || data?.job?.status === "cancelled") onClose();
            else setRefusal(data?.job || {});
        } catch (requestError) {
            if (requestError.status === 409) setRefusal(requestError.details?.job || { cancel_reason: requestError.details?.message });
            else setError(t("Pembatalan belum terkonfirmasi. Perbarui riwayat sebelum mencoba lagi."));
        } finally { lock.current = false; setBusy(false); }
    };
    return <MediaActionDialog title={t(unavailable ? "Permintaan tidak bisa dibatalkan" : "Batalkan permintaan ini?")} description={unavailable ? t(mediaError(refusal?.cancel_reason || job.cancel_reason || "Pengiriman sudah dimulai. Menutup halaman tidak membatalkan proses atau mengembalikan token.")) : t("Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai. Token dikembalikan setelah pembatalan dikonfirmasi server.")} closeLabel={t(unavailable ? "Mengerti" : "Lanjutkan proses")} confirmLabel={t("Ya, batalkan")} busyLabel={t("Membatalkan…")} busy={busy} error={error} onClose={onClose} onConfirm={unavailable ? undefined : cancel}><StudioStatus job={job} /></MediaActionDialog>;
}
