import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest, formatDateTime } from "../../lib/api";
import ProviderConnections from "./ProviderConnections";
import ModelBulkTable from "./ModelBulkTable";
import { LoadingState, ErrorState } from "./AsyncState";

const healthTone = {
    healthy: ["good", "Terhubung"],
    online: ["good", "Terhubung"],
    error: ["bad", "Bermasalah"],
    unavailable: ["warn", "Tidak tersedia"],
    unknown: ["neutral", "Belum diperiksa"],
};

const mediaCategories = ["image", "video", "audio"];

/**
 * A model is only usable once it carries a price: media categories bill per
 * result via `token_cost`, chat bills per token via usage rates. Without one the
 * model is published yet silently refuses every request, which is impossible to
 * spot in a flat table — so it gets its own counter on the provider card.
 */
function isUnpriced(model) {
    if (mediaCategories.includes(model.category)) {
        return !Number(model.token_cost);
    }
    const rates = model.rates || {};
    return !Number(rates.input_tokens?.price_usd) && !Number(rates.output_tokens?.price_usd);
}

function averageRate(models, meter) {
    const values = models
        .map((model) => Number(model.rates?.[meter]?.price_usd))
        .filter((value) => Number.isFinite(value) && value > 0);
    if (!values.length) return null;
    return values.reduce((total, value) => total + value, 0) / values.length;
}

