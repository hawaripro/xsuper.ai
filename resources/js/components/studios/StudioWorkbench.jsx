import { Suspense, lazy, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import MediaActionDialog from "../MediaActionDialog";
import { formatLocalDate } from "../member/MemberUI";
import JobDetailDialog from "./JobDetailDialog";
import { UNIT_LABELS, quoteBreakdown } from "./RequestPanel";
import { KindPlate, ModelMark, StudioButton, StudioEmpty, StudioIcon, StudioNotice, StudioProgressBar, StudioStatus, jobProgress, mediaError, stageLabel } from "./StudioUI";
import { modelKind } from "./modelBrands";
import { schemaDocs } from "./studioForm";
import { BILLING_LABELS, HISTORY_KINDS, STATUS_FILTERS, groupJobs, jobDraft, jobPrompt, jobStatusGroup, jobThumbnail, jobTokens, jobVideoPreview, timeAgo } from "./studioJobs";
import { CLEARABLE_KINDS } from "./useGlobalMediaWorkspace";
import { ownedMediaUrl } from "./mediaOutput";
import { mediaJobPending, operationLabel, outputKindLabel } from "./workspaceMedia";

const RealtimeMediaSession = lazy(() => import("./RealtimeMediaSession"));
const DISPLAY_KEY = "xsuper:studio:display:v1";
const VIEWS = [{ id: "grid", label: "Grid", icon: "grid" }, { id: "list", label: "Tampilan daftar", icon: "list" }, { id: "thumbs", label: "Thumbnail", icon: "thumbs" }];
const SIZES = [{ id: "s", label: "Kecil" }, { id: "m", label: "Sedang" }, { id: "l", label: "Besar" }];
const DEFAULT_DISPLAY = { view: "grid", size: "m", prompt: true, meta: true };
const readDisplay = () => {
    try {
        const value = JSON.parse(localStorage.getItem(DISPLAY_KEY) || "{}");
        return { view: VIEWS.some((entry) => entry.id === value.view) ? value.view : DEFAULT_DISPLAY.view, size: SIZES.some((entry) => entry.id === value.size) ? value.size : DEFAULT_DISPLAY.size,
            prompt: value.prompt !== false, meta: value.meta !== false };
    } catch { return DEFAULT_DISPLAY; }
};
const tabKeys = ["ArrowLeft", "ArrowRight", "Home", "End"];

function useRelativeTime() {
    const { locale } = useLocale();
    const relative = useMemo(() => new Intl.RelativeTimeFormat(locale, { numeric: "auto", style: "short" }), [locale]);
    return (iso) => {
        const ago = timeAgo(iso);
        return ago ? relative.format(-ago.value, ago.unit) : iso ? formatLocalDate(iso, { locale }) : "";
    };
}

// A non-modal popover that closes on Escape or an outside press and returns focus to its trigger.
function usePopover() {
    const [open, setOpen] = useState(false);
    const root = useRef(null);
    const trigger = useRef(null);
    useEffect(() => {
        if (!open) return undefined;
        const press = (event) => { if (!root.current?.contains(event.target)) setOpen(false); };
        const key = (event) => { if (event.key === "Escape") { event.stopPropagation(); setOpen(false); trigger.current?.focus(); } };
        document.addEventListener("pointerdown", press);
        root.current?.addEventListener("keydown", key);
        const element = root.current;
        return () => { document.removeEventListener("pointerdown", press); element?.removeEventListener("keydown", key); };
    }, [open]);
    return { open, setOpen, root, trigger };
}

function DisplayMenu({ display, onChange }) {
    const { t } = useLocale();
    const popover = usePopover();
    return <div className="sw-popover-anchor" ref={popover.root}>
        <button type="button" ref={popover.trigger} className="sw-icon-btn" aria-expanded={popover.open} aria-controls="sw-display-menu" aria-label={t("Pengaturan tampilan")} title={t("Pengaturan tampilan")}
            onClick={() => popover.setOpen((current) => !current)}><StudioIcon name="settings" /></button>
        {popover.open && <div id="sw-display-menu" className="sw-popover" role="dialog" aria-label={t("Pengaturan tampilan")}>
            <fieldset><legend>{t("Ukuran kartu")}</legend><div className="sw-segmented">{SIZES.map((entry) => <label key={entry.id}>
                <input type="radio" name="sw-card-size" value={entry.id} checked={display.size === entry.id} onChange={() => onChange({ size: entry.id })} /><span>{t(entry.label)}</span></label>)}</div></fieldset>
            <label className="studio-checkbox"><input type="checkbox" checked={display.prompt} onChange={(event) => onChange({ prompt: event.target.checked })} /><span>{t("Tampilkan prompt")}</span></label>
            <label className="studio-checkbox"><input type="checkbox" checked={display.meta} onChange={(event) => onChange({ meta: event.target.checked })} /><span>{t("Tampilkan model, operasi, dan biaya")}</span></label>
        </div>}
    </div>;
}

function CardMedia({ job, members, jobsById }) {
    const { t } = useLocale();
    const set = members.length > 1;
    const thumbs = set ? members.map((id) => jobThumbnail(jobsById.get(id))).filter(Boolean).slice(0, 4) : [jobThumbnail(job)].filter(Boolean);
    const video = !thumbs.length && jobVideoPreview(job);
    // Members that are not loaded (page boundary, deleted) are not shown as running forever.
    const running = set ? members.some((id) => jobsById.has(id) && mediaJobPending(jobsById.get(id))) : mediaJobPending(job);
    const progress = jobProgress(job);
    return <span className={`sw-card-media${thumbs.length > 1 ? " is-mosaic" : ""}`}>
        {thumbs.length ? thumbs.map((src) => <img key={src} src={src} alt="" loading="lazy" />)
            : video ? <video src={`${video}#t=0.1`} muted playsInline preload="metadata" tabIndex={-1} aria-hidden="true" />
                : <span className="sw-card-placeholder"><KindPlate kind={modelKind(job)} operation={job.operation} /></span>}
        {running && <span className="sw-card-running"><span className="studio-spinner" aria-hidden="true" /><span>{t(stageLabel(job))}</span>
            {progress != null && <StudioProgressBar value={progress} />}</span>}
        {!running && job.status !== "completed" && <span className="sw-card-badge"><StudioStatus job={job} /></span>}
        {set && <span className="sw-card-count">{members.length} {t("variasi")}</span>}
        {video && <span className="sw-card-play"><StudioIcon name="play" /></span>}
    </span>;
}

function JobCard({ item, index, jobsById, display, active, busy, onOpen, onLoad, onDelete }) {
    const { t, locale } = useLocale();
    const ago = useRelativeTime();
    const { job, members } = item;
    const set = members.length > 1;
    // A result that finishes while on screen is revealed once; loaded history simply appears.
    const pendingNow = mediaJobPending(job);
    const wasPending = useRef(pendingNow);
    const [fresh, setFresh] = useState(false);
    useEffect(() => {
        if (wasPending.current && !pendingNow && job.status === "completed") setFresh(true);
        wasPending.current = pendingNow;
    }, [pendingNow, job.status]);
    useEffect(() => {
        if (!fresh) return undefined;
        const timer = setTimeout(() => setFresh(false), 1600);
        return () => clearTimeout(timer);
    }, [fresh]);
    const title = jobPrompt(job) || job.details?.model_label || job.model || job.id;
    const tokens = set ? members.reduce((sum, id) => sum + (jobTokens(jobsById.get(id)) || 0), 0) : jobTokens(job);
    const download = !set && job.outputs?.length === 1 ? ownedMediaUrl(job.outputs[0].download_url) : null;
    const canLoad = Boolean(jobDraft(job));
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    const status = mediaJobPending(job) ? t(stageLabel(job)) : null;
    const label = `${title.length > 90 ? `${title.slice(0, 90)}…` : title}${status ? ` — ${status}` : ""}`;
    const meta = [t(operationLabel(job.operation)), job.details?.model_label || job.model, ago(job.created_at)].filter(Boolean).join(" · ");
    const cost = tokens != null ? `${format(tokens)} ${t("token")}${job.billing_status && BILLING_LABELS[job.billing_status] ? ` · ${t(BILLING_LABELS[job.billing_status])}` : ""}` : null;
    return <li className={`sw-card${fresh ? " is-fresh" : ""}`} data-status={jobStatusGroup(job)} data-kind={modelKind(job)} style={{ "--i": Math.min(index, 12) }} aria-current={active ? "true" : undefined}>
        <button type="button" className="sw-card-open" onClick={onOpen} aria-label={`${t("Buka detail")}: ${label}`} title={title}>
            <CardMedia job={job} members={members} jobsById={jobsById} />
            {display.view !== "thumbs" && (display.prompt || display.view === "list") && <span className="sw-card-title" dir="auto">{title}</span>}
            {display.view !== "thumbs" && display.meta && <span className="sw-card-meta"><ModelMark model={job} kind={job.output_kind} className="is-small" /><span>{meta}</span></span>}
            {display.view !== "thumbs" && display.meta && cost && <span className="sw-card-cost"><StudioIcon name="tokens" />{cost}</span>}
            {display.view === "list" && <StudioStatus job={job} />}
        </button>
        {display.view !== "thumbs" && <div className="sw-card-actions">
            {download && <a className="sw-icon-btn" href={download} download aria-label={`${t("Unduh")}: ${title.slice(0, 60)}`} title={t("Unduh")}><StudioIcon name="download" /></a>}
            <button type="button" className="sw-icon-btn" disabled={!canLoad} onClick={onLoad} aria-label={`${t("Pakai lagi")}: ${title.slice(0, 60)}`} title={t("Pakai lagi (muat ke form)")}><StudioIcon name="refresh" /></button>
            {job.can_delete && !set && <button type="button" className="sw-icon-btn is-danger" disabled={busy} onClick={onDelete} aria-label={`${t("Hapus dari riwayat")}: ${title.slice(0, 60)}`} title={t("Hapus dari riwayat")}><StudioIcon name="trash" /></button>}
        </div>}
    </li>;
}

function ResultsPanel({ studio, realtime, capability, canSubmit, realtimeProps, items, jobsById, statusFilter, setStatusFilter, onOpen, onLoadJob }) {
    const { t } = useLocale();
    const [display, setDisplay] = useState(readDisplay);
    const [pending, setPending] = useState(null);
    const [retained, setRetained] = useState(0);
    const updateDisplay = (patch) => setDisplay((current) => {
        const next = { ...current, ...patch };
        try { localStorage.setItem(DISPLAY_KEY, JSON.stringify(next)); } catch { /* The view still applies to this visit. */ }
        return next;
    });
    const busy = studio.clearing || Boolean(studio.actionBusy);
    const clearable = CLEARABLE_KINDS.includes(studio.historyKind) && studio.historyJobs.some((job) => ["completed", "failed", "cancelled"].includes(job.status));
    const kindLabel = HISTORY_KINDS.find((entry) => entry.id === studio.historyKind)?.label || "Semua";
    const confirm = async () => {
        if (pending === "all") {
            const result = await studio.clearHistory(studio.historyKind);
            if (result) { setRetained(Number(result.retained_count) || 0); setPending(null); }
            return;
        }
        try { await studio.jobAction(pending.id, "delete"); setPending(null); } catch { /* The error stays visible in the dialog. */ }
    };
    const moveView = (event, index) => {
        if (!tabKeys.includes(event.key)) return;
        event.preventDefault();
        const next = event.key === "Home" ? 0 : event.key === "End" ? VIEWS.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + VIEWS.length) % VIEWS.length;
        updateDisplay({ view: VIEWS[next].id });
        event.currentTarget.parentElement?.children[next]?.focus();
    };
    const dialogError = pending === "all" ? studio.clearError : studio.actionError;
    return <>
        <div className="sw-results-tools">
            <div className="sw-chips" role="group" aria-label={t("Jenis hasil")}>{HISTORY_KINDS.map((entry) => <button type="button" key={entry.id || "all"} data-kind={entry.id || "all"}
                aria-pressed={studio.historyKind === entry.id} onClick={() => studio.setHistoryKind(entry.id)}><StudioIcon name={entry.icon} /><span>{t(entry.label)}</span></button>)}</div>
            <div className="sw-results-row">
                <div className="sw-chips" role="group" aria-label={t("Status hasil")}>{STATUS_FILTERS.map((entry) => <button type="button" key={entry.id || "all"} data-filter={entry.id || "all"}
                    aria-pressed={statusFilter === entry.id} onClick={() => setStatusFilter(entry.id)}><StudioIcon name={entry.icon} /><span>{t(entry.label)}</span></button>)}</div>
                <div className="sw-view-tools">
                    <div className="sw-view-switch" role="radiogroup" aria-label={t("Tampilan hasil")}>{VIEWS.map((entry, index) => <button type="button" role="radio" key={entry.id}
                        aria-checked={display.view === entry.id} tabIndex={display.view === entry.id ? 0 : -1} aria-label={t(entry.label)} title={t(entry.label)}
                        onClick={() => updateDisplay({ view: entry.id })} onKeyDown={(event) => moveView(event, index)}><StudioIcon name={entry.icon} /></button>)}</div>
                    <DisplayMenu display={display} onChange={updateDisplay} />
                    <button type="button" className="sw-icon-btn" disabled={studio.historyLoading} onClick={() => { void studio.loadHistory(); }} aria-label={t("Perbarui riwayat")} title={t("Perbarui riwayat")}><StudioIcon name="refresh" /></button>
                    {clearable && <button type="button" className="sw-icon-btn is-danger" disabled={busy} aria-label={`${t("Bersihkan riwayat")}: ${t(kindLabel)}`} title={`${t("Bersihkan riwayat")}: ${t(kindLabel)}`}
                        onClick={() => { studio.setClearError(null); setRetained(0); setPending("all"); }}><StudioIcon name="trash" /></button>}
                </div>
            </div>
        </div>
        {realtime && capability && <section id="sw-realtime" className="sw-realtime" tabIndex={-1} aria-label={t("Sesi langsung")}>
            <Suspense fallback={<p role="status" className="studio-loading">{t("Memuat sesi realtime…")}</p>}>
                <RealtimeMediaSession key={`${studio.modelId}:${studio.operation}:${capability.source_hash}`} model={studio.modelId} capability={capability} inputs={studio.values}
                    disabled={!canSubmit} onValidationErrors={realtimeProps.onValidationErrors} onActiveChange={realtimeProps.onActiveChange} onSaved={studio.refreshAfterSession} />
            </Suspense>
        </section>}
        {studio.historyError && <StudioNotice error action={<StudioButton onClick={() => { void studio.loadHistory(); }}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.historyError))}</StudioNotice>}
        {studio.detailError && !studio.job && <StudioNotice error action={<StudioButton disabled={studio.detailLoading} onClick={() => { void studio.loadJob(studio.activeId); }}>{t("Periksa status")}</StudioButton>}>{t(mediaError(studio.detailError))}</StudioNotice>}
        {studio.detailLoading && !studio.job && studio.activeId && <p role="status" className="studio-loading">{t("Memuat hasil…")}</p>}
        {retained > 0 && !pending && <StudioNotice>{t("Sebagian hasil dipertahankan karena masih dipakai artefak chat atau referensi tersimpan.")} ({retained})</StudioNotice>}
        {(items.length > 0 || studio.submitting) && <ul className={`sw-results is-${display.view} size-${display.size}`} aria-label={t("Hasil dan riwayat")} aria-busy={studio.historyLoading}>
            {studio.submitting && <li className="sw-card is-submitting" data-status="running" data-kind={studio.kind || "all"} aria-live="polite"><span className="sw-card-media"><span className="sw-card-running"><span className="studio-spinner" aria-hidden="true" />
                <span>{t("Mengirim permintaan…")}</span></span></span>{display.view !== "thumbs" && <span className="sw-card-title">{t("Permintaan baru")}</span>}</li>}
            {items.map((item, index) => <JobCard key={item.key} item={item} index={index} jobsById={jobsById} display={display} active={item.members.includes(studio.activeId)} busy={busy}
                onOpen={() => onOpen(item.job.id)} onLoad={() => onLoadJob(item.job)} onDelete={() => { setRetained(0); setPending(item.job); }} />)}
        </ul>}
        {studio.historyLoading && !items.length && <p role="status" className="studio-loading">{t("Memuat riwayat…")}</p>}
        {!studio.historyLoading && !studio.historyError && !items.length && !studio.submitting && (statusFilter || studio.historyJobs.length
            ? <StudioEmpty icon="history" title="Tidak ada hasil dengan filter ini" description="Ubah filter jenis atau status untuk melihat hasil lain." />
            : <StudioEmpty icon="spark" title="Belum ada hasil" description="Lengkapi permintaan di panel kiri lalu jalankan. Hasil dan riwayat akun Anda muncul di sini." />)}
        {studio.historyCursor && <div className="sw-more"><StudioButton disabled={studio.historyLoading} onClick={() => { void studio.loadHistory(studio.historyCursor); }}>{t(studio.historyLoading ? "Memuat…" : "Muat riwayat berikutnya")}</StudioButton>
            {statusFilter && <p className="studio-help">{t("Filter status berlaku untuk riwayat yang sudah dimuat.")}</p>}</div>}
        {pending && <MediaActionDialog title={t(pending === "all" ? "Bersihkan riwayat?" : "Hapus dari riwayat?")}
            description={t(pending === "all" ? "Semua pekerjaan yang sudah selesai atau gagal beserta file hasilnya akan dihapus permanen. Pekerjaan yang sedang berjalan tetap dipertahankan." : "Pekerjaan ini beserta file hasilnya akan dihapus permanen dari akun Anda.")}
            closeLabel={t("Batal")} confirmLabel={t(pending === "all" ? "Hapus semua" : "Hapus")} busyLabel={t("Menghapus…")} busy={busy}
            error={dialogError ? t(mediaError(dialogError)) : null} onConfirm={() => { void confirm(); }} onClose={() => { if (!busy) setPending(null); }} />}
    </>;
}

