import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";
import MediaActionDialog from "../MediaActionDialog";
import BulkConfirmDialog from "./BulkConfirmDialog";
import GenerationConfigFields, { generationConfigDraft, parseGenerationConfig } from "./GenerationConfigFields";

const pageSizeOptions = [25, 50, 100];
const mediaCategories = ["image", "video", "audio"];
const isUnpriced = (model) => mediaCategories.includes(model.category)
    ? !Number(model.token_cost)
    : !Number(model.rates?.input_tokens?.price_usd) && !Number(model.rates?.output_tokens?.price_usd);
const integer = (value, min, max) => value !== "" && Number.isInteger(Number(value)) && Number(value) >= min && Number(value) <= max;

export default function ModelBulkTable({ models, providers = [], onRefresh, onEdit, onToggle, mediaOnly = false, disabled = false, providerId }) {
    const { t } = useLocale();
    const [search, setSearch] = useState("");
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
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [massField, setMassField] = useState("token_cost");
    const [massValue, setMassValue] = useState("");
    const [confirmation, setConfirmation] = useState(null);
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState({ error: "", success: "" });
    const [rowErrors, setRowErrors] = useState({});
    const mutationInFlight = useRef(false);
    const deletionCompleted = useRef(false);
    const deletionTrigger = useRef(null);
    const searchInput = useRef(null);
    const locked = busy || disabled;
    const scopedModels = useMemo(() => mediaOnly ? models.filter((model) => mediaCategories.includes(model.category)) : models, [models, mediaOnly]);
    const byId = useMemo(() => new Map(scopedModels.map((model) => [model.id, model])), [scopedModels]);
    const selection = useMemo(() => new Set(selected), [selected]);
    const categories = useMemo(() => [...new Set([...scopedModels.map((model) => model.category), ...(mediaOnly ? mediaCategories : ["chat", ...mediaCategories])])].sort(), [scopedModels, mediaOnly]);
    const activeProvider = providerId !== undefined ? String(providerId ?? "") : providerFilter;
    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();
        return scopedModels.filter((model) => (!selectedOnly || selection.has(model.id))
            && (!activeProvider || String(model.provider_id ?? model.provider?.id ?? "") === activeProvider)
            && (!categoryFilter || model.category === categoryFilter)
            && (!publicationFilter || String(model.is_enabled) === publicationFilter)
            && (!priceFilter || (priceFilter === "unpriced") === isUnpriced(model))
            && (!needle || [model.id, model.model_id, model.upstream_model_id, model.display_name, model.provider_name].some((value) => String(value ?? "").toLowerCase().includes(needle))));
    }, [scopedModels, selectedOnly, selection, activeProvider, categoryFilter, publicationFilter, priceFilter, search]);
    const lastPage = Math.max(1, Math.ceil(visible.length / pageSize));
    const currentPage = Math.min(page, lastPage);
    const pageRows = visible.slice((currentPage - 1) * pageSize, currentPage * pageSize);
    const allPageSelected = pageRows.length > 0 && pageRows.every((model) => selection.has(model.id));
    const dirtyIds = Object.keys(drafts).map(Number).filter((id) => byId.has(id));
    const selectedDirty = dirtyIds.filter((id) => selection.has(id));
    const selectedOffPage = selected.filter((id) => !pageRows.some((model) => model.id === id)).length;

    useEffect(() => { setPage(1); }, [search, activeProvider, categoryFilter, publicationFilter, priceFilter, selectedOnly, pageSize]);
    useEffect(() => { setSelected((current) => current.every((id) => byId.has(id)) ? current : current.filter((id) => byId.has(id))); }, [byId]);
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
    };
    const setSelection = (ids) => {
        if (ids.length > 200) { setStatus({ error: t("Pilih maksimal 200 baris dalam satu operasi."), success: "" }); return; }
        setSelected(ids);
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
        setStatus({ error: "", success: t("Perubahan diterapkan ke draf pilihan. Simpan untuk mempublikasikannya.") });
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
    const mutate = async () => {
        if (!confirmation || mutationInFlight.current || disabled || confirmation.needsReview) return;
        const operation = confirmation;
        mutationInFlight.current = true;
        setBusy(true);
        setStatus({ error: "", success: "" });
        if (operation.type === "delete") setConfirmation((current) => ({ ...current, error: "" }));
        try {
            await apiRequest("/api/admin/ai/models/bulk", {
                method: operation.type === "delete" ? "DELETE" : "PATCH",
                body: operation.type === "delete" ? { ids: operation.ids, expected_count: operation.ids.length, delete_usage_rates: true } : { items: operation.items },
            });
            setDrafts((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !operation.ids.includes(Number(id)))));
            setRowErrors((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !operation.ids.includes(Number(id)))));
            if (operation.type === "delete") {
                setSelected((current) => current.filter((id) => !operation.ids.includes(id)));
                setExpanded((current) => current.filter((id) => !operation.ids.includes(id)));
            }
            setStatus({ error: "", success: `${operation.ids.length} ${t(operation.type === "delete" ? "baris dihapus. Riwayat penggunaan dipertahankan." : "baris tersimpan.")}` });
            try {
                await onRefresh();
            } catch {
                setStatus({ error: t("Perubahan tersimpan. Muat ulang katalog untuk memperbarui daftar."), success: "" });
            }
            if (operation.type === "delete") deletionCompleted.current = true;
            setConfirmation(null);
        } catch (error) {
            if (operation.type === "delete") {
                const mediaBusy = error.status === 409 && error.details?.message === "Selected models have active or unreconciled media jobs. Finish or refund those jobs before deleting.";
                const needsReview = (error.status === 409 && !mediaBusy) || error.status === 404;
                const message = t(mediaBusy
                    ? "Model masih memiliki pekerjaan media aktif atau kredit yang dicadangkan. Selesaikan pekerjaan atau rekonsiliasi tagihannya sebelum menghapus."
                    : needsReview
                      ? "Pilihan model berubah. Tutup dialog, muat ulang katalog, lalu konfirmasi ulang."
                      : [401, 419].includes(error.status)
                        ? "Sesi Anda telah berakhir. Masuk kembali sebelum menghapus."
                        : error.status === 403
                          ? "Anda tidak memiliki izin untuk menghapus model."
                          : "Penghapusan model belum dikonfirmasi. Pilihan dan draf tetap tersedia; muat ulang katalog sebelum mencoba lagi.");
                setConfirmation((current) => ({ ...current, error: message, needsReview }));
                setStatus({ error: message, success: "" });
            } else {
                const errors = {};
                Object.entries(error.details?.errors || {}).forEach(([key, messages]) => {
                    const match = key.match(/^items\.(\d+)(?:\.(.+))?$/);
                    if (match && operation.ids[Number(match[1])] != null) {
                        const id = operation.ids[Number(match[1])];
                        errors[id] = { ...errors[id], [match[2] || "row"]: messages };
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
    const tableInput = "ui-input min-h-9 w-24 px-2";
    const numericInput = "ui-input min-h-9 w-24 px-2 text-right tabular-nums";

    return <div className="min-w-0 [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50" aria-busy={busy}>
        <div className="flex flex-wrap gap-3 border-b border-slate-200 p-4 dark:border-white/10">
            <label className="min-w-48 flex-1 text-xs font-medium">{t("Cari model")}
                <input ref={searchInput} type="search" className="ui-input mt-1 min-h-10" placeholder={t("ID, nama, atau provider")} value={search} onChange={(event) => setSearch(event.target.value)} />
            </label>
            {providerId === undefined && <label className="text-xs font-medium">{t("Penyedia")}
                <select className="ui-input mt-1 min-h-10" value={providerFilter} onChange={(event) => setProviderFilter(event.target.value)}>
                    <option value="">{t("Semua penyedia")}</option>
                    {providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name || provider.slug}</option>)}
                </select>
            </label>}
            <label className="text-xs font-medium">{t("Kategori")}
                <select className="ui-input mt-1 min-h-10" value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)}><option value="">{t("Semua kategori")}</option>{categories.map((category) => <option key={category}>{category}</option>)}</select>
            </label>
            <label className="text-xs font-medium">{t("Publikasi")}
                <select className="ui-input mt-1 min-h-10" value={publicationFilter} onChange={(event) => setPublicationFilter(event.target.value)}><option value="">{t("Semua status")}</option><option value="true">{t("Dipublikasikan")}</option><option value="false">{t("Draf")}</option></select>
            </label>
            <label className="text-xs font-medium">{t("Harga")}
                <select className="ui-input mt-1 min-h-10" value={priceFilter} onChange={(event) => setPriceFilter(event.target.value)}><option value="">{t("Semua harga")}</option><option value="priced">{t("Sudah berharga")}</option><option value="unpriced">{t("Belum berharga")}</option></select>
            </label>
        </div>
        <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-white/10">
            <label className="min-w-44 flex-1 text-xs font-medium">{t("Pilih ID baris secara eksplisit")}
                <input className="ui-input mt-1 min-h-10" value={explicitIds} onChange={(event) => setExplicitIds(event.target.value)} placeholder="12, 24, 31" disabled={locked} />
            </label>
            <button className="ui-btn-secondary" type="button" disabled={locked || !explicitIds.trim()} onClick={selectExplicit}>{t("Gunakan ID ini")}</button>
            <label className="flex min-h-10 items-center gap-2 text-xs"><input type="checkbox" checked={selectedOnly} onChange={(event) => setSelectedOnly(event.target.checked)} />{t("Tampilkan pilihan saja")}</label>
        </div>
        {selected.length > 0 && <div className="flex flex-wrap items-end gap-3 bg-slate-50 p-4 dark:bg-white/5">
            <label className="text-xs font-medium">{t("Ubah pilihan sekaligus")}
                <select className="ui-input mt-1 min-h-10" value={massField} disabled={locked} onChange={(event) => { setMassField(event.target.value); setMassValue(event.target.value === "is_enabled" ? "false" : ""); }}>
                    <option value="token_cost">{t("Token per hasil")}</option><option value="category">{t("Kategori")}</option><option value="is_enabled">{t("Publikasi")}</option><option value="sort_order">{t("Urutan tampil")}</option>
                </select>
            </label>
            <label className="min-w-32 flex-1 text-xs font-medium">{t("Nilai baru")}
                {massField === "is_enabled" ? <select className="ui-input mt-1 min-h-10" value={massValue} disabled={locked} onChange={(event) => setMassValue(event.target.value)}><option value="false">{t("Draf")}</option><option value="true">{t("Dipublikasikan")}</option></select>
                    : <input className="ui-input mt-1 min-h-10" type={["token_cost", "sort_order"].includes(massField) ? "number" : "text"} value={massValue} disabled={locked} onChange={(event) => setMassValue(event.target.value)} />}
            </label>
            <button className="ui-btn-secondary" type="button" disabled={locked} onClick={applyMass}>{t("Terapkan ke draf")} ({selected.length})</button>
        </div>}
        {(status.error || status.success) && <p role={status.error ? "alert" : "status"} className={`px-4 py-3 text-sm ${status.error ? "text-red-700 dark:text-red-300" : "text-emerald-700 dark:text-emerald-300"}`}>{status.error || status.success}</p>}
        <div className="max-w-full overflow-x-auto">
            <table className="w-full text-left text-xs text-slate-700 dark:text-slate-200">
                <caption className="sr-only">{t(mediaOnly ? "Harga token dan konfigurasi model media" : "Edit model secara massal")}</caption>
                <thead className="bg-slate-50 text-slate-600 dark:bg-white/5 dark:text-slate-400"><tr>
                    <th className="p-3"><input type="checkbox" aria-label={t("Pilih halaman ini")} checked={allPageSelected} disabled={locked || !pageRows.length} onChange={togglePage} /></th>
                    <th className="p-3">{t("Model")}</th><th className="p-3">{t("Kategori")}</th><th className="p-3">{t("Token per hasil")}</th><th className="p-3">{t("Urutan")}</th><th className="p-3">{t("Publikasi / upstream")}</th><th className="p-3">{t("Tindakan")}</th>
                </tr></thead>
                <tbody>{pageRows.map((model) => {
                    const draft = drafts[model.id] || {};
                    const row = { ...model, ...draft };
                    const isMedia = mediaCategories.includes(row.category);
                    const isExpanded = expanded.includes(model.id);
                    const generationReadOnly = !!model.generation_config_readonly;
                    const config = (!generationReadOnly && draft.configDraft) || generationConfigDraft(model.generation_config);
                    const errors = rowErrors[model.id] || {};
                    return <Fragment key={model.id}>
                        <tr className={`border-t border-slate-200 align-top dark:border-white/10 ${selection.has(model.id) ? "bg-red-50/60 dark:bg-red-500/5" : ""}`}>
                            <td className="p-3"><input type="checkbox" aria-label={`${t("Pilih model")} ${model.model_id}`} checked={selection.has(model.id)} disabled={locked} onChange={() => setSelection(selection.has(model.id) ? selected.filter((id) => id !== model.id) : [...selected, model.id])} /></td>
                            <td className="min-w-48 max-w-64 p-3"><strong className="block whitespace-normal text-slate-900 dark:text-white">{model.display_name || model.model_id}</strong><span className="mt-1 flex items-center gap-1.5 break-all font-mono text-xs">#{model.id} · {model.model_id}
                                <button type="button" className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md border transition-colors motion-reduce:transition-none ${copiedId === model.id ? "border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300" : "border-slate-200 text-slate-500 hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10"}`} aria-label={`${t(copiedId === model.id ? "Tersalin" : "Salin ID model")} ${model.model_id}`} title={t(copiedId === model.id ? "Tersalin" : "Salin ID model")} onClick={() => { navigator.clipboard?.writeText(model.model_id); setCopiedId(model.id); setTimeout(() => setCopiedId((current) => current === model.id ? null : current), 1600); }}>
                                    {copiedId === model.id
                                        ? <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-3 w-3"><path d="M20 6 9 17l-5-5" /></svg>
                                        : <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" className="h-3 w-3"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>}
                                </button></span><span className="mt-1 block text-slate-500 dark:text-slate-400">{model.provider_name || model.provider?.name || "—"}</span>{drafts[model.id] && <span className="mt-2 block font-semibold text-amber-700 dark:text-amber-300">{t("Belum disimpan")}</span>}{errors.row && fieldError(model.id, "row")}{errors.rates && fieldError(model.id, "rates")}
                                <span className="mt-2 block tabular-nums text-slate-500 dark:text-slate-400">{isMedia
                                    ? `${t("Tarif unit USD (terpisah)")}: ${model.rates?.unit?.price_usd == null ? t("Belum tersedia") : `$${model.rates.unit.price_usd}`}`
                                    : model.rates?.input_tokens?.price_usd == null && model.rates?.output_tokens?.price_usd == null
                                        ? `USD / 1M: ${t("Belum tersedia")}`
                                        : `USD / 1M: ${model.rates?.input_tokens?.price_usd ?? "—"} / ${model.rates?.output_tokens?.price_usd ?? "—"}`}</span>
                            </td>
                            <td className="p-3"><input className={tableInput} aria-label={`${t("Kategori")} ${model.model_id}`} value={row.category || ""} maxLength={32} disabled={locked} aria-invalid={!!errors.category} onChange={(event) => patch(model.id, "category", event.target.value)} />{fieldError(model.id, "category")}</td>
                            <td className="p-3"><input className={numericInput} aria-label={`${t("Token per hasil")} ${model.model_id}`} type="number" min="1" max="2147483647" step="1" value={row.token_cost ?? ""} placeholder={t("Belum diatur")} disabled={locked || !isMedia} aria-invalid={!!errors.token_cost} onChange={(event) => patch(model.id, "token_cost", event.target.value)} />{fieldError(model.id, "token_cost")}</td>
                            <td className="p-3"><input className="ui-input min-h-9 w-16 px-2 text-right tabular-nums" aria-label={`${t("Urutan")} ${model.model_id}`} type="number" min="0" max="65535" step="1" value={row.sort_order ?? 0} disabled={locked} aria-invalid={!!errors.sort_order} onChange={(event) => patch(model.id, "sort_order", event.target.value)} />{fieldError(model.id, "sort_order")}</td>
                            <td className="min-w-36 p-3"><label className="flex min-h-9 items-center gap-2"><input type="checkbox" aria-label={`${t("Publikasikan")} ${model.model_id}`} checked={!!row.is_enabled} disabled={locked} onChange={(event) => patch(model.id, "is_enabled", event.target.checked)} />{t(row.is_enabled ? "Dipublikasikan" : "Draf")}</label><span className="mt-1 block text-slate-500 dark:text-slate-400">{t(model.is_available ? "Tersedia di upstream" : "Belum tersedia di upstream")}</span></td>
                            <td className="min-w-36 max-w-44 p-3"><div className="flex flex-wrap gap-1">
                                {isMedia && <button type="button" className="ui-btn-mini" aria-expanded={isExpanded} disabled={locked} onClick={() => setExpanded((current) => isExpanded ? current.filter((id) => id !== model.id) : [...current, model.id])}>{t("Konfigurasi")}</button>}
                                {onEdit && <button type="button" className="ui-btn-mini" disabled={locked || !!drafts[model.id]} onClick={() => onEdit(model)}>{t("Edit model")}</button>}
                                {onToggle && <button type="button" className="ui-btn-mini" disabled={locked || !!drafts[model.id]} onClick={() => onToggle(model)}>{t(model.is_enabled ? "Nonaktifkan" : "Aktifkan")}</button>}
                                <button type="button" className="ui-btn-mini ui-btn-mini-danger" disabled={locked} onClick={(event) => prepareDeletion([model.id], event)} aria-label={`${t("Hapus model")} ${model.display_name || model.model_id}`}>{t("Hapus")}</button>
                            </div></td>
                        </tr>
                        {isMedia && isExpanded && <tr className="border-t border-slate-200 dark:border-white/10"><td colSpan={7} className="p-4"><div className="max-w-3xl">
                            {generationReadOnly && <p className="mb-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Pengaturan generasi fal mengikuti skema model yang didukung dan tidak dapat diubah di sini. Harga, biaya token, dan publikasi tetap dapat diedit.")}</p>}
                            <GenerationConfigFields category={row.category} value={config} onChange={(value) => patch(model.id, "configDraft", value)} disabled={locked || generationReadOnly} errors={generationReadOnly ? undefined : Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith("generation_config.")).map(([key, value]) => [key.slice(18), value]))} />
                            {!generationReadOnly && fieldError(model.id, "generation_config")}
                        </div></td></tr>}
                    </Fragment>;
                })}</tbody>
            </table>
            {!pageRows.length && <p className="p-6 text-sm text-slate-600 dark:text-slate-400">{t(scopedModels.length ? "Tidak ada model yang cocok. Ubah pencarian atau filter." : "Belum ada model. Sinkronkan katalog dari koneksi provider.")}</p>}
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-xs dark:border-white/10">
            <label>{t("Baris per halaman")} <select className="ui-input ml-2 min-h-10 w-auto" value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))}>{pageSizeOptions.map((size) => <option key={size}>{size}</option>)}</select></label>
            <span className="tabular-nums">{visible.length} {t("model")} · {t("Halaman")} {currentPage}/{lastPage}</span>
            <div className="flex gap-2"><button type="button" className="ui-btn-secondary" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>{t("Sebelumnya")}</button><button type="button" className="ui-btn-secondary" disabled={currentPage >= lastPage} onClick={() => setPage(currentPage + 1)}>{t("Berikutnya")}</button></div>
        </div>
        <div className="sticky bottom-0 z-20 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
            <p className="text-xs tabular-nums">{selected.length} {t("dipilih")} ({selectedOffPage} {t("di luar halaman ini")}) · {dirtyIds.length} {t("draf berubah")}</p>
            <div className="flex flex-wrap gap-2">
                {!!selected.length && <button type="button" className="ui-btn-secondary" disabled={locked} onClick={() => setSelected([])}>{t("Batalkan pilihan")}</button>}
                {!!dirtyIds.length && <button type="button" className="ui-btn-secondary" disabled={locked} onClick={() => { setDrafts({}); setRowErrors({}); }}>{t("Buang draf")}</button>}
                <button type="button" className="ui-btn-secondary text-red-700 dark:text-red-300" disabled={locked || !selected.length} onClick={(event) => prepareDeletion(selected, event)}>{t("Hapus pilihan")} ({selected.length})</button>
                <button type="button" className="ui-btn-secondary" disabled={locked || !selectedDirty.length} onClick={() => prepareSave(selectedDirty)}>{t("Simpan pilihan")} ({selectedDirty.length})</button>
                <button type="button" className="ui-btn-primary" disabled={locked || !dirtyIds.length} onClick={() => prepareSave(dirtyIds)}>{t("Simpan perubahan")} ({dirtyIds.length})</button>
            </div>
        </div>
        {confirmation?.type === "save" && <BulkConfirmDialog title={t("Simpan perubahan model?")} count={confirmation.ids.length} rows={confirmation.ids.map((id) => ({ id, label: byId.get(id)?.model_id || String(id) }))} description={t("Seluruh baris ini disimpan dalam satu transaksi. Jika satu baris tidak valid, tidak ada perubahan yang disimpan. Publikasi tidak mengubah ketersediaan upstream.")} busy={busy} onCancel={() => { if (!mutationInFlight.current) setConfirmation(null); }} onConfirm={mutate} />}
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
