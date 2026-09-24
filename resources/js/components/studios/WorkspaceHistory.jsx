import { useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import MediaActionDialog from "../MediaActionDialog";
import { formatLocalDate } from "../member/MemberUI";
import { ownedMediaUrl } from "./mediaOutput";
import { jobTitle } from "./nativeStudio";
import { operationLabel } from "./workspaceMedia";
import { StudioButton, StudioEmpty, StudioIcon, StudioNotice, StudioStatus, mediaError } from "./StudioUI";

const titles = { image: "Riwayat gambar", video: "Riwayat video", audio: "Riwayat audio", avatar: "Riwayat avatar", model3d: "Riwayat 3D" };
// The image and video studios offered clearing their finished history; the shared list keeps that.
const clearable = ["image", "video"];
const finished = (job) => ["completed", "failed", "cancelled"].includes(job.status);
const icon = (job) => job.output_kind === "audio" ? (job.operation === "text_to_speech" ? "voice" : "music") : job.operation === "talking_avatar" ? "avatar" : job.output_kind;

export default function WorkspaceHistory({ studio, kind }) {
    const { t, locale } = useLocale();
    const [pending, setPending] = useState(null);
    const [retained, setRetained] = useState(0);
    const title = titles[kind] || "Riwayat media";
    const finishedCount = studio.jobs.filter(finished).length;
    const busy = studio.clearing || Boolean(studio.actionBusy);
    const confirm = async () => {
        if (pending === "all") {
            const result = await studio.clearHistory();
            if (result) { setRetained(Number(result.retained_count) || 0); setPending(null); }
            return;
        }
        try { await studio.jobAction(pending.id, "delete"); setPending(null); } catch { /* The error stays visible in the dialog. */ }
    };
    const dialogError = pending === "all" ? studio.clearError : studio.actionError;
    return <section className="studio-history" aria-label={t(title)}>
        <div className="studio-section-heading"><h2><StudioIcon name="history" />{t(title)}</h2><div className="studio-history-tools">
            <StudioButton icon="refresh" disabled={studio.historyLoading} onClick={() => { void studio.loadHistory(); }}>{t("Perbarui riwayat")}</StudioButton>
            {clearable.includes(kind) && finishedCount > 0 && <StudioButton icon="close" className="studio-danger" disabled={busy}
                onClick={() => { studio.setClearError(null); setRetained(0); setPending("all"); }}>{t("Bersihkan riwayat")}</StudioButton>}
        </div></div>
        {studio.historyError && <StudioNotice error>{t(mediaError(studio.historyError))}</StudioNotice>}
        {retained > 0 && !pending && <StudioNotice>{t("Sebagian hasil dipertahankan karena masih dipakai artefak chat atau referensi tersimpan.")} ({retained})</StudioNotice>}
        {studio.jobs.length > 0 && <div className="studio-history-list">{studio.jobs.map((job) => {
            const thumbnail = job.outputs?.find((output) => output.kind === "image" && output.previewable && ownedMediaUrl(output.url));
            const name = jobTitle(job);
            return <div className="studio-history-row" key={job.id}>
                <button type="button" className="studio-history-item" aria-pressed={studio.activeId === job.id} onClick={() => studio.selectJob(job.id)}>
                    <span className={`studio-history-thumb studio-color-${job.output_kind}`}>{thumbnail ? <img src={ownedMediaUrl(thumbnail.url)} loading="lazy" alt="" /> : <StudioIcon name={icon(job)} />}</span>
                    <span className="studio-history-copy"><strong>{name}</strong><small>{[job.model !== name ? job.model : null, t(operationLabel(job.operation)),
                        job.created_at ? formatLocalDate(job.created_at, { locale }) : null, job.details?.pro_mode ? "Pro" : null,
                        // A variation row says which image of its request it is; opening it shows the whole set.
                        job.batch ? `${t("Variasi")} ${job.batch.index + 1}/${job.batch.jobs.length}` : null,
                        job.outputs?.length ? `${job.outputs.length} ${t("file")}` : null].filter(Boolean).join(" · ")}</small></span>
                    <StudioStatus job={job} />
                </button>
                {job.can_delete && <button type="button" className="studio-history-delete" aria-label={`${t("Hapus dari riwayat")}: ${name}`} title={t("Hapus dari riwayat")}
                    disabled={busy} onClick={() => { setRetained(0); setPending(job); }}><StudioIcon name="close" /></button>}
            </div>;
        })}</div>}
        {studio.historyLoading && <p role="status" className="studio-loading">{t("Memuat riwayat…")}</p>}
        {!studio.historyLoading && !studio.historyError && !studio.jobs.length && <StudioEmpty icon="history" title="Belum ada riwayat" description="Permintaan dan hasil pada akun Anda akan muncul di sini." />}
        {studio.historyCursor && <StudioButton disabled={studio.historyLoading} onClick={() => { void studio.loadHistory(studio.historyCursor); }}>{t("Muat riwayat berikutnya")}</StudioButton>}
        {pending && <MediaActionDialog title={t(pending === "all" ? "Bersihkan riwayat?" : "Hapus dari riwayat?")}
            description={t(pending === "all" ? "Semua pekerjaan yang sudah selesai atau gagal beserta file hasilnya akan dihapus permanen. Pekerjaan yang sedang berjalan tetap dipertahankan." : "Pekerjaan ini beserta file hasilnya akan dihapus permanen dari akun Anda.")}
            closeLabel={t("Batal")} confirmLabel={t(pending === "all" ? "Hapus semua" : "Hapus")} busyLabel={t("Menghapus…")} busy={busy}
            error={dialogError ? t(mediaError(dialogError)) : null} onConfirm={() => { void confirm(); }} onClose={() => { if (!busy) setPending(null); }} />}
    </section>;
}
