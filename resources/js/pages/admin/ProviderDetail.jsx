import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest, formatDateTime } from "../../lib/api";
import ProviderConnections from "../../components/dashboard/ProviderConnections";
import ModelBulkTable from "../../components/dashboard/ModelBulkTable";
import { LoadingState, ErrorState } from "../../components/dashboard/AsyncState";

const healthTone = {
    healthy: ["good", "Terhubung"],
    online: ["good", "Terhubung"],
    error: ["bad", "Bermasalah"],
    unavailable: ["warn", "Tidak tersedia"],
    unknown: ["neutral", "Belum diperiksa"],
};

const mediaCategories = ["image", "video", "audio"];

const isUnpriced = (model) => mediaCategories.includes(model.category)
    ? !Number(model.token_cost)
    : !Number(model.rates?.input_tokens?.price_usd) || !Number(model.rates?.output_tokens?.price_usd);

/**
 * A provider gets its own page instead of a tab: the model table needs the full
 * viewport width, and a shareable URL per provider makes the list navigable.
 */
export default function ProviderDetail() {
    const { t, localizedPath } = useLocale();
    const { providerId } = useParams();
    const navigate = useNavigate();
    const [state, setState] = useState({ data: null, loading: true, error: "" });

    const load = useCallback(async () => {
        setState((current) => ({ ...current, loading: true, error: "" }));
        try {
            const data = await apiRequest("/api/admin/ai/catalog");
            setState({ data, loading: false, error: "" });
        } catch (requestError) {
            setState({ data: null, loading: false, error: requestError.message });
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const providers = state.data?.providers || [];
    const provider = providers.find((entry) => String(entry.id) === String(providerId)) || null;
    const models = useMemo(
        () => (state.data?.models || []).filter((model) => String(model.provider_id) === String(providerId)),
        [state.data, providerId],
    );

    const stats = useMemo(() => ({
        total: models.length,
        published: models.filter((model) => model.is_enabled).length,
        available: models.filter((model) => model.is_available).length,
        unpriced: models.filter(isUnpriced).length,
    }), [models]);

    if (state.loading && !state.data) return <div className="ui-card-flat p-6"><LoadingState label={t("Memuat penyedia…")} /></div>;
    if (state.error && !state.data) return <div className="ui-card-flat p-6"><ErrorState message={state.error} onRetry={load} /></div>;
    if (!provider) {
        return (
            <div className="ui-card-flat p-6">
                <p className="text-sm">{t("Penyedia tidak ditemukan.")}</p>
                <Link className="ui-btn-secondary mt-3 inline-flex" to={localizedPath("/admin/ai")}>{t("Kembali ke katalog")}</Link>
            </div>
        );
    }

    const [tone, label] = healthTone[provider.status] || healthTone.unknown;

    return (
        <div className="pd-page">
            <nav className="pd-crumbs" aria-label={t("Navigasi")}>
                <button type="button" onClick={() => navigate(localizedPath("/admin/ai"))}>{t("Katalog AI")}</button>
                <span aria-hidden="true">/</span>
                <strong>{provider.name || provider.slug}</strong>
            </nav>

            <header className="pd-head">
                <div className="min-w-0">
                    <h1 className="pd-title">{provider.name || provider.slug}</h1>
                    <p className="pd-sub">
                        <code>{provider.protocol}</code>
                        {provider.base_url || t("Dikelola server")}
                        <span>{provider.last_checked_at ? formatDateTime(provider.last_checked_at) : t("Belum diperiksa")}</span>
                    </p>
                </div>
                <span className="ui-status" data-tone={tone}>{t(label)}</span>
            </header>

            <div className="pd-stats">
                <div><b>{stats.total}</b><span>{t("Model")}</span></div>
                <div><b>{stats.published}</b><span>{t("Terbit")}</span></div>
                <div><b>{stats.available}</b><span>{t("Tersedia")}</span></div>
                <div data-warn={stats.unpriced > 0 ? "" : undefined}><b>{stats.unpriced}</b><span>{t("Belum berharga")}</span></div>
            </div>

            <section className="ui-card-flat" aria-label={t("Koneksi")}>
                <ProviderConnections
                    providers={[provider]}
                    focusId={provider.id}
                    loading={state.loading}
                    error={state.error}
                    hasData={!!state.data}
                    onRefresh={load}
                />
            </section>

            <section className="ui-card-flat min-w-0" aria-label={t("Model penyedia")}>
                <div className="ui-card-header">
                    <div>
                        <h2 className="ui-section-title">{t("Model penyedia")}</h2>
                        <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                            {t("Metadata dan tier terisi otomatis dari upstream saat sinkronisasi; harga input/output juga dibuat otomatis.")}
                        </p>
                    </div>
                </div>
                <ModelBulkTable
                    models={models}
                    providers={providers}
                    providerId={String(provider.id)}
                    onRefresh={load}
                    onEdit={() => navigate(localizedPath("/admin/ai"))}
                    onToggle={() => {}}
                />
            </section>
        </div>
    );
}