function PricingPanel({ studio, capability, quote }) {
    const { t, locale, localizedPath } = useLocale();
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    if (!capability) return <StudioEmpty icon="tokens" title="Pilih model untuk melihat harga" description="Tarif token tampil per operasi setelah model dipilih." />;
    const billing = capability.billing || {};
    const unit = billing.price_unit || capability.price_unit;
    const runs = quote.total && studio.balance != null ? Math.floor(studio.balance / quote.total) : null;
    const operations = studio.model?.operations || [];
    return <div className="sw-pricing">
        <div className="sw-price-hero">
            <span>{t("Tarif operasi ini")}</span>
            <strong>{quote.unit == null ? "—" : format(quote.unit)} <small>{t("token")} / {t(UNIT_LABELS[unit] || unit || "permintaan")}</small></strong>
        </div>
        <dl className="sw-price-lines">
            <div><dt>{t("Tarif dasar")}</dt><dd>{quote.unit == null ? "—" : `${format(quote.unit)} ${t("token")}`}</dd></div>
            {billing.mode === "per_second" && <div><dt>{t("Durasi")}{billing.duration_field === "duration" ? " (duration)" : ""}</dt><dd>× {quote.seconds == null ? "—" : `${format(quote.seconds)} ${t("detik")}`}</dd></div>}
            {quote.quantityField && <div><dt>{t("Jumlah hasil")} <code dir="ltr">{quote.quantityField}</code></dt><dd>× {format(quote.quantity)}</dd></div>}
            {billing.count_field && <div><dt>{t("Jumlah permintaan")}</dt><dd>× {format(quote.count)}</dd></div>}
            {quote.pro && <div><dt>Pro</dt><dd>× {billing.pro_multiplier}</dd></div>}
            <div className="is-total"><dt>{t("Estimasi total")}</dt><dd>{quote.total == null ? "—" : `${format(quote.total)} ${t("token")}`}</dd></div>
        </dl>
        <p className="sw-price-formula">{quoteBreakdown(quote, billing, capability, t, format)}{quote.total != null ? ` = ${format(quote.total)} ${t("token")}` : ""}</p>
        {quote.reason && <StudioNotice error>{t(quote.reason)}</StudioNotice>}
        <ul className="sw-price-notes">
            {quote.quantityField && <li>{t("Setiap hasil ditagih: tarif dikalikan jumlah hasil yang diminta.")}</li>}
            {billing.mode === "per_second" && <li>{t("Tarif per detik dikalikan durasi dalam detik bulat yang dikirim ke model.")}</li>}
            {billing.count_field && <li>{t("Jumlah permintaan membuat pekerjaan terpisah; setiap pekerjaan dicadangkan sendiri.")}</li>}
            <li>{t("Token dicadangkan saat dikirim. Permintaan yang gagal dikembalikan sesuai status tagihan.")}</li>
            {capability.contract_version === 2 && <li>{t("Harga mengikuti tarif yang ditinjau pengelola. Perubahan konfigurasi tidak menunjukkan biaya penyedia.")}</li>}
        </ul>
        <div className="sw-price-balance">
            <span><StudioIcon name="tokens" />{t("Saldo Anda")} <strong>{studio.balance == null ? "—" : `${format(studio.balance)} ${t("token")}`}</strong></span>
            {runs != null && <span>{runs > 0 ? `≈ ${format(runs)} ${t("kali dengan saldo Anda")}` : t("Saldo belum cukup untuk satu kali jalan.")}</span>}
            <Link to={localizedPath("/deposit")}>{t("Isi saldo")}</Link>
        </div>
        {operations.length > 1 && <div className="sw-price-table"><h3>{t("Tarif semua operasi model ini")}</h3>
            <table><thead><tr><th scope="col">{t("Operasi")}</th><th scope="col">{t("Hasil")}</th><th scope="col">{t("Tarif")}</th></tr></thead>
                <tbody>{operations.map((entry) => <tr key={entry.operation} aria-current={entry.operation === studio.operation ? "true" : undefined}>
                    <td>{t(operationLabel(entry.operation))}</td><td>{t(outputKindLabel(entry.output_kind))}</td>
                    <td>{entry.price_tokens == null ? "—" : `${format(entry.price_tokens)} ${t("token")} / ${t(UNIT_LABELS[entry.price_unit] || entry.price_unit || "permintaan")}`}</td></tr>)}</tbody></table></div>}
    </div>;
}

