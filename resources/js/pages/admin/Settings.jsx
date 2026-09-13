import { useEffect, useState } from "react";
import { useTheme } from "../../contexts/ThemeContext";

const csrf = () =>
    decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || "");
const jsonOptions = (method, body) => ({
    method,
    credentials: "same-origin",
    headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-XSRF-TOKEN": csrf(),
    },
    body: JSON.stringify(body),
});

export default function Settings() {
    const { theme } = useTheme();
    const dark = theme === "dark";
    const [catalog, setCatalog] = useState({
        duration_packages: {},
        usage_rates: [],
    });
    const [draft, setDraft] = useState({
        service: "api",
        meter: "input_tokens",
        model: "",
        label: "",
        unit: "1M tokens",
        price_idr: "",
        price_usd: "",
        is_active: false,
        sort_order: 0,
    });
    const [topup, setTopup] = useState({
        user_id: "",
        amount_usd: "",
        description: "",
    });
    const [status, setStatus] = useState("");
    const [loading, setLoading] = useState(true);
    const panel = `rounded-2xl border p-5 ${dark ? "bg-gray-900/70 border-white/10" : "bg-white border-gray-200"}`;
    const input = `w-full px-3 py-2 rounded-lg text-sm border ${dark ? "bg-white/5 border-white/10 text-white" : "bg-gray-50 border-gray-200 text-gray-900"}`;

    const load = async () => {
        const response = await fetch("/api/pricing/settings", {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        const data = await response.json();
        if (!response.ok)
            throw new Error(data.message || "Gagal memuat pricing.");
        setCatalog(data);
        setLoading(false);
    };
    useEffect(() => {
        load().catch((error) => {
            setStatus(error.message);
            setLoading(false);
        });
    }, []);

    const saveDuration = async (id, values) => {
        const response = await fetch(
            `/api/pricing/durations/${id}`,
            jsonOptions("PUT", values),
        );
        const data = await response.json();
        if (!response.ok)
            throw new Error(data.message || "Gagal menyimpan paket.");
        setStatus(`Paket ${id} tersimpan.`);
        await load();
    };
    const saveRate = async (rate) => {
        const path = rate.id
            ? `/api/pricing/rates/${rate.id}`
            : "/api/pricing/rates";
        const response = await fetch(
            path,
            jsonOptions(rate.id ? "PUT" : "POST", rate),
        );
        const data = await response.json();
        if (!response.ok)
            throw new Error(
                data.message ||
                    Object.values(data.errors || {}).flat()[0] ||
                    "Gagal menyimpan tarif.",
            );
        setStatus("Tarif tersimpan.");
        setDraft({
            service: "api",
            meter: "input_tokens",
            model: "",
            label: "",
            unit: "1M tokens",
            price_idr: "",
            price_usd: "",
            is_active: false,
            sort_order: 0,
        });
        await load();
    };
    const deleteRate = async (id) => {
        const response = await fetch(`/api/pricing/rates/${id}`, {
            method: "DELETE",
            credentials: "same-origin",
            headers: { Accept: "application/json", "X-XSRF-TOKEN": csrf() },
        });
        const data = await response.json();
        if (!response.ok)
            throw new Error(data.message || "Gagal menghapus tarif.");
        setStatus("Tarif dihapus.");
        await load();
    };
    const topupWallet = async () => {
        const response = await fetch(
            "/api/pricing/wallet/topup",
            jsonOptions("POST", topup),
        );
        const data = await response.json();
        if (!response.ok)
            throw new Error(
                data.message ||
                    Object.values(data.errors || {}).flat()[0] ||
                    "Gagal top up wallet.",
            );
        setStatus(
            `Wallet user ${topup.user_id} sekarang $${Number(data.balance_usd).toFixed(2)}.`,
        );
        setTopup({ user_id: "", amount_usd: "", description: "" });
    };

    const patchPackage = (id, key, value) =>
        setCatalog((current) => ({
            ...current,
            duration_packages: {
                ...current.duration_packages,
                [id]: { ...current.duration_packages[id], [key]: value },
            },
        }));
    const patchRate = (id, key, value) =>
        setCatalog((current) => ({
            ...current,
            usage_rates: current.usage_rates.map((rate) =>
                rate.id === id ? { ...rate, [key]: value } : rate,
            ),
        }));

    if (loading)
        return <div className="p-8 text-sm text-gray-500">Memuat pricing…</div>;
    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: "90%" }}>
            <div>
                <h1
                    className={`text-2xl font-bold ${dark ? "text-white" : "text-slate-900"}`}
                >
                    Pricing & Billing
                </h1>
                <p className={dark ? "text-gray-400" : "text-gray-500"}>
                    Ubah harga paket, tarif PAYG, dan publikasi tanpa rebuild
                    frontend.
                </p>
            </div>

            <section className={panel}>
                <h2
                    className={`font-semibold mb-4 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    Paket durasi
                </h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr
                                className={
                                    dark ? "text-gray-400" : "text-gray-500"
                                }
                            >
                                <th className="text-left p-2">Paket</th>
                                <th className="p-2">IDR</th>
                                <th className="p-2">USD</th>
                                <th className="p-2">Aktif</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {Object.entries(catalog.duration_packages).map(
                                ([id, pkg]) => (
                                    <tr
                                        key={id}
                                        className={`border-t ${dark ? "border-white/10" : "border-gray-100"}`}
                                    >
                                        <td className="p-2">
                                            <strong>{pkg.label}</strong>
                                            <div className="text-xs text-gray-500">
                                                {pkg.days} hari
                                            </div>
                                        </td>
                                        <td className="p-2">
                                            <input
                                                className={input}
                                                type="number"
                                                value={pkg.price_idr}
                                                onChange={(event) =>
                                                    patchPackage(
                                                        id,
                                                        "price_idr",
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="p-2">
                                            <input
                                                className={input}
                                                type="number"
                                                step="0.01"
                                                value={pkg.price_usd}
                                                onChange={(event) =>
                                                    patchPackage(
                                                        id,
                                                        "price_usd",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="p-2 text-center">
                                            <input
                                                type="checkbox"
                                                checked={pkg.is_active}
                                                onChange={(event) =>
                                                    patchPackage(
                                                        id,
                                                        "is_active",
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="p-2">
                                            <button
                                                className="px-3 py-2 rounded-lg bg-red-600 text-white"
                                                onClick={() =>
                                                    saveDuration(id, pkg).catch(
                                                        (error) =>
                                                            setStatus(
                                                                error.message,
                                                            ),
                                                    )
                                                }
                                            >
                                                Simpan
                                            </button>
                                        </td>
                                    </tr>
                                ),
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className={panel}>
                <div className="flex items-center justify-between gap-4 mb-4">
                    <div>
                        <h2
                            className={`font-semibold ${dark ? "text-white" : "text-slate-900"}`}
                        >
                            Tarif pay as you go
                        </h2>
                        <p className="text-xs text-gray-500">
                            API: input/output per 1 juta token. Image/video: per
                            hasil berhasil.
                        </p>
                    </div>
                </div>
                <div className="space-y-3">
                    {catalog.usage_rates.map((rate) => (
                        <div
                            key={rate.id}
                            className={`grid md:grid-cols-[100px_125px_1fr_130px_130px_64px_78px_72px] gap-2 items-center rounded-xl border p-3 ${dark ? "border-white/10" : "border-gray-200"}`}
                        >
                            <select
                                className={input}
                                value={rate.service}
                                disabled
                            >
                                <option>api</option>
                                <option>image</option>
                                <option>video</option>
                            </select>
                            <select
                                className={input}
                                value={rate.meter}
                                disabled
                            >
                                <option>input_tokens</option>
                                <option>output_tokens</option>
                                <option>unit</option>
                            </select>
                            <input
                                className={input}
                                value={rate.model}
                                disabled
                            />
                            <input
                                className={input}
                                type="number"
                                step="0.000001"
                                value={rate.price_idr ?? ""}
                                onChange={(e) =>
                                    patchRate(
                                        rate.id,
                                        "price_idr",
                                        e.target.value,
                                    )
                                }
                            />
                            <input
                                className={input}
                                type="number"
                                step="0.000001"
                                value={rate.price_usd ?? ""}
                                onChange={(e) =>
                                    patchRate(
                                        rate.id,
                                        "price_usd",
                                        e.target.value,
                                    )
                                }
                            />
                            <label className="text-xs text-center">
                                <input
                                    type="checkbox"
                                    checked={rate.is_active}
                                    onChange={(e) =>
                                        patchRate(
                                            rate.id,
                                            "is_active",
                                            e.target.checked,
                                        )
                                    }
                                />{" "}
                                Aktif
                            </label>
                            <button
                                className="px-3 py-2 rounded-lg bg-red-600 text-white"
                                onClick={() =>
                                    saveRate(rate).catch((error) =>
                                        setStatus(error.message),
                                    )
                                }
                            >
                                Simpan
                            </button>
                            <button
                                className="px-3 py-2 rounded-lg border border-red-500/40 text-red-500"
                                onClick={() =>
                                    deleteRate(rate.id).catch((error) =>
                                        setStatus(error.message),
                                    )
                                }
                            >
                                Hapus
                            </button>
                        </div>
                    ))}
                </div>
                <div
                    className={`grid md:grid-cols-4 gap-3 mt-5 pt-5 border-t ${dark ? "border-white/10" : "border-gray-200"}`}
                >
                    <select
                        className={input}
                        value={draft.service}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                service: e.target.value,
                                meter:
                                    e.target.value === "api"
                                        ? "input_tokens"
                                        : "unit",
                            })
                        }
                    >
                        <option>api</option>
                        <option>image</option>
                        <option>video</option>
                    </select>
                    <select
                        className={input}
                        value={draft.meter}
                        onChange={(e) =>
                            setDraft({ ...draft, meter: e.target.value })
                        }
                    >
                        {draft.service === "api" ? (
                            <>
                                <option>input_tokens</option>
                                <option>output_tokens</option>
                            </>
                        ) : (
                            <option>unit</option>
                        )}
                    </select>
                    <input
                        className={input}
                        placeholder="model-id"
                        value={draft.model}
                        onChange={(e) =>
                            setDraft({ ...draft, model: e.target.value })
                        }
                    />
                    <input
                        className={input}
                        placeholder="Nama tampil"
                        value={draft.label}
                        onChange={(e) =>
                            setDraft({ ...draft, label: e.target.value })
                        }
                    />
                    <input
                        className={input}
                        placeholder="Unit"
                        value={draft.unit}
                        onChange={(e) =>
                            setDraft({ ...draft, unit: e.target.value })
                        }
                    />
                    <input
                        className={input}
                        type="number"
                        placeholder="IDR"
                        value={draft.price_idr}
                        onChange={(e) =>
                            setDraft({ ...draft, price_idr: e.target.value })
                        }
                    />
                    <input
                        className={input}
                        type="number"
                        step="0.000001"
                        placeholder="USD"
                        value={draft.price_usd}
                        onChange={(e) =>
                            setDraft({ ...draft, price_usd: e.target.value })
                        }
                    />
                    <button
                        className="px-4 py-2 rounded-lg bg-red-600 text-white"
                        onClick={() =>
                            saveRate(draft).catch((error) =>
                                setStatus(error.message),
                            )
                        }
                    >
                        Tambah tarif
                    </button>
                </div>
            </section>
            <section className={panel}>
                <h2
                    className={`font-semibold mb-1 ${dark ? "text-white" : "text-slate-900"}`}
                >
                    Top up wallet PAYG
                </h2>
                <p className="text-xs text-gray-500 mb-4">
                    Saldo disimpan dalam micro-USD dan setiap perubahan masuk
                    ledger.
                </p>
                <div className="grid md:grid-cols-[150px_160px_1fr_120px] gap-3">
                    <input
                        className={input}
                        type="number"
                        placeholder="User ID"
                        value={topup.user_id}
                        onChange={(e) =>
                            setTopup({ ...topup, user_id: e.target.value })
                        }
                    />
                    <input
                        className={input}
                        type="number"
                        step="0.01"
                        placeholder="Amount USD"
                        value={topup.amount_usd}
                        onChange={(e) =>
                            setTopup({ ...topup, amount_usd: e.target.value })
                        }
                    />
                    <input
                        className={input}
                        placeholder="Keterangan"
                        value={topup.description}
                        onChange={(e) =>
                            setTopup({ ...topup, description: e.target.value })
                        }
                    />
                    <button
                        className="px-4 py-2 rounded-lg bg-red-600 text-white"
                        onClick={() =>
                            topupWallet().catch((error) =>
                                setStatus(error.message),
                            )
                        }
                    >
                        Top up
                    </button>
                </div>
            </section>

            <p
                className={`text-sm ${status.includes("Gagal") ? "text-red-500" : "text-emerald-500"}`}
            >
                {status || "Perubahan tersimpan langsung ke database."}
            </p>
        </div>
    );
}
