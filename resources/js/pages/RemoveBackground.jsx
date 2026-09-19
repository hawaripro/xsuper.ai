import { useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { useTheme } from "../contexts/ThemeContext";
import { useLocale } from "../contexts/LocaleContext";
import useMediaToolQueue, { isActiveJob } from "../components/media-tools/useMediaToolQueue";
import MediaActionDialog from "../components/MediaActionDialog";
import { errorMessage, formatLocalDate } from "../components/member/MemberUI";
import "../components/media-tools/media-tools.css";

const STAGE_LABELS = {
    queued: "Menunggu antrean", pending: "Menunggu antrean", probing: "Memeriksa gambar",
    converting: "Menghapus latar", saving: "Menyimpan hasil",
};

function bytes(value, locale) {
    if (!Number.isFinite(value) || value < 1) return "—";
    const units = ["B", "KB", "MB"];
    let size = value;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(size)} ${units[unit]}`;
}

function Icon({ path, className = "mt-icon" }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{path}</svg>;
}

export default function RemoveBackground() {
    const { user } = useAuth();
    const { theme } = useTheme();
    const { t, locale, localizedPath } = useLocale();
    const [searchParams, setSearchParams] = useSearchParams();
    const queryJob = searchParams.get("job") || "";
    const queue = useMediaToolQueue({ kind: "rembg", userId: user.id, jobId: queryJob });

    const chooseJob = (id) => {
        const params = new URLSearchParams(searchParams);
        if (id) params.set("job", id); else params.delete("job");
        setSearchParams(params, { replace: true });
        if (id) queue.loadJob(id);
    };
    const [file, setFile] = useState(null);
    const [localError, setLocalError] = useState("");
    const [pendingDelete, setPendingDelete] = useState(null);
    const fileInput = useRef(null);

    useEffect(() => { queue.loadCapabilities(); queue.loadHistory(); }, []);

    const isAdmin = user.role === "admin";
    const available = queue.capabilities?.available?.rembg === true;
    const runtimeUnavailable = !queue.capabilityLoading && !queue.capabilityError && !available;
    const tokens = Number(queue.capabilities?.rembg_tokens ?? 15);
    const uploadLimit = Number(queue.capabilities?.limits?.max_upload_bytes ?? 128 * 1024 * 1024);
    const busy = queue.submitting;
    const account = user;
    const expired = !isAdmin && account?.is_expired;
    const canUse = isAdmin || account?.permissions?.media_converter !== false;

    const chooseFile = (event) => {
        const chosen = event.target.files?.[0] || null;
        setLocalError("");
        if (chosen && chosen.size > uploadLimit) {
            setLocalError(t("Ukuran gambar melebihi batas unggah."));
            setFile(null);
            return;
        }
        if (chosen && !/^image\//.test(chosen.type)) {
            setLocalError(t("Pilih berkas gambar (PNG, JPG, atau WebP)."));
            setFile(null);
            return;
        }
        setFile(chosen);
    };

    const submit = async (event) => {
        event.preventDefault();
        if (!file || busy) return;
        const body = new FormData();
        body.append("file", file);
        const job = await queue.submit(body, "png", file.name);
        if (job) {
            setFile(null);
            if (fileInput.current) fileInput.current.value = "";
            chooseJob(job.job_id);
        }
    };
    const activeCount = queue.jobs.filter(isActiveJob).length;
    const deletableCount = queue.jobs.filter((job) => !isActiveJob(job)).length;
    // Open fresh: only a deep-linked or in-flight job auto-opens; finished history stays below.
    const selected = queue.jobs.find((job) => job.job_id === queryJob) || queue.jobs.find(isActiveJob) || null;
    const stageLabel = (job) => t(STAGE_LABELS[job.stage] || STAGE_LABELS[job.status] || "Memproses");
    const costLabel = isAdmin ? t("Gratis untuk admin") : `${tokens} ${t("token / gambar")}`;

    const confirmDelete = async () => {
        if (pendingDelete === "all") { await queue.clear(); } else if (pendingDelete) { await queue.remove(pendingDelete); }
        if (!queue.deleteError) setPendingDelete(null);
    };

    return (
        <div className="media-tools-workspace media-tools-workspace--rembg" data-theme={theme}>
            <header className="mt-page-heading">
                <div className="mt-page-title">
                    <span className="mt-title-icon"><Icon path={<><path d="M3 3l18 18M9 4h9a2 2 0 0 1 2 2v9M4 9v9a2 2 0 0 0 2 2h9" /><circle cx="9" cy="9" r="2" /></>} /></span>
                    <div><h1>{t("Hapus Latar")}</h1><p>{t("Unggah gambar dan hapus latar belakangnya secara otomatis. Hasil berupa PNG transparan.")}</p></div>
                </div>
                <div className="mt-page-actions">
                    <Link className="mt-button mt-button--secondary" to={localizedPath("/generate-image")}><Icon path={<path d="m2 15 5-5 3 3 4-4 8 8M3 5h18v14H3z" />} />{t("Studio gambar")}</Link>
                    <button type="button" className="mt-button mt-button--secondary" disabled={queue.historyLoading} onClick={queue.refresh}><Icon path={<path d="M3 12a9 9 0 1 1 3 6.7L3 16" />} />{t("Muat ulang")}</button>
                </div>
            </header>

            {!canUse && <div className="mt-notice mt-notice--error" role="alert">{t("Akun Anda tidak memiliki izin untuk membuat pekerjaan baru di alat ini. Riwayat milik Anda tetap dapat diperiksa.")}</div>}
            {expired && <div className="mt-notice mt-notice--warning" role="status">{t("Masa aktif akun berakhir. Perpanjang akses untuk membuat pekerjaan baru; hasil lama tetap dapat diperiksa.")}</div>}
            {queue.capabilityError && <div className="mt-notice mt-notice--error" role="alert">{t(errorMessage(queue.capabilityError))} <button type="button" className="mt-text-button" onClick={queue.loadCapabilities}>{t("Coba periksa lagi")}</button></div>}
            {runtimeUnavailable && <div className="mt-notice" role="status"><strong>{t("Alat belum tersedia di server ini.")}</strong> <button type="button" className="mt-text-button" onClick={queue.loadCapabilities}>{t("Periksa ketersediaan")}</button></div>}

            <div className="mt-workbench">
                <section className="mt-composer" aria-labelledby="rembg-source-heading">
                    <div className="mt-panel-heading"><h2 id="rembg-source-heading">{t("Gambar sumber")}</h2><Icon path={<><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" /></>} /></div>
                    <form onSubmit={submit} noValidate>
                        <div className="mt-form-fields">
                            <label className="mt-field-label" htmlFor="rembg-file">{t("Berkas gambar")}</label>
                            <input ref={fileInput} id="rembg-file" type="file" accept="image/png,image/jpeg,image/webp" className="mt-input" disabled={busy || !available} onChange={chooseFile} />
                            <p className="mt-help">{t("PNG, JPG, atau WebP. Hasil dikembalikan sebagai PNG transparan.")}</p>
                            {file && <div className="mt-file-details"><strong title={file.name}>{file.name}</strong><span>{bytes(file.size, locale)}</span></div>}
                            {localError && <p className="mt-field-error" role="alert">{localError}</p>}
                            <dl className="mt-limits">
                                <div><dt>{t("Biaya")}</dt><dd>{costLabel}</dd></div>
                                <div><dt>{t("Ukuran unggah maksimal")}</dt><dd>{bytes(uploadLimit, locale)}</dd></div>
                            </dl>
                        </div>
                        <div className="mt-submit-area">
                            {queue.submitError && !queue.recovery && <div className="mt-notice mt-notice--error" role="alert">{t(errorMessage(queue.submitError))}</div>}
                            {queue.submitting && <div className="mt-upload-status" role="status"><strong>{t("Mengunggah gambar…")}</strong><progress aria-label={t("Mengunggah gambar")} /></div>}
                            <button type="submit" className="mt-button mt-button--primary mt-submit-button" disabled={!available || busy || !file || !canUse || expired}>
                                <Icon path={<><path d="M3 3l18 18M9 4h9a2 2 0 0 1 2 2v9M4 9v9a2 2 0 0 0 2 2h9" /></>} />{t(busy ? "Menunggu konfirmasi…" : "Hapus latar")}
                            </button>
                            <p className="mt-submit-note">{isAdmin ? t("Generasi admin gratis, tanpa token.") : `${t("Menggunakan")} ${tokens} ${t("token generator per gambar. Token dikembalikan bila gagal.")}`}</p>
                        </div>
                    </form>
                </section>

                <section className="mt-monitor" aria-label={t("Pratinjau dan status pekerjaan")}>
                    <div className="mt-monitor-toolbar"><h2>{t("Pratinjau hasil")}</h2><span className="mt-private-note"><Icon path={<><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></>} />{t("Privat")}</span></div>
                    {selected ? (
                        <div className="mt-stage">
                            {selected.status === "completed" && selected.result_url ? (
                                <>
                                    <div className="rembg-preview"><img src={selected.result_url} alt={selected.title || t("Hasil hapus latar")} loading="lazy" /></div>
                                    <div className="mt-result-actions">
                                        <a className="mt-button mt-button--primary" href={`${selected.result_url}?download=1`} download><Icon path={<path d="M12 3v12m-4-4 4 4 4-4M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4" />} />{t("Unduh PNG")}</a>
                                        <span className="mt-help">{bytes(Number(selected.size_bytes), locale)} · PNG</span>
                                    </div>
                                </>
                            ) : selected.status === "failed" ? (
                                <div className="mt-stage-message"><Icon path={<><circle cx="12" cy="12" r="9" /><path d="M12 8v4m0 4h.01" /></>} /><h3>{t("Gagal menghapus latar")}</h3><p>{t(selected.error || "Coba gambar lain.")}</p></div>
                            ) : (
                                <div className="mt-stage-message" role="status"><Icon path={<path d="M12 6v6l4 2" />} /><h3>{stageLabel(selected)}</h3><progress aria-label={stageLabel(selected)} /></div>
                            )}
                        </div>
                    ) : (
                        <div className="mt-stage"><div className="mt-stage-message"><Icon path={<><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" /></>} /><h3>{t(queue.historyLoading ? "Memuat pekerjaan Anda…" : "Hasil transparan tampil di sini")}</h3><p>{t("Unggah gambar untuk menghapus latarnya. Hasil dapat diunduh sebagai PNG.")}</p></div></div>
                    )}
                </section>
            </div>

            <section className="mt-history" aria-labelledby="rembg-history-heading">
                <div className="mt-history-heading">
                    <div><h2 id="rembg-history-heading"><Icon path={<path d="M3 12a9 9 0 1 1 3 6.7L3 16" />} />{t("Riwayat hapus latar")}</h2><p>{t("Pilih pekerjaan untuk melihat hasilnya.")}{activeCount > 0 && <> {new Intl.NumberFormat(locale).format(activeCount)} {t("pekerjaan sedang berjalan.")}</>}</p></div>
                    <div className="mt-history-actions">
                        <button type="button" className="mt-button mt-button--secondary" disabled={queue.historyLoading} onClick={queue.refresh}><Icon path={<path d="M3 12a9 9 0 1 1 3 6.7L3 16" />} />{t(queue.historyLoading ? "Memperbarui…" : "Perbarui riwayat")}</button>
                        {deletableCount > 0 && <button type="button" className="mt-button mt-button--danger" disabled={Boolean(queue.deleting)} onClick={() => { queue.clearDeleteError(); setPendingDelete("all"); }}><Icon path={<path d="M4 7h16M9 7V4h6v3m-8 0v13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V7" />} />{t("Bersihkan riwayat")}</button>}
                    </div>
                </div>
                {queue.historyError && <div className="mt-notice mt-notice--error" role="alert">{t(errorMessage(queue.historyError))} <button type="button" className="mt-text-button" onClick={queue.refresh}>{t("Coba lagi")}</button></div>}
                {queue.jobs.length > 0 ? (
                    <ul className="mt-history-list">{queue.jobs.map((job) => (
                        <li key={job.job_id}><div className="mt-history-row">
                            <button type="button" className="mt-history-choice" aria-pressed={selected?.job_id === job.job_id} onClick={() => chooseJob(job.job_id)}>
                                <span className="mt-history-file mt-tone--image"><Icon path={<><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" /></>} /><span>PNG</span></span>
                                <span className="mt-history-content"><strong>{job.title || job.input_name || t("Hapus latar")}</strong><span>{job.input_name || t("Pekerjaan media")}<span aria-hidden="true"> · </span><time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time></span></span>
                                <span className="mt-history-state">{isActiveJob(job) ? <span className="ui-status ui-status-warn">{stageLabel(job)}</span> : job.status === "completed" ? <span className="ui-status ui-status-good">{t("Selesai")}</span> : <span className="ui-status ui-status-bad">{t(job.status === "cancelled" ? "Dibatalkan" : "Gagal")}</span>}{job.status === "completed" && typeof job.size_bytes === "number" && <span>{bytes(job.size_bytes, locale)}</span>}</span>
                            </button>
                            {isActiveJob(job) && job.can_cancel && <button type="button" className="mt-history-delete" aria-label={t("Batalkan")} disabled={Boolean(queue.cancelling)} onClick={() => queue.cancel(job)}><Icon path={<path d="M6 6l12 12M18 6 6 18" />} /></button>}
                            {!isActiveJob(job) && <button type="button" className="mt-history-delete" aria-label={`${t("Hapus")} ${job.title || job.job_id}`} disabled={Boolean(queue.deleting)} onClick={() => { queue.clearDeleteError(); setPendingDelete(job); }}><Icon path={<path d="M4 7h16M9 7V4h6v3m-8 0v13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V7" />} /></button>}
                        </div></li>
                    ))}</ul>
                ) : (
                    <div className="mt-history-empty" role="status"><Icon path={<path d="M3 12a9 9 0 1 1 3 6.7L3 16" />} /><div><strong>{t(queue.historyLoading ? "Memuat riwayat…" : "Belum ada pekerjaan")}</strong><p>{t("Pekerjaan pertama Anda akan muncul di sini setelah diterima server.")}</p></div></div>
                )}
            </section>

            {pendingDelete && <MediaActionDialog
                title={t(pendingDelete === "all" ? "Bersihkan riwayat?" : "Hapus dari riwayat?")}
                description={t(pendingDelete === "all" ? "Semua pekerjaan yang sudah selesai beserta file hasilnya akan dihapus permanen." : "Pekerjaan ini beserta file hasilnya akan dihapus permanen dari akun Anda.")}
                closeLabel={t("Batal")} confirmLabel={t(pendingDelete === "all" ? "Hapus semua" : "Hapus")} busyLabel={t("Menghapus…")}
                busy={Boolean(queue.deleting)} error={queue.deleteError ? t(errorMessage(queue.deleteError.error)) : null}
                onConfirm={confirmDelete} onClose={() => { if (!queue.deleting) { setPendingDelete(null); queue.clearDeleteError(); } }} />}
        </div>
    );
}
