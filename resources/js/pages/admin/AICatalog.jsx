import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import ProviderConnections from "../../components/dashboard/ProviderConnections";
import { LoadingState, ErrorState, EmptyState } from "../../components/dashboard/AsyncState";
import { apiRequest, formatDateTime } from "../../lib/api";

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
    const common = { className, fill: "none", stroke: "currentColor", strokeWidth: 2, viewBox: "0 0 24 24", strokeLinecap: "round", strokeLinejoin: "round", "aria-hidden": true };
    switch (name) {
        case "provider":
            return (<svg {...common}><rect x="2" y="2" width="20" height="8" rx="2" /><rect x="2" y="14" width="20" height="8" rx="2" /><path d="M6 6h.01M6 18h.01" /></svg>);
        case "sync":
            return (<svg {...common}><path d="M23 4v6h-6M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>);
        case "activity":
            return (<svg {...common}><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>);
        case "plus":
            return (<svg {...common}><path d="M12 5v14M5 12h14" /></svg>);
        case "arrow":
            return (<svg {...common}><path d="M5 12h14m-6-6 6 6-6 6" /></svg>);
        default:
            return null;
    }
}

/**
 * Pure provider card grid, per the catalogue brief: every operational surface
 * (model table, connection ops, settings, audit trail) lives on the provider
 * detail page; the global media queue owns its own URL. Only two page-level
 * actions remain — reload and the real "add provider" flow.
 */
