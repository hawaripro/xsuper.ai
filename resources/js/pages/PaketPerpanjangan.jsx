import { useEffect, useState } from "react";
import { useTheme } from "../contexts/ThemeContext";

const money = (value) => `$${Number(value || 0).toFixed(2)}`;

export default function PaketPerpanjangan() {
    const { theme } = useTheme();
    const dark = theme === "dark";
    const [data, setData] = useState({
        duration_packages: {},
        usage_rates: {},
        wallet: { balance_usd: 0 },
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        fetch("/api/pricing/catalog", {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        })
            .then(async (response) => {
                const payload = await response.json();
                if (!response.ok)
                    throw new Error(payload.message || "Gagal memuat pricing.");
                return payload;
            })
            .then(setData)
            .catch((value) => setError(value.message))
            .finally(() => setLoading(false));
    }, []);

    const panel = `rounded-2xl border ${dark ? "bg-gray-900/60 border-white/10" : "bg-white border-gray-200"}`;
    if (loading)
        return <div className="p-8 text-sm text-gray-500">Memuat pricing…</div>;

    return (
        <div className="p-6 lg:p-8 space-y-7" style={{ fontSize: "90%" }}>
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1
                        className={`text-2xl font-bold ${dark ? "text-white" : "text-slate-900"}`}
                    >
                        Paket & Pemakaian
                    </h1>
                    <p className={dark ? "text-gray-400" : "text-gray-500"}>
                        Pilih durasi atau gunakan saldo pay as you go.
                    </p>
                </div>
                <div className={`${panel} px-5 py-3`}>
                    <span className="block text-[11px] uppercase tracking-wider text-gray-500">
                        Saldo PAYG
                    </span>
                    <strong
                        className={`text-xl ${dark ? "text-white" : "text-slate-900"}`}
                    >
                        {money(data.wallet.balance_usd)}
                    </strong>
                </div>
            </div>
            {error && <p className="text-red-500 text-sm">{error}</p>}

            <section>
                <h2
                    className={`font-semibold mb-3 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    Paket durasi
                </h2>
                <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                    {Object.entries(data.duration_packages).map(([id, pkg]) => (
                        <a
                            key={id}
                            href="/pricing"
                            className={`${panel} p-5 hover:border-red-500/50 transition-colors`}
                        >
                            <span className="text-xs text-gray-500">
                                {pkg.days} hari
                            </span>
                            <h3
                                className={`font-semibold mt-1 ${dark ? "text-white" : "text-slate-900"}`}
                            >
                                {pkg.label}
                            </h3>
                            <p className="text-xl font-bold text-red-500 mt-4">
                                Rp{" "}
                                {new Intl.NumberFormat("id-ID").format(
                                    pkg.price_idr,
                                )}
                            </p>
                            <small className="text-gray-500">
                                Harga internasional {money(pkg.price_usd)}
                            </small>
                        </a>
                    ))}
                </div>
            </section>

            <section>
                <h2
                    className={`font-semibold mb-3 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    Pay as you go
                </h2>
                {Object.keys(data.usage_rates).length === 0 ? (
                    <div
                        className={`${panel} p-8 text-center text-sm text-gray-500`}
                    >
                        Tarif belum dipublikasikan admin.
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
