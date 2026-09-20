import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { useTheme } from "../../contexts/ThemeContext";
import { ErrorState, LoadingState } from "../../components/dashboard/AsyncState";
import BulkConfirmDialog from "../../components/dashboard/BulkConfirmDialog";
import ModelBulkTable from "../../components/dashboard/ModelBulkTable";
import { apiRequest } from "../../lib/api";
import TokenPackageTable from "../../components/dashboard/TokenPackageTable";
import RatePairSummary from "../../components/dashboard/RatePairSummary";

const emptyRate = { service: "api", meter: "input_tokens", model: "", label: "", unit: "1M tokens", price_idr: "", price_usd: "", is_active: false, sort_order: 0 };
const apiMeters = ["input_tokens", "output_tokens", "cache_read", "cache_write"];
const nullableNumber = (value) => value === "" || value == null ? null : Number(value);
const priceInRange = (value, min, max) => value !== "" && Number.isFinite(Number(value)) && Number(value) >= min && Number(value) <= max;
const GIB = 1024 ** 3;
const emptyPlan = { key: "", label: "", extra_gb: "", days: "", price_idr: "", price_usd: "", is_active: true, sort_order: 0 };
const validStorageKey = (value) => /^[a-z0-9_]+$/.test(value);

const TONES = {
    red: "from-red-500 to-rose-600",
    blue: "from-blue-500 to-indigo-500",
    violet: "from-violet-500 to-fuchsia-500",
    emerald: "from-emerald-500 to-teal-500",
    amber: "from-amber-500 to-orange-500",
    cyan: "from-cyan-500 to-sky-500",
};

