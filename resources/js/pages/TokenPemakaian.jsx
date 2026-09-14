import React, { useCallback, useEffect, useMemo, useState } from "react";
import { apiRequest } from "../lib/api";
import {
    Button,
    MemberPage,
    Metric,
    PageHeader,
    Panel,
    SectionHeader,
    Spinner,
    StatePanel,
    StatusBadge,
    controlClass,
    errorMessage,
    formatCount,
    formatLocalDate,
    formatUsdMicros,
} from "../components/member/MemberUI";

const PERIODS = [
    ["hourly", "24 jam"],
    ["daily", "30 hari"],
    ["weekly", "12 minggu"],
    ["monthly", "12 bulan"],
    ["all", "Semua"],
];

function UsageOverview({ usage, wallet, tokens }) {
    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Metric label="Saldo wallet" value={formatUsdMicros(wallet?.balance_microusd)} detail="Saldo penggunaan terukur" />
            <Metric label="Saldo token" value={formatCount(tokens?.balance)} detail="Token gambar & video" />
            <Metric label="Permintaan" value={formatCount(usage?.total_requests)} detail="Pada periode terpilih" />
            <Metric label="Total token" value={formatCount(usage?.total_tokens)} detail={`${formatCount(usage?.total_credits)} kredit`} />
        </div>
    );
}

