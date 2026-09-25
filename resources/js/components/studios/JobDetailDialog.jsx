import { useEffect, useId, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import MediaActionDialog from "../MediaActionDialog";
import { formatLocalDate } from "../member/MemberUI";
import { JobBilling, MediaOutputs, SavedReferences } from "./MediaOutputs";
import { ownedMediaUrl } from "./mediaOutput";
import { jobDraft, jobParameters, jobPrompt, jobSeed, jobThumbnail } from "./studioJobs";
import { mediaJobPending, operationLabel, outputKindLabel } from "./workspaceMedia";
import { StudioButton, StudioIcon, StudioNotice, StudioProgress, StudioStatus, mediaError } from "./StudioUI";
import "./studio-detail.css";

const parameterLabels = { size: "Ukuran", aspect_ratio: "Rasio aspek", duration: "Durasi", voice: "Suara", speed: "Kecepatan", tempo: "Tempo", instrumental: "Instrumental", custom: "Kustom", pro: "Pro" };

function DetailContent({ job, batch, items, initialOutput, loading, error, actionBusy, actionError, onNavigate, onRefresh, onAction, onLoadToForm, onClose, titleId, dialogRef }) {
    const { t, locale } = useLocale();
    const [selected, setSelected] = useState(initialOutput);
    const [confirmation, setConfirmation] = useState(null);
    const [copyNotice, setCopyNotice] = useState("");
    const stripCurrent = useRef(null);
    const viewerJob = batch ? { ...job, outputs: batch.outputs } : job;
    const outputs = viewerJob.outputs || [];
    const currentOutput = outputs.find((output) => output.id === selected) || outputs[0];
    const prompt = jobPrompt(job);
    const details = job.details || {};
    const model = details.model_label || job.model;
    const title = prompt || model || job.id;
    const parameters = jobParameters(job);
    const canLoad = useMemo(() => jobDraft(job) !== null, [job]);
    const seed = jobSeed(job, currentOutput);
    const download = ownedMediaUrl(currentOutput?.download_url);
    const original = currentOutput?.previewable ? ownedMediaUrl(currentOutput.url) || download : download;
    const pending = mediaJobPending(job);
    const busy = Boolean(actionBusy);
    const index = items.findIndex((item) => item.job.id === job.id || item.members?.includes(job.id));
    const previous = index > 0 ? items[index - 1].job.id : null;
    const next = index >= 0 && index < items.length - 1 ? items[index + 1].job.id : null;
    const activeKey = index >= 0 ? items[index].key : null;
    const unavailableCancel = confirmation === "cancel" && job.can_cancel !== true;

    useEffect(() => {
        stripCurrent.current?.scrollIntoView({ block: "nearest", inline: "nearest" });
    }, [activeKey]);

    const navigate = (id) => { if (id && !confirmation) onNavigate(id); };
    const onKeyDown = (event) => {
        // Keep Tab at the modal boundary rather than moving to browser chrome. The portal-based
        // confirmation bubbles here too, so its own dialog remains the active focus scope.
        if (event.key === "Tab" && !event.defaultPrevented && event.target instanceof HTMLElement) {
            const modal = event.target.closest("dialog");
            if (!modal) return;
            const controls = [...modal.querySelectorAll("button, a[href], input, select, textarea, summary, [tabindex], video[controls], audio[controls]")]
                .filter((element) => element.tabIndex >= 0 && !element.matches(":disabled") && element.getClientRects().length && getComputedStyle(element).visibility !== "hidden");
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (!first) { event.preventDefault(); modal.focus(); }
            else if (event.shiftKey && event.target === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && event.target === last) { event.preventDefault(); first.focus(); }
            return;
        }
        if (confirmation || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || !dialogRef.current?.contains(event.target)) return;
        if (!(event.target instanceof HTMLElement) || event.target.closest("input, textarea, select, video, audio, [contenteditable], model-viewer, .media-image-viewport:not(.is-fit)")) return;
        const target = event.key === "ArrowLeft" ? previous : event.key === "ArrowRight" ? next : null;
        if (target) { event.preventDefault(); navigate(target); }
    };
    const copy = async (value) => {
        setCopyNotice("");
        try {
            if (!navigator.clipboard?.writeText) throw new Error("Clipboard unavailable");
            await navigator.clipboard.writeText(value);
            setCopyNotice(t("Tersalin"));
        } catch {
            setCopyNotice(t("Tidak dapat menyalin. Izinkan akses papan klip, lalu coba lagi."));
        }
    };
    const confirmAction = async () => {
        try {
            await onAction(job.id, confirmation);
            setConfirmation(null);
        } catch { /* The parent keeps the server's action error visible in this confirmation. */ }
    };

    return <div className="sw-detail-layout" onKeyDown={onKeyDown}>
        <header className="sw-detail-header">
            <div className="sw-detail-heading"><h2 id={titleId} dir="auto" title={title}>{title}</h2><div className="sw-detail-heading-meta"><StudioStatus job={job} />{job.created_at && <time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time>}</div></div>
            <div className="sw-detail-navigation">
                <StudioButton className="sw-detail-icon sw-detail-previous" icon="arrow" aria-label={t("Hasil sebelumnya")} title={t("Hasil sebelumnya")} disabled={!previous} onClick={() => navigate(previous)} />
                <StudioButton className="sw-detail-icon" icon="arrow" aria-label={t("Hasil berikutnya")} title={t("Hasil berikutnya")} disabled={!next} onClick={() => navigate(next)} />
                <StudioButton className="sw-detail-icon sw-detail-close" icon="close" aria-label={t("Tutup detail")} title={t("Tutup detail")} autoFocus onClick={onClose} />
            </div>
        </header>
        <div className="sw-detail-main">
            <section className="sw-detail-viewer" aria-label={t("Hasil media")}>
                {loading && <p className="sw-detail-loading" role="status">{t("Memeriksa…")}</p>}
                {error && <StudioNotice error>{t(mediaError(error))}</StudioNotice>}
                {pending && <div className={`sw-detail-pending${outputs.length ? " has-outputs" : ""}`}>
                    <StudioProgress job={job} synchronous={job.output_kind === "image" && job.id.startsWith("image:")} />
                </div>}
                {batch && <p className="studio-help media-output-note" role="status">{t("Variasi siap")}: {batch.done} {t("dari")} {batch.size}
                    {batch.failed > 0 && ` · ${batch.failed} ${t("berakhir tanpa gambar")}`}
                    {batch.pending.length > 0 && ` · ${t("lainnya masih diproses")}`}
                </p>}
                <MediaOutputs job={viewerJob} selected={selected} onSelect={setSelected} />
            </section>
            <aside className="sw-detail-aside" aria-label={t("Detail hasil")}>
                <dl className="sw-detail-metadata">
                    <div><dt>{t("Model")}</dt><dd dir="auto">{model || "—"}</dd></div>
                    <div><dt>{t("Operasi")}</dt><dd>{t(operationLabel(job.operation))}</dd></div>
                    {prompt && <div className="sw-detail-metadata-block"><dt>{t("Prompt")}</dt><dd className="sw-detail-prompt" dir="auto" tabIndex={0}>{prompt}</dd></div>}
                    {parameters.length > 0 && <div className="sw-detail-metadata-block"><dt>{t("Parameter")}</dt><dd><dl className="sw-detail-parameters">{parameters.map(([name, value]) => <div key={name}><dt>{t(parameterLabels[name] || name)}</dt><dd dir="auto">{value}</dd></div>)}</dl></dd></div>}
                    <div className="sw-detail-metadata-block"><dt>{t("Biaya")}</dt><dd><JobBilling job={job} /></dd></div>
                    <div><dt>{t("Status")}</dt><dd><StudioStatus job={job} /></dd></div>
                    <div><dt>{t("Dibuat")}</dt><dd>{job.created_at ? <time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time> : "—"}</dd></div>
                    <div><dt>{t("ID")}</dt><dd><code>{job.id}</code></dd></div>
                </dl>
                {job.error && <StudioNotice error={job.status !== "cancelled"}>{t(mediaError(job.error))}</StudioNotice>}
                {pending && job.cancel_reason && <p className="studio-help">{t(mediaError(job.cancel_reason))}</p>}
                {job.output_kind === "model3d" && job.status === "completed" && details.preview_unavailable_reason && <StudioNotice>{t(mediaError(details.preview_unavailable_reason))}</StudioNotice>}
                <SavedReferences job={job} />
                {details.improved_prompt && details.improved_prompt !== details.prompt && <details className="sw-detail-reviewed-prompt"><summary>{t("Lihat prompt hasil peninjauan lama")}</summary><p className="sw-detail-prompt" dir="auto" tabIndex={0}>{details.improved_prompt}</p></details>}
                <div className="sw-detail-actions">
                    <StudioButton primary icon="prompt" disabled={!canLoad || busy} onClick={() => onLoadToForm(job)}>{t("Muat ke form")}</StudioButton>
                    {prompt && <StudioButton onClick={() => { void copy(prompt); }}>{t("Salin prompt")}</StudioButton>}
                    {seed !== null && <StudioButton onClick={() => { void copy(seed); }}>{t("Salin seed")}</StudioButton>}
                    {download && <a className="studio-button studio-download" href={download} download><StudioIcon name="download" />{t("Unduh")}</a>}
                    {original && <a className="studio-button" href={original} target="_blank" rel="noreferrer">{t("Buka asli")}</a>}
                    <StudioButton icon="refresh" disabled={loading || busy} aria-busy={loading} onClick={() => { void Promise.resolve(onRefresh(job.id)).catch(() => {}); }}>{t(loading ? "Memeriksa…" : "Periksa status")}</StudioButton>
                    {pending && <StudioButton disabled={busy} onClick={() => setConfirmation("cancel")}>{t("Batalkan")}</StudioButton>}
                    {job.can_retry_save && <StudioButton icon="refresh" disabled={busy} aria-busy={actionBusy === `${job.id}:retry-save`} onClick={() => { void onAction(job.id, "retry-save").catch(() => {}); }}>{t(actionBusy === `${job.id}:retry-save` ? "Meminta simpan ulang…" : "Simpan ulang")}</StudioButton>}
                    {job.can_delete && <StudioButton className="sw-detail-danger" disabled={busy} onClick={() => setConfirmation("delete")}>{t("Hapus")}</StudioButton>}
                </div>
                <p className="sw-detail-copy-notice" aria-live="polite" aria-atomic="true">{copyNotice}</p>
                {job.can_retry_save && <p className="studio-help">{t("Simpan ulang memakai hasil yang sudah dibuat, bukan permintaan generasi baru.")}</p>}
                {actionError && !confirmation && <StudioNotice error>{t(mediaError(actionError))}</StudioNotice>}
            </aside>
        </div>
        {items.length > 0 && <footer className="sw-detail-strip">
            <span className="sw-detail-position" aria-live="polite">{index >= 0 ? index + 1 : "—"} / {items.length}</span>
            <nav className="sw-detail-thumbnails" aria-label={t("Riwayat hasil")}>{items.map((item, position) => {
                const thumbnail = jobThumbnail(item.job);
                const active = position === index;
                const label = `${t("Hasil")} ${position + 1}: ${jobPrompt(item.job) || item.job.details?.model_label || item.job.model || t(outputKindLabel(item.job.output_kind))}`;
                return <button type="button" key={item.key} ref={active ? stripCurrent : null} className="sw-detail-thumbnail" aria-current={active ? "true" : undefined} aria-label={label} title={label} onClick={() => navigate(item.job.id)}>
                    {thumbnail ? <img src={thumbnail} alt="" loading="lazy" /> : <StudioIcon name={["image", "video", "audio", "model3d"].includes(item.job.output_kind) ? item.job.output_kind : "download"} />}
                    <span>{position + 1}</span>
                </button>;
            })}</nav>
        </footer>}
        {confirmation && (unavailableCancel
            ? <MediaActionDialog title={t("Permintaan tidak bisa dibatalkan")} closeLabel={t("Mengerti")} onClose={() => setConfirmation(null)}
                description={t(mediaError(job.cancel_reason || "Pengiriman sudah dimulai. Menutup halaman tidak membatalkan proses atau mengembalikan token."))}><StudioStatus job={job} />{actionError && <StudioNotice error>{t(mediaError(actionError))}</StudioNotice>}</MediaActionDialog>
            : <MediaActionDialog title={t(confirmation === "delete" ? "Hapus dari riwayat?" : "Batalkan permintaan ini?")}
                description={t(confirmation === "delete" ? "Pekerjaan yang selesai beserta file hasilnya akan dihapus permanen. Tindakan ini tidak dapat dibatalkan." : "Pembatalan hanya selesai setelah dikonfirmasi server. Menutup halaman tidak membatalkan proses atau menjamin pengembalian token.")}
                closeLabel={t("Tutup")} confirmLabel={t(confirmation === "delete" ? "Hapus permanen" : "Ajukan pembatalan")} busyLabel={t("Memproses…")} busy={busy}
                error={actionError ? t(mediaError(actionError)) : null} confirmDisabled={confirmation === "cancel" && job.can_cancel !== true} onConfirm={confirmAction} onClose={() => setConfirmation(null)} />)}
    </div>;
}

export default function JobDetailDialog({ job, batch = null, items = [], initialOutput = "", loading = false, error = null, actionBusy = "", actionError = null, onNavigate, onRefresh, onAction, onLoadToForm, onClose }) {
    const ref = useRef(null);
    const titleId = useId();
    useEffect(() => {
        const element = ref.current;
        const previousFocus = document.activeElement;
        element.showModal();
        return () => {
            element.close();
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
        };
    }, []);

    return <dialog ref={ref} className="media-studio-detail sw-detail" aria-labelledby={titleId} onCancel={(event) => {
        event.preventDefault();
        event.stopPropagation();
        if (event.target === event.currentTarget) onClose();
    }}>
        <DetailContent key={`${job.id}:${initialOutput}`} job={job} batch={batch} items={items} initialOutput={initialOutput} loading={loading} error={error}
            actionBusy={actionBusy} actionError={actionError} onNavigate={onNavigate} onRefresh={onRefresh} onAction={onAction} onLoadToForm={onLoadToForm} onClose={onClose} titleId={titleId} dialogRef={ref} />
    </dialog>;
}
