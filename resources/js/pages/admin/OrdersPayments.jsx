import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

function formatRupiah(num) {
    if (!num) return 'Rp 0';
    return 'Rp ' + Number(num).toLocaleString('id-ID');
}

const STATUS_CONFIG = {
    pending: { label: 'Pending', bg: 'bg-amber-500/10 text-amber-500 border-amber-500/20' },
    approved: { label: 'Approved', bg: 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' },
    rejected: { label: 'Rejected', bg: 'bg-red-500/10 text-red-500 border-red-500/20' },
};

export default function OrdersPayments() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [orders, setOrders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState({ last_page: 1 });

    const load = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ page });
            if (filter !== 'all') params.set('status', filter);
            if (search) params.set('search', search);
            const res = await fetch(`/api/a/stats/orders?${params}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                const json = await res.json();
                setOrders(json.data || []);
                setMeta({ last_page: json.last_page || 1, total: json.total || 0 });
            }
        } catch {} finally { setLoading(false); }
    };

    useEffect(() => { load(); }, [filter, page]);

    const handleSearch = (e) => {
        e.preventDefault();
        setPage(1);
        load();
    };

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Orders & Payments</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Kelola semua pesanan dan pembayaran pengguna.</p>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3">
                {['all', 'pending', 'approved', 'rejected'].map(s => (
                    <button key={s} onClick={() => { setFilter(s); setPage(1); }}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all border ${
                            filter === s
                                ? (isDark ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-red-50 text-red-600 border-red-200')
                                : (isDark ? 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10' : 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200')
                        }`}
                    >
                        {s === 'all' ? 'Semua' : s.charAt(0).toUpperCase() + s.slice(1)}
                    </button>
                ))}
                <form onSubmit={handleSearch} className="ml-auto flex gap-2">
                    <input
                        type="text" value={search} onChange={e => setSearch(e.target.value)}
                        placeholder="Cari nama/email..."
                        className={`px-3 py-1.5 rounded-lg text-xs border ${isDark ? 'bg-white/5 border-white/10 text-white placeholder-gray-500' : 'bg-white border-gray-200 text-gray-900 placeholder-gray-400'}`}
                    />
                    <button type="submit" className={`px-3 py-1.5 rounded-lg text-xs font-medium ${isDark ? 'bg-white/10 text-white' : 'bg-gray-900 text-white'}`}>Cari</button>
                </form>
            </div>

            {/* Table */}
            <div className={`rounded-2xl border overflow-hidden ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                {loading ? (
                    <div className="flex items-center justify-center py-20">
                        <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    </div>
                ) : orders.length === 0 ? (
                    <div className="py-16 text-center">
                        <p className={`text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Tidak ada order ditemukan.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className={`border-b ${isDark ? 'border-white/5 text-gray-500' : 'border-gray-100 text-gray-400'}`}>
                                    <th className="text-left p-4 font-medium">ID</th>
                                    <th className="text-left p-4 font-medium">User</th>
                                    <th className="text-left p-4 font-medium">Paket</th>
                                    <th className="text-right p-4 font-medium">Harga</th>
                                    <th className="text-center p-4 font-medium">Status</th>
                                    <th className="text-right p-4 font-medium">Tanggal</th>
                                </tr>
                            </thead>
                            <tbody className={`divide-y ${isDark ? 'divide-white/5' : 'divide-gray-50'}`}>
                                {orders.map(order => {
                                    const sc = STATUS_CONFIG[order.status] || STATUS_CONFIG.pending;
                                    return (
                                        <tr key={order.id} className={`transition-colors ${isDark ? 'hover:bg-white/[0.02]' : 'hover:bg-gray-50'}`}>
                                            <td className={`p-4 font-mono ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>#{order.id}</td>
                                            <td className="p-4">
                                                <div className={`font-medium ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>{order.user_name}</div>
                                                <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>{order.user_email}</div>
                                            </td>
                                            <td className={`p-4 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{order.package?.replace('_', ' ')} ({order.days}d)</td>
                                            <td className={`p-4 text-right font-medium ${isDark ? 'text-white' : 'text-slate-900'}`}>{formatRupiah(order.price)}</td>
                                            <td className="p-4 text-center">
                                                <span className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold border ${sc.bg}`}>{sc.label}</span>
                                            </td>
                                            <td className={`p-4 text-right ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{new Date(order.created_at).toLocaleDateString('id-ID')}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Pagination */}
            {meta.last_page > 1 && (
                <div className="flex items-center justify-center gap-2">
                    <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-all ${isDark ? 'border-white/10 text-gray-400 hover:bg-white/5 disabled:opacity-30' : 'border-gray-200 text-gray-600 hover:bg-gray-100 disabled:opacity-30'}`}>← Prev</button>
                    <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{page} / {meta.last_page}</span>
                    <button onClick={() => setPage(p => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-all ${isDark ? 'border-white/10 text-gray-400 hover:bg-white/5 disabled:opacity-30' : 'border-gray-200 text-gray-600 hover:bg-gray-100 disabled:opacity-30'}`}>Next →</button>
                </div>
            )}
        </div>
    );
}