export default function TokenPemakaian() {
    const [period, setPeriod] = useState("daily");
    const [service, setService] = useState("all");
    const [data, setData] = useState({ usage: null, wallet: null, tokens: null, transactions: [] });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        const results = await Promise.allSettled([
            apiRequest(`/api/usage/me?period=${encodeURIComponent(period)}`),
            apiRequest("/api/pricing/wallet"),
            apiRequest("/api/t/balance"),
            apiRequest("/api/t/history"),
        ]);
        const rejected = results.find((result) => result.status === "rejected");
        if (rejected) {
            setError(rejected.reason);
            setLoading(false);
            return;
        }
        setData({
            usage: results[0].value,
            wallet: results[1].value,
            tokens: results[2].value,
            transactions: Array.isArray(results[3].value?.transactions) ? results[3].value.transactions : [],
        });
        setLoading(false);
    }, [period]);

    useEffect(() => {
        load();
    }, [load]);

    const modelRows = useMemo(() => {
        const rows = Array.isArray(data.usage?.by_model) ? data.usage.by_model : [];
        if (service === "all") return rows;
        const needle = service.toLocaleLowerCase("id-ID");
        return rows.filter((row) => String(row.model || "").toLocaleLowerCase("id-ID").includes(needle));
    }, [data.usage, service]);

    const maxRequests = Math.max(1, ...(Array.isArray(data.usage?.timeline) ? data.usage.timeline : []).map((row) => Number(row.requests) || 0));

    return (
        <MemberPage>
            <PageHeader
                eyebrow="Billing & usage"
                title="Token & pemakaian"
                description="Saldo nyata, penggunaan API, dan transaksi token pada akun Anda."
                actions={<Button variant="secondary" onClick={load} disabled={loading}>{loading ? "Memuat…" : "Muat ulang"}</Button>}
            />

            <Panel className="p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <SectionHeader title="Rentang pemakaian" description="Ringkasan dan grafik mengikuti periode pilihan." />
                    <label className="w-full sm:w-44">
                        <span className="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">Periode</span>
                        <select className={controlClass} value={period} onChange={(event) => setPeriod(event.target.value)} disabled={loading}>
                            {PERIODS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                </div>
            </Panel>

            {loading ? (
                <Panel className="flex min-h-56 items-center justify-center"><Spinner label="Mengambil data pemakaian" /></Panel>
            ) : error ? (
                <Panel className="p-4">
                    <StatePanel type="error" title="Data pemakaian tidak dapat dimuat" description={errorMessage(error, "Saldo atau riwayat penggunaan gagal dimuat.")} action={<Button variant="secondary" onClick={load}>Coba lagi</Button>} />
                </Panel>
            ) : (
                <>
                    <UsageOverview usage={data.usage} wallet={data.wallet} tokens={data.tokens} />

                    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(300px,.75fr)]">
                        <Panel className="p-4">
                            <SectionHeader title="Aktivitas permintaan" description={data.usage?.from ? `Sejak ${formatLocalDate(data.usage.from)}` : "Seluruh riwayat tersedia"} />
                            {Array.isArray(data.usage?.timeline) && data.usage.timeline.length > 0 ? (
                                <div className="mt-5 flex h-48 items-end gap-1 overflow-x-auto border-b border-slate-200 pb-1 dark:border-white/[0.08]" role="img" aria-label="Grafik jumlah permintaan per periode">
                                    {data.usage.timeline.map((row) => (
                                        <div key={row.period} className="group flex h-full min-w-4 flex-1 items-end" title={`${row.period}: ${formatCount(row.requests)} permintaan`}>
                                            <div className="w-full rounded-t-sm bg-red-500/70 transition group-hover:bg-red-500" style={{ height: `${Math.max(3, (Number(row.requests || 0) / maxRequests) * 100)}%` }} />
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="mt-4"><StatePanel title="Belum ada pemakaian" description="Aktivitas API pada periode ini akan tampil sebagai grafik." compact /></div>
                            )}
                            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <Metric label="Kredit" value={formatCount(data.usage?.total_credits)} />
                                <Metric label="Biaya tercatat" value={formatUsdMicros(data.usage?.total_cost_microusd)} />
                                <Metric label="Terakhir dipakai" value={data.usage?.last_used_at ? formatLocalDate(data.usage.last_used_at, { year: undefined }) : "Belum pernah"} />
                            </div>
                        </Panel>

                        <Panel className="p-4">
                            <SectionHeader title="Transaksi token" description="Maksimal 50 transaksi terbaru." />
                            <div className="mt-3 max-h-72 divide-y divide-slate-100 overflow-y-auto dark:divide-white/[0.06]">
                                {data.transactions.length === 0 ? (
                                    <StatePanel title="Belum ada transaksi" description="Top-up dan pemakaian token akan muncul di sini." compact />
                                ) : data.transactions.map((transaction, index) => {
                                    const amount = Number(transaction.amount || 0);
                                    return (
                                        <div key={`${transaction.created_at || "transaction"}-${index}`} className="flex items-start justify-between gap-3 py-2.5 first:pt-0">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="truncate text-[12px] font-medium text-slate-800 dark:text-slate-200">{transaction.description || transaction.type || "Transaksi token"}</p>
                                                    <StatusBadge value={transaction.type} />
                                                </div>
                                                <p className="mt-0.5 text-[10px] text-slate-500">{formatLocalDate(transaction.created_at)}</p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p className={`text-[12px] font-semibold ${amount >= 0 ? "text-emerald-600 dark:text-emerald-400" : "text-red-600 dark:text-red-400"}`}>{amount > 0 ? "+" : ""}{formatCount(amount)}</p>
                                                <p className="text-[10px] text-slate-500">Saldo {formatCount(transaction.balance_after)}</p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </Panel>
                    </div>

                    <Panel className="overflow-hidden">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-end sm:justify-between dark:border-white/[0.08]">
                            <SectionHeader title="Pemakaian per model" description="Permintaan, token, kredit, dan biaya pada periode terpilih." />
                            <label className="w-full sm:w-48">
                                <span className="mb-1 block text-[11px] font-medium text-slate-500 dark:text-slate-400">Filter model</span>
                                <input className={controlClass} value={service === "all" ? "" : service} onChange={(event) => setService(event.target.value || "all")} type="search" placeholder="Semua model" />
                            </label>
                        </div>
                        {modelRows.length === 0 ? (
                            <div className="p-4"><StatePanel title="Tidak ada rincian model" description={service === "all" ? "Belum ada model yang digunakan pada periode ini." : "Tidak ada model yang cocok dengan filter."} compact /></div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[680px] text-left">
                                    <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500 dark:bg-white/[0.025] dark:text-slate-400">
                                        <tr><th className="px-4 py-2.5 font-semibold">Model</th><th className="px-4 py-2.5 text-right font-semibold">Permintaan</th><th className="px-4 py-2.5 text-right font-semibold">Token</th><th className="px-4 py-2.5 text-right font-semibold">Kredit</th><th className="px-4 py-2.5 text-right font-semibold">Biaya</th></tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-white/[0.06]">
                                        {modelRows.map((row) => (
                                            <tr key={row.model || "unknown"} className="text-[12px] text-slate-600 dark:text-slate-300">
                                                <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">{row.model || "Tidak diketahui"}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatCount(row.requests)}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatCount(row.tokens)}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatCount(row.credits)}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatUsdMicros(row.cost_microusd)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>
                </>
            )}
        </MemberPage>
    );
}
