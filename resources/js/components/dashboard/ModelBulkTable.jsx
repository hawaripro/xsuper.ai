import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";
import MediaActionDialog from "../MediaActionDialog";
import BulkConfirmDialog from "./BulkConfirmDialog";
import GenerationConfigFields, { generationConfigDraft, parseGenerationConfig } from "./GenerationConfigFields";
import CatalogRevisionPanel, { capabilityStatuses, CapabilityStatus } from "./CatalogRevisionPanel";
import { LoadingState, ErrorState } from "./AsyncState";

const pageSizeOptions = [25, 50, 100];
const mediaCategories = ["image", "video", "audio", "avatar", "model3d", "other"];
const isMediaModel = (model) => model.schema_managed || mediaCategories.includes(model.category);
const isUnpriced = (model) => isMediaModel(model)
    ? !Number(model.token_cost)
    : !Number(model.rates?.input_tokens?.price_usd) && !Number(model.rates?.output_tokens?.price_usd);
const integer = (value, min, max) => value !== "" && Number.isInteger(Number(value)) && Number(value) >= min && Number(value) <= max;
// Bulk catalog review prices each model in its own catalog unit; the server rejects any other unit.
const catalogUnits = {
    request: ["token / permintaan", "Masukkan harga jual token positif per permintaan."],
    generation: ["token / hasil", "Masukkan harga jual token positif per hasil."],
    second: ["token / detik", "Masukkan harga jual token positif per detik."],
};
const catalogUnit = (model) => model.catalog_price_unit || "request";

