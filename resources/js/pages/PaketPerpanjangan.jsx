import { useCallback, useEffect, useState } from "react";
import { useTheme } from "../contexts/ThemeContext";
import { useLocale } from "../contexts/LocaleContext";
import { apiRequest } from "../lib/api";
import QrisCheckout from "../components/QrisCheckout";

const money = (value) => `$${Number(value || 0).toFixed(2)}`;

export default function PaketPerpanjangan() {
    const { theme } = useTheme();
    const { t } = useLocale();
    const dark = theme === "dark";
    const [data, setData] = useState({
        duration_packages: {},
        usage_rates: {},
        wallet: { balance_usd: 0 },
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [checkoutKey, setCheckoutKey] = useState(null);
    const [checkoutReset, setCheckoutReset] = useState(0);

    useEffect(() => {
        apiRequest("/api/pricing/catalog")
            .then(setData)
            .catch((value) => setError(value))
            .finally(() => setLoading(false));
    }, []);

    const reloadCatalog = useCallback(() => {
        setError(null);
        apiRequest("/api/pricing/catalog")
            .then(setData)
            .catch((value) => setError(value));
    }, []);

    const packages = Object.entries(data.duration_packages || {})
        .filter(([, pkg]) => pkg?.is_active !== false)
        .map(([key, pkg]) => ({
            key,
            label: pkg.label,
            days: pkg.days,
            price: pkg.price_idr ?? pkg.price,
        }));

    const startPurchase = (key) => {
        setCheckoutKey(key);
        setCheckoutReset((value) => value + 1);
        document.getElementById("qris-checkout")?.scrollIntoView({ behavior: "smooth", block: "start" });
    };

    const panel = `rounded-2xl border ${dark ? "bg-gray-900/60 border-white/10" : "bg-white border-gray-200"}`;
    if (loading)
        return <div className="p-8 text-sm text-gray-500">{t("Memuat pricing…")}</div>;

    return (
        <div className="p-6 lg:p-8 space-y-7" style={{ fontSize: "90%" }}>
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1
                        className={`text-2xl font-bold ${dark ? "text-white" : "text-slate-900"}`}
                    >
                        {t("Paket & Pemakaian")}
                    </h1>
                    <p className={dark ? "text-gray-400" : "text-gray-500"}>
                        {t("Pilih durasi atau gunakan saldo pay as you go.")}
                    </p>
                </div>
                <div className={`${panel} px-5 py-3`}>
                    <span className="block text-[11px] uppercase tracking-wider text-gray-500">
                        {t("Saldo PAYG")}
                    </span>
                    <strong
                        className={`text-xl ${dark ? "text-white" : "text-slate-900"}`}
                    >
                        {money(data.wallet.balance_usd)}
                    </strong>
                </div>
            </div>
            {error && <p className="text-red-500 text-sm">{error.message || t("Gagal memuat pricing.")}</p>}

            <section>
                <h2
                    className={`font-semibold mb-3 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    {t("Paket durasi")}
                </h2>
                <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                    {Object.entries(data.duration_packages).map(([id, pkg]) => (
                        <button
                            key={id}
                            type="button"
                            onClick={() => startPurchase(id)}
                            disabled={pkg?.is_active === false}
                            className={`${panel} p-5 text-left transition-colors hover:border-red-500/50 disabled:cursor-not-allowed disabled:opacity-50 ${checkoutKey === id ? "border-red-500/60" : ""}`}
                        >
                            <span className="text-xs text-gray-500">
                                {pkg.days} {t("hari")}
                            </span>
                            <h3
                                className={`font-semibold mt-1 ${dark ? "text-white" : "text-slate-900"}`}
                            >
                                {t(pkg.label)}
                            </h3>
                            <p className="text-xl font-bold text-red-500 mt-4">
                                Rp{" "}
                                {new Intl.NumberFormat("id-ID").format(
                                    pkg.price_idr,
                                )}
                            </p>
                            <small className="text-gray-500">
                                {t("Harga internasional")} {money(pkg.price_usd)}
                            </small>
                            <span className="mt-3 block text-[12px] font-semibold text-red-600 dark:text-red-400">
                                {t("Beli dengan QRIS")} →
                            </span>
                        </button>
                    ))}
                </div>
            </section>

            <section id="qris-checkout" className={panel + " p-5"}>
                <h2
                    className={`font-semibold mb-3 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    {t("Pembayaran QRIS")}
                </h2>
                <QrisCheckout
                    key={checkoutReset}
                    packages={packages}
                    loading={false}
                    error={null}
                    onReloadPackages={reloadCatalog}
                    initialPackageKey={checkoutKey}
                />
            </section>

            <section>
                <h2
                    className={`font-semibold mb-3 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    {t("Pay as you go")}
                </h2>
                {Object.keys(data.usage_rates).length === 0 ? (
                    <div
                        className={`${panel} p-8 text-center text-sm text-gray-500`}
                    >
                        {t("Tarif belum dipublikasikan admin.")}
                    </div>
                ) : (
                    <div className="grid lg:grid-cols-3 gap-4">
                        {Object.entries(data.usage_rates).map(
                            ([service, rates]) => (
                                <div key={service} className={panel}>
                                    <div
                                        className={`px-4 py-3 border-b ${dark ? "border-white/10" : "border-gray-200"}`}
                                    >
                                        <strong className="uppercase text-red-500">
                                            {service}
                                        </strong>
                                    </div>
                                    {rates.map((rate) => (
                                        <div
                                            key={rate.id}
                                            className={`flex items-center justify-between gap-4 p-4 border-t first:border-0 ${dark ? "border-white/10" : "border-gray-100"}`}
                                        >
                                            <div>
                                                <strong
                                                    className={
                                                        dark
                                                            ? "text-white"
                                                            : "text-slate-900"
                                                    }
                                                >
                                                    {rate.label}
                                                </strong>
                                                <code className="block text-[11px] text-gray-500 mt-1">
                                                    {rate.model}
                                                </code>
                                            </div>
                                            <div className="text-right">
                                                <span className="font-semibold text-red-500">
                                                    {money(rate.price_usd)}
                                                </span>
                                                <small className="block text-gray-500">
                                                    {rate.unit}
                                                </small>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ),
                        )}
                    </div>
                )}
            </section>
        </div>
    );
}