function SectionHead({ dark, tone, icon, id, title, subtitle }) {
    return <div className={`flex items-start gap-3 border-b p-4 ${dark ? "border-white/[0.06]" : "border-gray-200/70"}`}>
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br ${TONES[tone]} text-white shadow-md`} aria-hidden="true">{icon}</span>
        <div className="min-w-0">
            <h2 id={id} className={`text-sm font-bold ${dark ? "text-white" : "text-slate-900"}`}>{title}</h2>
            {subtitle && <p className={`mt-0.5 text-xs leading-5 ${dark ? "text-gray-400" : "text-gray-500"}`}>{subtitle}</p>}
        </div>
    </div>;
}

function StoragePlansTab({ dark, t, plans, busy, setBusy, setStatus, onReload, loading, error }) {
    const [drafts, setDrafts] = useState({});
    const [creating, setCreating] = useState(emptyPlan);
    const [createError, setCreateError] = useState("");
    const card = dark ? "bg-white/[0.02] border-white/[0.08]" : "bg-white border-gray-200/80";
    const input = "ui-input min-h-10";
    const patch = (id, key, value) => setDrafts((current) => ({ ...current, [id]: { ...current[id], [key]: value } }));
    const bodyFor = (row) => ({ key: row.key, label: row.label, extra_gb: Number(row.extra_gb), days: Number(row.days), price_idr: Number(row.price_idr), price_usd: Number(row.price_usd), is_active: !!row.is_active, sort_order: Number(row.sort_order) || 0 });
    const savePlan = async (original) => {
        const row = { ...original, extra_gb: original.extra_bytes / GIB, ...drafts[original.id] };
        if (!validStorageKey(row.key)) { setStatus({ error: t("Key hanya boleh huruf kecil, angka, dan garis bawah."), success: "" }); return; }
        if (!String(row.label).trim()) { setStatus({ error: t("Label paket wajib diisi."), success: "" }); return; }
        setBusy(true);
        setStatus({ error: "", success: "" });
        try {
            await apiRequest(`/api/pricing/storage-plans/${original.id}`, { method: "PUT", body: bodyFor(row) });
            setDrafts((current) => { const next = { ...current }; delete next[original.id]; return next; });
            setStatus({ error: "", success: t("Paket penyimpanan tersimpan.") });
            await onReload();
        } catch (err) { setStatus({ error: err.message, success: "" }); }
        finally { setBusy(false); }
    };
    const deletePlan = async (id) => {
        setBusy(true);
        setStatus({ error: "", success: "" });
        try {
            await apiRequest(`/api/pricing/storage-plans/${id}`, { method: "DELETE" });
            setDrafts((current) => { const next = { ...current }; delete next[id]; return next; });
            setStatus({ error: "", success: t("Paket penyimpanan dihapus.") });
            await onReload();
        } catch (err) { setStatus({ error: err.message, success: "" }); }
        finally { setBusy(false); }
    };
    const createPlan = async (event) => {
        event.preventDefault();
        if (!validStorageKey(creating.key)) { setCreateError(t("Key hanya boleh huruf kecil, angka, dan garis bawah.")); return; }
        if (!creating.label.trim()) { setCreateError(t("Label paket wajib diisi.")); return; }
        setBusy(true);
        setCreateError("");
        setStatus({ error: "", success: "" });
        try {
            await apiRequest("/api/pricing/storage-plans", { method: "POST", body: bodyFor(creating) });
            setCreating(emptyPlan);
            setStatus({ error: "", success: t("Paket penyimpanan ditambahkan.") });
            await onReload();
        } catch (err) { setCreateError(err.message); setStatus({ error: err.message, success: "" }); }
        finally { setBusy(false); }
    };
    return <section className={`min-w-0 rounded-2xl border ${card} animate-fade-in-up motion-reduce:animate-none`} aria-labelledby="storage-plans-title">
        <SectionHead dark={dark} tone="cyan" id="storage-plans-title" title={t("Paket penyimpanan")} subtitle={t("Upgrade kuota Library berbasis durasi. Kuota dasar 500 MB per pengguna; paket menambah kapasitas selama masa berlaku.")} icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3" /><path d="M3 5v6c0 1.66 4 3 9 3s9-1.34 9-3V5" /><path d="M3 11v6c0 1.66 4 3 9 3s9-1.34 9-3v-6" /></svg>} />
        {error && <div className="p-4"><ErrorState message={error} onRetry={onReload} /></div>}
        {loading && !plans.length ? <div className="p-4"><LoadingState label={t("Memuat paket…")} /></div> : <>
            <div className="max-w-full overflow-x-auto"><table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs text-slate-600 dark:bg-white/5 dark:text-slate-400"><tr><th className="p-3">{t("Label")}</th><th className="p-3">Key</th><th className="p-3">{t("Ukuran (GB)")}</th><th className="p-3">{t("Masa berlaku (hari)")}</th><th className="p-3">IDR</th><th className="p-3">USD</th><th className="p-3">{t("Urutan")}</th><th className="p-3">{t("Aktif")}</th><th className="p-3">{t("Aksi")}</th></tr></thead>
                <tbody>{plans.map((original) => {
                    const row = { ...original, extra_gb: original.extra_bytes / GIB, ...drafts[original.id] };
                    const dirty = !!drafts[original.id];
                    return <tr key={original.id} className="border-t border-slate-200 align-top dark:border-white/10">
                        <td className="min-w-40 p-3"><input className={`${input} min-w-40`} value={row.label} disabled={busy} onChange={(event) => patch(original.id, "label", event.target.value)} aria-label={`${t("Label")} #${original.id}`} />{dirty && <span className="mt-1 block text-xs text-amber-700 dark:text-amber-300">{t("Belum disimpan")}</span>}</td>
                        <td className="p-3"><input className={`${input} min-w-32 font-mono`} value={row.key} disabled={busy} onChange={(event) => patch(original.id, "key", event.target.value)} aria-label={`Key #${original.id}`} /></td>
                        <td className="p-3"><input className={`${input} w-28`} type="number" min="0" step="0.001" value={row.extra_gb} disabled={busy} onChange={(event) => patch(original.id, "extra_gb", event.target.value)} aria-label={`${t("Ukuran (GB)")} #${original.id}`} /></td>
                        <td className="p-3"><input className={`${input} w-24`} type="number" min="0" step="1" value={row.days} disabled={busy} onChange={(event) => patch(original.id, "days", event.target.value)} aria-label={`${t("Masa berlaku (hari)")} #${original.id}`} /></td>
                        <td className="p-3"><input className={`${input} min-w-28`} type="number" min="0" step="1" value={row.price_idr} disabled={busy} onChange={(event) => patch(original.id, "price_idr", event.target.value)} aria-label={`IDR #${original.id}`} /></td>
                        <td className="p-3"><input className={`${input} min-w-24`} type="number" min="0" step="0.01" value={row.price_usd} disabled={busy} onChange={(event) => patch(original.id, "price_usd", event.target.value)} aria-label={`USD #${original.id}`} /></td>
                        <td className="p-3"><input className={`${input} w-20`} type="number" min="0" step="1" value={row.sort_order ?? 0} disabled={busy} onChange={(event) => patch(original.id, "sort_order", event.target.value)} aria-label={`${t("Urutan")} #${original.id}`} /></td>
                        <td className="p-3"><label className="flex min-h-10 items-center gap-2"><input type="checkbox" disabled={busy} checked={!!row.is_active} onChange={(event) => patch(original.id, "is_active", event.target.checked)} aria-label={`${t("Aktif")} #${original.id}`} />{t(row.is_active ? "Aktif" : "Nonaktif")}</label></td>
                        <td className="p-3"><div className="flex gap-2"><button type="button" className="ui-btn-primary" disabled={busy || !dirty} onClick={() => savePlan(original)}>{t("Simpan")}</button><button type="button" className="ui-btn-secondary" disabled={busy} onClick={() => deletePlan(original.id)}>{t("Hapus")}</button></div></td>
                    </tr>;
                })}</tbody>
            </table>{!plans.length && <p className="p-6 text-sm text-slate-600 dark:text-slate-400">{t("Belum ada paket penyimpanan. Tambahkan di bawah.")}</p>}</div>
            <form className="grid gap-3 border-t border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/10" onSubmit={createPlan}>
                <label className="text-xs font-medium">{t("Label")}<input className={`${input} mt-1`} required disabled={busy} value={creating.label} onChange={(event) => setCreating((current) => ({ ...current, label: event.target.value }))} /></label>
                <label className="text-xs font-medium">Key<input className={`${input} mt-1 font-mono`} required disabled={busy} placeholder="contoh_10gb" value={creating.key} onChange={(event) => setCreating((current) => ({ ...current, key: event.target.value }))} /></label>
                <label className="text-xs font-medium">{t("Ukuran (GB)")}<input className={`${input} mt-1`} type="number" min="0" step="0.001" required disabled={busy} value={creating.extra_gb} onChange={(event) => setCreating((current) => ({ ...current, extra_gb: event.target.value }))} /></label>
                <label className="text-xs font-medium">{t("Masa berlaku (hari)")}<input className={`${input} mt-1`} type="number" min="0" step="1" required disabled={busy} value={creating.days} onChange={(event) => setCreating((current) => ({ ...current, days: event.target.value }))} /></label>
                <label className="text-xs font-medium">IDR<input className={`${input} mt-1`} type="number" min="0" step="1" required disabled={busy} value={creating.price_idr} onChange={(event) => setCreating((current) => ({ ...current, price_idr: event.target.value }))} /></label>
                <label className="text-xs font-medium">USD<input className={`${input} mt-1`} type="number" min="0" step="0.01" required disabled={busy} value={creating.price_usd} onChange={(event) => setCreating((current) => ({ ...current, price_usd: event.target.value }))} /></label>
                <label className="text-xs font-medium">{t("Urutan")}<input className={`${input} mt-1`} type="number" min="0" step="1" disabled={busy} value={creating.sort_order} onChange={(event) => setCreating((current) => ({ ...current, sort_order: event.target.value }))} /></label>
                <label className="flex items-center gap-2 self-end text-xs font-medium"><input type="checkbox" className="h-4 w-4" disabled={busy} checked={creating.is_active} onChange={(event) => setCreating((current) => ({ ...current, is_active: event.target.checked }))} />{t("Aktif")}</label>
                <button type="submit" className="ui-btn-primary self-end" disabled={busy}>{t("Tambah paket")}</button>
                {createError && <p role="alert" className="text-xs text-red-600 dark:text-red-300 sm:col-span-2 lg:col-span-4">{createError}</p>}
            </form>
        </>}
    </section>;
}

