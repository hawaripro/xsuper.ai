import { useCallback, useEffect, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest, formatDateTime } from "../../lib/api";
import MediaActionDialog from "../MediaActionDialog";
import { LoadingState, ErrorState, EmptyState } from "./AsyncState";

export const capabilityStatuses = {
    found: ["neutral", "Ditemukan"],
    imported: ["neutral", "Diimpor"],
    needs_handling: ["warn", "Perlu penanganan"],
    tested: ["good", "Lulus kompatibilitas"],
    published: ["good", "Dipublikasikan"],
    disabled: ["neutral", "Dinonaktifkan"],
};

export function CapabilityStatus({ status }) {
    const { t } = useLocale();
    const [tone, label] = capabilityStatuses[status] || ["neutral", "Belum ditinjau"];
    return <span className="ui-status" data-tone={tone}>{t(label)}</span>;
}

function Blockers({ items }) {
    const { t } = useLocale();
    if (!items?.length) return null;
    return <ul className="list-disc space-y-2 pl-5 text-sm leading-6">
        {items.map((item, index) => <li key={index} className="break-words">
            {typeof item === "string" ? t(item) : <>
                <span>{t(item.message || item.reason || item.code || JSON.stringify(item))}</span>
                {(item.field || item.path) && <code className="ml-2 break-all text-xs">{item.field || item.path}</code>}
                {(item.suggestion || item.resolution) && <p>{t(item.suggestion || item.resolution)}</p>}
            </>}
        </li>)}
    </ul>;
}

function JsonDisclosure({ title, value }) {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    return <details className="min-w-0 border-t border-slate-200 py-3 dark:border-white/10" onToggle={(event) => setOpen(event.currentTarget.open)}>
        <summary className="cursor-pointer py-1 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-red-500">{t(title)}</summary>
        {open && (value == null
            ? <p className="mt-3 text-sm text-slate-600 dark:text-slate-400">{t("Tidak ada data tersimpan untuk bagian ini.")}</p>
            : <pre tabIndex={0} className="mt-3 max-h-80 max-w-full overflow-auto rounded-lg bg-slate-100 p-3 text-xs leading-5 text-slate-800 focus-visible:outline-2 focus-visible:outline-red-500 dark:bg-white/5 dark:text-slate-200">{JSON.stringify(value, null, 2)}</pre>)}
    </details>;
}

const actions = {
    review: ["Tinjau revisi", "Simpan tinjauan revisi?", "Simpan tinjauan teknis tanpa mengaktifkan model. Harga yang sudah ada tidak diubah."],
    publish: ["Publikasikan revisi", "Publikasikan revisi ini?", "Saya telah meninjau schema, definisi, dan laporan kompatibilitas. Publikasi memilih revisi ini untuk permintaan baru; label dan harga kurasi tidak berubah."],
    disable: ["Nonaktifkan revisi", "Nonaktifkan revisi ini?", "Revisi ini tidak lagi dipilih untuk permintaan baru. Riwayat dan revisi yang digunakan pekerjaan lama tetap disimpan."],
    rollback: ["Pulihkan revisi", "Pulihkan revisi ini?", "Pilih kembali revisi yang pernah dipublikasikan ini untuk permintaan baru. Revisi pekerjaan lama, label, dan harga tidak diubah."],
};

const priceUnits = { request: "permintaan", generation: "generasi", second: "detik" };

