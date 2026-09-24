import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest, formatDateTime } from "../../lib/api";
import ProviderConnections from "../../components/dashboard/ProviderConnections";
import ModelBulkTable from "../../components/dashboard/ModelBulkTable";
import GenerationConfigFields, { generationConfigDraft, parseGenerationConfig } from "../../components/dashboard/GenerationConfigFields";
import { LoadingState, ErrorState } from "../../components/dashboard/AsyncState";
import MediaActionDialog from "../../components/MediaActionDialog";
import { capabilityStatuses } from "../../components/dashboard/CatalogRevisionPanel";

const mediaCategories = ["image", "video", "audio", "avatar", "model3d", "other"];
const count = (value) => new Intl.NumberFormat("id-ID").format(Number(value || 0));

const healthTone = {
    discovered: ["neutral", "Katalog terdokumentasi"],
    healthy: ["good", "Terhubung"],
    online: ["good", "Terhubung"],
    active: ["good", "Terhubung"],
    error: ["bad", "Bermasalah"],
    unavailable: ["warn", "Tidak tersedia"],
    unknown: ["neutral", "Belum diperiksa"],
};

function Icon({ name, className = "h-4 w-4" }) {
    const common = {
        className,
        fill: "none",
        stroke: "currentColor",
        strokeWidth: 2,
        viewBox: "0 0 24 24",
        strokeLinecap: "round",
        strokeLinejoin: "round",
        "aria-hidden": true,
    };
    switch (name) {
        case "chat":
            return (<svg {...common}><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" /></svg>);
        case "image":
            return (<svg {...common}><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="M21 15l-5-5L5 21" /></svg>);
        case "video":
            return (<svg {...common}><rect x="2" y="4" width="20" height="16" rx="2" /><path d="M10 9l5 3-5 3z" /></svg>);
        case "audio":
            return (<svg {...common}><path d="M9 18V5l12-2v13" /><circle cx="6" cy="18" r="3" /><circle cx="18" cy="16" r="3" /></svg>);
        case "provider":
            return (<svg {...common}><rect x="2" y="2" width="20" height="8" rx="2" /><rect x="2" y="14" width="20" height="8" rx="2" /><path d="M6 6h.01M6 18h.01" /></svg>);
        case "cube":
            return (<svg {...common}><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" /><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12" /></svg>);
        case "power":
            return (<svg {...common}><path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10" /></svg>);
        case "sync":
            return (<svg {...common}><path d="M23 4v6h-6M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>);
        case "activity":
            return (<svg {...common}><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>);
        case "alert":
            return (<svg {...common}><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><path d="M12 9v4M12 17h.01" /></svg>);
        case "spark":
            return (<svg {...common}><path d="M12 3v3m0 12v3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M3 12h3m12 0h3M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1" /></svg>);
        case "shield":
            return (<svg {...common}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>);
        case "plus":
            return (<svg {...common}><path d="M12 5v14M5 12h14" /></svg>);
        case "grid":
            return (<svg {...common}><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg>);
        case "layers":
            return (<svg {...common}><path d="M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" /></svg>);
        default:
            return null;
    }
}

function ConfirmDialog({ title, description, confirmLabel, onConfirm, onCancel, busy }) {
    const { t } = useLocale();
    return <MediaActionDialog title={title} description={description} confirmLabel={confirmLabel} closeLabel={t("Batal")} busyLabel={t("Memproses…")} onConfirm={onConfirm} onClose={onCancel} busy={busy} />;
}

/**
 * One provider = one page. The list page stays a pure card grid; everything
 * operational (models, connection, settings, audit trail) lives here in tabs.
 * The model editor dialog and its handlers moved verbatim from the old
 * catalogue page so the mutation contract with the API is unchanged.
 */
export default function ProviderDetail() {
    const { t, localizedPath } = useLocale();
    const { providerId } = useParams();
    const navigate = useNavigate();
    const [catalog, setCatalog] = useState({ data: null, loading: true, error: "" });
    const [modelPage, setModelPage] = useState({ data: null, loading: true, error: "" });
    const [query, setQuery] = useState({ q: "", category: "", status: "", sort: "display_name", direction: "asc", page: 1, per_page: 25 });
    const [discovery, setDiscovery] = useState({ busy: false, error: "", nextCursor: null, started: false, discovered: 0, imported: 0 });
    const [discoveryLimit, setDiscoveryLimit] = useState(10);
    const [tab, setTab] = useState("models");
    const [audit, setAudit] = useState({ rows: [], loading: false, error: "" });
    const [confirmation, setConfirmation] = useState(null);
    const [modelEditor, setModelEditor] = useState(null);
    const [providerOp, setProviderOp] = useState({ busy: false, error: "", success: "" });
    const [autoPrice, setAutoPrice] = useState({ margin: "40", idr: "16000", overwrite: false });
    const [autoState, setAutoState] = useState({ busy: false, error: "", success: "" });
    const [mutation, setMutation] = useState({ busy: false, error: "", success: "", fields: {} });
    const discoveryInFlight = useRef(false);
    const currentProviderId = useRef(providerId);
    const editorDialog = useRef(null);
    const editorOpen = !!modelEditor;
    useEffect(() => {
        if (!editorOpen) return;
        const dialog = editorDialog.current;
        const previousFocus = document.activeElement;
        dialog.showModal();
        return () => {
            dialog.close();
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
        };
    }, [editorOpen]);

    const catalogRequest = useRef(0);
    const loadSummary = useCallback(async (signal) => {
        const requestId = ++catalogRequest.current;
        setCatalog((current) => ({ ...current, loading: true, error: "" }));
        try {
            const data = await apiRequest("/api/admin/ai/catalog/summary", { signal });
            if (!signal?.aborted && requestId === catalogRequest.current) setCatalog({ data, loading: false, error: "" });
        } catch (error) {
            if (error?.name !== "AbortError" && !signal?.aborted && requestId === catalogRequest.current)
                setCatalog((current) => ({ ...current, loading: false, error: error.message || t("Katalog AI tidak dapat dimuat.") }));
        }
    }, []);
    const modelsRequest = useRef(0);
    const loadModels = useCallback(async (signal) => {
        const requestId = ++modelsRequest.current;
        setModelPage((current) => ({ ...current, loading: true, error: "" }));
        try {
            const params = new URLSearchParams(query);
            const data = await apiRequest(`/api/admin/ai/providers/${providerId}/models?${params}`, { signal });
            if (!signal?.aborted && requestId === modelsRequest.current) {
                setModelPage({ data, loading: false, error: "" });
                if (query.page > Math.max(1, data.meta.last_page)) setQuery((current) => ({ ...current, page: Math.max(1, data.meta.last_page) }));
            }
        } catch (error) {
            if (!signal?.aborted && requestId === modelsRequest.current) setModelPage((current) => ({ ...current, loading: false, error: error.message || t("Daftar model tidak dapat dimuat.") }));
        }
    }, [providerId, query]);
    const latestModelsLoader = useRef(loadModels);
    useEffect(() => { latestModelsLoader.current = loadModels; }, [loadModels]);
    const loadCatalog = useCallback(async () => { await Promise.all([loadSummary(), latestModelsLoader.current()]); }, [loadSummary]);
    const changeQuery = useCallback((patch) => setQuery((current) => ({ ...current, ...patch, page: patch.page ?? 1 })), []);
    useEffect(() => {
        const controller = new AbortController();
        loadSummary(controller.signal);
        return () => controller.abort();
    }, [loadSummary]);
    useEffect(() => {
        const controller = new AbortController();
        loadModels(controller.signal);
        return () => controller.abort();
    }, [loadModels]);
    useEffect(() => {
        if (currentProviderId.current === providerId) return;
        currentProviderId.current = providerId;
        setQuery({ q: "", category: "", status: "", sort: "display_name", direction: "asc", page: 1, per_page: 25 });
        setDiscovery({ busy: false, error: "", nextCursor: null, started: false, discovered: 0, imported: 0 });
    }, [providerId]);
    useEffect(() => {
        if (tab !== "activity") return;
        const controller = new AbortController();
        setAudit({ rows: [], loading: true, error: "" });
        const params = new URLSearchParams({ provider_id: String(providerId), per_page: "50" });
        apiRequest(`/api/admin/audit?${params}`, { signal: controller.signal })
            .then((page) => { if (!controller.signal.aborted) setAudit({ rows: page?.data || [], loading: false, error: "" }); })
            .catch((error) => { if (!controller.signal.aborted) setAudit({ rows: [], loading: false, error: error.message || t("Aktivitas tidak dapat dimuat.") }); });
        return () => controller.abort();
    }, [providerId, tab]);

    const providers = catalog.data?.providers || [];
    const provider = providers.find((entry) => String(entry.id) === String(providerId)) || null;
    const models = modelPage.data?.models || [];
    const editorProvider = modelEditor ? providers.find((candidate) => candidate.slug === modelEditor.provider_slug) : null;
    const generationConfigReadOnly = ["fal", "kinovi"].includes(editorProvider?.protocol) || (modelEditor?.generation_config_readonly && modelEditor.provider_slug === modelEditor.original_provider_slug);
    const discoverModels = async () => {
        if (discoveryInFlight.current) return;
        discoveryInFlight.current = true;
        setDiscovery((current) => ({ ...current, busy: true, error: "" }));
        try {
            const result = await apiRequest(`/api/admin/ai/providers/${provider.id}/discover`, {
                method: "POST",
                body: { limit: discoveryLimit, ...(discovery.nextCursor ? { cursor: discovery.nextCursor } : {}) },
            });
            if (currentProviderId.current !== providerId) return;
            setDiscovery({ busy: false, error: "", started: true, nextCursor: result.next_cursor, discovered: result.discovered, imported: result.imported });
            await loadCatalog();
        } catch (error) {
            setDiscovery((current) => ({ ...current, busy: false, error: error.message || t("Impor belum selesai. Coba lagi dari halaman yang sama.") }));
        } finally {
            discoveryInFlight.current = false;
        }
    };

    const updateModel = async (model, patch, success) => {
        setMutation({ busy: true, error: "", success: "", fields: {} });
        try {
            await apiRequest(`/api/admin/ai/models/${model.id}`, {
                method: "PATCH",
                body: patch,
            });
            setConfirmation(null);
            setModelEditor(null);
            setMutation({ busy: false, error: "", fields: {}, success });
            await loadCatalog();
        } catch (error) {
            setConfirmation(null);
            setMutation({
                busy: false,
                error: error.message || t("Metadata model tidak dapat diperbarui."),
                success: "",
                fields: error.details?.errors || {},
            });
        }
    };

    const createModel = async (patch) => {
        setMutation({ busy: true, error: "", success: "", fields: {} });
        try {
            await apiRequest("/api/admin/ai/models", {
                method: "POST",
                body: patch,
            });
            setConfirmation(null);
            setModelEditor(null);
            setMutation({
                busy: false,
                error: "",
                fields: {},
                success: t(
                    "Model dibuat. Tinjau koneksi, harga, dan capability sebelum mengaktifkannya.",
                ),
            });
            await loadCatalog();
        } catch (error) {
            setConfirmation(null);
            setMutation({
                busy: false,
                error: error.message || t("Model tidak dapat dibuat."),
                success: "",
                fields: error.details?.errors || {},
            });
        }
    };

    const emptyModelDraft = (model) => ({
        id: model.id,
        model_id: model.model_id,
        display_name: model.display_name || "",
        provider_name: model.provider_name || "",
        provider_slug: model.provider_slug || model.provider?.slug || "",
        original_provider_slug: model.provider_slug || model.provider?.slug || "",
        generation_config_readonly: !!model.generation_config_readonly,
        upstream_model_id: model.upstream_model_id || (model.id ? model.model_id : ""),
        category: model.category || "chat",
        description_id: model.description_id || "",
        description_en: model.description_en || "",
        logo_url: model.logo_url || "",
        context_window: model.context_window ?? "",
        max_output_tokens: model.max_output_tokens ?? "",
        capabilities: (model.capabilities || []).join(", "),
        input_modalities: (model.input_modalities || []).join(", "),
        output_modalities: (model.output_modalities || []).join(", "),
        badges: (model.badges || []).join(", "),
        sort_order: model.sort_order ?? 0,
        is_enabled: !!model.is_enabled,
        token_cost: model.token_cost ?? "",
        configDraft: generationConfigDraft(model.generation_config),
        rate_unit: model.rates?.unit?.price_usd ?? "",
        rate_input: model.rates?.input_tokens?.price_usd ?? "",
        rate_output: model.rates?.output_tokens?.price_usd ?? "",
        rate_cache_read: model.rates?.cache_read?.price_usd ?? "",
        rate_cache_write: model.rates?.cache_write?.price_usd ?? "",
    });

    const openModelEditor = (model) => {
        setMutation({ busy: false, error: "", success: "", fields: {} });
        setModelEditor(emptyModelDraft(model));
    };

    const openNewModelEditor = () => {
        setMutation({ busy: false, error: "", success: "", fields: {} });
        setModelEditor(
            emptyModelDraft({
                id: null,
                model_id: "",
                display_name: "",
                provider_slug: provider.slug,
                is_enabled: false,
                sort_order: 0,
            }),
        );
    };

    const saveModel = (event) => {
        event.preventDefault();
        const displayName = (modelEditor.display_name || "").trim();
        if (!displayName) {
            setMutation((current) => ({
                ...current,
                fields: { display_name: t("Nama tampilan wajib diisi.") },
            }));
            return;
        }
        if (!providers.some((provider) => provider.slug === modelEditor.provider_slug)) {
            setMutation((current) => ({
                ...current,
                fields: { provider_slug: t("Pilih koneksi provider yang tersedia. Tambahkan provider terlebih dahulu jika katalog kosong.") },
            }));
            return;
        }
        const upstreamModelId = modelEditor.upstream_model_id.trim();
        if (!upstreamModelId) {
            setMutation((current) => ({
                ...current,
                fields: { upstream_model_id: t("Masukkan ID model upstream untuk koneksi yang dipilih.") },
            }));
            return;
        }
        const generation = generationConfigReadOnly ? null : parseGenerationConfig(modelEditor.configDraft);
        if (generation && Object.keys(generation.errors).length) {
            setMutation((current) => ({ ...current, fields: Object.fromEntries(Object.entries(generation.errors).map(([key, value]) => [`generation_config.${key}`, value])) }));
            return;
        }
        const list = (value) =>
            (value || "")
                .split(",")
                .map((item) => item.trim())
                .filter(Boolean);
        const numberOrNull = (value) =>
            value === "" || value === null ? null : Number(value);
        const patch = {
            display_name: displayName,
            provider_name: modelEditor.provider_name || null,
            provider_slug: modelEditor.provider_slug,
            upstream_model_id: upstreamModelId || null,
            category: modelEditor.category || "chat",
            description_id: modelEditor.description_id || null,
            description_en: modelEditor.description_en || null,
            logo_url: modelEditor.logo_url || null,
            context_window: numberOrNull(modelEditor.context_window),
            max_output_tokens: numberOrNull(modelEditor.max_output_tokens),
            token_cost: numberOrNull(modelEditor.token_cost),
            ...(generation ? { generation_config: generation.config } : {}),
            capabilities: list(modelEditor.capabilities),
            input_modalities: list(modelEditor.input_modalities),
            output_modalities: list(modelEditor.output_modalities),
            badges: list(modelEditor.badges),
            sort_order: Number(modelEditor.sort_order) || 0,
            is_enabled: !!modelEditor.is_enabled,
            rates: mediaCategories.includes(modelEditor.category)
                ? { unit: numberOrNull(modelEditor.rate_unit) }
                : {
                    input_tokens: numberOrNull(modelEditor.rate_input),
                    output_tokens: numberOrNull(modelEditor.rate_output),
                    cache_read: numberOrNull(modelEditor.rate_cache_read),
                    cache_write: numberOrNull(modelEditor.rate_cache_write),
                },
        };
        if (modelEditor.id) {
            updateModel(modelEditor, patch, t("Profil model diperbarui."));
            return;
        }
        const modelId = (modelEditor.model_id || "").trim();
        if (!modelId) {
            setMutation((current) => ({
                ...current,
                fields: { model_id: t("ID model wajib diisi.") },
            }));
            return;
        }
        createModel({ ...patch, model_id: modelId });
    };

    const toggleProvider = async () => {
        setProviderOp({ busy: true, error: "", success: "" });
        try {
            await apiRequest(`/api/admin/ai/providers/${provider.id}`, {
                method: "PATCH",
                body: { is_enabled: !provider.is_enabled },
            });
            setProviderOp({ busy: false, error: "", success: t(provider.is_enabled ? "Koneksi dinonaktifkan." : "Koneksi diaktifkan.") });
            await loadCatalog();
        } catch (error) {
            setProviderOp({ busy: false, success: "", error: t(error.message || "Operasi provider gagal.") });
        }
    };

    const runAutoPrice = async () => {
        setAutoState({ busy: true, error: "", success: "" });
        try {
            const result = await apiRequest("/api/pricing/rates/auto", {
                method: "POST",
                body: {
                    margin: 1 + (Number(autoPrice.margin) / 100),
                    idr_per_usd: Number(autoPrice.idr),
                    overwrite: autoPrice.overwrite,
                },
            });
            setAutoState({ busy: false, error: "", success: `${t("Harga input & output dibuat otomatis.")} ${count(result?.updated_count ?? 0)} ${t("tarif ditulis.")}` });
            await loadCatalog();
        } catch (error) {
            setAutoState({ busy: false, success: "", error: t(error.message || "Gagal membuat harga otomatis.") });
        }
    };

    if (catalog.loading && !catalog.data) return <div className="ui-card-flat p-6"><LoadingState label={t("Memuat penyedia…")} /></div>;
    if (catalog.error && !catalog.data) return <div className="ui-card-flat p-6"><ErrorState message={catalog.error} onRetry={() => loadCatalog()} /></div>;
    if (!provider) {
        return (
            <div className="ui-card-flat p-6">
                <p className="text-sm">{t("Penyedia tidak ditemukan.")}</p>
                <Link className="ui-btn-secondary mt-3 inline-flex" to={localizedPath("/admin/ai")}>{t("Kembali ke katalog")}</Link>
            </div>
        );
    }

    const [tone, label] = !provider.is_enabled ? ["neutral", "Nonaktif"] : healthTone[String(provider.status).toLowerCase()] || healthTone.unknown;
    const stats = provider.model_counts || {};
    const counts = modelPage.data?.counts || provider.counts || {};
    const tabs = [
        ["models", "Model & Harga"],
        ["connection", "Koneksi"],
        ["settings", "Pengaturan"],
        ["activity", "Aktivitas"],
    ];

    return (
        <div className="ui-page space-y-5">
            <nav className="pd-crumbs animate-fade-in-up motion-reduce:animate-none" aria-label={t("Navigasi")}>
                <button type="button" onClick={() => navigate(localizedPath("/admin/ai"))}>{t("Penyedia AI")}</button>
                <span aria-hidden="true">/</span>
                <strong>{provider.name || provider.slug}</strong>
            </nav>

            <header className="relative overflow-hidden rounded-2xl border border-gray-200/80 bg-white p-5 animate-fade-in-up motion-reduce:animate-none dark:border-white/[0.08] dark:bg-white/[0.02]">
                <div className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-gradient-to-br from-red-500 to-orange-500 opacity-10 blur-3xl" aria-hidden="true" />
                <div className="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/25">
                            <Icon name="provider" className="h-6 w-6" />
                        </span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold text-slate-900 dark:text-white sm:text-2xl">{provider.name || provider.slug}</h1>
                                <span className="ui-status" data-tone={tone}>{t(label)}</span>
                                {!provider.is_enabled && <span className="ui-status" data-tone="warn">{t("Nonaktif")}</span>}
                            </div>
                            <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] dark:bg-white/10">{provider.protocol}</code>
                                <span className="break-all">{provider.base_url || t("Dikelola server")}</span>
                                <span>{provider.last_checked_at ? formatDateTime(provider.last_checked_at) : t("Belum diperiksa")}</span>
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        <button
                            type="button"
                            className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-slate-700 transition-all hover:-translate-y-0.5 hover:border-red-500/40 disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transition-none motion-reduce:hover:transform-none dark:border-white/[0.12] dark:bg-white/[0.04] dark:text-slate-200"
                            onClick={() => loadCatalog()}
                            disabled={catalog.loading}
                        >
                            <Icon name="sync" className={`h-4 w-4 ${catalog.loading ? "animate-spin" : ""}`} />
                            {catalog.loading ? t("Memuat…") : t("Muat ulang")}
                        </button>
                        <button
                            type="button"
                            className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-gradient-to-r from-red-500 to-red-600 px-4 text-sm font-bold text-white shadow-lg shadow-red-500/25 transition-all hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transition-none motion-reduce:hover:transform-none"
                            onClick={openNewModelEditor}
                            disabled={mutation.busy}
                        >
                            <Icon name="plus" className="h-4 w-4" /> {t("Model baru")}
                        </button>
                    </div>
                </div>
            </header>
            {catalog.error && <ErrorState message={catalog.error} onRetry={() => loadSummary()} />}
            {provider.last_error && <p role="alert" className="ui-alert break-words" data-tone="bad">{provider.last_error}</p>}
            {provider.verification?.catalog_source === "static_documentation" && <p className="text-sm leading-6 text-slate-600 dark:text-slate-400">{t("Katalog dari dokumentasi; autentikasi dan generasi belum diverifikasi.")}</p>}

            <div className="pd-stats animate-fade-in-up motion-reduce:animate-none">
                <div><b>{count(stats.total)}</b><span>{t("Model")}</span></div>
                <div><b>{count(stats.enabled)}</b><span>{t("Aktif")}</span></div>
                <div><b>{count(counts.published)}</b><span>{t("Capability terbit")}</span></div>
                <div data-warn={stats.unpriced > 0 ? "" : undefined}><b>{count(stats.unpriced)}</b><span>{t("Belum berharga")}</span></div>
            </div>

            {(mutation.error || mutation.success || providerOp.error || providerOp.success) && (
                <div
                    className={`rounded-xl border p-3 text-xs ${(mutation.error || providerOp.error) ? "border-red-500/25 bg-red-500/5 text-red-700 dark:text-red-300" : "border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300"}`}
                    role={(mutation.error || providerOp.error) ? "alert" : "status"}
                >
                    {mutation.error || providerOp.error || mutation.success || providerOp.success}
                </div>
            )}

            <div className="pw-tabs" role="tablist" aria-label={t("Bagian penyedia")}>
                {tabs.map(([id, tabLabel]) => (
                    <button key={id} id={`provider-tab-${id}`} type="button" role="tab" aria-controls={`provider-panel-${id}`} aria-selected={tab === id} tabIndex={tab === id ? 0 : -1} className="pw-tab" onClick={() => setTab(id)} onKeyDown={(event) => {
                        const index = tabs.findIndex(([value]) => value === id);
                        const next = event.key === "ArrowRight" ? (index + 1) % tabs.length : event.key === "ArrowLeft" ? (index - 1 + tabs.length) % tabs.length : event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : null;
                        if (next === null) return;
                        event.preventDefault();
                        setTab(tabs[next][0]);
                        event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[next].focus();
                    }}>
                        {t(tabLabel)}
                    </button>
                ))}
            </div>

            {tab === "models" && (
                <section id="provider-panel-models" role="tabpanel" aria-labelledby="provider-tab-models" data-provider-models className="ui-card-flat min-w-0 animate-fade-in-up motion-reduce:animate-none">
                    <div className="flex items-start gap-3 border-b border-slate-200 p-4 dark:border-white/10">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-500 to-blue-600 text-white shadow-md">
                            <Icon name="sync" className="h-4 w-4" />
                        </span>
                        <div className="min-w-0 text-xs leading-5 text-slate-600 dark:text-slate-300">
                            <p className="font-bold text-slate-900 dark:text-white">{t(provider.protocol === "fal" ? "Impor bertahap, tinjau sebelum publikasi" : "Model kurasi dan harga")}</p>
                            <p className="mt-0.5">{t(provider.protocol === "fal" ? "Setiap klik mengambil satu halaman schema, maksimal 10 model. Label dan harga kurasi dipertahankan; model yang belum terlihat di halaman ini tidak dinonaktifkan." : "Sinkronkan metadata dari tab Koneksi. Impor OpenAPI bertahap tersedia untuk fal; provider ini tetap memakai integrasi dan konfigurasi kurasi yang didukung.")}</p>
                        </div>
                    </div>
                    <div className="space-y-3 border-b border-slate-200 p-4 dark:border-white/10">
                        {provider.protocol === "fal" ? <>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="text-xs font-medium">{t("Batas impor per halaman")}<select className="ui-input mt-1 min-h-11" value={discoveryLimit} disabled={discovery.busy} onChange={(event) => setDiscoveryLimit(Number(event.target.value))}><option value={5}>5</option><option value={10}>10</option></select></label>
                            <button type="button" className="ui-btn-primary min-h-11" disabled={discovery.busy || !provider.is_enabled} onClick={discoverModels}>{t(discovery.busy ? "Mengimpor schema…" : discovery.nextCursor ? "Lanjutkan halaman impor" : discovery.started ? "Mulai impor ulang" : "Impor halaman pertama")}</button>
                        </div>
                        {!provider.is_enabled && <p className="text-xs text-slate-600 dark:text-slate-400">{t("Aktifkan koneksi sebelum mengimpor schema.")}</p>}
                        {discovery.started && <p role="status" className="text-sm text-slate-700 dark:text-slate-300">{t("Halaman terakhir")}: {count(discovery.discovered)} {t("ditemukan")}, {count(discovery.imported)} {t("diimpor")}. {t(discovery.nextCursor ? "Masih ada halaman berikutnya. Lanjutkan saat siap." : "Impor mencapai halaman terakhir. Tinjau revisi sebelum publikasi.")}</p>}
                        {discovery.nextCursor && <details><summary className="cursor-pointer text-xs font-semibold">{t("Cursor halaman berikutnya")}</summary><code className="mt-2 block break-all text-xs">{discovery.nextCursor}</code></details>}
                        {discovery.error && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{discovery.error}</p>}
                        <p className="max-w-prose text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Pilih model baru tanpa harga, isi draf biaya token, lalu gunakan Tinjau harga dan publikasi. Maksimal 50 kandidat per konfirmasi; harga positif dan tarif API yang sudah ada tidak ditimpa.")}</p>
                        <details className="text-xs leading-6"><summary className="cursor-pointer font-semibold">{t("Normalisasi ulang schema tersimpan tanpa jaringan")}</summary>
                            <p className="mt-2">{t("Perintah administrator berikut membuat kandidat v2, bukan publikasi. Setiap model dilaporkan; gunakan next_after dari ringkasan untuk melanjutkan. Schema kosong hanya dipulihkan dari dokumentasi resmi yang tercatat; selain itu temukan ulang dari sumber.")}</p>
                            <code className="mt-2 block break-all rounded bg-slate-100 p-2 dark:bg-white/5">php artisan media:renormalize-catalog --provider={provider.id} --actor=ADMIN_ID --after=0 --limit=100</code>
                        </details>
                        </> : <button type="button" className="ui-btn-secondary min-h-11" onClick={() => setTab("connection")}>{t("Buka koneksi provider")}</button>}
                        <dl className="flex flex-wrap gap-x-5 gap-y-2 text-xs">
                            {Object.entries(capabilityStatuses).map(([status, [, statusLabel]]) => <div key={status} className="flex items-center gap-2"><dt>{t(statusLabel)}</dt><dd className="font-semibold tabular-nums">{count(counts[status])}</dd></div>)}
                            <div className="flex items-center gap-2"><dt>{t("Schema kompatibel")}</dt><dd className="font-semibold tabular-nums">{count(counts.compatible)}</dd></div>
                            <div className="flex items-center gap-2"><dt>{t("Harga positif")}</dt><dd className="font-semibold tabular-nums">{count(counts.priced)}</dd></div>
                        </dl>
                    </div>
                    <ModelBulkTable
                        key={provider.id}
                        models={models}
                        providers={providers}
                        providerId={String(provider.id)}
                        pagination={modelPage.data?.meta || { current_page: 1, last_page: 1, per_page: query.per_page, total: 0 }}
                        query={query}
                        onQueryChange={changeQuery}
                        loading={modelPage.loading}
                        error={modelPage.error}
                        onRefresh={loadCatalog}
                        onEdit={openModelEditor}
                        onToggle={(model) => setConfirmation({ type: "toggle", model })}
                        disabled={mutation.busy}
                    />
                </section>
            )}

            {tab === "connection" && (
                <div id="provider-panel-connection" role="tabpanel" aria-labelledby="provider-tab-connection" className="animate-fade-in-up motion-reduce:animate-none">
                    <ProviderConnections
                        providers={[provider]}
                        focusId={provider.id}
                        loading={catalog.loading}
                        error={catalog.error}
                        hasData={!!catalog.data}
                        onRefresh={loadCatalog}
                    />
                </div>
            )}

            {tab === "settings" && (
                <section id="provider-panel-settings" role="tabpanel" aria-labelledby="provider-tab-settings" className="space-y-4 animate-fade-in-up motion-reduce:animate-none">
                    <div className="ui-card-flat p-4">
                        <h2 className="ui-section-title mb-3">{t("Fakta koneksi")}</h2>
                        <dl className="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-3">
                            {[
                                [t("Slug"), <code key="slug" className="font-mono">{provider.slug}</code>],
                                [t("Protokol"), <code key="protocol" className="font-mono">{provider.protocol}</code>],
                                [t("URL dasar"), provider.base_url || t("Dikelola server")],
                                [t("Sumber konfigurasi"), provider.configuration_source === "environment" ? t("Konfigurasi server") : t("Dikelola admin")],
                                [t("Terakhir diperiksa"), provider.last_checked_at ? formatDateTime(provider.last_checked_at) : t("Belum diperiksa")],
                                [t("Status publikasi"), provider.is_enabled ? t("Penyedia aktif") : t("Penyedia nonaktif")],
                            ].map(([term, value]) => (
                                <div key={term} className="min-w-0">
                                    <dt className="font-bold uppercase tracking-wide text-[10px] text-slate-500 dark:text-slate-400">{term}</dt>
                                    <dd className="mt-0.5 break-all text-slate-800 dark:text-slate-200">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
                            <button type="button" className="ui-btn-secondary" disabled={providerOp.busy} onClick={toggleProvider}>
                                {providerOp.busy ? t("Memproses…") : t(provider.is_enabled ? "Nonaktifkan penyedia" : "Aktifkan penyedia")}
                            </button>
                            <p className="text-xs text-slate-500 dark:text-slate-400">{t("Menonaktifkan penyedia menghentikan semua modelnya tanpa menghapus data. Penghapusan permanen ada di tab Koneksi.")}</p>
                        </div>
                    </div>

                    <div className="ui-card-flat p-4">
                        <h2 className="ui-section-title">{t("Harga otomatis")}</h2>
                        <p className="mt-1 max-w-prose text-xs leading-5 text-slate-600 dark:text-slate-400">
                            {t("Isi harga input dan output untuk semua model chat sekaligus, dihitung dari tarif dasar ditambah margin. Tidak perlu mengetik satu per satu.")}
                        </p>
                        <div className="mt-3 grid max-w-md grid-cols-2 gap-3">
                            <label className="text-xs font-medium">{t("Margin (%)")}
                                <input className="ui-input mt-1 min-h-10" type="number" min="0" max="500" value={autoPrice.margin}
                                    onChange={(event) => setAutoPrice((current) => ({ ...current, margin: event.target.value }))} />
                            </label>
                            <label className="text-xs font-medium">{t("Kurs IDR per USD")}
                                <input className="ui-input mt-1 min-h-10" type="number" min="1" value={autoPrice.idr}
                                    onChange={(event) => setAutoPrice((current) => ({ ...current, idr: event.target.value }))} />
                            </label>
                        </div>
                        <label className="mt-3 flex items-center gap-2 text-xs">
                            <input type="checkbox" checked={autoPrice.overwrite}
                                onChange={(event) => setAutoPrice((current) => ({ ...current, overwrite: event.target.checked }))} />
                            {t("Timpa harga yang sudah ada")}
                        </label>
                        {autoState.error && <p className="ui-alert mt-3" data-tone="bad">{autoState.error}</p>}
                        {autoState.success && <p className="ui-alert mt-3" data-tone="good">{autoState.success}</p>}
                        <button type="button" className="ui-btn-primary mt-3" disabled={autoState.busy} onClick={runAutoPrice}>
                            {autoState.busy ? t("Memproses...") : t("Buat harga otomatis")}
                        </button>
                    </div>
                </section>
            )}

            {tab === "activity" && (
                <section id="provider-panel-activity" role="tabpanel" aria-labelledby="provider-tab-activity" className="ui-card-flat animate-fade-in-up motion-reduce:animate-none">
                    <div className="ui-card-header">
                        <div>
                            <h2 className="ui-section-title">{t("Aktivitas")}</h2>
                            <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{t("Riwayat aksi admin untuk penyedia ini, terekam dari log audit.")}</p>
                        </div>
                    </div>
                    {audit.loading && <div className="p-4"><LoadingState label={t("Memuat aktivitas…")} /></div>}
                    {audit.error && <p role="alert" className="p-4 text-sm text-red-700 dark:text-red-300">{audit.error}</p>}
                    {!audit.loading && !audit.error && !audit.rows.length && <p className="p-4 text-sm text-slate-600 dark:text-slate-400">{t("Belum ada aktivitas untuk penyedia ini.")}</p>}
                    <ul className="divide-y divide-slate-200 dark:divide-white/10">
                        {audit.rows.map((event) => (
                            <li key={event.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-4 text-xs">
                                <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-800 dark:bg-white/10 dark:text-slate-200">{event.action}</code>
                                <span className="text-slate-700 dark:text-slate-300">{event.actor?.name || t("Sistem")}</span>
                                {event.ip_address && <span className="font-mono text-[11px] text-slate-400">{event.ip_address}</span>}
                                <span className="ml-auto tabular-nums text-slate-500 dark:text-slate-400">{formatDateTime(event.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {modelEditor && (
                <dialog ref={editorDialog} aria-labelledby="model-editor-title" onCancel={(event) => { event.preventDefault(); if (!mutation.busy) setModelEditor(null); }} className="m-auto w-[calc(100%_-_2rem)] max-w-2xl border-0 bg-transparent p-0 backdrop:bg-slate-950/55">
                    <form
                        className="max-h-[calc(100dvh-2rem)] w-full min-w-0 max-w-2xl space-y-4 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900"
                        data-model-editor
                        aria-labelledby="model-editor-title"
                        onSubmit={saveModel}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 id="model-editor-title" className="text-base font-bold text-slate-900 dark:text-white">
                                    {modelEditor.id
                                        ? t("Edit profil model")
                                        : t("Model baru")}
                                </h2>
                                {modelEditor.id ? (
                                    <p className="mt-1 font-mono text-[11px] text-slate-500">
                                        {modelEditor.model_id}
                                    </p>
                                ) : (
                                    <label className="mt-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                        <span className="mb-1 block">
                                            {t("ID model")}
                                        </span>
                                        <input
                                            className="ui-input min-h-9 font-mono"
                                            placeholder="openai/gpt-4o"
                                            value={modelEditor.model_id}
                                            onChange={(event) =>
                                                setModelEditor((current) => ({
                                                    ...current,
                                                    model_id:
                                                        event.target.value,
                                                }))
                                            }
                                        />
                                        {mutation.fields.model_id && (
                                            <span className="mt-1 block text-[11px] font-normal text-red-600 dark:text-red-300">
                                                {mutation.fields.model_id}
                                            </span>
                                        )}
                                    </label>
                                )}
                                {!modelEditor.id && (
                                    <p className="mt-2 text-[11px] leading-4 text-slate-500 dark:text-slate-400">
                                        {t(
                                            "Profil baru tidak menguji koneksi upstream. Tinjau harga dan capability sebelum mengaktifkan model.",
                                        )}
                                    </p>
                                )}
                            </div>
                            <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <input
                                    type="checkbox"
                                    className="h-4 w-4 rounded border-slate-300"
                                    checked={modelEditor.is_enabled}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            is_enabled: event.target.checked,
                                        }))
                                    }
                                />
                                {t("Aktif")}
                            </label>
                        </div>
                        <div className="grid gap-3 border-b border-slate-200 pb-4 dark:border-white/10 sm:grid-cols-2">
                            <div>
                                <label htmlFor="model-provider-connection" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                    {t("Koneksi provider")}
                                </label>
                                <select
                                    id="model-provider-connection"
                                    name="provider_slug"
                                    className="ui-input min-h-10"
                                    value={modelEditor.provider_slug}
                                    required
                                    onChange={(event) => setModelEditor((current) => ({
                                        ...current,
                                        provider_slug: event.target.value,
                                        upstream_model_id: "",
                                        configDraft: generationConfigDraft(null),
                                    }))}
                                    aria-invalid={!!mutation.fields.provider_slug}
                                    aria-describedby={`model-routing-help${mutation.fields.provider_slug ? " model-provider-error" : ""}`}
                                >
                                    <option value="" disabled>{t("Pilih koneksi provider")}</option>
                                    {providers.map((provider) => (
                                        <option key={provider.id} value={provider.slug}>
                                            {provider.name || provider.slug}{provider.is_enabled ? "" : ` (${t("Nonaktif")})`}
                                        </option>
                                    ))}
                                </select>
                                {mutation.fields.provider_slug && <p id="model-provider-error" className="mt-1 text-xs text-red-700 dark:text-red-300">{t([mutation.fields.provider_slug].flat()[0])}</p>}
                            </div>
                            <div>
                                <label htmlFor="model-upstream-id" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                    {t("ID model upstream")}
                                </label>
                                <input
                                    id="model-upstream-id"
                                    name="upstream_model_id"
                                    className="ui-input min-h-10 font-mono"
                                    value={modelEditor.upstream_model_id}
                                    onChange={(event) => setModelEditor((current) => ({
                                        ...current,
                                        upstream_model_id: event.target.value,
                                        configDraft: generationConfigReadOnly ? generationConfigDraft(null) : current.configDraft,
                                    }))}
                                    maxLength={160}
                                    required
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    aria-invalid={!!mutation.fields.upstream_model_id}
                                    aria-describedby="model-routing-help"
                                />
                            </div>
                            <p id="model-routing-help" className="text-xs leading-5 text-slate-600 dark:text-slate-400 sm:col-span-2">
                                {t("Koneksi menentukan tujuan permintaan; label Penyedia di bawah tetap untuk tampilan. Gunakan ID model dari upstream. Mengganti koneksi memerlukan ID upstream baru dan sinkronisasi ulang; ID publik tidak berubah.")}
                                {` ${t("Pilih koneksi tersimpan; konfigurasi provider baru tidak dibuat otomatis.")}`}
                            </p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Nama tampilan")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.display_name}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            display_name: event.target.value,
                                        }))
                                    }
                                    maxLength={160}
                                    required
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Penyedia")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.provider_name}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            provider_name: event.target.value,
                                        }))
                                    }
                                    maxLength={120}
                                    placeholder="OpenAI"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Kategori")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.category}
                                    onChange={(event) => {
                                        const category = event.target.value;
                                        setModelEditor((current) => ({
                                            ...current,
                                            category,
                                        }));
                                    }}
                                    maxLength={32}
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Context window (token)")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.context_window}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            context_window: event.target.value,
                                        }))
                                    }
                                    type="number"
                                    min="1"
                                    placeholder="128000"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Output maksimal (token)")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.max_output_tokens}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            max_output_tokens:
                                                event.target.value,
                                        }))
                                    }
                                    type="number"
                                    min="1"
                                    placeholder="8192"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("URL logo")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.logo_url}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            logo_url: event.target.value,
                                        }))
                                    }
                                    maxLength={255}
                                    placeholder="/brands/ai/openai.svg"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Urutan tampil")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.sort_order}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            sort_order: event.target.value,
                                        }))
                                    }
                                    type="number"
                                    min="0"
                                />
                            </label>
                        </div>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                            <span className="mb-1 block">{t("Deskripsi (Indonesia)")}</span>
                            <textarea
                                className="ui-input min-h-20 resize-y py-2"
                                maxLength={2000}
                                value={modelEditor.description_id}
                                onChange={(event) =>
                                    setModelEditor((current) => ({
                                        ...current,
                                        description_id: event.target.value,
                                    }))
                                }
                            />
                        </label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                            <span className="mb-1 block">{t("Deskripsi (Inggris)")}</span>
                            <textarea
                                className="ui-input min-h-20 resize-y py-2"
                                maxLength={2000}
                                value={modelEditor.description_en}
                                onChange={(event) =>
                                    setModelEditor((current) => ({
                                        ...current,
                                        description_en: event.target.value,
                                    }))
                                }
                            />
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Kemampuan (pisahkan koma)")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.capabilities}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            capabilities: event.target.value,
                                        }))
                                    }
                                    placeholder="chat, vision"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Lencana (pisahkan koma)")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.badges}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            badges: event.target.value,
                                        }))
                                    }
                                    placeholder="Popular, New"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Modalitas input")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.input_modalities}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            input_modalities:
                                                event.target.value,
                                        }))
                                    }
                                    placeholder="text, image"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span className="mb-1 block">{t("Modalitas output")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.output_modalities}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            output_modalities:
                                                event.target.value,
                                        }))
                                    }
                                    placeholder="text"
                                />
                            </label>
                        </div>
                        {mediaCategories.includes(modelEditor.category) && (
                            <div className="space-y-4 border-t border-slate-200 pt-4 dark:border-white/10">
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                    {t("Biaya token")}
                                    <input className="ui-input mt-1 min-h-10" type="number" min="1" max="2147483647" step="1" value={modelEditor.token_cost} onChange={(event) => setModelEditor((current) => ({ ...current, token_cost: event.target.value }))} />
                                    <span className="mt-1 block font-normal text-slate-500 dark:text-slate-400">{t(modelEditor.configDraft.price_unit === "second" ? "Biaya ini dikalikan durasi audio yang dibulatkan ke atas ke detik penuh. Kosong menonaktifkan pembuatan." : modelEditor.category === "audio" ? "Biaya audio dikenakan satu kali per pekerjaan, termasuk jika provider menghasilkan beberapa berkas. Kosong menonaktifkan pembuatan." : "Biaya mengikuti satuan capability, bukan selalu jumlah berkas. Periksa satuannya sebelum mengaktifkan model. Kosong menonaktifkan pembuatan.")}</span>
                                </label>
                                {generationConfigReadOnly && <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Konfigurasi efektif mengikuti adapter dan capability aktif. Ubah revisi melalui tinjauan capability; label, harga, dan aktivasi profil tetap dapat diedit.")}</p>}
                                <GenerationConfigFields
                                    category={modelEditor.category}
                                    value={modelEditor.configDraft}
                                    onChange={(configDraft) => setModelEditor((current) => ({ ...current, configDraft }))}
                                    disabled={mutation.busy || generationConfigReadOnly}
                                    readOnly={generationConfigReadOnly}
                                    protocol={editorProvider?.protocol}
                                    errors={generationConfigReadOnly ? undefined : Object.fromEntries(Object.entries(mutation.fields).filter(([key]) => key.startsWith("generation_config.")).map(([key, value]) => [key.slice(18), value]))}
                                />
                            </div>
                        )}
                        <fieldset className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                            <legend className="px-1 text-xs font-semibold text-slate-600 dark:text-slate-400">
                                {t(mediaCategories.includes(modelEditor.category) ? "Tarif unit USD (terpisah)" : "PAYG per 1 juta token (USD)")}
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-4">
                                {(mediaCategories.includes(modelEditor.category)
                                    ? [["rate_unit", "Per hasil"]]
                                    : [["rate_input", "Input"], ["rate_output", "Output"], ["rate_cache_read", "Cache read"], ["rate_cache_write", "Cache write"]]
                                ).map(([field, label]) => (
                                    <label key={field} className="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                        <span className="mb-1 block">{t(label)}</span>
                                        <input className="ui-input min-h-10" value={modelEditor[field]} onChange={(event) => setModelEditor((current) => ({ ...current, [field]: event.target.value }))} type="number" min="0" max="1000000" step="0.00000001" />
                                    </label>
                                ))}
                            </div>
                            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{t(mediaCategories.includes(modelEditor.category)
                                ? "Tarif USD ini terpisah dari token generator dan tidak menggantikan biaya token per hasil."
                                : "Kosong berarti belum dikonfigurasi, bukan gratis. Tarif API input dan output harus dipublikasikan bersama.")}</p>
                        </fieldset>
                        {mutation.fields &&
                            Object.keys(mutation.fields).length > 0 && (
                                <p role="alert" className="text-xs font-semibold text-red-600 dark:text-red-300">
                                    {t(Object.values(mutation.fields).flat()[0])}
                                </p>
                            )}
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                className="ui-btn-secondary"
                                onClick={() => setModelEditor(null)}
                            >
                                {t("Batal")}
                            </button>
                            <button
                                type="submit"
                                className="ui-btn-primary"
                                disabled={mutation.busy}
                            >
                                {mutation.busy ? t("Menyimpan…") : t("Simpan model")}
                            </button>
                        </div>
                    </form>
                </dialog>
            )}

            {confirmation?.type === "toggle" && (
                <ConfirmDialog
                    title={confirmation.model.is_enabled ? t("Nonaktifkan model?") : t("Aktifkan model?")}
                    description={t("Aktivasi profil terpisah dari publikasi revisi capability dan ketersediaan upstream. Perubahan ini juga memperbarui publikasi tarif model yang sesuai.")}
                    confirmLabel={
                        confirmation.model.is_enabled
                            ? t("Nonaktifkan model")
                            : t("Aktifkan model")
                    }
                    onConfirm={() =>
                        updateModel(
                            confirmation.model,
                            { is_enabled: !confirmation.model.is_enabled },
                            confirmation.model.is_enabled ? t("Model dinonaktifkan.") : t("Model diaktifkan."),
                        )
                    }
                    onCancel={() => setConfirmation(null)}
                    busy={mutation.busy}
                />
            )}
        </div>
    );
}
