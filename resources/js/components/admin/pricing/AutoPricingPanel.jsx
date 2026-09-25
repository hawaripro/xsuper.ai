import { useCallback, useEffect, useRef, useState } from "react";
import { useLocale } from "../../../contexts/LocaleContext";
import { apiRequest, formatDateTime } from "../../../lib/api";
import { ErrorState, LoadingState } from "../../dashboard/AsyncState";
import MediaActionDialog from "../../MediaActionDialog";
import ProviderCostForm from "./ProviderCostForm";
import ModelCostEditor from "./ModelCostEditor";

const settingFields = [
    ["margin_pct", "Main margin (%)", 0, 95, 0.01], ["llm_margin_pct", "LLM margin override (%)", 0, 95, 0.01],
    ["buffer_pct", "Cost buffer (%)", 0, 100, 0.01], ["payment_fee_pct", "Payment fee (%)", 0, 20, 0.01],
    ["wallet_idr_per_usd", "Saldo AI sale rate (IDR / USD)", 1000, 100000, 1], ["default_cost_idr_per_usd", "Default landed rate (IDR / USD)", 1000, 100000, 1],
    ["chat_output_cap", "Chat output limit (tokens)", 256, 131072, 1],
];
const draftOf = (settings) => Object.fromEntries([...settingFields.map(([key]) => [key, settings[key] ?? ""]), ["round_tokens", Boolean(settings.round_tokens)]]);
const number = (value) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 4 }).format(Number(value));

function Price({ value, kind, t }) {
    if (!value) return <span className="text-slate-600 dark:text-slate-400">{t("Unavailable")}</span>;
    if (kind === "media") return <span>{value.token_cost == null ? "—" : number(value.token_cost)} {t("tokens")} / {value.unit}</span>;
    const rate = (key) => typeof value[key] === "object" ? value[key]?.usd : value[key];
    return <span className="space-y-1"><span className="block">{t("Input")}: ${rate("input_tokens") == null ? "—" : number(rate("input_tokens"))}</span>
        <span className="block">{t("Output")}: ${rate("output_tokens") == null ? "—" : number(rate("output_tokens"))}</span>
        {(rate("cache_read") != null || rate("cache_write") != null) && <span className="block text-xs">{t("Cache read / write")}: {rate("cache_read") == null ? "—" : `$${number(rate("cache_read"))}`} / {rate("cache_write") == null ? "—" : `$${number(rate("cache_write"))}`}</span>}
        <span className="block text-xs text-slate-600 dark:text-slate-400">{t("USD per 1M tokens")}</span></span>;
}