export default function ModelBulkTable({ models, providers = [], onRefresh, onEdit, onToggle, mediaOnly = false, disabled = false, providerId, pagination, query, onQueryChange, loading = false, error = "" }) {
    const { t } = useLocale();
    const [search, setSearch] = useState(query?.q || "");
    const [providerFilter, setProviderFilter] = useState("");
    const [categoryFilter, setCategoryFilter] = useState("");
    const [publicationFilter, setPublicationFilter] = useState("");
    const [priceFilter, setPriceFilter] = useState("");
    const [copiedId, setCopiedId] = useState(null);
    const [selectedOnly, setSelectedOnly] = useState(false);
    const [selected, setSelected] = useState([]);
    const [explicitIds, setExplicitIds] = useState("");
    const [drafts, setDrafts] = useState({});
    const [expanded, setExpanded] = useState([]);
    const [reviewModel, setReviewModel] = useState(null);
    const reviewTrigger = useRef(null);
    const retainedModels = useRef(new Map());
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [massField, setMassField] = useState("token_cost");
    const [massValue, setMassValue] = useState("");
    const [catalogOpen, setCatalogOpen] = useState(false);
    const [catalogRevisions, setCatalogRevisions] = useState({});
    const [catalogAcknowledged, setCatalogAcknowledged] = useState(false);
    const [confirmation, setConfirmation] = useState(null);
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState({ error: "", success: "" });
    const [rowErrors, setRowErrors] = useState({});
    const mutationInFlight = useRef(false);
    const deletionCompleted = useRef(false);
    const deletionTrigger = useRef(null);
    const searchInput = useRef(null);
    const server = !!pagination;
    const locked = busy || disabled || loading || !!error;
    const scopedModels = useMemo(() => mediaOnly ? models.filter(isMediaModel) : models, [models, mediaOnly]);
    // Only keep off-page rows with an explicit selection or unsaved edits.
    const byId = useMemo(() => {
        const next = new Map(server ? [...retainedModels.current].filter(([id]) => selected.includes(id) || drafts[id]) : []);
        scopedModels.forEach((model) => next.set(model.id, model));
        return next;
    }, [scopedModels, server, selected, drafts]);
    useEffect(() => { retainedModels.current = byId; }, [byId]);
    const selection = useMemo(() => new Set(selected), [selected]);
    const categories = useMemo(() => [...new Set([...scopedModels.map((model) => model.category), ...(query?.category ? [query.category] : []), ...(mediaOnly ? mediaCategories : ["chat", ...mediaCategories])])].sort(), [scopedModels, mediaOnly, query?.category]);
    const activeProvider = providerId !== undefined ? String(providerId ?? "") : providerFilter;
    const visible = useMemo(() => {
        if (server) return selectedOnly ? selected.map((id) => byId.get(id)).filter(Boolean) : scopedModels;
        const needle = search.trim().toLowerCase();
        return scopedModels.filter((model) => (!selectedOnly || selection.has(model.id))
            && (!activeProvider || String(model.provider_id ?? model.provider?.id ?? "") === activeProvider)
            && (!categoryFilter || model.category === categoryFilter)
            && (!publicationFilter || String(model.is_enabled) === publicationFilter)
            && (!priceFilter || (priceFilter === "unpriced") === isUnpriced(model))
            && (!needle || [model.id, model.model_id, model.upstream_model_id, model.display_name, model.provider_name].some((value) => String(value ?? "").toLowerCase().includes(needle))));
    }, [scopedModels, selectedOnly, selection, activeProvider, categoryFilter, publicationFilter, priceFilter, search, server, selected, byId]);
    const remotePage = server && !selectedOnly;
    const effectivePageSize = server ? Number(query.per_page) : pageSize;
    const lastPage = remotePage ? Math.max(1, pagination.last_page) : Math.max(1, Math.ceil(visible.length / effectivePageSize));
    const currentPage = remotePage ? pagination.current_page : Math.min(page, lastPage);
    const pageRows = remotePage ? visible : visible.slice((currentPage - 1) * effectivePageSize, currentPage * effectivePageSize);
    const total = remotePage ? pagination.total : visible.length;
    const allPageSelected = pageRows.length > 0 && pageRows.every((model) => selection.has(model.id));
    const dirtyIds = Object.keys(drafts).map(Number).filter((id) => byId.has(id));
    const selectedDirty = dirtyIds.filter((id) => selection.has(id));
    const selectedOffPage = selected.filter((id) => !pageRows.some((model) => model.id === id)).length;
    const catalogRows = selected.map((id) => byId.get(id)).filter(Boolean);
    const catalogCandidate = (model) => model.capability_summary?.find((revision) => revision.id === catalogRevisions[model.id])
        || model.capability_summary?.find((revision) => revision.contract_version === 2 && !revision.previously_published && revision.status !== "disabled" && revision.compatible);

    useEffect(() => { setPage(1); }, [search, activeProvider, categoryFilter, publicationFilter, priceFilter, selectedOnly, pageSize]);
    useEffect(() => { if (!server) setSelected((current) => current.every((id) => byId.has(id)) ? current : current.filter((id) => byId.has(id))); }, [byId, server]);
    useEffect(() => {
        if (!server || search === query.q) return;
        const timer = setTimeout(() => onQueryChange({ q: search }), 300);
        return () => clearTimeout(timer);
    }, [search, server, query?.q, onQueryChange]);
    useEffect(() => {
        if (!confirmation && deletionCompleted.current) {
            deletionCompleted.current = false;
            const trigger = deletionTrigger.current;
            const target = trigger?.isConnected && !trigger.disabled ? trigger : searchInput.current;
            target?.focus({ preventScroll: true });
        }
    }, [confirmation]);

    const patch = (id, key, value) => {
        setDrafts((current) => ({ ...current, [id]: { ...current[id], [key]: value } }));
        setRowErrors((current) => ({ ...current, [id]: { ...current[id], [key]: undefined } }));
        setStatus({ error: "", success: "" });
        setCatalogAcknowledged(false);
    };
    const setSelection = (ids) => {
        if (ids.length > 200) { setStatus({ error: t("Pilih maksimal 200 baris dalam satu operasi."), success: "" }); return; }
        setSelected(ids);
        setCatalogAcknowledged(false);
    };
    const togglePage = () => setSelection(allPageSelected ? selected.filter((id) => !pageRows.some((model) => model.id === id)) : [...new Set([...selected, ...pageRows.map((model) => model.id)])]);
    const selectExplicit = () => {
        const values = explicitIds.split(/[\s,]+/).filter(Boolean);
        const ids = [...new Set(values.map(Number))];
        if (!ids.length || values.some((value) => !/^\d+$/.test(value)) || ids.some((id) => !byId.has(id))) {
            setStatus({ error: t("Masukkan ID baris yang ada di tabel ini, dipisahkan koma."), success: "" });
            return;
        }
        setSelection(ids);
    };
    const applyMass = () => {
        let value = massValue;
        if (massField === "is_enabled") value = massValue === "true";
        setDrafts((current) => {
            const next = { ...current };
            selected.forEach((id) => { next[id] = { ...next[id], [massField]: value }; });
            return next;
        });
        setCatalogAcknowledged(false);
        setStatus({ error: "", success: t("Perubahan diterapkan ke draf pilihan. Simpan untuk menerapkannya; revisi capability tidak dipublikasikan.") });
    };
    const prepareSave = (ids) => {
        if (!ids.length || ids.length > 200) { setStatus({ error: t("Pilih maksimal 200 baris dalam satu operasi."), success: "" }); return; }
        const errors = {};
        const items = ids.map((id) => {
            const values = drafts[id];
            const item = { id };
            const fields = {};
            for (const [key, value] of Object.entries(values)) {
                if (key === "configDraft") {
                    if (byId.get(id)?.generation_config_readonly) continue;
                    const parsed = parseGenerationConfig(value);
                    item.generation_config = parsed.config;
                    Object.entries(parsed.errors).forEach(([field, message]) => { fields[`generation_config.${field}`] = message; });
                } else if (key === "token_cost") {
                    item[key] = value === "" || value == null ? null : Number(value);
                    if (item[key] !== null && !integer(value, 1, 2147483647)) fields[key] = "Biaya token harus bilangan bulat 1–2147483647 atau kosong.";
                } else if (key === "sort_order") {
                    item[key] = Number(value);
                    if (!integer(value, 0, 65535)) fields[key] = "Urutan harus bilangan bulat 0–65535.";
                } else if (key === "category") {
                    item[key] = String(value).trim();
                    if (!item[key] || item[key].length > 32) fields[key] = "Kategori wajib diisi, maksimal 32 karakter.";
                } else item[key] = value;
            }
            if (Object.keys(fields).length) errors[id] = fields;
            return item;
        });
        setRowErrors(errors);
        if (Object.keys(errors).length) { setStatus({ error: t("Periksa baris yang ditandai. Tidak ada perubahan tersimpan."), success: "" }); return; }
        setConfirmation({ type: "save", items, ids });
    };
    const prepareDeletion = (ids, event) => {
        if (locked || mutationInFlight.current || !ids.length) return;
        deletionTrigger.current = event.currentTarget;
        setStatus({ error: "", success: "" });
        setConfirmation({
            type: "delete",
            ids: [...ids],
            rows: ids.map((id) => {
                const model = byId.get(id);
                return { id, name: model.display_name || model.model_id, modelId: model.model_id };
            }),
            error: "",
            needsReview: false,
        });
    };
    const prepareCatalog = (action) => {
        if (!catalogAcknowledged || locked || !selected.length || selected.length > 50) return;
        const errors = {};
        const providerIds = new Set(catalogRows.map((model) => model.provider_id));
        const items = catalogRows.map((model) => {
            const revision = catalogCandidate(model);
            const price = drafts[model.id]?.token_cost ?? "";
            const unit = catalogUnit(model);
            if (Number(model.token_cost) > 0) errors[model.id] = { token_cost: "Harga positif yang ada dilindungi. Tinjau revisi satu per satu tanpa mengubah tarif." };
            else if (model.capability_summary?.some((entry) => entry.previously_published) || !revision || revision.contract_version !== 2 || !revision.compatible || revision.status === "disabled") errors[model.id] = { row: "Pilih kandidat v2 kompatibel pada model baru yang belum pernah dipublikasikan." };
            else if (!integer(price, 1, 2147483647)) errors[model.id] = { token_cost: catalogUnits[unit]?.[1] || "Masukkan harga jual token positif sesuai unit katalog model." };
            else if (Object.keys(drafts[model.id] || {}).some((key) => key !== "token_cost")) errors[model.id] = { row: "Simpan atau buang perubahan selain harga sebelum tinjauan massal." };
            return { model_id: model.id, revision_id: revision?.id, token_cost: Number(price), price_unit: unit,
                ...(revision?.execution?.transport === "realtime" ? { max_session_seconds: revision.execution.max_session_seconds } : {}) };
        });
        setRowErrors(errors);
        if (catalogRows.length !== selected.length || providerIds.size !== 1 || ![...providerIds][0] || Object.keys(errors).length) {
            setStatus({ error: t("Periksa pilihan: maksimal 50 model baru dari satu provider, tanpa harga positif dan dengan kandidat kompatibel."), success: "" });
            return;
        }
        setConfirmation({ type: "catalog", action, providerId: [...providerIds][0], ids: [...selected], items });
    };
    const mutate = async () => {
        if (!confirmation || mutationInFlight.current || disabled || confirmation.needsReview) return;
        const operation = confirmation;
        mutationInFlight.current = true;
        setBusy(true);
        setStatus({ error: "", success: "" });
        if (operation.type === "delete") setConfirmation((current) => ({ ...current, error: "" }));
        try {
            await apiRequest(operation.type === "catalog" ? `/api/admin/ai/providers/${operation.providerId}/catalog-bulk` : "/api/admin/ai/models/bulk", {
                method: operation.type === "catalog" ? "POST" : operation.type === "delete" ? "DELETE" : "PATCH",
                body: operation.type === "catalog"
                    ? { items: operation.items, expected_count: operation.items.length, action: operation.action, reviewed: true, confirm: true }
                    : operation.type === "delete" ? { ids: operation.ids, expected_count: operation.ids.length, delete_usage_rates: true } : { items: operation.items },
            });
            setDrafts((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !operation.ids.includes(Number(id)))));
            setRowErrors((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !operation.ids.includes(Number(id)))));
            if (operation.type === "delete") {
                setSelected((current) => current.filter((id) => !operation.ids.includes(id)));
                setExpanded((current) => current.filter((id) => !operation.ids.includes(id)));
            }
            setStatus({ error: "", success: `${operation.ids.length} ${t(operation.type === "catalog" ? operation.action === "publish" ? "model diberi harga, ditinjau, dan dipublikasikan." : "model diberi harga dan ditinjau; belum dipublikasikan." : operation.type === "delete" ? "baris dihapus. Riwayat penggunaan dipertahankan." : "baris tersimpan.")}` });
            if (operation.type === "catalog") {
                operation.ids.forEach((id) => retainedModels.current.delete(id));
                setSelected((current) => current.filter((id) => !operation.ids.includes(id)));
                setCatalogOpen(false);
                setCatalogAcknowledged(false);
            }
            try {
                await onRefresh();
            } catch {
                setStatus({ error: t("Perubahan tersimpan. Muat ulang katalog untuk memperbarui daftar."), success: "" });
            }
            if (operation.type === "delete") deletionCompleted.current = true;
            setConfirmation(null);
        } catch (error) {
            if (operation.type === "delete") {
                const busyMessage = error.status === 409 ? {
                    "Selected models have active jobs or retained capability history. Disable them instead of deleting.": "Model masih memiliki pekerjaan aktif, kredit yang dicadangkan, atau riwayat capability yang dipertahankan. Nonaktifkan model alih-alih menghapusnya.",
                    "Selected models have active chat operations. Stop them before deleting, or disable these models instead.": "Model masih memiliki operasi chat aktif. Hentikan operasi tersebut atau nonaktifkan model alih-alih menghapusnya.",
                }[error.details?.message] : undefined;
                const needsReview = (error.status === 409 && !busyMessage) || error.status === 404;
                const message = t(busyMessage
                    || (needsReview
                      ? "Pilihan model berubah. Tutup dialog, muat ulang katalog, lalu konfirmasi ulang."
                      : [401, 419].includes(error.status)
                        ? "Sesi Anda telah berakhir. Masuk kembali sebelum menghapus."
                        : error.status === 403
                          ? "Anda tidak memiliki izin untuk menghapus model."
                          : "Penghapusan model belum dikonfirmasi. Pilihan dan draf tetap tersedia; muat ulang katalog sebelum mencoba lagi."));
                setConfirmation((current) => ({ ...current, error: message, needsReview }));
                setStatus({ error: message, success: "" });
            } else {
                const errors = {};
                Object.entries(error.details?.errors || {}).forEach(([key, messages]) => {
                    const match = key.match(/^items\.(\d+)(?:\.(.+))?$/);
                    if (match && operation.ids[Number(match[1])] != null) {
                        const id = operation.ids[Number(match[1])];
                        const field = ["revision_id", "model_id"].includes(match[2]) ? "row" : match[2] || "row";
                        errors[id] = { ...errors[id], [field]: messages };
                    }
                });
                setRowErrors(errors);
                setConfirmation(null);
                setStatus({ error: error.message || t("Perubahan gagal disimpan. Draf tetap tersedia."), success: "" });
            }
        } finally {
            mutationInFlight.current = false;
            setBusy(false);
        }
    };
    const fieldError = (id, key) => rowErrors[id]?.[key] && <span className="mt-1 block max-w-56 whitespace-normal text-xs text-red-600 dark:text-red-300">{t([rowErrors[id][key]].flat()[0])}</span>;
    const unitLabel = (unit) => catalogUnits[unit] ? t(catalogUnits[unit][0]) : `token / ${unit}`;
    const tableInput = "ui-input min-h-9 min-w-28 w-28 px-2";
    const numericInput = "ui-input min-h-9 min-w-32 w-32 px-2 text-right tabular-nums";

    return <div className="min-w-0 [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50" aria-busy={busy || loading}>
        <div className="flex flex-wrap gap-3 border-b border-slate-200 p-4 dark:border-white/10">
            <label className="min-w-48 flex-1 text-xs font-medium">{t("Cari model")}
                <input ref={searchInput} type="search" className="ui-input mt-1 min-h-10" placeholder={t("ID publik, ID upstream, atau nama")} value={search} disabled={server && selectedOnly} onChange={(event) => setSearch(event.target.value)} />
            </label>
            {providerId === undefined && <label className="text-xs font-medium">{t("Penyedia")}
                <select className="ui-input mt-1 min-h-10" value={providerFilter} onChange={(event) => setProviderFilter(event.target.value)}>
                    <option value="">{t("Semua penyedia")}</option>
                    {providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name || provider.slug}</option>)}
                </select>
            </label>}
            <label className="text-xs font-medium">{t("Kategori")}
                <select className="ui-input mt-1 min-h-10" value={server ? query.category : categoryFilter} disabled={server && selectedOnly} onChange={(event) => server ? onQueryChange({ category: event.target.value }) : setCategoryFilter(event.target.value)}><option value="">{t("Semua kategori")}</option>{categories.map((category) => <option key={category}>{category}</option>)}</select>
            </label>
            {server ? <>
                <label className="text-xs font-medium">{t("Status katalog")}
                    <select className="ui-input mt-1 min-h-10" value={query.status} disabled={selectedOnly} onChange={(event) => onQueryChange({ status: event.target.value })}>
                        <option value="">{t("Semua status")}</option>
                        {Object.entries(capabilityStatuses).map(([value, [, label]]) => <option key={value} value={value}>{t(label)}</option>)}
                        <option value="enabled">{t("Profil aktif")}</option><option value="unavailable">{t("Belum tersedia di upstream")}</option><option value="unpriced">{t("Belum berharga")}</option>
                    </select>
                </label>
                <label className="text-xs font-medium">{t("Urutkan menurut")}
                    <select className="ui-input mt-1 min-h-10" value={query.sort} disabled={selectedOnly} onChange={(event) => onQueryChange({ sort: event.target.value })}>
                        {[["display_name", "Nama tampilan"], ["model_id", "ID publik"], ["upstream_model_id", "ID upstream"], ["category", "Kategori"], ["sort_order", "Urutan tampil"], ["status", "Status katalog"]].map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}
                    </select>
                </label>
                <label className="text-xs font-medium">{t("Arah urutan")}
                    <select className="ui-input mt-1 min-h-10" value={query.direction} disabled={selectedOnly} onChange={(event) => onQueryChange({ direction: event.target.value })}><option value="asc">{t("Menaik")}</option><option value="desc">{t("Menurun")}</option></select>
                </label>
            </> : <>
                <label className="text-xs font-medium">{t("Aktivasi profil")}
                    <select className="ui-input mt-1 min-h-10" value={publicationFilter} onChange={(event) => setPublicationFilter(event.target.value)}><option value="">{t("Semua status")}</option><option value="true">{t("Aktif")}</option><option value="false">{t("Nonaktif")}</option></select>
                </label>
                <label className="text-xs font-medium">{t("Harga")}
                    <select className="ui-input mt-1 min-h-10" value={priceFilter} onChange={(event) => setPriceFilter(event.target.value)}><option value="">{t("Semua harga")}</option><option value="priced">{t("Sudah berharga")}</option><option value="unpriced">{t("Belum berharga")}</option></select>
                </label>
            </>}
        </div>
        <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-white/10">
            <label className="min-w-44 flex-1 text-xs font-medium">{t("Pilih ID baris secara eksplisit")}
                <input className="ui-input mt-1 min-h-10" value={explicitIds} onChange={(event) => setExplicitIds(event.target.value)} placeholder="12, 24, 31" disabled={locked} />
            </label>
            <button className="ui-btn-secondary" type="button" disabled={locked || !explicitIds.trim()} onClick={selectExplicit}>{t("Gunakan ID ini")}</button>
            <label className="flex min-h-10 items-center gap-2 text-xs"><input type="checkbox" checked={selectedOnly} onChange={(event) => setSelectedOnly(event.target.checked)} />{t("Tampilkan pilihan saja")}</label>
        </div>
        {server && <p className="px-4 py-3 text-xs leading-5 text-slate-600 dark:text-slate-400">{t(selectedOnly ? "Menampilkan pilihan yang disimpan lintas halaman. Matikan pilihan saja untuk mencari katalog kembali." : "Pencarian, filter, dan urutan berlaku untuk seluruh katalog provider. Pilihan dan draf tetap tersimpan saat berpindah halaman.")}</p>}
        {selected.length > 0 && <div className="flex flex-wrap items-end gap-3 bg-slate-50 p-4 dark:bg-white/5">
            <label className="text-xs font-medium">{t("Ubah pilihan sekaligus")}
                <select className="ui-input mt-1 min-h-10" value={massField} disabled={locked} onChange={(event) => { setMassField(event.target.value); setMassValue(event.target.value === "is_enabled" ? "false" : ""); }}>
                    <option value="token_cost">{t("Biaya token")}</option><option value="category">{t("Kategori")}</option><option value="is_enabled">{t("Aktivasi profil")}</option><option value="sort_order">{t("Urutan tampil")}</option>
                </select>
            </label>
            <label className="min-w-32 flex-1 text-xs font-medium">{t("Nilai baru")}
                {massField === "is_enabled" ? <select className="ui-input mt-1 min-h-10" value={massValue} disabled={locked} onChange={(event) => setMassValue(event.target.value)}><option value="false">{t("Nonaktif")}</option><option value="true">{t("Aktif")}</option></select>
                    : <input className="ui-input mt-1 min-h-10" type={["token_cost", "sort_order"].includes(massField) ? "number" : "text"} value={massValue} disabled={locked} onChange={(event) => setMassValue(event.target.value)} />}
            </label>
            <button className="ui-btn-secondary" type="button" disabled={locked} onClick={applyMass}>{t("Terapkan ke draf")} ({selected.length})</button>
        </div>}
        {catalogOpen && selected.length > 0 && <section className="space-y-4 border-b border-slate-200 p-4 dark:border-white/10" aria-labelledby="catalog-bulk-heading">
            <h3 id="catalog-bulk-heading" className="text-sm font-semibold">{t("Harga dan publikasi kandidat terpilih")}</h3>
            <p className="max-w-prose text-sm leading-6 text-slate-600 dark:text-slate-300">{t(catalogRows.every((model) => catalogUnit(model) === "request")
                ? "Hanya model baru tanpa harga positif. Isi harga token pada baris tabel atau gunakan draf massal. Harga per permintaan mencakup seluruh konfigurasi dan semua hasil; jumlah, durasi, resolusi, atau pelatihan dapat mengubah biaya provider. Tarif USD API tidak diubah."
                : "Hanya model baru tanpa harga positif. Isi harga token pada baris tabel atau gunakan draf massal. Unit harga jual mengikuti unit katalog setiap model: harga per permintaan mencakup seluruh konfigurasi dan semua hasil, harga per hasil dikalikan jumlah hasil, dan harga per detik dikalikan durasi dalam detik penuh serta jumlah hasil. Resolusi atau konfigurasi lain tetap dapat mengubah biaya provider. Tarif USD API tidak diubah.")}</p>
            <ul className="max-h-64 space-y-3 overflow-y-auto">
                {catalogRows.map((model) => {
                    const candidate = catalogCandidate(model);
                    const candidates = (model.capability_summary || []).filter((entry) => entry.contract_version === 2 && !entry.previously_published && entry.status !== "disabled");
                    return <li key={model.id} className="flex flex-wrap items-end gap-3 border-b border-slate-100 pb-3 last:border-0 dark:border-white/5">
                        <label className="min-w-52 flex-1 text-xs font-medium"><span className="block break-all">{model.display_name || model.model_id}</span>
                            <select className="ui-input mt-1 min-h-10" value={candidate?.id ?? ""} disabled={locked || Number(model.token_cost) > 0} onChange={(event) => { setCatalogRevisions((current) => ({ ...current, [model.id]: Number(event.target.value) })); setCatalogAcknowledged(false); }}>
                                <option value="">{t("Pilih kandidat v2")}</option>
                                {candidates.map((entry) => <option key={entry.id} value={entry.id}>{entry.operation} · r{entry.revision} · {t(entry.compatible ? "Lulus kompatibilitas" : "Perlu penanganan")}</option>)}
                            </select>
                        </label>
                        <span className="pb-3 text-xs tabular-nums">{Number(model.token_cost) > 0 ? t("Harga yang ada dilindungi") : `${drafts[model.id]?.token_cost || "—"} ${unitLabel(catalogUnit(model))}`}</span>
                        {candidate?.execution?.transport === "realtime" && <span className="pb-3 text-xs font-medium">{t("Satu sesi, maksimal")} {candidate.execution.max_session_seconds} {t("detik; biaya bervariasi menurut resolusi.")}</span>}
                        <button type="button" className="ui-btn-secondary min-h-10" disabled={locked} onClick={(event) => { reviewTrigger.current = event.currentTarget; setReviewModel(model.id); }}>{t("Tinjau capability")}</button>
                    </li>;
                })}
            </ul>
            <label className="flex max-w-prose items-start gap-2 text-sm leading-6"><input type="checkbox" className="mt-1" checked={catalogAcknowledged} disabled={locked} onChange={(event) => setCatalogAcknowledged(event.target.checked)} />{t("Saya telah meninjau schema, harga jual, unit, dan risiko biaya konfigurasi untuk setiap kandidat terpilih. Tidak ada pengujian generasi berbayar.")}</label>
            {selected.length > 50 && <p role="alert" className="text-sm text-amber-800 dark:text-amber-200">{t("Tinjauan publikasi dibatasi 50 model. Kurangi pilihan sebelum melanjutkan.")}</p>}
            <div className="flex flex-wrap gap-2">
                <button type="button" className="ui-btn-secondary" disabled={locked || !catalogAcknowledged || selected.length > 50} onClick={() => prepareCatalog("review")}>{t("Simpan harga dan tinjauan")}</button>
                <button type="button" className="ui-btn-primary" disabled={locked || !catalogAcknowledged || selected.length > 50} onClick={() => prepareCatalog("publish")}>{t("Tinjau dan publikasikan pilihan")}</button>
                <button type="button" className="ui-btn-secondary" disabled={locked} onClick={() => setCatalogOpen(false)}>{t("Tutup tinjauan")}</button>
            </div>
        </section>}
        {(status.error || status.success) && <p role={status.error ? "alert" : "status"} className={`px-4 py-3 text-sm ${status.error ? "text-red-700 dark:text-red-300" : "text-emerald-700 dark:text-emerald-300"}`}>{status.error || status.success}</p>}
        {loading && <div className="p-4"><LoadingState label={t("Memuat model…")} /></div>}
        {error && <div className="p-4"><ErrorState message={error} onRetry={onRefresh} /></div>}
        <div className="max-w-full overflow-x-auto" role="region" aria-label={t("Tabel model")} tabIndex={0}>
            <table className="w-full text-left text-xs text-slate-700 dark:text-slate-200">
                <caption className="sr-only">{t(mediaOnly ? "Harga token dan konfigurasi model media" : "Edit model secara massal")}</caption>
                <thead className="bg-slate-50 text-slate-600 dark:bg-white/5 dark:text-slate-400"><tr>
                    <th className="p-3"><input type="checkbox" aria-label={t("Pilih halaman ini")} checked={allPageSelected} disabled={locked || !pageRows.length} onChange={togglePage} /></th>
                    <th scope="col" className="p-3">{t("Model")}</th><th scope="col" className="p-3">{t("Kategori")}</th><th scope="col" className="p-3">{t("Biaya token")}</th><th scope="col" className="p-3">{t("Urutan")}</th><th scope="col" className="p-3">{t("Aktivasi / upstream")}</th><th scope="col" className="p-3">{t("Operasi / revisi")}</th><th scope="col" className="p-3">{t("Tindakan")}</th>
                </tr></thead>
                <tbody>{pageRows.map((model) => {
                    const draft = drafts[model.id] || {};
                    const row = { ...model, ...draft };
                    const isMedia = isMediaModel(row);
                    const isExpanded = expanded.includes(model.id);
                    const generationReadOnly = !!model.generation_config_readonly;
                    const config = (!generationReadOnly && draft.configDraft) || generationConfigDraft(model.generation_config);
                    const unit = model.catalog_price_unit || model.generation_config?.price_unit;
                    // Native audio is charged once per job; catalog "generation" units (Runware) are charged per result.
                    const costLabel = unit === "request" ? "Token per permintaan" : unit === "second" ? "Token per detik" : model.category === "audio" && !model.catalog_price_unit ? "Token per pekerjaan" : "Token per hasil";
                    const errors = rowErrors[model.id] || {};
                    return <Fragment key={model.id}>
                        <tr className={`border-t border-slate-200 align-top dark:border-white/10 ${selection.has(model.id) ? "bg-red-50/60 dark:bg-red-500/5" : ""}`}>
                            <td className="p-3"><input type="checkbox" aria-label={`${t("Pilih model")} ${model.model_id}`} checked={selection.has(model.id)} disabled={locked} onChange={() => setSelection(selection.has(model.id) ? selected.filter((id) => id !== model.id) : [...selected, model.id])} /></td>
                            <td className="min-w-48 max-w-64 p-3"><strong className="block whitespace-normal text-slate-900 dark:text-white">{model.display_name || model.model_id}</strong><span className="mt-1 block text-slate-600 dark:text-slate-400">{t("ID publik")} · #{model.id}</span><span className="mt-1 flex items-center gap-1.5 break-all font-mono text-xs">{model.model_id}
                                <button type="button" className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md border transition-colors motion-reduce:transition-none ${copiedId === model.id ? "border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300" : "border-slate-200 text-slate-500 hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10"}`} aria-label={`${t(copiedId === model.id ? "Tersalin" : "Salin ID model")} ${model.model_id}`} title={t(copiedId === model.id ? "Tersalin" : "Salin ID model")} onClick={() => { navigator.clipboard?.writeText(model.model_id); setCopiedId(model.id); setTimeout(() => setCopiedId((current) => current === model.id ? null : current), 1600); }}>
                                    {copiedId === model.id
                                        ? <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-3 w-3"><path d="M20 6 9 17l-5-5" /></svg>
                                        : <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" className="h-3 w-3"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>}
                                </button></span><span className="mt-1 block text-slate-500 dark:text-slate-400">{model.provider_name || model.provider?.name || "—"}</span>{drafts[model.id] && <span className="mt-2 block font-semibold text-amber-700 dark:text-amber-300">{t("Belum disimpan")}</span>}{errors.row && fieldError(model.id, "row")}{errors.rates && fieldError(model.id, "rates")}
                                <span className="mt-2 block text-slate-600 dark:text-slate-400">{t("ID upstream")}</span><code className="mt-1 block break-all text-xs">{model.upstream_model_id || "—"}</code>
                                <span className="mt-2 block tabular-nums text-slate-500 dark:text-slate-400">{isMedia
                                    ? `${t("Tarif unit USD (terpisah)")}: ${model.rates?.unit?.price_usd == null ? t("Belum tersedia") : `$${model.rates.unit.price_usd}`}`
                                    : model.rates?.input_tokens?.price_usd == null && model.rates?.output_tokens?.price_usd == null
                                        ? `USD / 1M: ${t("Belum tersedia")}`
                                        : `USD / 1M: ${model.rates?.input_tokens?.price_usd ?? "—"} / ${model.rates?.output_tokens?.price_usd ?? "—"}`}</span>
                            </td>
                            <td className="p-3"><input className={tableInput} aria-label={`${t("Kategori")} ${model.model_id}`} value={row.category || ""} maxLength={32} disabled={locked} aria-invalid={!!errors.category} onChange={(event) => patch(model.id, "category", event.target.value)} />{fieldError(model.id, "category")}</td>
                            <td className="p-3">{isMedia
                                ? <><input className={numericInput} aria-label={`${t(costLabel)} ${model.model_id}`} type="number" min="1" max="2147483647" step="1" value={row.token_cost ?? ""} placeholder={t("Belum diatur")} disabled={locked} aria-invalid={!!errors.token_cost} onChange={(event) => patch(model.id, "token_cost", event.target.value)} /><span className="mt-1 block text-slate-500 dark:text-slate-400">{t(costLabel)}</span>{fieldError(model.id, "token_cost")}</>
                                : <span className="block text-slate-400 dark:text-slate-500" title={t("Model chat ditagih per token masukan/keluaran (lihat tarif USD/1M di bawah nama model), bukan per hasil.")}>{t("Per token")}</span>}</td>
                            <td className="p-3"><input className="ui-input min-h-9 min-w-20 w-20 px-2 text-right tabular-nums" aria-label={`${t("Urutan")} ${model.model_id}`} type="number" min="0" max="65535" step="1" value={row.sort_order ?? 0} disabled={locked} aria-invalid={!!errors.sort_order} onChange={(event) => patch(model.id, "sort_order", event.target.value)} />{fieldError(model.id, "sort_order")}</td>
                            <td className="min-w-36 p-3"><label className="flex min-h-9 items-center gap-2"><input type="checkbox" aria-label={`${t("Aktifkan profil")} ${model.model_id}`} checked={!!row.is_enabled} disabled={locked} onChange={(event) => patch(model.id, "is_enabled", event.target.checked)} />{t(row.is_enabled ? "Aktif" : "Nonaktif")}</label><span className="mt-1 block text-slate-500 dark:text-slate-400">{t(model.is_available ? "Tersedia di upstream" : "Belum tersedia di upstream")}</span></td>
                            <td className="min-w-48 max-w-64 p-3">
                                {model.capability_summary?.length ? <ul className="space-y-3">{model.capability_summary.map((revision) => <li key={revision.id} className="space-y-1"><code className="block break-all text-xs">{revision.operation} · r{revision.revision}</code><CapabilityStatus status={revision.status} />{revision.is_active && <span className="ml-1 text-xs font-semibold">{t("Aktif")}</span>}{revision.blocker_count > 0 && <span className="block text-xs text-amber-800 dark:text-amber-200">{revision.blocker_count} {t("penghalang publikasi")}</span>}</li>)}</ul> : <span className="text-slate-600 dark:text-slate-400">{t(isMedia ? "Konfigurasi kurasi; belum ada revisi impor" : "Tidak memakai revisi media")}</span>}
                            </td>
                            <td className="min-w-36 max-w-44 p-3"><div className="flex flex-wrap gap-1">
                                {isMedia && <button type="button" className="ui-btn-mini" aria-expanded={isExpanded} disabled={locked} onClick={() => setExpanded((current) => isExpanded ? current.filter((id) => id !== model.id) : [...current, model.id])}>{t("Konfigurasi")}</button>}
                                {isMedia && <button type="button" className="ui-btn-mini min-h-9" aria-expanded={reviewModel === model.id} disabled={locked || !!drafts[model.id]} onClick={(event) => { reviewTrigger.current = event.currentTarget; setReviewModel(reviewModel === model.id ? null : model.id); }}>{t("Tinjau capability")}</button>}
                                {onEdit && <button type="button" className="ui-btn-mini" disabled={locked || !!drafts[model.id]} onClick={() => onEdit(model)}>{t("Edit model")}</button>}
                                {onToggle && <button type="button" className="ui-btn-mini" disabled={locked || !!drafts[model.id]} onClick={() => onToggle(model)}>{t(model.is_enabled ? "Nonaktifkan" : "Aktifkan")}</button>}
                                <button type="button" className="ui-btn-mini ui-btn-mini-danger" disabled={locked} onClick={(event) => prepareDeletion([model.id], event)} aria-label={`${t("Hapus model")} ${model.display_name || model.model_id}`}>{t("Hapus")}</button>
                            </div></td>
                        </tr>
                        {isMedia && isExpanded && <tr className="border-t border-slate-200 dark:border-white/10"><td colSpan={8} className="p-4"><div className="max-w-3xl">
                            {generationReadOnly && <p className="mb-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Konfigurasi efektif mengikuti adapter dan capability aktif. Ubah revisi melalui tinjauan capability; label, harga, dan aktivasi profil tetap dapat diedit.")}</p>}
                            {model.schema_managed
                                ? <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Seluruh kontrol mengikuti schema revisi. Buka Tinjau capability untuk melihat input, output, batasan, dan bukti sumber; formulir native tidak menggambarkan kontrak ini.")}</p>
                                : <GenerationConfigFields category={row.category} value={config} onChange={(value) => patch(model.id, "configDraft", value)} disabled={locked || generationReadOnly} readOnly={generationReadOnly} protocol={model.provider?.protocol} errors={generationReadOnly ? undefined : Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith("generation_config.")).map(([key, value]) => [key.slice(18), value]))} />}
                            {!generationReadOnly && fieldError(model.id, "generation_config")}
                        </div></td></tr>}
                    </Fragment>;
                })}</tbody>
            </table>
            {!pageRows.length && !loading && !error && <p className="p-6 text-sm text-slate-600 dark:text-slate-400">{t(selectedOnly ? "Belum ada pilihan. Pilih baris pada daftar katalog." : scopedModels.length || query?.q || query?.category || query?.status || search ? "Tidak ada model yang cocok. Ubah pencarian atau filter." : "Belum ada model. Impor dari halaman provider atau tambahkan model kurasi.")}</p>}
        </div>
        {reviewModel && byId.has(reviewModel) && <div className="min-w-0 border-t border-slate-200 p-4 dark:border-white/10"><CatalogRevisionPanel key={reviewModel} model={byId.get(reviewModel)} disabled={locked} onRefresh={onRefresh} onClose={() => { setReviewModel(null); reviewTrigger.current?.focus({ preventScroll: true }); }} /></div>}
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-xs dark:border-white/10">
            <label>{t("Baris per halaman")} <select className="ui-input ml-2 min-h-10 w-auto" value={effectivePageSize} disabled={loading} onChange={(event) => server ? onQueryChange({ per_page: Number(event.target.value) }) : setPageSize(Number(event.target.value))}>{pageSizeOptions.map((size) => <option key={size}>{size}</option>)}</select></label>
            <span className="tabular-nums" aria-live="polite">{total} {t("model")} · {t("Halaman")} {currentPage}/{lastPage}</span>
            <div className="flex gap-2"><button type="button" className="ui-btn-secondary" disabled={loading || currentPage <= 1} onClick={() => remotePage ? onQueryChange({ page: currentPage - 1 }) : setPage(currentPage - 1)}>{t("Sebelumnya")}</button><button type="button" className="ui-btn-secondary" disabled={loading || currentPage >= lastPage} onClick={() => remotePage ? onQueryChange({ page: currentPage + 1 }) : setPage(currentPage + 1)}>{t("Berikutnya")}</button></div>
        </div>
        <div className="sticky bottom-0 z-20 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
            <p className="text-xs tabular-nums">{selected.length} {t("dipilih")} ({selectedOffPage} {t("di luar halaman ini")}) · {dirtyIds.length} {t("draf berubah")}</p>
            <div className="flex flex-wrap gap-2">
                {!!selected.length && catalogRows.some((model) => model.schema_managed) && <button type="button" className="ui-btn-secondary" disabled={locked} aria-expanded={catalogOpen} onClick={() => { setCatalogOpen(!catalogOpen); setCatalogAcknowledged(false); }}>{t("Tinjau harga dan publikasi")} ({selected.length})</button>}
                {!!selected.length && <button type="button" className="ui-btn-secondary" disabled={locked} onClick={() => setSelected([])}>{t("Batalkan pilihan")}</button>}
                {!!dirtyIds.length && <button type="button" className="ui-btn-secondary" disabled={locked} onClick={() => { setDrafts({}); setRowErrors({}); }}>{t("Buang draf")}</button>}
                <button type="button" className="ui-btn-secondary text-red-700 dark:text-red-300" disabled={locked || !selected.length} onClick={(event) => prepareDeletion(selected, event)}>{t("Hapus pilihan")} ({selected.length})</button>
                <button type="button" className="ui-btn-secondary" disabled={locked || !selectedDirty.length} onClick={() => prepareSave(selectedDirty)}>{t("Simpan pilihan")} ({selectedDirty.length})</button>
                <button type="button" className="ui-btn-primary" disabled={locked || !dirtyIds.length} onClick={() => prepareSave(dirtyIds)}>{t("Simpan perubahan")} ({dirtyIds.length})</button>
            </div>
        </div>
        {confirmation?.type === "save" && <BulkConfirmDialog title={t("Simpan perubahan model?")} count={confirmation.ids.length} rows={confirmation.ids.map((id) => ({ id, label: byId.get(id)?.model_id || String(id) }))} description={t("Seluruh baris ini disimpan dalam satu transaksi. Jika satu baris tidak valid, tidak ada perubahan yang disimpan. Publikasi tidak mengubah ketersediaan upstream.")} busy={busy} onCancel={() => { if (!mutationInFlight.current) setConfirmation(null); }} onConfirm={mutate} />}
        {confirmation?.type === "catalog" && <MediaActionDialog
            title={t(confirmation.action === "publish" ? "Publikasikan kandidat terpilih?" : "Simpan harga dan tinjauan?")}
            description={t("Seluruh pilihan diproses atomik. Harga positif yang sudah ada, tarif USD API, identitas, label, dan riwayat tidak diubah. Publikasi memerlukan koneksi terautentikasi; kompatibilitas schema bukan bukti generasi berhasil.")}
            closeLabel={t("Batal")} confirmLabel={t(confirmation.action === "publish" ? "Tinjau dan publikasikan pilihan" : "Simpan harga dan tinjauan")}
            busyLabel={t("Memproses…")} busy={busy} confirmDisabled={disabled}
            onConfirm={mutate} onClose={() => { if (!mutationInFlight.current) setConfirmation(null); }}
        >
            <ul className="max-h-56 space-y-2 overflow-y-auto text-sm">
                {confirmation.items.map((item) => <li className="break-words" key={item.model_id}><strong>{byId.get(item.model_id)?.display_name || item.model_id}</strong><span className="block text-xs">{t("Revisi")} #{item.revision_id} · {item.token_cost} {unitLabel(item.price_unit)}</span>{item.max_session_seconds && <span className="block text-xs">{t("Satu sesi, maksimal")} {item.max_session_seconds} {t("detik; biaya bervariasi menurut resolusi.")}</span>}</li>)}
            </ul>
        </MediaActionDialog>}
        {confirmation?.type === "delete" && <MediaActionDialog
            title={`${t(confirmation.ids.length === 1 ? "Hapus model?" : "Hapus model terpilih?")} (${confirmation.ids.length})`}
            description={t("Hanya model dengan ID di bawah dan tarif PAYG-nya yang dihapus. Riwayat penggunaan, tagihan, dan hasil generasi tetap disimpan. Pekerjaan media aktif atau kredit yang dicadangkan akan membatalkan seluruh penghapusan.")}
            closeLabel={t("Batal")}
            confirmLabel={confirmation.ids.length === 1 ? t("Hapus model") : `${t("Hapus")} ${confirmation.ids.length} ${t("baris")}`}
            busyLabel={t("Menghapus…")}
            busy={busy}
            confirmDisabled={disabled || confirmation.needsReview}
            error={confirmation.error}
            onConfirm={mutate}
            onClose={() => { if (!mutationInFlight.current) setConfirmation(null); }}
        >
            <ul className="max-h-48 space-y-3 overflow-y-auto rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/5">
                {confirmation.rows.map((row) => <li key={row.id} className="break-words"><strong className="block">{row.name}</strong><span className="mt-1 block break-all font-mono text-xs">#{row.id} · {row.modelId}</span></li>)}
            </ul>
        </MediaActionDialog>}
    </div>;
}
