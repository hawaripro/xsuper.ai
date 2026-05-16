import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function AnalyticsFunnel() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await fetch('/api/a/stats/revenue', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (res.ok) setStats(await res.json());
            } catch {} finally { setLoading(false); }
        };
        load();
    }, []);

    const funnel = stats ? [
        { label: 'Total Users', value: stats.total_users, pct: 100 },
        { label: 'User Aktif', value: stats.active_users, pct: stats.total_users > 0 ? Math.round((stats.active_users / stats.total_users) * 100) : 0 },
        { label: 'Pernah Order', value: stats.orders_this_month, pct: stats.active_users > 0 ? Math.min(100, Math.round((stats.orders_this_month / stats.active_users) * 100)) : 0 },
        { label: 'Approved', value: stats.approved_this_month, pct: stats.orders_this_month > 0 ? Math.round((stats.approved_this_month / stats.orders_this_month) * 100) : 0 },
    ] : [];

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Analytics & Funnel</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Analisis funnel konversi pengguna bulan ini.</p>
            </div>

            {loading ? (
                <div className="flex items-center justify-center py-20">
                    <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                </div>
            ) : (
                <div className="space-y-6">
                    {/* Funnel visualization */}
                    <div className={`rounded-2xl border p-6 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                        <h3 className={`text-sm font-semibold mb-6 ${isDark ? 'text-white' : 'text-slate-900'}`}>Conversion Funnel</h3>
                        <div className="space-y-4 max-w-lg mx-auto">
                            {funnel.map((step, i) => (
                                <div key={step.label}>
                                    <div className="flex items-center justify-between mb-1.5">
                                        <span className={`text-xs font-medium ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{step.label}</span>
                                        <span className={`text-xs font-bold ${isDark ? 'text-white' : 'text-slate-900'}`}>{step.value} <span className={`font-normal ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>({step.pct}%)</span></span>
                                    </div>
                                    <div className={`h-8 rounded-lg overflow-hidden ${isDark ? 'bg-white/5' : 'bg-gray-100'}`}>
                                        <div
                                            className="h-full rounded-lg bg-gradient-to-r from-red-500 to-orange-500 transition-all duration-700 flex items-center justify-end pr-2"
                                            style={{ width: `${Math.max(step.pct, 5)}%` }}
                                        >
                                            {step.pct > 20 && <span className="text-[10px] font-bold text-white">{step.pct}%</span>}
                                        </div>
                                    </div>
                                    {i < funnel.length - 1 && (
                                        <div className="flex justify-center py-1">
                                            <svg className={`w-4 h-4 ${isDark ? 'text-gray-700' : 'text-gray-300'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Quick stats */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className={`rounded-2xl border p-4 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                            <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>New Users (Bulan Ini)</div>
                            <div className={`text-xl font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{stats?.new_users_this_month || 0}</div>
                        </div>
                        <div className={`rounded-2xl border p-4 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                            <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Conversion Rate</div>
                            <div className={`text-xl font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{stats?.total_users > 0 ? Math.round((stats.approved_this_month / stats.total_users) * 100) : 0}%</div>
                        </div>
                        <div className={`rounded-2xl border p-4 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                            <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Pending Orders</div>
                            <div className={`text-xl font-bold mt-1 text-amber-500`}>{stats?.pending_orders || 0}</div>
                        </div>
                        <div className={`rounded-2xl border p-4 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                            <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Retention (Aktif/Total)</div>
                            <div className={`text-xl font-bold mt-1 text-emerald-500`}>{stats?.total_users > 0 ? Math.round((stats.active_users / stats.total_users) * 100) : 0}%</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