function DocDescription({ text }) {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    const long = text.length > 320;
    return <div className="sw-doc-description"><p className={long && !open ? "is-clamped" : ""} dir="auto">{text}</p>
        {long && <button type="button" className="studio-text-link" aria-expanded={open} onClick={() => setOpen((current) => !current)}>{t(open ? "Ringkas" : "Selengkapnya")}</button>}</div>;
}

function ParametersPanel({ docsSchema }) {
    const { t } = useLocale();
    const rows = useMemo(() => schemaDocs(docsSchema), [docsSchema]);
    if (!docsSchema) return <StudioEmpty icon="settings" title="Pilih model untuk melihat parameter" description="Setiap parameter tampil dengan jenis, batas, nilai bawaan, dan penjelasannya." />;
    if (!rows.length) return <StudioEmpty icon="settings" title="Model ini tidak memiliki parameter" description="Permintaan dikirim tanpa pengaturan tambahan." />;
    const value = (entry) => typeof entry === "string" ? (entry === "" ? '""' : entry) : JSON.stringify(entry);
    return <div className="sw-docs">{rows.map((row) => <article key={row.path} className="sw-doc" data-depth={row.depth}>
        <header><code dir="ltr">{row.path}</code>{row.title && row.title !== row.name && <span className="sw-doc-title">{row.title}</span>}
            <span className="sw-doc-type" dir="ltr">{row.type}</span>{row.required && <span className="sw-doc-required">{t("wajib")}</span>}</header>
        {row.description && <DocDescription text={row.description} />}
        <dl>
            {row.default !== undefined && <div><dt>{t("Bawaan")}</dt><dd><code dir="ltr">{value(row.default)}</code></dd></div>}
            {(row.minimum != null || row.maximum != null) && <div><dt>{t("Rentang")}</dt><dd>{row.minimum ?? "…"} – {row.maximum ?? "…"}{row.step != null ? ` · ${t("kelipatan")} ${row.step}` : ""}{row.unit ? ` ${row.unit}` : ""}</dd></div>}
            {row.options && <div><dt>{t("Pilihan")}</dt><dd className="sw-doc-options">{row.options.map((option) => <code key={value(option)} dir="ltr">{value(option)}</code>)}</dd></div>}
            {row.file && <div><dt>{t("Berkas")}</dt><dd>{t(outputKindLabel(row.file) || "Berkas")}</dd></div>}
        </dl>
    </article>)}</div>;
}