export default function AICatalog() {
    const { t, localizedPath } = useLocale();
    const navigate = useNavigate();
    const [catalog, setCatalog] = useState({ data: null, loading: true, error: "" });
    const [showConnect, setShowConnect] = useState(false);

    const requestId = useRef(0);
    const loadCatalog = useCallback(async (signal) => {
        const id = ++requestId.current;
        setCatalog((current) => ({ ...current, loading: true, error: "" }));
        try {
            const data = await apiRequest("/api/admin/ai/catalog/summary", { signal });
            if (!signal?.aborted && id === requestId.current) setCatalog({ data, loading: false, error: "" });
        } catch (error) {
            if (error?.name !== "AbortError" && !signal?.aborted && id === requestId.current)
                setCatalog((current) => ({ ...current, loading: false, error: error.message || t("Katalog AI tidak dapat dimuat.") }));
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        loadCatalog(controller.signal);
        return () => controller.abort();
    }, [loadCatalog]);

    const providers = catalog.data?.providers || [];

    return (
        <div className="ui-page space-y-5">
            <header className="relative overflow-hidden rounded-2xl border border-gray-200/80 bg-white p-5 animate-fade-in-up motion-reduce:animate-none sm:p-6 dark:border-white/[0.08] dark:bg-white/[0.02]">
                <div className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-gradient-to-br from-red-500 to-orange-500 opacity-10 blur-3xl" aria-hidden="true" />
                <div className="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/25">
                            <Icon name="provider" className="h-6 w-6" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-xl font-bold text-slate-900 dark:text-white sm:text-2xl">{t("Penyedia AI")}</h1>
                            <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                                {t("Kelola koneksi API dan model dari setiap penyedia. Buka kartu untuk tabel model, harga, koneksi, dan pengaturannya.")}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        <Link
                            to={localizedPath("/admin/ai/queue")}
                            className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-slate-700 transition-all hover:-translate-y-0.5 hover:border-red-500/40 motion-reduce:transition-none motion-reduce:hover:transform-none dark:border-white/[0.12] dark:bg-white/[0.04] dark:text-slate-200"
                        >
                            <Icon name="activity" className="h-4 w-4" /> {t("Antrean media global")}
                        </Link>
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
                            className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-gradient-to-r from-red-500 to-red-600 px-4 text-sm font-bold text-white shadow-lg shadow-red-500/25 transition-all hover:-translate-y-0.5 motion-reduce:transition-none motion-reduce:hover:transform-none"
                            aria-expanded={showConnect}
                            onClick={() => setShowConnect((current) => !current)}
                        >
                            <Icon name="plus" className="h-4 w-4" /> {t("Tambah penyedia")}
                        </button>
                    </div>
                </div>
            </header>

            {catalog.loading && !catalog.data ? (
                <div className="ui-card-flat p-6"><LoadingState label={t("Memuat penyedia…")} /></div>
            ) : catalog.error && !catalog.data ? (
                <div className="ui-card-flat p-6"><ErrorState message={catalog.error} onRetry={() => loadCatalog()} /></div>
            ) : (
                <>
                    {catalog.error && <div className="ui-card-flat p-4"><ErrorState message={catalog.error} onRetry={() => loadCatalog()} /></div>}
                    {providers.length > 0 && (
                        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label={t("Kartu penyedia AI")}>
                            {providers.map((provider, index) => {
                                const totals = provider.model_counts || {};
                                const [tone, label] = !provider.is_enabled ? ["neutral", "Nonaktif"] : healthTone[String(provider.status).toLowerCase()] || healthTone.unknown;
                                return (
                                    <li key={provider.id} className="min-w-0">
                                        <button
                                            type="button"
                                            onClick={() => navigate(localizedPath(`/admin/ai/${provider.id}`))}
                                            style={{ animationDelay: `${Math.min(index, 11) * 45}ms` }}
                                            className="group w-full rounded-2xl border border-gray-200/80 bg-white p-5 text-left animate-fade-in-up transition-all duration-200 hover:-translate-y-0.5 hover:border-red-500/40 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-red-500 motion-reduce:animate-none motion-reduce:transform-none motion-reduce:transition-none dark:border-white/[0.08] dark:bg-white/[0.02] dark:hover:border-red-500/40"
                                        >
                                            <span className="flex items-start justify-between gap-3">
                                                <span className="flex min-w-0 items-center gap-3">
                                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-md transition-transform duration-200 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:transform-none">
                                                        <Icon name="provider" className="h-5 w-5" />
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="block truncate text-sm font-bold text-slate-900 dark:text-white">{provider.name || provider.slug}</span>
                                                        <span className="mt-0.5 block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                                            <code className="font-mono">{provider.protocol}</code> · {provider.base_url || t("Dikelola server")}
                                                        </span>
                                                    </span>
                                                </span>
                                                <span className="flex shrink-0 flex-col items-end gap-1">
                                                    <span className="ui-status" data-tone={tone}>{t(label)}</span>
                                                    {!provider.is_enabled && <span className="ui-status" data-tone="warn">{t("Nonaktif")}</span>}
                                                </span>
                                            </span>
                                            <span className="mt-4 grid grid-cols-4 gap-2 text-center">
                                                {[
                                                    [totals.total, "Model", false],
                                                    [totals.enabled, "Aktif", false],
                                                    [provider.counts?.published, "Capability terbit", false],
                                                    [provider.counts?.needs_handling, "Perlu penanganan", provider.counts?.needs_handling > 0],
                                                ].map(([value, metric, warn]) => (
                                                    <span key={metric} className="rounded-xl bg-slate-50 px-1 py-2 dark:bg-white/[0.04]">
                                                        <b className={`block text-base font-bold tabular-nums ${warn ? "text-amber-500" : "text-slate-900 dark:text-white"}`}>{count(value)}</b>
                                                        <span className="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{t(metric)}</span>
                                                    </span>
                                                ))}
                                            </span>
                                            <span className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                                                <span>{t("Belum berharga")}: {count(totals.unpriced)}</span>
                                                <span>{provider.last_checked_at ? formatDateTime(provider.last_checked_at) : t("Belum diperiksa")}</span>
                                                <span className="ml-auto inline-flex items-center gap-1 font-semibold text-red-500 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none motion-reduce:group-hover:transform-none">
                                                    {t("Lihat detail")} <Icon name="arrow" className="h-3.5 w-3.5" />
                                                </span>
                                            </span>
                                            {provider.last_error && <span className="mt-3 block break-words text-xs leading-5 text-red-700 dark:text-red-300">{provider.last_error}</span>}
                                            {provider.verification?.catalog_source === "static_documentation" && <span className="mt-2 block text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Katalog dari dokumentasi; autentikasi dan generasi belum diverifikasi.")}</span>}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {!providers.length && !showConnect && (
                        <div className="ui-card-flat p-6">
                            <EmptyState
                                title={t("Belum ada penyedia terhubung")}
                                description={t("Hubungkan penyedia pertama Anda, periksa koneksinya, lalu impor dan tinjau model sebelum publikasi.")}
                                action={
                                    <button type="button" className="ui-btn-primary" onClick={() => setShowConnect(true)}>
                                        {t("Tambah penyedia")}
                                    </button>
                                }
                            />
                        </div>
                    )}

                    {showConnect && (
                        <div className="animate-fade-in-up motion-reduce:animate-none">
                            <ProviderConnections
                                providers={providers}
                                loading={catalog.loading}
                                error={catalog.error}
                                hasData={!!catalog.data}
                                onRefresh={loadCatalog}
                                autoOpenNew
                                onClose={() => setShowConnect(false)}
                            />
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