export default function ProviderWorkbench({
    providers = [],
    models = [],
    scopedProviderId = "",
    onScope,
    loading = false,
    error = "",
    hasData = false,
    onRefresh,
    onEditModel,
    onToggleModel,
    disabled = false,
}) {
    const { t, locale, localizedPath } = useLocale();
    const navigate = useNavigate();
    const [tab, setTab] = useState("models");
    const [autoPrice, setAutoPrice] = useState({ margin: "40", idr: "16000", overwrite: false });
    const [autoState, setAutoState] = useState({ busy: false, error: "", success: "" });

    const byProvider = useMemo(() => {
        const map = new Map();
        models.forEach((model) => {
            const key = String(model.provider_id ?? model.provider?.id ?? "");
            if (!map.has(key)) map.set(key, []);
            map.get(key).push(model);
        });
        return map;
    }, [models]);

    const cards = useMemo(() => providers.map((provider) => {
        const own = byProvider.get(String(provider.id)) || [];
        const published = own.filter((model) => model.is_enabled).length;
        const unpriced = own.filter(isUnpriced).length;
        return {
            provider,
            total: own.length,
            published,
            unpriced,
            available: own.filter((model) => model.is_available).length,
            inputRate: averageRate(own, "input_tokens"),
            outputRate: averageRate(own, "output_tokens"),
        };
    }), [providers, byProvider]);

    const selected = cards.find((card) => String(card.provider.id) === String(scopedProviderId)) || null;
    const scopedModels = selected ? (byProvider.get(String(selected.provider.id)) || []) : models;

    const money = (value) => value === null ? "—" : `$${value < 0.01 ? value.toFixed(5) : value.toFixed(3)}`;
    const num = (value) => Number(value || 0).toLocaleString(locale);

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
            setAutoState({
                busy: false,
                error: "",
                success: `${t("Harga input & output dibuat otomatis.")} ${num(result?.updated_count ?? 0)} ${t("tarif ditulis.")}`,
            });
            await onRefresh?.();
        } catch (requestError) {
            setAutoState({ busy: false, success: "", error: t(requestError.message || "Gagal membuat harga otomatis.") });
        }
    };

    if (loading && !hasData) return <div className="ui-card-flat p-4"><LoadingState label={t("Memuat penyedia…")} /></div>;
    if (error && !hasData) return <div className="ui-card-flat p-4"><ErrorState message={error} onRetry={onRefresh} /></div>;

    return (
        <div className="pw-shell">
            <div className="pw-cards" role="list" aria-label={t("Kartu penyedia AI")}>
                <button
                    type="button"
                    role="listitem"
                    className="pw-card"
                    aria-pressed={!scopedProviderId}
                    onClick={() => onScope?.("")}
                >
                    <span className="pw-card-title">{t("Semua penyedia")}</span>
                    <span className="pw-card-sub">{num(models.length)} {t("model")}</span>
                    <span className="pw-metrics">
                        <span><b>{num(models.filter((model) => model.is_enabled).length)}</b>{t("Terbit")}</span>
                        <span data-warn={models.filter(isUnpriced).length > 0 ? "" : undefined}>
                            <b>{num(models.filter(isUnpriced).length)}</b>{t("Belum berharga")}
                        </span>
                    </span>
                </button>

                {cards.map(({ provider, total, published, unpriced, available, inputRate, outputRate }) => {
                    const [tone, label] = healthTone[provider.status] || healthTone.unknown;
                    return (
                        <button
                            key={provider.id}
                            type="button"
                            role="listitem"
                            className="pw-card"
                            aria-pressed={String(provider.id) === String(scopedProviderId)}
                            onClick={() => navigate(localizedPath(`/admin/ai/${provider.id}`))}
                        >
                            <span className="pw-card-head">
                                <span className="pw-card-title">{provider.name || provider.slug}</span>
                                <span className="ui-status" data-tone={tone}>{t(label)}</span>
                            </span>
                            <span className="pw-card-sub">
                                <code>{provider.protocol}</code>
                                {provider.base_url || t("Dikelola server")}
                            </span>
                            <span className="pw-metrics">
                                <span><b>{num(total)}</b>{t("Model")}</span>
                                <span><b>{num(published)}</b>{t("Terbit")}</span>
                                <span><b>{num(available)}</b>{t("Tersedia")}</span>
                                <span data-warn={unpriced > 0 ? "" : undefined}><b>{num(unpriced)}</b>{t("Belum berharga")}</span>
                            </span>
                            <span className="pw-rates">
                                <span>{t("Input")} {money(inputRate)}</span>
                                <span>{t("Output")} {money(outputRate)}</span>
                                <span>{provider.last_checked_at ? formatDateTime(provider.last_checked_at) : t("Belum diperiksa")}</span>
                            </span>
                        </button>
                    );
                })}
                {!providers.length && <p className="pw-empty">{t("Belum ada penyedia. Tambahkan koneksi untuk mulai.")}</p>}
            </div>

            <aside className="pw-panel" aria-label={t("Detail penyedia")}>
                <header className="pw-panel-head">
                    <div className="min-w-0">
                        <h2 className="ui-section-title">
                            {selected ? (selected.provider.name || selected.provider.slug) : t("Semua penyedia")}
                        </h2>
                        <p className="pw-panel-sub">
                            {selected
                                ? `${num(selected.total)} ${t("model")} · ${num(selected.published)} ${t("terbit")}`
                                : t("Pilih kartu penyedia untuk menyaring tabel.")}
                        </p>
                    </div>
                </header>
                <div className="pw-tabs" role="tablist">
                    {[["models", "Model & harga"], ["connection", "Koneksi"], ["auto", "Harga otomatis"]].map(([id, label]) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            aria-selected={tab === id}
                            className="pw-tab"
                            onClick={() => setTab(id)}
                        >
                            {t(label)}
                        </button>
                    ))}
                </div>

                <div className="pw-panel-body" role="tabpanel">
                    {tab === "models" && (
                        <ModelBulkTable
                            models={scopedModels}
                            providers={providers}
                            providerId={scopedProviderId}
                            onRefresh={onRefresh}
                            onEdit={onEditModel}
                            onToggle={onToggleModel}
                            disabled={disabled}
                        />
                    )}
                    {tab === "connection" && (
                        <ProviderConnections
                            providers={selected ? [selected.provider] : providers}
                            focusId={selected?.provider.id ?? null}
                            loading={loading}
                            error={error}
                            hasData={hasData}
                            onRefresh={onRefresh}
                        />
                    )}
                    {tab === "auto" && (
                        <div className="pw-auto">
                            <p className="pw-panel-sub">
                                {t("Isi harga input dan output untuk semua model chat sekaligus, dihitung dari tabel tier ditambah margin. Tidak perlu mengetik satu per satu.")}
                            </p>
                            <div className="pw-auto-grid">
                                <label>
                                    {t("Margin (%)")}
                                    <input className="ui-input" type="number" min="0" max="500" value={autoPrice.margin}
                                        onChange={(event) => setAutoPrice((current) => ({ ...current, margin: event.target.value }))} />
                                </label>
                                <label>
                                    {t("Kurs IDR per USD")}
                                    <input className="ui-input" type="number" min="1" value={autoPrice.idr}
                                        onChange={(event) => setAutoPrice((current) => ({ ...current, idr: event.target.value }))} />
                                </label>
                            </div>
                            <label className="pw-auto-check">
                                <input type="checkbox" checked={autoPrice.overwrite}
                                    onChange={(event) => setAutoPrice((current) => ({ ...current, overwrite: event.target.checked }))} />
                                {t("Timpa harga yang sudah ada")}
                            </label>
                            {autoState.error && <p className="ui-alert" data-tone="bad">{autoState.error}</p>}
                            {autoState.success && <p className="ui-alert" data-tone="good">{autoState.success}</p>}
                            <button type="button" className="ui-btn-primary" disabled={autoState.busy} onClick={runAutoPrice}>
                                {autoState.busy ? t("Memproses...") : t("Buat harga otomatis")}
                            </button>
                        </div>
                    )}
                </div>
            </aside>
        </div>
    );
}