function ExamplesPanel({ examples, disabled, onUse }) {
    const { t } = useLocale();
    const scalar = (value) => ["string", "number", "boolean"].includes(typeof value);
    return <div className="sw-examples">{examples.map((example, index) => {
        const values = Object.entries(example.values || {}).filter(([key, value]) => scalar(value) && !/prompt/i.test(key)).slice(0, 8);
        return <article key={`${example.title}:${index}`} className="sw-example">
            <h3>{example.title || `${t("Contoh")} ${index + 1}`}</h3>
            {example.prompt && <p className="sw-example-prompt" dir="auto">{example.prompt}</p>}
            {values.length > 0 && <dl>{values.map(([key, value]) => <div key={key}><dt dir="ltr">{key}</dt><dd dir="ltr">{String(value)}</dd></div>)}</dl>}
            <StudioButton primary disabled={disabled} onClick={() => onUse(example)}>{t("Gunakan contoh")}</StudioButton>
        </article>;
    })}</div>;
}

// Right pane: results and history, pricing, parameter docs and examples. Panels stay mounted so a live
// session or a scrolled list survives switching tabs.
export default function StudioWorkbench({ ref, studio, capability, quote, docsSchema, realtime, canSubmit, realtimeProps, onLoadJob, onUseExample }) {
    const { t } = useLocale();
    const [tab, setTab] = useState("results");
    const [statusFilter, setStatusFilter] = useState("");
    const [detailOpen, setDetailOpen] = useState(() => Boolean(studio.linkRequest));
    const [initialOutput, setInitialOutput] = useState(() => studio.linkRequest?.track || "");
    const examples = Array.isArray(capability?.examples) ? capability.examples.filter((example) => example && typeof example === "object" && example.values && typeof example.values === "object").slice(0, 6) : [];
    const tabs = [
        { id: "results", label: "Hasil", icon: "grid" }, { id: "pricing", label: "Harga", icon: "tokens" }, { id: "params", label: "Parameter", icon: "settings" },
        ...(examples.length ? [{ id: "examples", label: "Contoh", icon: "spark" }] : []),
    ];
    const current = tabs.some((entry) => entry.id === tab) ? tab : "results";
    const jobsById = useMemo(() => new Map(studio.jobs.map((job) => [job.id, job])), [studio.jobs]);
    const visible = statusFilter ? studio.historyJobs.filter((job) => jobStatusGroup(job) === statusFilter) : studio.historyJobs;
    const items = useMemo(() => groupJobs(visible), [visible]);
    const running = studio.historyJobs.filter(mediaJobPending).length;
    // A ?job= link (notification, Library, reload) opens its result.
    const seq = studio.linkRequest?.seq || 0;
    const seenSeq = useRef(seq);
    useEffect(() => {
        if (seq === seenSeq.current) return;
        seenSeq.current = seq;
        setInitialOutput(studio.linkRequest?.track || "");
        setDetailOpen(true);
        setTab("results");
    }, [seq, studio.linkRequest?.track]);
    useEffect(() => { if (!studio.activeId || (studio.detailError && !studio.job)) setDetailOpen(false); }, [studio.activeId, studio.detailError, studio.job]);
    const openJob = (id) => { studio.selectJob(id); setInitialOutput(""); setDetailOpen(true); };
    const closeDetail = () => { setDetailOpen(false); studio.releaseLink(); };
    const moveTab = (event, index) => {
        if (!tabKeys.includes(event.key)) return;
        event.preventDefault();
        const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
        setTab(tabs[next].id);
        document.getElementById(`sw-tab-${tabs[next].id}`)?.focus();
    };
    // One indicator slides between tabs instead of each tab drawing its own underline.
    const tablist = useRef(null);
    useLayoutEffect(() => {
        const list = tablist.current;
        const selected = list?.querySelector('[role="tab"][aria-selected="true"]');
        if (!list || !selected) return undefined;
        const place = () => {
            list.style.setProperty("--sw-tab-x", `${selected.offsetLeft}px`);
            list.style.setProperty("--sw-tab-w", String(selected.offsetWidth));
        };
        place();
        const observer = typeof ResizeObserver === "function" ? new ResizeObserver(place) : null;
        observer?.observe(selected);
        return () => observer?.disconnect();
    }, [current, tabs.length, running]);
    const job = studio.job;
    return <section ref={ref} id="sw-workbench" className="sw-workbench" aria-label={t("Ruang kerja hasil")}>
        <div className="sw-tabs" role="tablist" aria-label={t("Ruang kerja")} ref={tablist}>{tabs.map((entry, index) => <button type="button" role="tab" key={entry.id} id={`sw-tab-${entry.id}`}
            aria-selected={current === entry.id} aria-controls={`sw-panel-${entry.id}`} tabIndex={current === entry.id ? 0 : -1}
            onClick={() => setTab(entry.id)} onKeyDown={(event) => moveTab(event, index)}>
            <StudioIcon name={entry.icon} /><span>{t(entry.label)}</span>{entry.id === "results" && running > 0 && <span className="sw-tab-count" aria-label={`${running} ${t("berjalan")}`}>{running}</span>}
        </button>)}<span className="sw-tab-indicator" aria-hidden="true" /></div>
        <div className="sw-panels">
            <div id="sw-panel-results" role="tabpanel" aria-labelledby="sw-tab-results" className="sw-panel" hidden={current !== "results"}>
                <ResultsPanel studio={studio} realtime={realtime} capability={capability} canSubmit={canSubmit} realtimeProps={realtimeProps} items={items} jobsById={jobsById}
                    statusFilter={statusFilter} setStatusFilter={setStatusFilter} onOpen={openJob} onLoadJob={onLoadJob} />
            </div>
            <div id="sw-panel-pricing" role="tabpanel" aria-labelledby="sw-tab-pricing" className="sw-panel" hidden={current !== "pricing"}>
                <PricingPanel studio={studio} capability={capability} quote={quote} />
            </div>
            <div id="sw-panel-params" role="tabpanel" aria-labelledby="sw-tab-params" className="sw-panel" hidden={current !== "params"}>
                <ParametersPanel docsSchema={docsSchema} />
            </div>
            {examples.length > 0 && <div id="sw-panel-examples" role="tabpanel" aria-labelledby="sw-tab-examples" className="sw-panel" hidden={current !== "examples"}>
                <ExamplesPanel examples={examples} disabled={studio.submitting} onUse={onUseExample} />
            </div>}
        </div>
        {detailOpen && job && <JobDetailDialog job={job} batch={studio.batch} items={items}
            initialOutput={studio.batch ? (job.outputs?.[0] ? `${job.id}#${job.outputs[0].id}` : "") : initialOutput}
            loading={studio.detailLoading} error={studio.detailError} actionBusy={studio.actionBusy} actionError={studio.actionError}
            onNavigate={(id) => { setInitialOutput(""); studio.selectJob(id); }} onRefresh={(id) => { void studio.loadJob(id); }}
            onAction={studio.jobAction} onLoadToForm={(entry) => { if (onLoadJob(entry)) closeDetail(); }} onClose={closeDetail} />}
    </section>;
}