export default function AutoPricingPanel({ onApplied }) {
    const { t } = useLocale();
    const [data, setData] = useState(null);
    const [draft, setDraft] = useState(null);
    const [rows, setRows] = useState(null);
    const [filters, setFilters] = useState({ kind: "", status: "", q: "", page: 1 });
    const [state, setState] = useState({ busy: false, error: "", success: "" });
    const [loadError, setLoadError] = useState("");
    const [previewError, setPreviewError] = useState("");
    const [previewLoading, setPreviewLoading] = useState(false);
    const [editing, setEditing] = useState(null);
    const [confirmation, setConfirmation] = useState(false);
    const requests = useRef(0);
    const dirty = draft && data && JSON.stringify(draft) !== JSON.stringify(draftOf(data.settings));
    const load = useCallback(async (signal) => {
        const result = await apiRequest("/api/admin/pricing/auto", { signal });
        if (signal?.aborted) return;
        setData(result);
        setDraft(draftOf(result.settings));
        setLoadError("");
    }, []);
    const preview = useCallback(async (signal) => {
        const request = ++requests.current;
        setPreviewLoading(true);
        try {
            const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ""));
            const result = await apiRequest(`/api/admin/pricing/auto/preview?${query}`, { signal });
            if (request === requests.current && !signal?.aborted) { setRows(result); setPreviewError(""); }
        } catch (error) {
            if (error.name !== "AbortError" && request === requests.current) setPreviewError(error.message);
        } finally { if (request === requests.current && !signal?.aborted) setPreviewLoading(false); }
    }, [filters]);
    useEffect(() => {
        const controller = new AbortController();
        load(controller.signal).catch((error) => { if (error.name !== "AbortError") setLoadError(error.message); });
        return () => controller.abort();
    }, [load]);
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => preview(controller.signal), 200);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [preview]);
    const reload = async () => { await Promise.all([load(), preview()]); };
    const mutate = async (url, body, message, method = "POST") => {
        setState({ busy: true, error: "", success: "" });
        try {
            const result = await apiRequest(url, { method, body });
            await reload();
            setState({ busy: false, error: "", success: t(message) });
            return result;
        } catch (error) {
            setState({ busy: false, error: error.message, success: "" });
            return null;
        }
    };
    const saveSettings = async (event) => {
        event.preventDefault();
        const body = Object.fromEntries(settingFields.map(([key]) => [key, draft[key] === "" ? null : Number(draft[key])]));
        await mutate("/api/admin/pricing/auto/settings", { ...body, round_tokens: draft.round_tokens }, "Settings saved. Review the updated preview.", "PUT");
    };
    const apply = async () => {
        const result = await mutate("/api/admin/pricing/auto/apply", { confirm: true }, "Sale prices applied.");
        if (result) { setConfirmation(false); await onApplied?.(); }
    };
    const filter = (key, value) => setFilters((current) => ({ ...current, [key]: value, page: 1 }));
    if (!data) return loadError ? <ErrorState message={loadError} onRetry={() => load().catch((error) => setLoadError(error.message))} /> : <LoadingState label={t("Loading automatic pricing…")} />;
    return <div className="min-w-0 space-y-6">
        <section className="ui-card-flat p-4 sm:p-5" aria-labelledby="auto-pricing-heading">
            <h2 id="auto-pricing-heading" className="ui-section-title">{t("Cost-based automatic pricing")}</h2>
            <p className="mt-2 max-w-prose text-sm text-slate-600 dark:text-slate-400">{t("Refresh provider costs, review the preview, then apply sale prices. Unknown costs are never replaced with invented prices.")}</p>
            <form onSubmit={saveSettings} className="mt-5 space-y-4">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{settingFields.map(([key, label, min, max, step]) => <label key={key} className="text-sm" htmlFor={`pricing-${key}`}>{t(label)}
                    <input id={`pricing-${key}`} className="ui-input mt-1 min-h-10" type="number" min={min} max={max} step={step} required={key !== "llm_margin_pct"} disabled={state.busy}
                        placeholder={key === "llm_margin_pct" ? t("Follow main margin") : undefined} value={draft[key]} onChange={(event) => setDraft((current) => ({ ...current, [key]: event.target.value }))} />
                </label>)}</div>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={draft.round_tokens} disabled={state.busy} onChange={(event) => setDraft((current) => ({ ...current, round_tokens: event.target.checked }))} />{t("Round media token prices up to friendly steps")}</label>
                <div className="flex flex-wrap items-center gap-3"><button type="submit" className="ui-btn-secondary min-h-10" disabled={state.busy || !dirty}>{t("Save pricing settings")}</button>
                    <p className="text-sm text-slate-600 dark:text-slate-400">{t("Token revenue floor")}: {data.token_revenue_idr == null ? "—" : `Rp ${number(data.token_revenue_idr)}`} / {t("token")}</p></div>
            </form>
            {data.media_error && <p role="alert" className="ui-alert mt-3" data-tone="warn">{data.media_error}</p>}
        </section>
        <section className="ui-card-flat p-4 sm:p-5" aria-labelledby="provider-cost-heading">
            <h2 id="provider-cost-heading" className="ui-section-title">{t("Provider landed costs")}</h2>
            <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[560px] text-left text-sm"><thead className="text-xs text-slate-600 dark:text-slate-400"><tr>
                {["Provider", "IDR per billing unit", "Cost coverage", "Actions"].map((label) => <th key={label} scope="col" className="px-3 py-2 font-medium">{t(label)}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-200 dark:divide-white/10">{data.providers.map((provider) => <tr key={provider.id}>
                    <td className="px-3 py-3 align-top font-medium">{provider.name}<span className="mt-1 block text-xs text-slate-600 dark:text-slate-400">{provider.cost_currency?.toUpperCase()} · {provider.models_count} {t("models")}</span></td>
                    <td className="px-3 py-3 align-top tabular-nums">{provider.effective_idr_per_unit == null ? t("Unknown") : number(provider.effective_idr_per_unit)}<span className="mt-1 block max-w-52 text-xs text-slate-600 dark:text-slate-400">{provider.cost_note}</span></td>
                    <td className="px-3 py-3 align-top text-xs">{t("Known")}: {provider.status_counts.ok}<br />{t("Estimated")}: {provider.status_counts.estimate}<br />{t("Unknown")}: {provider.status_counts.unknown}</td>
                    <td className="px-3 py-3 align-top"><details><summary className="cursor-pointer font-medium">{t("Edit landed cost")}</summary><div className="mt-3 min-w-64 max-w-lg"><ProviderCostForm provider={provider} onSaved={reload} /></div></details>
                        <button type="button" className="ui-btn-secondary mt-3 min-h-10" disabled={state.busy || dirty} onClick={() => mutate("/api/admin/pricing/auto/refresh", { provider_id: provider.id }, "Provider costs refreshed.")}>{t("Refresh this provider")}</button></td>
                </tr>)}</tbody></table></div>
            {!data.providers.length && <p className="mt-4 text-sm">{t("Add a provider and sync its catalog to collect costs.")}</p>}
        </section>
        <section className="ui-card-flat p-4 sm:p-5" aria-labelledby="pricing-preview-heading">
            <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 id="pricing-preview-heading" className="ui-section-title">{t("Review sale prices")}</h2><p className="mt-1 text-xs text-slate-600 dark:text-slate-400">{t("Last applied")}: {data.settings.last_applied_at ? formatDateTime(data.settings.last_applied_at) : t("Never")}</p></div>
                <div className="flex flex-wrap gap-2"><button className="ui-btn-secondary min-h-11" type="button" disabled={state.busy || dirty} onClick={() => mutate("/api/admin/pricing/auto/refresh", {}, "Provider costs refreshed.")}>{t("Refresh cost data")}</button>
                    <button className="ui-btn-primary min-h-11" type="button" disabled={state.busy || dirty || previewLoading || !!previewError || !rows} onClick={() => setConfirmation(true)}>{t("Apply prices")}</button></div></div>
            {dirty && <p className="mt-3 text-sm text-amber-800 dark:text-amber-300">{t("Save settings before refreshing or applying prices.")}</p>}
            {(state.error || state.success) && <p role={state.error ? "alert" : "status"} className="ui-alert mt-4" data-tone={state.error ? "bad" : "good"}>{state.error || state.success}</p>}
            <div className="my-4 grid gap-3 sm:grid-cols-[1fr_160px_180px]">
                <label className="text-sm">{t("Search models")}<input className="ui-input mt-1 min-h-10" type="search" value={filters.q} onChange={(event) => filter("q", event.target.value)} /></label>
                <label className="text-sm">{t("Kind")}<select className="ui-input mt-1 min-h-10" value={filters.kind} onChange={(event) => filter("kind", event.target.value)}><option value="">{t("All kinds")}</option><option value="llm">LLM</option><option value="media">Media</option></select></label>
                <label className="text-sm">{t("Cost status")}<select className="ui-input mt-1 min-h-10" value={filters.status} onChange={(event) => filter("status", event.target.value)}>{[["", "All statuses"], ["ok", "Known"], ["estimate", "Estimated"], ["unknown", "Unknown"], ["locked", "Locked"]].map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</select></label>
            </div>
            {previewError && <ErrorState message={previewError} onRetry={() => preview()} />}
            {previewLoading && <p role="status" className="mb-3 text-sm">{t("Updating preview…")}</p>}
            <div className="overflow-x-auto" aria-busy={previewLoading}><table className="w-full min-w-[820px] text-left text-sm"><thead className="text-xs text-slate-600 dark:text-slate-400"><tr>{["Model and cost basis", "Current sale price", "New sale price", "Actions"].map((label) => <th scope="col" key={label} className="px-3 py-2 font-medium">{t(label)}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-200 dark:divide-white/10">{rows?.data.map((row) => <tr key={row.id}>
                    <td className="max-w-80 px-3 py-4 align-top"><span className="font-medium">{row.display_name}</span><span className="mt-1 block text-xs text-slate-600 dark:text-slate-400">{row.provider_name} · {row.source || "—"} · {t({ ok: "Known", estimate: "Estimated", unknown: "Unknown" }[row.status])}</span><p className="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-400">{row.basis_note || t("Refresh data or enter a verified manual cost.")}</p>{row.error && <p className="mt-2 text-xs text-red-700 dark:text-red-300">{row.error}</p>}</td>
                    <td className="px-3 py-4 align-top tabular-nums"><Price value={row.current_price} kind={row.kind} t={t} /></td>
                    <td className="px-3 py-4 align-top tabular-nums">{row.locked ? t("Locked — unchanged") : <Price value={row.new_price} kind={row.kind} t={t} />}{!row.locked && !row.new_price && <span className="mt-2 block text-xs">{t(row.kind === "llm" ? "API rates will be deactivated." : "Media price stays unchanged.")}</span>}</td>
                    <td className="px-3 py-4 align-top"><button className="ui-btn-secondary min-h-10" type="button" onClick={() => setEditing(row)} disabled={state.busy}>{t("Edit manual cost")}</button><label className="mt-3 flex items-center gap-2 text-xs"><input type="checkbox" checked={row.locked} disabled={state.busy} onChange={(event) => mutate(`/api/admin/pricing/auto/models/${row.id}`, { price_locked: event.target.checked }, "Price lock updated.", "PUT")} />{t("Lock sale price")}</label></td>
                </tr>)}</tbody></table></div>
            {rows?.data.length === 0 && <p className="py-6 text-sm text-slate-600 dark:text-slate-400">{t("No matching models. Change the filters or sync a provider catalog.")}</p>}
            {rows && <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm"><p>{rows.total} {t("models")} · {rows.current_page} / {rows.last_page}</p><div className="flex gap-2"><button className="ui-btn-secondary min-h-10" disabled={previewLoading || rows.current_page <= 1} onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}>{t("Previous")}</button><button className="ui-btn-secondary min-h-10" disabled={previewLoading || rows.current_page >= rows.last_page} onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}>{t("Next")}</button></div></div>}
        </section>
        {editing && <ModelCostEditor row={editing} onSaved={reload} onClose={() => setEditing(null)} />}
        {confirmation && <MediaActionDialog title={t("Apply all unlocked prices?")} description={t("This applies across the entire catalog, not only the current filters. Unknown chat costs deactivate API rates; unknown media costs leave existing prices unchanged. Package USD displays are recalculated.")}
            closeLabel={t("Cancel")} confirmLabel={t("Apply prices")} busyLabel={t("Applying…")} busy={state.busy} onClose={() => setConfirmation(false)} onConfirm={apply} error={state.error}>
            <dl className="grid grid-cols-2 gap-2 text-sm">{[["total", "Total models"], ["ok", "Known"], ["estimate", "Estimated"], ["unknown", "Unknown"], ["locked", "Locked"]].map(([key, label]) => <div key={key} className="flex justify-between gap-3"><dt>{t(label)}</dt><dd className="tabular-nums">{rows?.summary[key] ?? 0}</dd></div>)}</dl>
        </MediaActionDialog>}
    </div>;
}
