import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

function formatRupiah(num) {
    if (!num) return 'Rp 0';
    return 'Rp ' + Number(num).toLocaleString('id-ID');
}

function StatCard({ label, value, sub, isDark, color = 'red' }) {
    const colors = {
        red: 'from-red-500 to-red-600',
        blue: 'from-blue-500 to-indigo-500',
        emerald: 'from-emerald-500 to-teal-500',
        amber: 'from-amber-500 to-orange-500',
    };
    return (
        <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
            <div className={`w-10 h-10 rounded-xl bg-gradient-to-br ${colors[color]} text-white flex items-center justify-center mb-3 text-sm font-bold shadow-lg`}>
                {label[0]}
            </div>
            <div className={`text-2xl font-extrabold ${isDark ? 'text-white' : 'text-slate-900'}`}>{value}</div>
            <div className={`text-xs mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{label}</div>
            {sub && <div className={`text-[10px] mt-1 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>{sub}</div>}
        </div>
    );
}

export default function RevenueOverview() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await fetch('/api/a/stats/revenue', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (res.ok) setData(await res.json());
            } catch {} finally { setLoading(false); }
        };
        load();
    }, []);

    if (loading) {
        return (
            <div className="p-6 lg:p-8 flex items-center justify-center min-h-[400px]">
                <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
            </div>
        );
    }

    if (!data) {
        return (
            <div className="p-6 lg:p-8">
                <div className={`rounded-2xl border p-12 text-center ${isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'}`}>
                    <p className={`text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Gagal memuat data revenue.</p>
                </div>
            </div>
        );
    }

    const growthPct = data.revenue_last_month > 0
        ? Math.round(((data.revenue_this_month - data.revenue_last_month) / data.revenue_last_month) * 100)
        : data.revenue_this_month > 0 ? 100 : 0;

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Revenue Overview</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Ringkasan pendapatan dan metrik bisnis bulan ini.</p>
            </div>

            {/* Stats grid */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <StatCard label="Revenue Bulan Ini" value={formatRupiah(data.revenue_this_month)} sub={growthPct >= 0 ? `+${growthPct}% vs bulan lalu` : `${growthPct}% vs bulan lalu`} isDark={isDark} color="red" />
                <StatCard label="Total Revenue" value={formatRupiah(data.total_revenue)} isDark={isDark} color="emerald" />
                <StatCard label="User Aktif" value={data.active_users} sub={`dari ${data.total_users} total`} isDark={isDark} color="blue" />
                <StatCard label="Pending Orders" value={data.pending_orders} sub={`${data.orders_this_month} order bulan ini`} isDark={isDark} color="amber" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Revenue by package */}
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Revenue per Paket (Bulan Ini)</h3>
                    {data.revenue_by_package?.length > 0 ? (
                        <div className="space-y-3">
                            {data.revenue_by_package.map(pkg => {
                                const maxTotal = Math.max(...data.revenue_by_package.map(p => p.total));
                                const pct = maxTotal > 0 ? (pkg.total / maxTotal) * 100 : 0;
                                return (
                                    <div key={pkg.package}>
                                        <div className="flex items-center justify-between mb-1">
                                            <span className={`text-xs font-medium ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{pkg.package.replace('_', ' ')}</span>
                                            <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{pkg.count}x — {formatRupiah(pkg.total)}</span>
                                        </div>
                                        <div className={`h-2 rounded-full overflow-hidden ${isDark ? 'bg-white/5' : 'bg-gray-100'}`}>
                                            <div className="h-full rounded-full bg-gradient-to-r from-red-500 to-orange-500 transition-all duration-500" style={{ width: `${pct}%` }} />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <p className={`text-xs ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Belum ada data bulan ini.</p>
                    )}
                </div>

                {/* Daily revenue chart (simple bar) */}
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Revenue Harian (30 Hari)</h3>
                    {data.daily_revenue?.length > 0 ? (
                        <div className="flex items-end gap-[2px] h-32">
                            {data.daily_revenue.map(day => {
                                const maxDay = Math.max(...data.daily_revenue.map(d => d.total));
                                const h = maxDay > 0 ? (day.total / maxDay) * 100 : 0;
                                return (
                                    <div key={day.date} className="flex-1 flex flex-col items-center justify-end h-full group relative">
                                        <div className="absolute -top-6 left-1/2 -translate-x-1/2 hidden group-hover:block z-10">
                                            <div className={`px-2 py-1 rounded text-[9px] font-medium whitespace-nowrap ${isDark ? 'bg-gray-800 text-gray-200' : 'bg-gray-900 text-white'}`}>
                                                {day.date}: {formatRupiah(day.total)}
                                            </div>
                                        </div>
                                        <div className="w-full rounded-t bg-gradient-to-t from-red-500 to-orange-400 transition-all duration-300 hover:opacity-80" style={{ height: `${Math.max(h, 2)}%` }} />
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <p className={`text-xs ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Belum ada data.</p>
                    )}
                </div>
            </div>

            {/* Recent orders */}
            <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Transaksi Terakhir</h3>
                {data.recent_orders?.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className={isDark ? 'text-gray-500' : 'text-gray-400'}>
                                    <th className="text-left pb-3 font-medium">User</th>
                                    <th className="text-left pb-3 font-medium">Paket</th>
                                    <th className="text-right pb-3 font-medium">Harga</th>
                                    <th className="text-right pb-3 font-medium">Tanggal</th>
                                </tr>
                            </thead>
                            <tbody className={`divide-y ${isDark ? 'divide-white/5' : 'divide-gray-100'}`}>
                                {data.recent_orders.map(order => (
                                    <tr key={order.id}>
                                        <td className={`py-2.5 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                                            <div className="font-medium">{order.user_name}</div>
                                            <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>{order.user_email}</div>
                                        </td>
                                        <td className={`py-2.5 ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>{order.package.replace('_', ' ')} ({order.days}d)</td>
                                        <td className={`py-2.5 text-right font-medium ${isDark ? 'text-emerald-400' : 'text-emerald-600'}`}>{formatRupiah(order.price)}</td>
                                        <td className={`py-2.5 text-right ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{new Date(order.approved_at).toLocaleDateString('id-ID')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className={`text-xs ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Belum ada transaksi.</p>
                )}
            </div>
        </div>
    );
}
