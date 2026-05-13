import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

const fmt = new Intl.NumberFormat('id-ID');

function getCsrfToken() {
    try {
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        if (match) return decodeURIComponent(match[1]);
    } catch {}
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

export default function PeriodManagement() {
    const { user } = useAuth();
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [orders, setOrders] = useState([]);
    const [filter, setFilter] = useState('pending');
    const [loading, setLoading] = useState(true);
    const [pendingCount, setPendingCount] = useState(0);
    const [actionLoading, setActionLoading] = useState(null);
    const [addModal, setAddModal] = useState(null); // {user_id, name}
    const [addDays, setAddDays] = useState('');

    const fetchOrders = useCallback(async () => {
        try {
            const res = await fetch(`/api/a/period?status=${filter}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setOrders(data.orders || []);
                setPendingCount(data.pending_count || 0);
            }
        } catch (err) {
            console.error('Failed to load orders:', err);
        } finally {
            setLoading(false);
        }
    }, [filter]);

    useEffect(() => { fetchOrders(); }, [fetchOrders]);

    // Auto refresh every 10s
    useEffect(() => {
        const interval = setInterval(fetchOrders, 10000);
        return () => clearInterval(interval);
    }, [fetchOrders]);

    const handleApprove = async (orderId) => {
        setActionLoading(orderId);
        try {
            await fetch(`/api/a/period/approve/${orderId}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            fetchOrders();
        } catch {} finally { setActionLoading(null); }
    };

    const handleReject = async (orderId) => {
        setActionLoading(orderId);
        try {
            await fetch(`/api/a/period/reject/${orderId}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            fetchOrders();
        } catch {} finally { setActionLoading(null); }
    };

    const handleAddDuration = async () => {
        if (!addModal || !addDays) return;
        setActionLoading('add');
        try {
            await fetch('/api/a/period/add-duration', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ user_id: addModal.user_id, days: parseInt(addDays), note: 'Manual admin' }),
            });
            setAddModal(null);
            setAddDays('');
            fetchOrders();
        } catch {} finally { setActionLoading(null); }
    };

    const statusColors = {
        pending: isDark ? 'bg-amber-500/15 text-amber-400 border-amber-500/20' : 'bg-amber-50 text-amber-600 border-amber-200',
        approved: isDark ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20' : 'bg-emerald-50 text-emerald-600 border-emerald-200',
        rejected: isDark ? 'bg-red-500/15 text-red-400 border-red-500/20' : 'bg-red-50 text-red-600 border-red-200',
    };

    const formatDate = (d) => d ? new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

    return (
        <div className="p-4 lg:p-6 max-w-7xl mx-auto space-y-5">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 className={`text-xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>Period Management</h1>
                    <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                        Kelola durasi langganan member
                        {pendingCount > 0 && <span className="ml-2 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-400 animate-pulse">{pendingCount} pending</span>}
                    </p>
                </div>
            </div>

            {/* Filter tabs */}
            <div className="flex gap-2">
                {['pending', 'approved', 'rejected', ''].map(s => (
                    <button
                        key={s}
                        onClick={() => { setFilter(s); setLoading(true); }}
                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                            filter === s
                                ? 'bg-red-500/15 text-red-400 border border-red-500/20'
                                : isDark ? 'text-gray-400 hover:bg-white/[0.06]' : 'text-gray-500 hover:bg-gray-100'
                        }`}
                    >
                        {s === '' ? 'Semua' : s === 'pending' ? 'Pending' : s === 'approved' ? 'Approved' : 'Rejected'}
                    </button>
                ))}
            </div>

            {/* Orders list */}
            <div className="space-y-3">
                {loading ? (
                    <div className={`text-center py-12 text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Memuat...</div>
                ) : orders.length === 0 ? (
                    <div className={`text-center py-12 text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Tidak ada order</div>
                ) : (
                    orders.map(order => (
                        <div key={order.id} className={`p-4 rounded-xl border ${isDark ? 'bg-white/[0.02] border-white/[0.06]' : 'bg-white border-gray-200 shadow-sm'}`}>
                            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 mb-1">
                                        <span className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{order.user_name}</span>
                                        <span className={`text-[10px] font-bold uppercase px-1.5 py-0.5 rounded-md border ${statusColors[order.status]}`}>{order.status}</span>
                                    </div>
                                    <div className={`text-xs space-y-0.5 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                        <div>{order.user_email} · {order.user_role}</div>
                                        <div>
                                            Paket: <span className="font-semibold">{order.package}</span> · 
                                            {order.days} hari · 
                                            Rp {fmt.format(order.price)}
                                        </div>
                                        <div>Order: {formatDate(order.created_at)}</div>
                                        {order.user_expires_at && (
                                            <div>Masa aktif saat ini: {formatDate(order.user_expires_at)}</div>
                                        )}
                                    </div>
                                </div>

                                {/* Actions */}
                                {order.status === 'pending' && (
                                    <div className="flex gap-2 flex-shrink-0">
                                        <button
                                            onClick={() => handleApprove(order.id)}
                                            disabled={actionLoading === order.id}
                                            className="px-3 py-1.5 rounded-lg text-xs font-bold bg-emerald-500 text-white hover:bg-emerald-600 disabled:opacity-50 transition-all"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            onClick={() => handleReject(order.id)}
                                            disabled={actionLoading === order.id}
                                            className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${isDark ? 'bg-red-500/15 text-red-400 hover:bg-red-500/25' : 'bg-red-50 text-red-600 hover:bg-red-100'}`}
                                        >
                                            Tolak
                                        </button>
                                        <button
                                            onClick={() => setAddModal({ user_id: order.user_id, name: order.user_name })}
                                            className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${isDark ? 'bg-white/[0.06] text-gray-300 hover:bg-white/[0.1]' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}
                                        >
                                            + Durasi
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* Add Duration Modal */}
            {addModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setAddModal(null)} />
                    <div className={`relative w-full max-w-sm rounded-2xl border p-6 shadow-2xl ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <h3 className={`text-lg font-bold mb-1 ${isDark ? 'text-white' : 'text-gray-900'}`}>Tambah Durasi</h3>
                        <p className={`text-sm mb-4 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Untuk: {addModal.name}</p>
                        <input
                            type="number"
                            value={addDays}
                            onChange={(e) => setAddDays(e.target.value)}
                            placeholder="Jumlah hari"
                            className={`w-full px-4 py-2.5 rounded-xl border text-sm mb-4 focus:outline-none focus:ring-2 focus:ring-red-500/20 ${
                                isDark ? 'bg-white/[0.05] border-white/[0.08] text-white placeholder-gray-500' : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400'
                            }`}
                        />
                        <div className="flex gap-3">
                            <button onClick={() => setAddModal(null)} className={`flex-1 py-2.5 rounded-xl border text-sm font-medium ${isDark ? 'bg-white/[0.05] border-white/[0.08] text-gray-400' : 'bg-gray-50 border-gray-300 text-gray-500'}`}>Batal</button>
                            <button onClick={handleAddDuration} disabled={!addDays || actionLoading === 'add'} className="flex-1 py-2.5 rounded-xl bg-red-500 text-white text-sm font-bold hover:bg-red-600 disabled:opacity-50 transition-all">Tambah</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