export default function CatalogRevisionPanel({ model, onRefresh, onClose, disabled = false }) {
    const { t } = useLocale();
    const [state, setState] = useState({ data: null, loading: true, error: "" });
    const [selectedId, setSelectedId] = useState(null);
    const [confirmation, setConfirmation] = useState(null);
    const [mutation, setMutation] = useState({ busy: false, error: "", blockers: [], success: "" });
    const [priceAcknowledged, setPriceAcknowledged] = useState(false);
    const request = useRef(0);
    const inFlight = useRef(false);
    const heading = useRef(null);
    const load = useCallback(async (signal) => {
        const id = ++request.current;
        setState((current) => ({ ...current, loading: true, error: "" }));
        try {
            const data = await apiRequest(`/api/admin/ai/models/${model.id}/capabilities`, { signal });
            if (!signal?.aborted && id === request.current) {
                setState({ data, loading: false, error: "" });
                setSelectedId((current) => data.revisions.some((revision) => revision.id === current) ? current : data.revisions[0]?.id ?? null);
            }
        } catch (error) {
            if (!signal?.aborted && id === request.current) setState((current) => ({ ...current, loading: false, error: error.message || t("Revisi capability tidak dapat dimuat.") }));
        }
    }, [model.id]);
    useEffect(() => {
        const controller = new AbortController();
        load(controller.signal);
        heading.current?.focus();
        return () => controller.abort();
    }, [load]);

    const revision = state.data?.revisions.find((entry) => entry.id === selectedId);
    const active = revision && state.data.active_revision_ids.includes(revision.id);
    const report = revision?.compatibility_report || {};
    const blockers = Array.isArray(report.blockers) ? report.blockers : [];
    const warnings = Array.isArray(report.warnings) ? report.warnings : [];
    // Documented-direct contracts already surface their evidence warnings in the compatibility report.
    const supplementWarnings = (Array.isArray(revision?.source_metadata?.source_evidence?.warnings) ? revision.source_metadata.source_evidence.warnings : [])
        .filter((warning) => !warnings.includes(warning));
    const sale = state.data?.model || {};
    const account = state.data?.account_verification || {};
    const priceReviewed = revision?.price_review?.token_cost === sale.token_cost && revision?.price_review?.unit === sale.price_unit
        && (revision?.execution?.transport !== "realtime" || revision.price_review?.max_session_seconds >= revision.execution.max_session_seconds);
    // Pricing is curation: an active or previously published v2 revision is re-reviewed after tariff or session-cap drift.
    const needsPriceReview = revision?.contract_version === 2 && revision.status !== "disabled" && !priceReviewed;
    const confirmAction = (action) => {
        setMutation({ busy: false, error: "", blockers: [], success: "" });
        setConfirmation({ action, revision, priceReview: ["review", "publish"].includes(action) && priceAcknowledged && revision.contract_version === 2
            ? { token_cost: sale.token_cost, unit: sale.price_unit, variable_configuration: true,
                ...(revision.execution?.transport === "realtime" ? { max_session_seconds: revision.execution.max_session_seconds } : {}) } : null });
    };
    const locked = disabled || state.loading || mutation.busy || !!state.error;
    const performAction = async () => {
        if (!confirmation || locked || inFlight.current) return;
        inFlight.current = true;
        setMutation({ busy: true, error: "", blockers: [], success: "" });
        try {
            await apiRequest(`/api/admin/ai/capabilities/${confirmation.revision.id}/${confirmation.action}`, {
                method: "POST",
                body: { reviewed: true, ...(confirmation.priceReview ? { price_review: confirmation.priceReview } : {}) },
            });
            setConfirmation(null);
            setPriceAcknowledged(false);
            setMutation({ busy: false, error: "", blockers: [], success: t("Status revisi diperbarui. Pekerjaan lama tetap memakai revisinya.") });
            await load();
            await onRefresh();
        } catch (error) {
            setMutation({ busy: false, error: error.message || t("Revisi tidak dapat diperbarui."), blockers: error.details?.blockers || [], success: "" });
        } finally {
            inFlight.current = false;
        }
    };

    return <section className="min-w-0 space-y-4" aria-labelledby={`revision-review-${model.id}`} aria-busy={state.loading || mutation.busy}>
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
                <h3 ref={heading} tabIndex={-1} id={`revision-review-${model.id}`} className="text-base font-semibold text-slate-900 dark:text-white">{t("Tinjau capability")} · {model.display_name || model.model_id}</h3>
                <p className="mt-1 max-w-prose text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Impor tidak mempublikasikan model. Tinjau setiap revisi sebelum mengubah capability aktif. Pemeriksaan kompatibilitas tidak mengirim generasi berbayar.")}</p>
            </div>
            <button type="button" className="ui-btn-secondary min-h-11" onClick={onClose} disabled={mutation.busy}>{t("Tutup tinjauan")}</button>
        </div>
        {state.loading && !state.data ? <LoadingState label={t("Memuat revisi capability…")} /> : state.error ? <ErrorState message={state.error} onRetry={() => load()} /> : !state.data?.revisions.length ? <EmptyState title={t("Belum ada revisi capability")} description={t("Revisi muncul setelah impor schema yang didukung. Model kurasi existing tetap memakai konfigurasi efektifnya.")} /> : <>
            <label className="block max-w-xl text-sm font-medium">{t("Operasi dan revisi")}
                <select className="ui-input mt-1 min-h-11" value={selectedId ?? ""} disabled={locked} onChange={(event) => { setSelectedId(Number(event.target.value)); setPriceAcknowledged(false); setMutation({ busy: false, error: "", blockers: [], success: "" }); }}>
                    {state.data.revisions.map((entry) => <option key={entry.id} value={entry.id}>{entry.operation} · v{entry.contract_version} / r{entry.revision} · {t(capabilityStatuses[entry.status]?.[1] || entry.status)}{state.data.active_revision_ids.includes(entry.id) ? ` · ${t("Aktif")}` : ""}</option>)}
                </select>
            </label>
            {revision && <div className="min-w-0 space-y-4" key={revision.id}>
                <div className="flex flex-wrap items-center gap-2 text-sm"><CapabilityStatus status={revision.status} />{active && <span className="ui-status" data-tone="good">{t("Revisi aktif")}</span>}<span className="text-slate-600 dark:text-slate-400">{formatDateTime(revision.created_at)}</span></div>
                <dl className="grid gap-3 text-xs sm:grid-cols-2">
                    <div><dt className="font-semibold">{t("ID publik")}</dt><dd className="mt-1 break-all font-mono">{model.model_id}</dd></div>
                    <div><dt className="font-semibold">{t("ID upstream")}</dt><dd className="mt-1 break-all font-mono">{model.upstream_model_id || "—"}</dd></div>
                    <div className="sm:col-span-2"><dt className="font-semibold">{t("Hash schema sumber")}</dt><dd className="mt-1 break-all font-mono">{revision.source_hash || "—"}</dd></div>
                    <div><dt className="font-semibold">{t("Bukti schema")}</dt><dd className="mt-1">{t(revision.source_evidence === "documented_supplement" ? "Dipulihkan dari dokumentasi resmi" : revision.source_evidence === "captured" ? "Sumber tersimpan" : "Sumber belum tersedia")} · {t(revision.compatible ? "Lulus kompatibilitas" : "Perlu penanganan")}</dd></div>
                    <div><dt className="font-semibold">{t("Verifikasi akun provider")}</dt><dd className="mt-1">{t(account.authenticated && account.healthy ? "Koneksi terautentikasi" : "Koneksi belum diverifikasi")}</dd></div>
                    <div><dt className="font-semibold">{t("Harga jual saat ini")}</dt><dd className="mt-1 tabular-nums">{Number(sale.token_cost) > 0 ? `${sale.token_cost} token / ${t(priceUnits[sale.price_unit] || sale.price_unit)}` : t("Belum berharga")}</dd></div>
                    <div><dt className="font-semibold">{t("Tinjauan harga revisi")}</dt><dd className="mt-1">{t(priceReviewed ? "Harga dan unit ditinjau" : "Belum ditinjau")}</dd></div>
                </dl>
                <p className="max-w-prose text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Sumber tersimpan, kompatibilitas, tinjauan harga, publikasi, dan autentikasi akun adalah status berbeda. Halaman ini tidak membuktikan bahwa generasi berbayar pada model telah berhasil.")}</p>
                {revision.source_evidence === "documented_supplement" && <div className="space-y-2 text-sm leading-6 text-slate-700 dark:text-slate-300">
                    <p className="max-w-prose">{t("Ekspor schema provider tidak tersedia atau tidak lengkap. Kontrak ini dipulihkan dari dokumentasi publik resmi; sumber, transport, dan schema asli tercatat pada bukti sumber.")}</p>
                    <Blockers items={supplementWarnings} />
                </div>}
                {revision.execution?.transport === "realtime" && <p className="text-sm font-medium">{t("Satu sesi, maksimal")} {revision.execution.max_session_seconds} {t("detik; biaya bervariasi menurut resolusi.")}</p>}
                {needsPriceReview && <div className="space-y-3">
                    <p className="max-w-prose text-sm leading-6 text-amber-900 dark:text-amber-200">{t("Jumlah hasil, durasi, resolusi, dan konfigurasi dapat mengubah biaya upstream. Tinjau harga jual beserta unitnya; biaya provider bukan harga jual otomatis. Tarif positif yang ada tidak akan ditimpa.")}</p>
                    {Number(sale.token_cost) > 0
                        ? <label className="flex max-w-prose items-start gap-2 text-sm leading-6"><input type="checkbox" className="mt-1" checked={priceAcknowledged} disabled={locked} onChange={(event) => setPriceAcknowledged(event.target.checked)} />{t("Saya menyetujui harga dan unit saat ini untuk revisi ini, termasuk risiko biaya konfigurasi.")}</label>
                        : <p className="text-sm leading-6">{t("Masukkan harga melalui tinjauan massal model baru atau editor model terlebih dahulu. Model belum dapat dipublikasikan.")}</p>}
                </div>}
                {!!blockers.length && <div className="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-amber-900 dark:text-amber-200"><h4 className="text-sm font-semibold">{t("Penghalang publikasi")}</h4><Blockers items={blockers} /><p className="text-sm leading-6">{t("Perbaiki mapping atau dukungan adapter yang disebutkan, lalu impor ulang schema. Mengubah harga atau mengaktifkan profil tidak melewati penghalang ini.")}</p></div>}
                {!!warnings.length && <div className="space-y-2 text-slate-700 dark:text-slate-300"><h4 className="text-sm font-semibold">{t("Catatan kompatibilitas")}</h4><Blockers items={warnings} /></div>}
                <div className="min-w-0">
                    <JsonDisclosure title="Schema sumber" value={revision.source_schema} />
                    <JsonDisclosure title="Bukti sumber" value={revision.source_metadata} />
                    <JsonDisclosure title="Definisi ternormalisasi" value={revision.definition} />
                    <JsonDisclosure title="Laporan kompatibilitas" value={revision.compatibility_report} />
                    <JsonDisclosure title="Metadata UI kurasi" value={revision.ui_metadata} />
                </div>
                <div className="flex flex-wrap gap-2">
                    {!active && !revision.previously_published && revision.status !== "disabled" && <button type="button" className="ui-btn-secondary min-h-11" disabled={locked || blockers.length > 0} onClick={() => confirmAction("review")}>{t(actions.review[0])}</button>}
                    {!active && !revision.previously_published && revision.status !== "disabled" && <button type="button" className="ui-btn-primary min-h-11" disabled={locked || blockers.length > 0 || !sale.available || !account.enabled || !account.authenticated || !account.healthy || !(Number(sale.token_cost) > 0) || (needsPriceReview && !priceAcknowledged)} onClick={() => confirmAction("publish")}>{t(actions.publish[0])}</button>}
                    {(active || revision.previously_published) && needsPriceReview && <button type="button" className="ui-btn-secondary min-h-11" disabled={locked || blockers.length > 0 || !priceAcknowledged} onClick={() => confirmAction("review")}>{t("Tinjau ulang harga")}</button>}
                    {!active && revision.previously_published && <button type="button" className="ui-btn-primary min-h-11" disabled={locked || blockers.length > 0 || needsPriceReview} onClick={() => confirmAction("rollback")}>{t(actions.rollback[0])}</button>}
                    {revision.status !== "disabled" && <button type="button" className="ui-btn-secondary min-h-11" disabled={locked} onClick={() => confirmAction("disable")}>{t(actions.disable[0])}</button>}
                </div>
            </div>}
        </>}
        {mutation.success && <p role="status" className="text-sm text-emerald-800 dark:text-emerald-300">{mutation.success}</p>}
        {!confirmation && mutation.error && <div role="alert" className="space-y-2 text-sm text-red-700 dark:text-red-300"><p>{mutation.error}</p><Blockers items={mutation.blockers} /></div>}
        {confirmation && <MediaActionDialog title={t(actions[confirmation.action][1])} description={t(actions[confirmation.action][2])} closeLabel={t("Batal")} confirmLabel={t(actions[confirmation.action][0])} busyLabel={t("Memproses…")} busy={mutation.busy} confirmDisabled={disabled || mutation.blockers.length > 0} error={mutation.error} onConfirm={performAction} onClose={() => { if (!inFlight.current) setConfirmation(null); }}>
            <p className="break-all text-sm font-semibold">{model.display_name || model.model_id} · {confirmation.revision.operation} · r{confirmation.revision.revision}</p>
            {confirmation.priceReview && <p className="mt-3 text-sm tabular-nums">{t("Harga jual saat ini")}: {confirmation.priceReview.token_cost} token / {t(priceUnits[confirmation.priceReview.unit] || confirmation.priceReview.unit)}. {t("Harga positif tetap dipertahankan.")}</p>}
            {confirmation.priceReview?.max_session_seconds && <p className="mt-2 text-sm">{t("Satu sesi, maksimal")} {confirmation.priceReview.max_session_seconds} {t("detik; biaya bervariasi menurut resolusi.")}</p>}
            {!!mutation.blockers.length && <div className="mt-3 space-y-2 text-red-700 dark:text-red-300"><h4 className="text-sm font-semibold">{t("Penghalang publikasi")}</h4><Blockers items={mutation.blockers} /><p className="text-sm">{t("Tutup dialog dan perbaiki penghalang sebelum mencoba lagi.")}</p></div>}
        </MediaActionDialog>}
    </section>;
}
