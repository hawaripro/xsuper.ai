import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

function formatRupiah(num) {
    if (!num) return 'Rp 0';
    return 'Rp ' + Number(num).toLocaleString('id-ID');
}

export default function CostProfit() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [usage, setUsage] = useState(null);
    const [revenue, setRevenue] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const [usageRes, revenueRes] = await Promise.all([
                    fetch('/api/usage', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }),
                    fetch('/api/a/stats/revenue', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }),
                ]);
                if (usageRes.ok) setUsage(await usageRes.json());
                if (revenueRes.ok) setRevenue(await revenueRes.json());
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

    const totalTokens = usage?.total_tokens || 0;
    const estimatedCost = Math.round(totalTokens * 0.015); // rough estimate per 1k tokens
    const revenueThisMonth = revenue?.revenue_this_month || 0;
    const profit = revenueThisMonth - estimatedCost;
    const margin = revenueThisMonth > 0 ? Math.round((profit / revenueThisMonth) * 100) : 0;

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Cost & Profit</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Analisis biaya AI dan keuntungan bulan ini.</p>
            </div>

            {/* Summary cards */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <div className={`text-xs mb-2 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Revenue Bulan Ini</div>
                    <div className={`text-xl font-extrabold ${isDark ? 'text-emerald-400' : 'text-emerald-600'}`}>{formatRupiah(revenueThisMonth)}</div>
                </div>
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <div className={`text-xs mb-2 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Estimasi Biaya AI</div>
                    <div className={`text-xl font-extrabold ${isDark ? 'text-red-400' : 'text-red-600'}`}>{formatRupiah(estimatedCost)}</div>
                </div>
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <div className={`text-xs mb-2 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Profit</div>
                    <div className={`text-xl font-extrabold ${profit >= 0 ? (isDark ? 'text-emerald-400' : 'text-emerald-600') : (isDark ? 'text-red-400' : 'text-red-600')}`}>{formatRupiah(profit)}</div>
                </div>
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <div className={`text-xs mb-2 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Margin</div>
                    <div className={`text-xl font-extrabold ${margin >= 0 ? (isDark ? 'text-emerald-400' : 'text-emerald-600') : (isDark ? 'text-red-400' : 'text-red-600')}`}>{margin}%</div>
                </div>
            </div>

            {/* Token usage breakdown */}
            <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Token Usage Breakdown</h3>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className={`p-4 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Total Tokens</div>
                        <div className={`text-lg font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{totalTokens.toLocaleString('id-ID')}</div>
                    </div>
                    <div className={`p-4 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Total Requests</div>
                        <div className={`text-lg font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{(usage?.total_requests || 0).toLocaleString('id-ID')}</div>
                    </div>
                    <div className={`p-4 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Avg Token/Request</div>
                        <div className={`text-lg font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{usage?.total_requests > 0 ? Math.round(totalTokens / usage.total_requests).toLocaleString('id-ID') : '0'}</div>
                    </div>
                </div>
            </div>

            {/* Note */}
            <div className={`rounded-xl border p-4 ${isDark ? 'bg-amber-500/5 border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                <p className={`text-xs ${isDark ? 'text-amber-300' : 'text-amber-700'}`}>
                    ⚠️ Estimasi biaya AI dihitung berdasarkan rata-rata harga per token. Untuk data akurat, integrasikan dengan billing provider.
                </p>
            </div>
        </div>
    );
}