export default function Settings() {
    const { t } = useLocale();
    const { theme } = useTheme();
    const dark = theme === "dark";
    const [catalog, setCatalog] = useState(null);
    const [modelCatalog, setModelCatalog] = useState(null);
    const [loadErrors, setLoadErrors] = useState({ pricing: "", models: "" });
    const [loading, setLoading] = useState({ pricing: true, models: true });
    const [draft, setDraft] = useState(emptyRate);
    const [rateDrafts, setRateDrafts] = useState({});
    const [durationDrafts, setDurationDrafts] = useState({});
    const [rateSelection, setRateSelection] = useState([]);
    const [durationSelection, setDurationSelection] = useState([]);
    const [search, setSearch] = useState("");
    const [serviceFilter, setServiceFilter] = useState("");
    const [activeFilter, setActiveFilter] = useState("");
    const [selectedOnly, setSelectedOnly] = useState(false);
    const [explicitIds, setExplicitIds] = useState("");
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [massField, setMassField] = useState("is_active");
    const [massValue, setMassValue] = useState("false");
    const [confirmation, setConfirmation] = useState(null);
    const [rowErrors, setRowErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState({ error: "", success: "" });
    const [autoForm, setAutoForm] = useState({ margin: 1, idr_per_usd: 16000, overwrite: false });
    const [tab, setTab] = useState("auto");
    const requests = useRef({ pricing: 0, models: 0 });

    const loadPricing = useCallback(async (signal) => {
        const request = ++requests.current.pricing;
        setLoading((current) => ({ ...current, pricing: true }));
        try {
            const data = await apiRequest("/api/pricing/settings", { signal });
            if (!signal?.aborted && request === requests.current.pricing) {
                setCatalog(data);
                setLoadErrors((current) => ({ ...current, pricing: "" }));
            }
        } catch (error) {
            if (error.name !== "AbortError" && request === requests.current.pricing) setLoadErrors((current) => ({ ...current, pricing: error.message }));
        } finally {
            if (!signal?.aborted && request === requests.current.pricing) setLoading((current) => ({ ...current, pricing: false }));
        }
    }, []);
    const loadModels = useCallback(async (signal) => {
        const request = ++requests.current.models;
        setLoading((current) => ({ ...current, models: true }));
        try {
            const data = await apiRequest("/api/admin/ai/catalog", { signal });
            if (!signal?.aborted && request === requests.current.models) {
                setModelCatalog(data);
                setLoadErrors((current) => ({ ...current, models: "" }));
            }
        } catch (error) {
            if (error.name !== "AbortError" && request === requests.current.models) setLoadErrors((current) => ({ ...current, models: error.message }));
        } finally {
            if (!signal?.aborted && request === requests.current.models) setLoading((current) => ({ ...current, models: false }));
        }
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        loadPricing(controller.signal);
        loadModels(controller.signal);
        return () => controller.abort();
    }, [loadPricing, loadModels]);
    useEffect(() => { setPage(1); }, [search, serviceFilter, activeFilter, selectedOnly, pageSize]);

    const rates = catalog?.usage_rates || [];
    const packages = Object.entries(catalog?.duration_packages || {});
    const rateById = useMemo(() => new Map(rates.map((rate) => [rate.id, rate])), [rates]);
    const selectedRateIds = useMemo(() => rateSelection.filter((id) => rateById.has(id)), [rateSelection, rateById]);
    const selectedSet = useMemo(() => new Set(selectedRateIds), [selectedRateIds]);
    const visibleRates = useMemo(() => {
        const needle = search.trim().toLowerCase();
        return rates.filter((rate) => (!serviceFilter || rate.service === serviceFilter)
            && (!activeFilter || String(rate.is_active) === activeFilter)
            && (!selectedOnly || selectedSet.has(rate.id))
            && (!needle || [rate.id, rate.model, rate.label, rate.meter].some((value) => String(value).toLowerCase().includes(needle))));
    }, [rates, search, serviceFilter, activeFilter, selectedOnly, selectedSet]);
    const lastPage = Math.max(1, Math.ceil(visibleRates.length / pageSize));
    const currentPage = Math.min(page, lastPage);
    const pageRows = visibleRates.slice((currentPage - 1) * pageSize, currentPage * pageSize);
    const allPageSelected = pageRows.length > 0 && pageRows.every((rate) => selectedSet.has(rate.id));
    const dirtyRateIds = Object.keys(rateDrafts).map(Number).filter((id) => rateById.has(id));
    const selectedDirtyRates = dirtyRateIds.filter((id) => selectedSet.has(id));
    const dirtyPackages = Object.keys(durationDrafts);
    const selectedDirtyPackages = dirtyPackages.filter((id) => durationSelection.includes(id));
    const card = dark ? "bg-white/[0.02] border-white/[0.08]" : "bg-white border-gray-200/80";
    const head = dark ? "text-white" : "text-slate-900";
    const muted = dark ? "text-gray-400" : "text-gray-500";
    const field = `w-full rounded-xl px-3 py-2.5 text-sm ${dark ? "bg-white/[0.04] border border-white/[0.08] text-white placeholder:text-gray-600" : "bg-white border border-gray-200 text-slate-900 placeholder:text-gray-400"} focus:outline-none focus:border-emerald-500/50 focus:ring-4 focus:ring-emerald-500/10`;
    const panel = `min-w-0 rounded-2xl border ${card} animate-fade-in-up`;
    const input = "ui-input min-h-10";
    const patchRate = (id, key, value) => setRateDrafts((current) => ({ ...current, [id]: { ...current[id], [key]: value } }));
    const patchDuration = (id, key, value) => setDurationDrafts((current) => ({ ...current, [id]: { ...current[id], [key]: value } }));
    const selectRates = (ids) => {
        if (ids.length > 200) { setStatus({ error: t("Pilih maksimal 200 baris dalam satu operasi."), success: "" }); return; }
        setRateSelection(ids);
    };
    const selectExplicit = () => {
        const values = explicitIds.split(/[\s,]+/).filter(Boolean);
        const ids = [...new Set(values.map(Number))];
        if (!ids.length || values.some((value) => !/^\d+$/.test(value)) || ids.some((id) => !rateById.has(id))) {
            setStatus({ error: t("Masukkan ID baris yang ada di tabel ini, dipisahkan koma."), success: "" });
            return;
        }
        selectRates(ids);
    };
    const applyMass = () => {
        setRateDrafts((current) => {
            const next = { ...current };
            selectedRateIds.forEach((id) => { next[id] = { ...next[id], [massField]: massField === "is_active" ? massValue === "true" : massValue }; });
            return next;
        });
        setStatus({ error: "", success: t("Perubahan diterapkan ke draf pilihan. Simpan untuk mempublikasikannya.") });
    };
    const prepareSave = (type, ids) => {
        if (!ids.length || ids.length > 200) { setStatus({ error: t("Pilih maksimal 200 baris dalam satu operasi."), success: "" }); return; }
        const errors = {};
        const items = ids.map((id) => {
            const changes = type === "rates" ? rateDrafts[id] : durationDrafts[id];
            const item = type === "rates" ? { id } : { package: id };
            for (const [field, value] of Object.entries(changes)) {
                item[field] = ["price_idr", "price_usd", "sort_order"].includes(field) ? nullableNumber(value) : value;
            }
            const row = { ...(type === "rates" ? rateById.get(id) : catalog.duration_packages[id]), ...item };
            const fields = {};
            if (type === "rates") {
                if (!row.label?.trim() || row.label.length > 160) fields.label = "Nama tarif wajib diisi, maksimal 160 karakter.";
                if (!row.unit?.trim() || row.unit.length > 40) fields.unit = "Unit wajib diisi, maksimal 40 karakter.";
                for (const [field, max] of [["price_idr", 999999999999], ["price_usd", 1000000]]) {
                    if (row[field] != null && !priceInRange(row[field], 0, max)) fields[field] = "Masukkan harga valid yang tidak negatif.";
                    if (row.is_active && row[field] == null) fields[field] = "Tarif aktif memerlukan harga IDR dan USD.";
                }
            } else {
                if (!priceInRange(row.price_idr, 1, 4294967295) || !Number.isInteger(Number(row.price_idr))) fields.price_idr = "Harga IDR paket harus bilangan bulat positif.";
                if (!priceInRange(row.price_usd, 0.01, 999999.99)) fields.price_usd = "Harga USD paket minimal 0.01.";
            }
            if (row.sort_order !== null && (!priceInRange(row.sort_order, 0, 65535) || !Number.isInteger(Number(row.sort_order)))) fields.sort_order = "Urutan harus bilangan bulat 0–65535.";
            if (Object.keys(fields).length) errors[id] = fields;
            return item;
        });
        setRowErrors({ [type]: errors });
        if (Object.keys(errors).length) { setStatus({ error: t("Periksa baris yang ditandai. Tidak ada perubahan tersimpan."), success: "" }); return; }
        setConfirmation({ type, ids, items });
    };
    const mutate = async () => {
        const operation = confirmation;
        setBusy(true);
        setStatus({ error: "", success: "" });
        try {
            const deleting = operation.type === "deleteRates";
            const path = operation.type === "durations" ? "/api/pricing/durations/bulk" : "/api/pricing/rates/bulk";
            await apiRequest(path, { method: deleting ? "DELETE" : "PATCH", body: deleting ? { ids: operation.ids, expected_count: operation.ids.length } : { items: operation.items } });
            if (operation.type === "durations") setDurationDrafts((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !operation.ids.includes(id))));
            else setRateDrafts((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !operation.ids.includes(Number(id)))));
            if (deleting) setRateSelection((current) => current.filter((id) => !operation.ids.includes(id)));
            setConfirmation(null);
            setRowErrors({});
            setStatus({ error: "", success: `${operation.ids.length} ${t(deleting ? "baris dihapus. Riwayat penggunaan dipertahankan." : "baris tersimpan.")}` });
            await Promise.all([loadPricing(), loadModels()]);
        } catch (error) {
            const fields = {};
            Object.entries(error.details?.errors || {}).forEach(([key, messages]) => {
                const match = key.match(/^items\.(\d+)(?:\.(.+))?$/);
                if (match && operation.ids[Number(match[1])] != null) {
                    const id = operation.ids[Number(match[1])];
                    fields[id] = { ...fields[id], [match[2] || "row"]: messages };
                }
            });
            setRowErrors({ [operation.type]: fields });
            setConfirmation(null);
            setStatus({ error: error.message || t("Perubahan gagal disimpan. Draf tetap tersedia."), success: "" });
        } finally { setBusy(false); }
    };
    const createRate = async (event) => {
        event.preventDefault();
        setBusy(true);
        setStatus({ error: "", success: "" });
        try {
            await apiRequest("/api/pricing/rates", { method: "POST", body: { ...draft, price_idr: nullableNumber(draft.price_idr), price_usd: nullableNumber(draft.price_usd), sort_order: Number(draft.sort_order) } });
            setDraft(emptyRate);
            setRowErrors((current) => ({ ...current, create: {} }));
            setStatus({ error: "", success: t("Tarif draf ditambahkan. Publikasikan setelah pasangan harga lengkap.") });
            await loadPricing();
        } catch (error) {
            setRowErrors((current) => ({ ...current, create: error.details?.errors || {} }));
            setStatus({ error: error.message, success: "" });
        } finally { setBusy(false); }
    };
    const autoPrice = async () => {
        setBusy(true);
        setStatus({ error: "", success: "" });
        try {
            const data = await apiRequest("/api/pricing/rates/auto", { method: "POST", body: { margin: Number(autoForm.margin), idr_per_usd: Number(autoForm.idr_per_usd), overwrite: autoForm.overwrite } });
            setStatus({ error: "", success: `${t("Harga otomatis diterapkan ke")} ${data.updated_count} ${t("tarif model.")}` });
            await Promise.all([loadPricing(), loadModels()]);
        } catch (error) { setStatus({ error: error.message, success: "" }); }
        finally { setBusy(false); }
    };
    const fieldError = (type, id, field) => rowErrors[type]?.[id]?.[field] && <span className="mt-1 block max-w-52 whitespace-normal text-xs text-red-600 dark:text-red-300">{t([rowErrors[type][id][field]].flat()[0])}</span>;
    const tabs = [
        ["auto", "Auto-harga"],
        ["durations", "Paket durasi"],
        ["payg", "Tarif PAYG"],
        ["tokens", "Paket token"],
        ["media", "Media & token"],
        ["storage", "Penyimpanan"],
    ];

    return <div className="mx-auto min-w-0 max-w-7xl space-y-6 p-4 lg:p-6 [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50" style={{ fontSize: "90%" }}>
        <header className={`relative overflow-hidden rounded-2xl border p-5 lg:p-6 ${dark ? "border-white/[0.08] bg-gradient-to-br from-red-500/[0.08] via-transparent to-violet-500/[0.06]" : "border-gray-200/80 bg-gradient-to-br from-red-50 via-white to-violet-50"} animate-fade-in-up`}>
            <div className="pointer-events-none absolute -right-10 -top-12 h-44 w-44 rounded-full bg-gradient-to-br from-red-500 to-rose-600 opacity-10 blur-3xl" aria-hidden="true" />
            <div className="pointer-events-none absolute -bottom-16 right-1/4 h-40 w-40 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 opacity-[0.08] blur-3xl" aria-hidden="true" />
            <div className="relative flex flex-wrap items-center gap-4">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 text-white shadow-lg shadow-red-500/25 animate-pop-in">
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" /><line x1="1" y1="10" x2="23" y2="10" /></svg>
                </span>
                <div className="min-w-0">
                    <h1 className={`text-2xl font-bold tracking-tight ${head}`}>Pricing &amp; Billing</h1>
                    <p className={`text-sm ${muted}`}>{t("Ubah harga paket, tarif PAYG, dan publikasi tanpa rebuild frontend.")}</p>
                </div>
            </div>
        </header>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[
                { label: t("Paket durasi"), value: packages.length, tone: "blue", icon: <><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 14" /></> },
                { label: t("Tarif PAYG"), value: rates.length, tone: "violet", icon: <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" /> },
                { label: t("Dipublikasikan"), value: rates.filter((rate) => rate.is_active).length, tone: "emerald", icon: <><circle cx="12" cy="12" r="9" /><polyline points="8.5 12 11 14.5 15.5 9.5" /></> },
                { label: t("Draf belum disimpan"), value: dirtyRateIds.length + dirtyPackages.length, tone: "amber", icon: <><path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" /></> },
            ].map((stat, index) => <div key={stat.label} className={`relative overflow-hidden rounded-2xl border p-4 ${card} transition-all hover:-translate-y-0.5 animate-fade-in-up [animation-fill-mode:both]`} style={{ animationDelay: `${index * 60}ms` }}>
                <div className={`pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-gradient-to-br ${TONES[stat.tone]} opacity-10 blur-2xl`} aria-hidden="true" />
                <span className={`relative flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br ${TONES[stat.tone]} text-white shadow-md`}><svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">{stat.icon}</svg></span>
                <div className={`relative mt-3 text-2xl font-bold tabular-nums ${head}`}>{stat.value}</div>
                <div className={`relative text-xs ${muted}`}>{stat.label}</div>
            </div>)}
        </div>
        {(status.error || status.success) && <p role={status.error ? "alert" : "status"} className={`rounded-xl border p-3 text-sm ${status.error ? "border-red-500/20 text-red-700 dark:text-red-300" : "border-emerald-500/20 text-emerald-700 dark:text-emerald-300"}`}>{status.error || status.success}</p>}
        <div className="pw-tabs" role="tablist" aria-label={t("Bagian harga")}>
            {tabs.map(([id, tabLabel]) => <button key={id} type="button" role="tab" aria-selected={tab === id} className="pw-tab" onClick={() => setTab(id)}>{t(tabLabel)}</button>)}
        </div>
        {tab === "auto" && (
        <section className={`relative overflow-hidden rounded-2xl border p-5 ${dark ? "border-emerald-500/25 bg-gradient-to-br from-emerald-500/[0.06] via-transparent to-teal-500/[0.05]" : "border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-teal-50"} animate-fade-in-up`} aria-labelledby="auto-price-title">
            <div className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 opacity-10 blur-3xl" aria-hidden="true" />
            <div className="pointer-events-none absolute -bottom-12 left-1/4 h-32 w-32 rounded-full bg-gradient-to-br from-cyan-400 to-emerald-500 opacity-[0.07] blur-3xl" aria-hidden="true" />
            <div className="relative flex flex-wrap items-start gap-3">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 text-white shadow-lg shadow-emerald-500/25 animate-pop-in">
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M13 3l2.4 5.6L21 11l-5.6 2.4L13 19l-2.4-5.6L5 11l5.6-2.4z" /><path d="M5 3v3" /><path d="M3.5 4.5h3" /><path d="M18 17v3" /><path d="M16.5 18.5h3" /></svg>
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id="auto-price-title" className={`text-sm font-bold ${head}`}>{t("Harga otomatis per tier")}</h2>
                        <span className="rounded-md bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-500">{t("Otomatis")}</span>
                    </div>
                    <p className={`mt-0.5 text-xs leading-5 ${muted}`}>{t("Admin tidak perlu lagi mengisi harga input/output per model secara manual. Harga dibuat otomatis untuk semua model chat: harga = dasar per tier × margin.")}</p>
                </div>
            </div>
            <div className="relative mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,150px)_minmax(0,180px)_1fr_auto] lg:items-end">
                <div>
                    <label htmlFor="auto-margin" className={`mb-1.5 block text-xs font-bold uppercase tracking-wide ${muted}`}>{t("Margin (×)")}</label>
                    <input id="auto-margin" type="number" min="0.1" max="100" step="0.1" className={field} value={autoForm.margin} disabled={busy} onChange={(event) => setAutoForm((current) => ({ ...current, margin: event.target.value }))} />
                </div>
                <div>
                    <label htmlFor="auto-idr" className={`mb-1.5 block text-xs font-bold uppercase tracking-wide ${muted}`}>{t("IDR per USD")}</label>
                    <input id="auto-idr" type="number" min="1" step="1" className={field} value={autoForm.idr_per_usd} disabled={busy} onChange={(event) => setAutoForm((current) => ({ ...current, idr_per_usd: event.target.value }))} />
                </div>
                <label className={`flex items-center gap-2 rounded-xl border px-3 py-2.5 text-xs font-medium ${dark ? "border-white/[0.08] bg-white/[0.03] text-gray-300" : "border-gray-200 bg-white/70 text-slate-700"}`}>
                    <input type="checkbox" className="h-4 w-4 accent-emerald-500" checked={autoForm.overwrite} disabled={busy} onChange={(event) => setAutoForm((current) => ({ ...current, overwrite: event.target.checked }))} />
                    {t("Timpa tarif yang sudah ada")}
                </label>
                <button type="button" onClick={autoPrice} disabled={busy} className="ui-btn-primary min-h-11 px-5">{busy ? t("Menerapkan…") : t("Terapkan harga otomatis")}</button>
            </div>
            <p className={`relative mt-3 flex items-start gap-2 text-[11px] leading-5 ${muted}`}>
                <svg className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><line x1="12" y1="11" x2="12" y2="16" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
                {t("Tier standar: input 0.15 / output 0.60 USD per 1 juta token. Tier MAX: input 3.00 / output 15.00 USD per 1 juta token.")}
            </p>
        </section>
        )}
        {tab === "durations" && (<>
        {loadErrors.pricing && <ErrorState message={loadErrors.pricing} onRetry={() => loadPricing()} />}
        {loading.pricing && !catalog ? <LoadingState label={t("Memuat pricing…")} /> : catalog && (
            <section className={panel} aria-labelledby="duration-prices-title">
                <SectionHead dark={dark} tone="blue" id="duration-prices-title" title={t("Paket durasi")} subtitle={t("Minimal satu paket tetap aktif. Kredit token Deposit dikelola terpisah.")} icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" /></svg>} />
                <div className="max-w-full overflow-x-auto"><table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs text-slate-600 dark:bg-white/5 dark:text-slate-400"><tr><th className="p-3"><input type="checkbox" aria-label={t("Pilih semua paket di tabel")} checked={packages.length > 0 && packages.every(([id]) => durationSelection.includes(id))} disabled={busy} onChange={(event) => setDurationSelection(event.target.checked ? packages.map(([id]) => id) : [])} /></th><th className="p-3">{t("Paket")}</th><th className="p-3">IDR</th><th className="p-3">USD</th><th className="p-3">{t("Urutan")}</th><th className="p-3">{t("Aktif")}</th></tr></thead>
                    <tbody>{packages.map(([id, original]) => {
                        const row = { ...original, ...durationDrafts[id] };
                        return <tr key={id} className="border-t border-slate-200 align-top dark:border-white/10">
                            <td className="p-3"><input type="checkbox" aria-label={`${t("Pilih paket")} ${id}`} disabled={busy} checked={durationSelection.includes(id)} onChange={(event) => setDurationSelection((current) => event.target.checked ? [...current, id] : current.filter((key) => key !== id))} /></td>
                            <td className="min-w-40 p-3"><strong>{t(row.label)}</strong><span className="block text-xs text-slate-500 dark:text-slate-400">{row.days} {t("hari")} · {id}</span>{durationDrafts[id] && <span className="mt-1 block text-xs text-amber-700 dark:text-amber-300">{t("Belum disimpan")}</span>}{fieldError("durations", id, "row")}</td>
                            <td className="p-3"><input aria-label={`IDR ${id}`} className={`${input} min-w-32`} type="number" min="1" step="1" value={row.price_idr} disabled={busy} aria-invalid={!!rowErrors.durations?.[id]?.price_idr} onChange={(event) => patchDuration(id, "price_idr", event.target.value)} />{fieldError("durations", id, "price_idr")}</td>
                            <td className="p-3"><input aria-label={`USD ${id}`} className={`${input} min-w-28`} type="number" min="0.01" step="0.01" value={row.price_usd} disabled={busy} aria-invalid={!!rowErrors.durations?.[id]?.price_usd} onChange={(event) => patchDuration(id, "price_usd", event.target.value)} />{fieldError("durations", id, "price_usd")}</td>
                            <td className="p-3"><input aria-label={`${t("Urutan")} ${id}`} className={`${input} w-24`} type="number" min="0" max="65535" step="1" value={row.sort_order} disabled={busy} onChange={(event) => patchDuration(id, "sort_order", event.target.value)} />{fieldError("durations", id, "sort_order")}</td>
                            <td className="p-3"><label className="flex min-h-10 items-center gap-2"><input type="checkbox" disabled={busy} checked={!!row.is_active} onChange={(event) => patchDuration(id, "is_active", event.target.checked)} aria-label={`${t("Aktif")} ${id}`} />{t(row.is_active ? "Aktif" : "Nonaktif")}</label></td>
                        </tr>;
                    })}</tbody>
                </table></div>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 dark:border-white/10"><span className="text-xs">{durationSelection.length} {t("dipilih")} · {dirtyPackages.length} {t("draf berubah")}</span><div className="flex flex-wrap gap-2"><button className="ui-btn-secondary" disabled={busy || !dirtyPackages.length} onClick={() => { setDurationDrafts({}); setRowErrors((current) => ({ ...current, durations: {} })); }}>{t("Buang draf")}</button><button className="ui-btn-secondary" disabled={busy || !selectedDirtyPackages.length} onClick={() => prepareSave("durations", selectedDirtyPackages)}>{t("Simpan pilihan")} ({selectedDirtyPackages.length})</button><button className="ui-btn-primary" disabled={busy || !dirtyPackages.length} onClick={() => prepareSave("durations", dirtyPackages)}>{t("Simpan paket")} ({dirtyPackages.length})</button></div></div>
            </section>
        )}
        </>)}
        {tab === "payg" && (<>
        {loadErrors.pricing && <ErrorState message={loadErrors.pricing} onRetry={() => loadPricing()} />}
        {loading.pricing && !catalog ? <LoadingState label={t("Memuat pricing…")} /> : catalog && (
            <section className={panel} aria-labelledby="usage-prices-title">
                <SectionHead dark={dark} tone="violet" id="usage-prices-title" title={t("Tarif pay as you go")} subtitle={t("API: input, output, cache read/write per 1 juta token. Image/video: per hasil. Kosong bukan harga nol; pasangan API dipublikasikan bersama.")} icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12" /></svg>} />
                <RatePairSummary rates={rates} onFocusModel={(model) => { setSearch(model); setPage(1); }} />
                <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-white/10">
                    <label className="min-w-48 flex-1 text-xs font-medium">{t("Cari tarif")}<input type="search" className={`${input} mt-1`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t("ID, model, nama, atau meter")} /></label>
                    <label className="text-xs font-medium">{t("Layanan")}<select className={`${input} mt-1`} value={serviceFilter} onChange={(event) => setServiceFilter(event.target.value)}><option value="">{t("Semua layanan")}</option>{["api", "image", "video", "audio"].map((service) => <option key={service}>{service}</option>)}</select></label>
                    <label className="text-xs font-medium">{t("Publikasi")}<select className={`${input} mt-1`} value={activeFilter} onChange={(event) => setActiveFilter(event.target.value)}><option value="">{t("Semua status")}</option><option value="true">{t("Dipublikasikan")}</option><option value="false">{t("Draf")}</option></select></label>
                    <label className="flex min-h-10 items-center gap-2 text-xs"><input type="checkbox" checked={selectedOnly} onChange={(event) => setSelectedOnly(event.target.checked)} />{t("Tampilkan pilihan saja")}</label>
                </div>
                <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-white/10"><label className="min-w-44 flex-1 text-xs font-medium">{t("Pilih ID baris secara eksplisit")}<input className={`${input} mt-1`} placeholder="12, 24, 31" value={explicitIds} disabled={busy} onChange={(event) => setExplicitIds(event.target.value)} /></label><button className="ui-btn-secondary" disabled={busy || !explicitIds.trim()} onClick={selectExplicit}>{t("Gunakan ID ini")}</button></div>
                {selectedRateIds.length > 0 && <div className="flex flex-wrap items-end gap-3 bg-slate-50 p-4 dark:bg-white/5"><label className="text-xs font-medium">{t("Ubah pilihan sekaligus")}<select className={`${input} mt-1`} value={massField} disabled={busy} onChange={(event) => { setMassField(event.target.value); setMassValue(event.target.value === "is_active" ? "false" : ""); }}><option value="is_active">{t("Publikasi")}</option><option value="price_idr">IDR</option><option value="price_usd">USD</option><option value="sort_order">{t("Urutan")}</option></select></label><label className="min-w-32 flex-1 text-xs font-medium">{t("Nilai baru")}{massField === "is_active" ? <select className={`${input} mt-1`} value={massValue} disabled={busy} onChange={(event) => setMassValue(event.target.value)}><option value="false">{t("Draf")}</option><option value="true">{t("Dipublikasikan")}</option></select> : <input className={`${input} mt-1`} type="number" min="0" value={massValue} disabled={busy} onChange={(event) => setMassValue(event.target.value)} />}</label><button className="ui-btn-secondary" disabled={busy} onClick={applyMass}>{t("Terapkan ke draf")} ({selectedRateIds.length})</button></div>}
                <div className="max-w-full overflow-x-auto"><table className="w-full text-left text-xs">
                    <thead className="bg-slate-50 text-slate-600 dark:bg-white/5 dark:text-slate-400"><tr><th className="p-3"><input type="checkbox" aria-label={t("Pilih halaman tarif ini")} checked={allPageSelected} disabled={busy || !pageRows.length} onChange={() => selectRates(allPageSelected ? selectedRateIds.filter((id) => !pageRows.some((rate) => rate.id === id)) : [...new Set([...selectedRateIds, ...pageRows.map((rate) => rate.id)])])} /></th><th className="p-3">{t("Model / meter")}</th><th className="p-3">{t("Nama / unit")}</th><th className="p-3">IDR</th><th className="p-3">USD</th><th className="p-3">{t("Urutan")}</th><th className="p-3">{t("Publikasi")}</th></tr></thead>
                    <tbody>{pageRows.map((original) => {
                        const rate = { ...original, ...rateDrafts[original.id] };
                        const errors = rowErrors.rates?.[rate.id] || {};
                        return <tr key={rate.id} className={`border-t border-slate-200 align-top dark:border-white/10 ${selectedSet.has(rate.id) ? "bg-red-50/60 dark:bg-red-500/5" : ""}`}>
                            <td className="p-3"><input type="checkbox" aria-label={`${t("Pilih tarif")} #${rate.id}`} disabled={busy} checked={selectedSet.has(rate.id)} onChange={() => selectRates(selectedSet.has(rate.id) ? selectedRateIds.filter((id) => id !== rate.id) : [...selectedRateIds, rate.id])} /></td>
                            <td className="min-w-48 max-w-64 p-3"><strong className="block break-words">{rate.model}</strong><span className="mt-1 block font-mono">#{rate.id} · {rate.service} / {rate.meter}</span>{rateDrafts[rate.id] && <span className="mt-2 block text-amber-700 dark:text-amber-300">{t("Belum disimpan")}</span>}{fieldError("rates", rate.id, "rates")}{fieldError("rates", rate.id, "row")}</td>
                            <td className="min-w-48 p-3"><input className={input} aria-label={`${t("Nama tarif")} #${rate.id}`} maxLength={160} value={rate.label} disabled={busy} aria-invalid={!!errors.label} onChange={(event) => patchRate(rate.id, "label", event.target.value)} />{fieldError("rates", rate.id, "label")}<input className={`${input} mt-2`} aria-label={`${t("Unit")} #${rate.id}`} maxLength={40} value={rate.unit} disabled={busy} aria-invalid={!!errors.unit} onChange={(event) => patchRate(rate.id, "unit", event.target.value)} />{fieldError("rates", rate.id, "unit")}</td>
                            <td className="p-3"><input className={`${input} min-w-32 tabular-nums`} aria-label={`IDR #${rate.id}`} type="number" min="0" step="0.000001" value={rate.price_idr ?? ""} placeholder={t("Belum diatur")} disabled={busy} aria-invalid={!!errors.price_idr} onChange={(event) => patchRate(rate.id, "price_idr", event.target.value)} />{fieldError("rates", rate.id, "price_idr")}</td>
                            <td className="p-3"><input className={`${input} min-w-32 tabular-nums`} aria-label={`USD #${rate.id}`} type="number" min="0" step="0.00000001" value={rate.price_usd ?? ""} placeholder={t("Belum diatur")} disabled={busy} aria-invalid={!!errors.price_usd} onChange={(event) => patchRate(rate.id, "price_usd", event.target.value)} />{fieldError("rates", rate.id, "price_usd")}</td>
                            <td className="p-3"><input className={`${input} w-24`} aria-label={`${t("Urutan")} #${rate.id}`} type="number" min="0" max="65535" step="1" value={rate.sort_order ?? 0} disabled={busy} aria-invalid={!!errors.sort_order} onChange={(event) => patchRate(rate.id, "sort_order", event.target.value)} />{fieldError("rates", rate.id, "sort_order")}</td>
                            <td className="min-w-40 p-3"><label className="flex min-h-10 items-center gap-2"><input type="checkbox" aria-label={`${t("Publikasikan tarif")} #${rate.id}`} disabled={busy} checked={!!rate.is_active} onChange={(event) => patchRate(rate.id, "is_active", event.target.checked)} />{t(rate.is_active ? "Dipublikasikan" : "Draf")}</label>{fieldError("rates", rate.id, "is_active")}</td>
                        </tr>;
                    })}</tbody>
                </table>{!pageRows.length && <p className="p-6 text-sm text-slate-600 dark:text-slate-400">{t(rates.length ? "Tidak ada tarif yang cocok. Ubah pencarian atau filter." : "Belum ada tarif. Tambahkan tarif draf di bawah.")}</p>}</div>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-xs dark:border-white/10"><label>{t("Baris per halaman")} <select className={`${input} ml-2 w-auto`} value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))}>{[25, 50, 100].map((size) => <option key={size}>{size}</option>)}</select></label><span>{visibleRates.length} {t("tarif")} · {t("Halaman")} {currentPage}/{lastPage}</span><div className="flex gap-2"><button className="ui-btn-secondary" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>{t("Sebelumnya")}</button><button className="ui-btn-secondary" disabled={currentPage >= lastPage} onClick={() => setPage(currentPage + 1)}>{t("Berikutnya")}</button></div></div>
                <div className="sticky bottom-0 z-20 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900"><p className="text-xs">{selectedRateIds.length} {t("dipilih")} ({selectedRateIds.filter((id) => !pageRows.some((rate) => rate.id === id)).length} {t("di luar halaman ini")}) · {dirtyRateIds.length} {t("draf berubah")}</p><div className="flex flex-wrap gap-2"><button className="ui-btn-secondary" disabled={busy || !selectedRateIds.length} onClick={() => setRateSelection([])}>{t("Batalkan pilihan")}</button><button className="ui-btn-secondary" disabled={busy || !dirtyRateIds.length} onClick={() => { setRateDrafts({}); setRowErrors((current) => ({ ...current, rates: {} })); }}>{t("Buang draf")}</button><button className="ui-btn-secondary text-red-700 dark:text-red-300" disabled={busy || !selectedRateIds.length} onClick={() => setConfirmation({ type: "deleteRates", ids: [...selectedRateIds] })}>{t("Hapus pilihan")} ({selectedRateIds.length})</button><button className="ui-btn-secondary" disabled={busy || !selectedDirtyRates.length} onClick={() => prepareSave("rates", selectedDirtyRates)}>{t("Simpan pilihan")} ({selectedDirtyRates.length})</button><button className="ui-btn-primary" disabled={busy || !dirtyRateIds.length} onClick={() => prepareSave("rates", dirtyRateIds)}>{t("Simpan tarif")} ({dirtyRateIds.length})</button></div></div>
                <details className="border-t border-slate-200 p-4 dark:border-white/10"><summary className="cursor-pointer text-sm font-semibold">{t("Tambah tarif draf")}</summary><form className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" onSubmit={createRate}>
                    <label className="text-xs font-medium">{t("Layanan")}<select className={`${input} mt-1`} disabled={busy} value={draft.service} onChange={(event) => setDraft((current) => ({ ...current, service: event.target.value, meter: event.target.value === "api" ? "input_tokens" : "unit", unit: event.target.value === "api" ? "1M tokens" : event.target.value }))}>{["api", "image", "video", "audio"].map((service) => <option key={service}>{service}</option>)}</select></label>
                    <label className="text-xs font-medium">{t("Meter")}<select className={`${input} mt-1`} disabled={busy} value={draft.meter} onChange={(event) => setDraft((current) => ({ ...current, meter: event.target.value }))}>{(draft.service === "api" ? apiMeters : ["unit"]).map((meter) => <option key={meter}>{meter}</option>)}</select></label>
                    <label className="text-xs font-medium">{t("ID model")}<input className={`${input} mt-1`} required maxLength={120} disabled={busy} value={draft.model} onChange={(event) => setDraft((current) => ({ ...current, model: event.target.value }))} /></label>
                    <label className="text-xs font-medium">{t("Nama tarif")}<input className={`${input} mt-1`} required maxLength={160} disabled={busy} value={draft.label} onChange={(event) => setDraft((current) => ({ ...current, label: event.target.value }))} /></label>
                    <label className="text-xs font-medium">{t("Unit")}<input className={`${input} mt-1`} required maxLength={40} disabled={busy} value={draft.unit} onChange={(event) => setDraft((current) => ({ ...current, unit: event.target.value }))} /></label>
                    <label className="text-xs font-medium">IDR<input className={`${input} mt-1`} type="number" min="0" step="0.000001" disabled={busy} value={draft.price_idr} onChange={(event) => setDraft((current) => ({ ...current, price_idr: event.target.value }))} /></label>
                    <label className="text-xs font-medium">USD<input className={`${input} mt-1`} type="number" min="0" step="0.00000001" disabled={busy} value={draft.price_usd} onChange={(event) => setDraft((current) => ({ ...current, price_usd: event.target.value }))} /></label>
                    <button type="submit" className="ui-btn-primary self-end" disabled={busy}>{t("Tambah tarif")}</button>
                    {rowErrors.create && Object.keys(rowErrors.create).length > 0 && <p role="alert" className="text-xs text-red-600 dark:text-red-300 sm:col-span-2 lg:col-span-4">{t(Object.values(rowErrors.create).flat()[0])}</p>}
                </form></details>
            </section>
        )}
        </>)}
        {tab === "tokens" && <TokenPackageTable />}
        {tab === "media" && (
        <section className={panel} aria-labelledby="media-token-prices-title">
            <SectionHead dark={dark} tone="cyan" id="media-token-prices-title" title={t("Harga token & konfigurasi media")} subtitle={t("Per model, lintas provider. Semua akun, termasuk admin, membayar token per hasil × jumlah. Ini terpisah dari wallet PAYG dan paket Deposit.")} icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="M21 15l-5-5L5 21" /></svg>} />
            {loadErrors.models && <div className="p-4"><ErrorState message={loadErrors.models} onRetry={() => loadModels()} /></div>}
            {loading.models && !modelCatalog ? <div className="p-4"><LoadingState label={t("Memuat model…")} /></div> : modelCatalog && <ModelBulkTable models={modelCatalog.models} providers={modelCatalog.providers} mediaOnly disabled={busy} onRefresh={async () => { await Promise.all([loadModels(), loadPricing()]); }} />}
        </section>
        )}
        {tab === "storage" && <StoragePlansTab dark={dark} t={t} plans={catalog?.storage_plans || []} busy={busy} setBusy={setBusy} setStatus={setStatus} onReload={loadPricing} loading={loading.pricing} error={loadErrors.pricing} />}
        {confirmation && <BulkConfirmDialog title={t(confirmation.type === "deleteRates" ? "Hapus tarif terpilih?" : confirmation.type === "durations" ? "Simpan paket durasi?" : "Simpan tarif terpilih?")} count={confirmation.ids.length} rows={confirmation.ids.map((id) => ({ id, label: confirmation.type === "durations" ? catalog.duration_packages[id]?.label : `${rateById.get(id)?.model} · ${rateById.get(id)?.meter}` }))} description={t(confirmation.type === "deleteRates" ? "Hanya tarif dengan ID ini yang dihapus. Jika pasangan API tidak lengkap, tarif saudara dinonaktifkan. Riwayat dan saldo tidak dihapus." : confirmation.type === "durations" ? "Simpan seluruh perubahan paket dalam satu transaksi. Minimal satu paket harus tetap aktif." : "Simpan seluruh baris dalam satu transaksi. Publikasi input/output API mengikuti pasangan; seluruh harga aktif wajib lengkap.")} destructive={confirmation.type === "deleteRates"} busy={busy} onCancel={() => setConfirmation(null)} onConfirm={mutate} />}
    </div>;
}
