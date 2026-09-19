import { useCallback, useEffect, useRef, useState } from "react";
import PageHeader from "../../components/dashboard/PageHeader";
import ProviderConnections from "../../components/dashboard/ProviderConnections";
import ModelBulkTable from "../../components/dashboard/ModelBulkTable";
import GenerationConfigFields, { generationConfigDraft, parseGenerationConfig } from "../../components/dashboard/GenerationConfigFields";
import { useLocale } from "../../contexts/LocaleContext";
import {
    EmptyState,
    ErrorState,
    LoadingState,
} from "../../components/dashboard/AsyncState";
import StatCard from "../../components/dashboard/StatCard";
import StatusBadge from "../../components/dashboard/StatusBadge";
import DataTable from "../../components/dashboard/DataTable";
import { apiRequest, formatCurrency, formatDateTime } from "../../lib/api";

const queueStatuses = ["", "pending", "processing", "completed", "failed"];
const emptyResource = { data: null, loading: true, error: "" };
const mediaCategories = ["image", "video", "audio"];

function count(value) {
    return new Intl.NumberFormat("id-ID").format(Number(value || 0));
}

function ConfirmDialog({
    title,
    description,
    confirmLabel,
    onConfirm,
    onCancel,
    busy,
}) {
    const { t } = useLocale();
    return (
        <div
            className="fixed inset-0 z-[90] grid place-items-center bg-slate-950/55 p-4"
            role="presentation"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget && !busy) onCancel();
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="ai-confirm-title"
                className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900"
            >
                <h2
                    id="ai-confirm-title"
                    className="text-base font-bold text-slate-900 dark:text-white"
                >
                    {title}
                </h2>
                <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">
                    {description}
                </p>
                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        className="ui-btn-secondary"
                        onClick={onCancel}
                        disabled={busy}
                    >
                        {t("Batal")}
                    </button>
                    <button
                        type="button"
                        className="ui-btn-primary min-h-10 px-4 text-xs"
                        onClick={onConfirm}
                        disabled={busy}
                    >
                        {busy ? t("Memproses…") : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function AICatalog() {
    const { t } = useLocale();
    const [catalog, setCatalog] = useState(emptyResource);
    const [queue, setQueue] = useState(emptyResource);
    const [providerScope, setProviderScope] = useState("");
    const [railQuery, setRailQuery] = useState("");
    const [section, setSection] = useState("catalog");
    const [queueType, setQueueType] = useState("images");
    const [queueStatus, setQueueStatus] = useState("");
    const [confirmation, setConfirmation] = useState(null);
    const [modelEditor, setModelEditor] = useState(null);
    const [mutation, setMutation] = useState({
        busy: false,
        error: "",
        success: "",
        fields: {},
    });

    const catalogRequest = useRef(0);
    const loadCatalog = useCallback(async (signal) => {
        const requestId = ++catalogRequest.current;
        setCatalog((current) => ({ ...current, loading: true, error: "" }));
        try {
            const data = await apiRequest("/api/admin/ai/catalog", { signal });
            if (!signal?.aborted && requestId === catalogRequest.current) setCatalog({ data, loading: false, error: "" });
        } catch (error) {
            if (error?.name !== "AbortError" && !signal?.aborted && requestId === catalogRequest.current)
                setCatalog((current) => ({
                    ...current,
                    loading: false,
                    error: error.message || t("Katalog AI tidak dapat dimuat."),
                }));
        }
    }, []);

    const loadQueue = useCallback(
        async (signal) => {
            setQueue((current) => ({ ...current, loading: true, error: "" }));
            try {
                const query = new URLSearchParams({ limit: "100" });
                if (queueStatus) query.set("status", queueStatus);
                const data = await apiRequest(
                    `/api/admin/media/queue?${query}`,
                    { signal },
                );
                setQueue({ data, loading: false, error: "" });
            } catch (error) {
                if (error?.name !== "AbortError")
                    setQueue((current) => ({
                        ...current,
                        loading: false,
                        error:
                            error.message ||
                            t("Antrean media global tidak dapat dimuat."),
                    }));
            }
        },
        [queueStatus],
    );

    useEffect(() => {
        const controller = new AbortController();
        loadCatalog(controller.signal);
        return () => controller.abort();
    }, [loadCatalog]);

    useEffect(() => {
        const controller = new AbortController();
        loadQueue(controller.signal);
        return () => controller.abort();
    }, [loadQueue]);

    const providers = catalog.data?.providers || [];
    const models = catalog.data?.models || [];
    const editorProvider = modelEditor ? providers.find((provider) => provider.slug === modelEditor.provider_slug) : null;
    const generationConfigReadOnly = editorProvider?.protocol === "fal" || (modelEditor?.generation_config_readonly && modelEditor.provider_slug === modelEditor.original_provider_slug);
    const enabledModels = models.filter((model) => model.is_enabled).length;
    const modelCounts = models.reduce((counts, model) => {
        const key = String(model.provider_id ?? model.provider?.id ?? "");
        counts[key] = (counts[key] || 0) + 1;
        return counts;
    }, {});
    const railNeedle = railQuery.trim().toLowerCase();
    const railProviders = railNeedle
        ? providers.filter((provider) => [provider.name, provider.slug, provider.protocol].some((value) => String(value ?? "").toLowerCase().includes(railNeedle)))
        : providers;
    const scopedProvider = providerScope ? providers.find((provider) => String(provider.id) === providerScope) : null;
    if (providerScope && !scopedProvider && catalog.data && !catalog.loading) setProviderScope("");
    const unhealthyProviders = providers.filter(
        (provider) =>
            !provider.is_enabled ||
            !["online", "healthy", "active"].includes(
                String(provider.status).toLowerCase(),
            ),
    ).length;
    const images = queue.data?.images || [];
    const videos = queue.data?.videos || [];
    const audio = queue.data?.audio || [];
    const activeJobs = [...images, ...videos, ...audio].filter((job) =>
        ["pending", "processing"].includes(job.status),
    ).length;
    const failedJobs = [...images, ...videos, ...audio].filter(
        (job) => job.status === "failed",
    ).length;


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
                    "Model dibuat. Ketersediaan tetap nonaktif sampai sinkronisasi katalog upstream mengonfirmasinya.",
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
        tier: model.tier || "",
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
                provider_slug: providers.length === 1 ? providers[0].slug : "",
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
            tier: modelEditor.tier || null,
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

    const renderCost = (job) => {
        if (job.billing_mode === "admin") return t("Gratis admin (riwayat lama)");
        if (job.billing_mode === "tokens") return `${Number(job.tokens_reserved || 0)} ${t("token")}`;
        return job.cost_microusd == null ? "—" : formatCurrency(Number(job.cost_microusd) / 1_000_000, "USD");
    };
    const queueRows = { images, videos, audio }[queueType];

    return (
        <div className="ui-page space-y-5">
            <PageHeader
                eyebrow={t("Operasi AI")}
                title={t("Katalog AI & antrean media")}
                description={t("Sinkronkan metadata model upstream, atur harga dan publikasi, serta pantau pekerjaan gambar, video, dan audio tanpa menampilkan kredensial.")}
                actions={
                    <>
                        <button
                            type="button"
                            className="ui-btn-secondary"
                            onClick={openNewModelEditor}
                            disabled={mutation.busy || !providers.length}
                        >
                            {t("Model baru")}
                        </button>
                    </>
                }
            />

            {(mutation.error || mutation.success) && (
                <div
                    className={`rounded-xl border p-3 text-xs ${mutation.error ? "border-red-500/25 bg-red-500/5 text-red-700 dark:text-red-300" : "border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300"}`}
                    role={mutation.error ? "alert" : "status"}
                >
                    {mutation.error || mutation.success}
                </div>
            )}

            <section aria-labelledby="ai-summary-title" className="space-y-3">
                <h2 id="ai-summary-title" className="ui-section-title">
                    Operational summary
                </h2>
                <div className="ui-stat-grid">
                    <StatCard
                        label={t("Penyedia")}
                        value={
                            catalog.loading && !catalog.data
                                ? "…"
                                : catalog.error && !catalog.data
                                  ? "Unavailable"
                                  : count(providers.length)
                        }
                        detail={`${unhealthyProviders} need attention`}
                        tone={unhealthyProviders ? "warn" : "good"}
                    />
                    <StatCard
                        label={t("Model aktif")}
                        value={
                            catalog.loading && !catalog.data
                                ? "…"
                                : catalog.error && !catalog.data
                                  ? "Unavailable"
                                  : count(enabledModels)
                        }
                        detail={`${count(models.length)} synchronized models`}
                    />
                    <StatCard
                        label={t("Pekerjaan media aktif")}
                        value={
                            queue.loading && !queue.data
                                ? "…"
                                : queue.error && !queue.data
                                  ? "Unavailable"
                                  : count(activeJobs)
                        }
                        detail="Pending and processing globally"
                        tone={activeJobs ? "warn" : "neutral"}
                    />
                    <StatCard
                        label={t("Pekerjaan media gagal")}
                        value={
                            queue.loading && !queue.data
                                ? "…"
                                : queue.error && !queue.data
                                  ? "Unavailable"
                                  : count(failedJobs)
                        }
                        detail="Current status filter response"
                        tone={failedJobs ? "bad" : "good"}
                    />
                </div>
            </section>

            <div
                className="flex flex-wrap gap-2"
                role="tablist"
                aria-label={t("Bagian administrasi AI")}
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={section === "catalog"}
                    className={
                        section === "catalog"
                            ? "ui-btn-primary min-h-10 px-4 text-xs"
                            : "ui-btn-secondary"
                    }
                    onClick={() => setSection("catalog")}
                >
                    {t("Katalog & kesehatan")}
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected={section === "queue"}
                    className={
                        section === "queue"
                            ? "ui-btn-primary min-h-10 px-4 text-xs"
                            : "ui-btn-secondary"
                    }
                    onClick={() => setSection("queue")}
                >
                    {t("Antrean media global")}
                </button>
            </div>

            <div hidden={section !== "catalog"} className="ai-workspace">
                <aside className="ai-rail" aria-label={t("Daftar penyedia")}>
                    <div className="ai-rail-head">
                        <h2 className="ui-section-title">{t("Penyedia")}</h2>
                        <span className="ai-rail-count">{count(providers.length)}</span>
                    </div>
                    {providers.length > 6 && (
                        <input
                            type="search"
                            className="ui-input min-h-9 text-xs"
                            placeholder={t("Cari penyedia")}
                            aria-label={t("Cari penyedia")}
                            value={railQuery}
                            onChange={(event) => setRailQuery(event.target.value)}
                        />
                    )}
                    <ul className="ai-rail-list">
                        <li>
                            <button type="button" className="ai-rail-item" aria-pressed={providerScope === ""} onClick={() => setProviderScope("")}>
                                <span className="ai-rail-dot" data-tone="neutral" aria-hidden="true" />
                                <span className="ai-rail-name">{t("Semua penyedia")}</span>
                                <span className="ai-rail-count">{count(models.length)}</span>
                            </button>
                        </li>
                        {railProviders.map((provider) => {
                            const tone = !provider.is_enabled ? "neutral" : ["online", "healthy", "active"].includes(provider.status) ? "good" : provider.status === "degraded" ? "warn" : ["offline", "failed", "error", "unavailable"].includes(provider.status) ? "bad" : "neutral";
                            return (
                                <li key={provider.id}>
                                    <button type="button" className="ai-rail-item" aria-pressed={providerScope === String(provider.id)} onClick={() => setProviderScope(String(provider.id))}>
                                        <span className="ai-rail-dot" data-tone={tone} aria-hidden="true" />
                                        <span className="ai-rail-name">{provider.name || provider.slug}</span>
                                        <span className="ai-rail-count">{count(modelCounts[String(provider.id)] || 0)}</span>
                                    </button>
                                </li>
                            );
                        })}
                        {!railProviders.length && providers.length > 0 && <li className="ai-rail-empty">{t("Tidak ada penyedia yang cocok.")}</li>}
                    </ul>
                </aside>

                <div className="ai-main">
                    <ProviderConnections
                        providers={providers}
                        focusId={scopedProvider?.id ?? null}
                        loading={catalog.loading}
                        error={catalog.error}
                        hasData={!!catalog.data}
                        onRefresh={loadCatalog}
                    />

                    <section className="ui-card-flat min-w-0" aria-labelledby="models-title">
                        <div className="ui-card-header">
                            <div>
                                <h2
                                    id="models-title"
                                    className="ui-section-title"
                                >
                                    {scopedProvider ? `${t("Model")} · ${scopedProvider.name || scopedProvider.slug}` : t("Metadata & harga model")}
                                </h2>
                                <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    {t("Edit banyak baris lalu simpan sekaligus. Publikasi profil terpisah dari ketersediaan upstream.")}
                                </p>
                            </div>
                        </div>
                        {catalog.loading && !catalog.data ? (
                            <div className="p-4">
                                <LoadingState label={t("Memuat model…")} />
                            </div>
                        ) : catalog.error && !catalog.data ? (
                            <div className="p-4">
                                <ErrorState
                                    message={catalog.error}
                                    onRetry={() => loadCatalog()}
                                />
                            </div>
                        ) : (
                            <ModelBulkTable
                                models={models}
                                providers={providers}
                                providerId={providerScope}
                                onRefresh={loadCatalog}
                                onEdit={openModelEditor}
                                onToggle={(model) => setConfirmation({ type: "toggle", model })}
                                disabled={mutation.busy}
                            />
                        )}
                    </section>
                </div>
            </div>

            {section === "queue" && (
                <section className="ui-card" aria-labelledby="queue-title">
                    <div className="ui-card-header">
                        <div>
                            <h2 id="queue-title" className="ui-section-title">
                                Global media queue
                            </h2>
                            <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                                Authorized cross-account job status, billing,
                                outputs, and operational failures.
                            </p>
                        </div>
                        <button
                            type="button"
                            className="ui-btn-secondary"
                            onClick={() => loadQueue()}
                            disabled={queue.loading}
                        >
                            Refresh
                        </button>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-white/10">
                        {[
                            ["images", "Gambar", images.length],
                            ["videos", "Video", videos.length],
                            ["audio", "Audio", audio.length],
                        ].map(([type, label, total]) => <button
                            key={type}
                            type="button"
                            aria-pressed={queueType === type}
                            className={queueType === type ? "ui-btn-primary min-h-10 px-4 text-xs" : "ui-btn-secondary"}
                            onClick={() => setQueueType(type)}
                        >
                            {t(label)} ({total})
                        </button>)}
                        <select
                            className="ui-input ml-auto min-h-10 w-auto"
                            value={queueStatus}
                            onChange={(event) =>
                                setQueueStatus(event.target.value)
                            }
                        >
                            {queueStatuses.map((status) => (
                                <option key={status || "all"} value={status}>
                                    {status
                                        ? status.replaceAll("_", " ")
                                        : "All statuses"}
                                </option>
                            ))}
                        </select>
                    </div>
                    {queue.loading && !queue.data ? (
                        <div className="p-4">
                            <LoadingState label={t("Memuat pekerjaan media global…")} />
                        </div>
                    ) : queue.error && !queue.data ? (
                        <div className="p-4">
                            <ErrorState
                                message={queue.error}
                                onRetry={() => loadQueue()}
                            />
                        </div>
                    ) : (
                        <DataTable
                            rows={queueRows}
                            rowKey="job_id"
                            emptyTitle={`No ${queueType} jobs`}
                            emptyDescription={
                                queueStatus
                                    ? `No ${queueStatus} jobs match the current queue filter.`
                                    : `No global ${queueType} jobs have been recorded.`
                            }
                            columns={[
                                {
                                    key: "job",
                                    label: "Job",
                                    render: (row) => (
                                        <div>
                                            <strong className="block font-mono text-[11px] text-slate-900 dark:text-white">
                                                {row.job_id}
                                            </strong>
                                            <span className="text-[11px] text-slate-500">
                                                {row.model}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: "user",
                                    label: "Member",
                                    render: (row) => (
                                        <div>
                                            <span className="block">
                                                {row.user?.name || "Unknown"}
                                            </span>
                                            <span className="text-[11px] text-slate-500">
                                                {row.user?.email}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: "prompt",
                                    label: "Prompt",
                                    render: (row) => (
                                        <span
                                            className="block max-w-sm whitespace-normal"
                                            title={row.prompt}
                                        >
                                            {row.prompt || "—"}
                                        </span>
                                    ),
                                },
                                {
                                    key: "spec",
                                    label: "Spec",
                                    render: (row) => queueType === "images"
                                        ? `${row.size || "—"} · ${row.n || 1} ${t("Gambar")}`
                                        : queueType === "audio"
                                            ? (row.mode === "speech" ? `${row.voice || "—"} · ${row.speed ?? "—"}×` : `${t("Musik / efek suara")} · ${row.duration ?? "—"} s`)
                                            : <div>
                                                <span>{row.aspect_ratio || "—"} · {row.duration ?? "—"} s · {row.pro_mode ? "Pro" : "Standard"}</span>
                                                {row.has_reference && row.reference_url && <a className="mt-1 block underline underline-offset-2" href={row.reference_url} target="_blank" rel="noopener noreferrer">{t("Gambar referensi")}</a>}
                                            </div>,
                                },
                                {
                                    key: "status",
                                    label: "Status",
                                    render: (row) => (
                                        <div>
                                            <StatusBadge status={row.stage === "cancelled" ? "cancelled" : row.status} />
                                            {row.error && (
                                                <p className="mt-1 max-w-xs whitespace-normal text-[10px] text-red-600 dark:text-red-400">
                                                    {row.error}
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: "billing",
                                    label: "Billing",
                                    render: (row) => (
                                        <div>
                                            <span className="block">
                                                {renderCost(row)}
                                            </span>
                                            <StatusBadge
                                                status={
                                                    row.billing_status ||
                                                    "unknown"
                                                }
                                            />
                                        </div>
                                    ),
                                },
                                {
                                    key: "created",
                                    label: "Created",
                                    render: (row) =>
                                        formatDateTime(row.created_at),
                                },
                            ]}
                        />
                    )}
                </section>
            )}

            {modelEditor && (
                <div className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-950/55 p-4 sm:items-center">
                    <form
                        className="max-h-[calc(100dvh-2rem)] w-full min-w-0 max-w-2xl space-y-4 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900"
                        data-model-editor
                        role="dialog"
                        aria-modal="true"
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
                                            "Ketersediaan tetap nonaktif sampai sinkronisasi katalog upstream mengonfirmasi model ini. Mengaktifkan hanya mempublikasikan profil.",
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
                                <span className="mb-1 block">{t("Tier")}</span>
                                <input
                                    className="ui-input min-h-9"
                                    value={modelEditor.tier}
                                    onChange={(event) =>
                                        setModelEditor((current) => ({
                                            ...current,
                                            tier: event.target.value,
                                        }))
                                    }
                                    maxLength={40}
                                    placeholder="Authentic"
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
                                    {t("Token per hasil")}
                                    <input className="ui-input mt-1 min-h-10" type="number" min="1" max="2147483647" step="1" value={modelEditor.token_cost} onChange={(event) => setModelEditor((current) => ({ ...current, token_cost: event.target.value }))} />
                                    <span className="mt-1 block font-normal text-slate-500 dark:text-slate-400">{t("Semua akun, termasuk admin, membayar biaya ini × jumlah hasil. Kosong menonaktifkan pembuatan, bukan publikasi profil.")}</span>
                                </label>
                                {generationConfigReadOnly && <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Pengaturan generasi fal mengikuti skema model yang didukung dan tidak dapat diubah di sini. Harga, biaya token, dan publikasi tetap dapat diedit.")}</p>}
                                <GenerationConfigFields
                                    category={modelEditor.category}
                                    value={modelEditor.configDraft}
                                    onChange={(configDraft) => setModelEditor((current) => ({ ...current, configDraft }))}
                                    disabled={mutation.busy || generationConfigReadOnly}
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
                </div>
            )}

            {confirmation?.type === "toggle" && (
                <ConfirmDialog
                    title={confirmation.model.is_enabled ? t("Nonaktifkan model?") : t("Aktifkan model?")}
                    description={t("Publikasi profil terpisah dari ketersediaan upstream. Perubahan ini juga memperbarui publikasi tarif model yang sesuai.")}
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
