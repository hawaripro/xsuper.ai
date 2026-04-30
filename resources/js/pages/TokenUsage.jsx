import React, { useState, useEffect, useCallback } from 'react';
import { useTheme } from '../contexts/ThemeContext';
import { AreaChart, Area, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend, PieChart, Pie, Cell } from 'recharts';

const COLORS = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316', '#14b8a6', '#a855f7', '#6366f1', '#84cc16', '#e11d48', '#0ea5e9', '#d946ef'];
const PERIODS = [
    { key: 'hourly', label: 'Per Jam', desc: '24 jam terakhir' },
    { key: 'daily', label: 'Per Hari', desc: '30 hari terakhir' },
    { key: 'weekly', label: 'Per Minggu', desc: '12 minggu terakhir' },
    { key: 'monthly', label: 'Per Bulan', desc: '12 bulan terakhir' },
    { key: 'all', label: 'Semua', desc: 'Seluruh waktu' },
];

function StatCard({ label, value, icon, color, isDark }) {
    const bg = isDark ? `from-${color}-500/15 to-${color}-500/5` : `from-${color}-50 to-white`;
    const border = isDark ? `border-${color}-500/10` : `border-${color}-200`;
    const iconBg = isDark ? `bg-${color}-500/15` : `bg-${color}-100`;
    const iconText = `text-${color}-400`;
    return (
        <div className={`relative p-4 rounded-xl bg-gradient-to-br ${bg} border ${border} overflow-hidden hover:scale-[1.02] transition-transform duration-300`}>
            <div className="absolute top-0 right-0 w-20 h-20 bg-current opacity-[0.03] rounded-full blur-2xl -translate-y-1/2 translate-x-1/2" />
            <div className={`w-8 h-8 rounded-lg ${iconBg} ${iconText} flex items-center justify-center mb-2`}>{icon}</div>
            <div className={`text-lg font-extrabold ${isDark ? 'text-white' : 'text-gray-900'} tracking-tight`}>{value}</div>
            <div className={`text-[10px] mt-0.5 font-medium ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{label}</div>
        </div>
    );
}

function CustomTooltip({ active, payload, label, isDark }) {
    if (!active || !payload?.length) return null;
    return (
        <div className={`p-3 rounded-xl border shadow-xl ${isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'}`}>
            <p className={`text-xs font-bold mb-1.5 ${isDark ? 'text-white' : 'text-gray-900'}`}>{label}</p>
            {payload.map((p, i) => (
                <div key={i} className="flex items-center gap-2 text-[11px]">
                    <span className="w-2 h-2 rounded-full" style={{ background: p.color }} />
                    <span className={isDark ? 'text-gray-400' : 'text-gray-500'}>{p.name}:</span>
                    <span className={`font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{Number(p.value).toLocaleString()}</span>
                </div>
            ))}
        </div>
    );
}

export default function TokenUsage() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState('daily');
    const [selectedUser, setSelectedUser] = useState(null);
    const [userStats, setUserStats] = useState(null);
    const [chartType, setChartType] = useState('area'); // area, bar

    const textClass = isDark ? 'text-white' : 'text-gray-900';
    const subClass = isDark ? 'text-gray-500' : 'text-gray-400';
    const cardClass = isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm';
    const gridColor = isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.06)';
    const axisColor = isDark ? '#6b7280' : '#9ca3af';

    const loadStats = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`/api/usage?period=${period}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) setStats(await res.json());
        } catch {} finally { setLoading(false); }
    }, [period]);

    useEffect(() => { loadStats(); }, [loadStats]);

    const loadUserStats = async (userId) => {
        setSelectedUser(userId);
        try {
            const res = await fetch(`/api/usage?user_id=${userId}&period=${period}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) setUserStats(await res.json());
        } catch {}
    };

    const modelTimeline = stats?.model_timeline || { data: [], models: [] };
    const maxModel = Math.max(...(stats?.by_model || []).map(x => Number(x.tokens)), 1);

    return (
        <div className="p-3 lg:p-5 space-y-4 max-w-7xl mx-auto" style={{ fontSize: '90%' }}>
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 className={`text-xl font-extrabold tracking-tight ${textClass}`}>Token Usage</h1>
                    <p className={`text-xs mt-1 ${subClass}`}>Monitor penggunaan token AI seluruh platform</p>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={loadStats} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}>
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-2">
                <StatCard isDark={isDark} label="Total Tokens" value={(stats?.total_tokens || 0).toLocaleString()} color="red"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>} />
                <StatCard isDark={isDark} label="Credits" value={(stats?.total_credits || 0).toFixed(2)} color="blue"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>} />
                <StatCard isDark={isDark} label="Requests" value={(stats?.total_requests || 0).toLocaleString()} color="emerald"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>} />
                <StatCard isDark={isDark} label="Users" value={stats?.active_users || 0} color="amber"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>} />
                <StatCard isDark={isDark} label="Avg/Req" value={stats?.total_requests ? Math.round((stats?.total_tokens || 0) / stats.total_requests) : 0} color="violet"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>} />
            </div>

            {/* Period Selector + Chart Type */}
            <div className="flex flex-wrap items-center gap-2">
                <div className={`flex rounded-lg p-0.5 ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                    {PERIODS.map(p => (
                        <button key={p.key} onClick={() => setPeriod(p.key)}
                            className={`px-3 py-1.5 rounded-md text-[11px] font-semibold transition-all ${period === p.key
                                ? 'bg-gradient-to-r from-red-500 to-red-600 text-white shadow'
                                : isDark ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-900'
                            }`}>
                            {p.label}
                        </button>
                    ))}
                </div>
                <div className={`flex rounded-lg p-0.5 ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                    {[['area', 'Area'], ['bar', 'Bar']].map(([k, l]) => (
                        <button key={k} onClick={() => setChartType(k)}
                            className={`px-3 py-1.5 rounded-md text-[11px] font-semibold transition-all ${chartType === k
                                ? isDark ? 'bg-white/[0.1] text-white' : 'bg-white text-gray-900 shadow'
                                : isDark ? 'text-gray-500' : 'text-gray-400'
                            }`}>
                            {l}
                        </button>
                    ))}
                </div>
                <span className={`text-[10px] ml-auto ${subClass}`}>{PERIODS.find(p => p.key === period)?.desc}</span>
            </div>

            {loading ? (
                <div className="flex items-center justify-center py-16"><div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" /></div>
            ) : (
                <>
                    {/* Main Timeline Chart — Stacked by Model */}
                    <div className={`p-4 rounded-xl border ${cardClass}`}>
                        <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                            <svg className="w-4 h-4 text-red-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            Token Usage Timeline — Per Model
                        </h3>
                        <div style={{ width: '100%', height: 320 }}>
                            <ResponsiveContainer>
                                {chartType === 'area' ? (
                                    <AreaChart data={modelTimeline.data}>
                                        <defs>
                                            {modelTimeline.models.map((m, i) => (
                                                <linearGradient key={m} id={`grad-${i}`} x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor={COLORS[i % COLORS.length]} stopOpacity={0.3} />
                                                    <stop offset="95%" stopColor={COLORS[i % COLORS.length]} stopOpacity={0} />
                                                </linearGradient>
                                            ))}
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                        <XAxis dataKey="label" tick={{ fontSize: 10, fill: axisColor }} />
                                        <YAxis tick={{ fontSize: 10, fill: axisColor }} tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}k` : v} />
                                        <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                        <Legend wrapperStyle={{ fontSize: 10 }} />
                                        {modelTimeline.models.map((m, i) => (
                                            <Area key={m} type="monotone" dataKey={m} stackId="1" stroke={COLORS[i % COLORS.length]} fill={`url(#grad-${i})`} strokeWidth={2} dot={false} activeDot={{ r: 4, strokeWidth: 2 }} animationDuration={1000} animationBegin={i * 100} />
                                        ))}
                                    </AreaChart>
                                ) : (
                                    <BarChart data={modelTimeline.data}>
                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                        <XAxis dataKey="label" tick={{ fontSize: 10, fill: axisColor }} />
                                        <YAxis tick={{ fontSize: 10, fill: axisColor }} tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}k` : v} />
                                        <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                        <Legend wrapperStyle={{ fontSize: 10 }} />
                                        {modelTimeline.models.map((m, i) => (
                                            <Bar key={m} dataKey={m} stackId="1" fill={COLORS[i % COLORS.length]} animationDuration={800} animationBegin={i * 50} radius={i === modelTimeline.models.length - 1 ? [4, 4, 0, 0] : [0, 0, 0, 0]} />
                                        ))}
                                    </BarChart>
                                )}
                            </ResponsiveContainer>
                        </div>
                    </div>

                    <div className="grid lg:grid-cols-2 gap-4">
                        {/* Total Timeline Chart */}
                        <div className={`p-4 rounded-xl border ${cardClass}`}>
                            <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                                <svg className="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                Total Tokens
                            </h3>
                            <div style={{ width: '100%', height: 200 }}>
                                <ResponsiveContainer>
                                    <AreaChart data={stats?.timeline || []}>
                                        <defs>
                                            <linearGradient id="gradTotal" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#10b981" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                        <XAxis dataKey="label" tick={{ fontSize: 9, fill: axisColor }} />
                                        <YAxis tick={{ fontSize: 9, fill: axisColor }} tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}k` : v} />
                                        <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                        <Area type="monotone" dataKey="tokens" name="Tokens" stroke="#10b981" fill="url(#gradTotal)" strokeWidth={2} dot={false} activeDot={{ r: 4, strokeWidth: 2, fill: '#10b981' }} animationDuration={1200} />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </div>

                        {/* Model Breakdown — Horizontal Bars */}
                        <div className={`p-4 rounded-xl border ${cardClass}`}>
                            <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                                <svg className="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                                Model Breakdown
                            </h3>
                            <div className="space-y-2 max-h-48 overflow-y-auto scrollbar-thin pr-1">
                                {(stats?.by_model || []).map((m, i) => {
                                    const pct = (Number(m.tokens) / maxModel * 100);
                                    return (
                                        <div key={i}>
                                            <div className="flex items-center justify-between mb-0.5">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="w-2 h-2 rounded-full" style={{ background: COLORS[i % COLORS.length] }} />
                                                    <span className={`text-[11px] font-medium truncate ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{m.model}</span>
                                                </div>
                                                <span className={`text-[10px] font-bold ml-2 ${textClass}`}>{Number(m.tokens).toLocaleString()}</span>
                                            </div>
                                            <div className={`h-2 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                                <div className="h-full rounded-full transition-all duration-700" style={{ width: `${pct}%`, background: COLORS[i % COLORS.length] }} />
                                            </div>
                                        </div>
                                    );
                                })}
                                {(stats?.by_model || []).length === 0 && <p className={`text-xs text-center py-6 ${subClass}`}>Belum ada data</p>}
                            </div>
                        </div>
                    </div>

                    {/* Top Users */}
                    <div className={`p-4 rounded-xl border ${cardClass}`}>
                        <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                            <svg className="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            Top Users
                        </h3>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className={`border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                                        {['#', 'User', 'Tokens', 'Credits', 'Requests'].map(h => (
                                            <th key={h} className={`${h === '#' || h === 'User' ? 'text-left' : 'text-right'} px-3 py-2 text-[10px] font-bold uppercase tracking-wider ${subClass}`}>{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {(stats?.top_users || []).map((u, i) => {
                                        const rankColors = ['bg-amber-500 text-white', 'bg-gray-400 text-white', 'bg-amber-700 text-white'];
                                        const rankClass = i < 3 ? rankColors[i] : (isDark ? 'bg-white/[0.05] text-gray-400' : 'bg-gray-100 text-gray-500');
                                        return (
                                            <tr key={i} className={`border-b cursor-pointer transition-colors ${isDark ? 'border-white/[0.03] hover:bg-white/[0.03]' : 'border-gray-100 hover:bg-gray-50'}`}
                                                onClick={() => loadUserStats(u.user_id)}>
                                                <td className="px-3 py-2"><span className={`inline-flex items-center justify-center w-5 h-5 rounded text-[9px] font-bold ${rankClass}`}>{i + 1}</span></td>
                                                <td className={`px-3 py-2 text-xs font-medium ${textClass}`}>{u.user?.name || '-'}</td>
                                                <td className={`px-3 py-2 text-xs text-right font-bold ${textClass}`}>{Number(u.tokens).toLocaleString()}</td>
                                                <td className={`px-3 py-2 text-xs text-right ${subClass}`}>{Number(u.credits).toFixed(2)}</td>
                                                <td className={`px-3 py-2 text-xs text-right ${subClass}`}>{u.requests}</td>
                                            </tr>
                                        );
                                    })}
                                    {(stats?.top_users || []).length === 0 && <tr><td colSpan="5" className={`text-center py-8 text-xs ${subClass}`}>Belum ada data</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}

            {/* User Detail Modal */}
            {selectedUser && userStats && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => { setSelectedUser(null); setUserStats(null); }} />
                    <div className={`relative w-full max-w-2xl max-h-[85vh] overflow-y-auto rounded-2xl border p-5 scrollbar-thin ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className={`text-base font-bold ${textClass}`}>Detail Usage</h3>
                            <button onClick={() => { setSelectedUser(null); setUserStats(null); }} className={`p-1.5 rounded-lg ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}>
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div className="grid grid-cols-3 gap-2 mb-4">
                            {[
                                { label: 'Tokens', value: (userStats.total_tokens || 0).toLocaleString(), color: 'text-red-400' },
                                { label: 'Credits', value: (userStats.total_credits || 0).toFixed(2), color: 'text-blue-400' },
                                { label: 'Requests', value: userStats.total_requests || 0, color: 'text-emerald-400' },
                            ].map((s, i) => (
                                <div key={i} className={`p-3 rounded-lg text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                                    <div className={`text-sm font-bold ${s.color}`}>{s.value}</div>
                                    <div className={`text-[10px] ${subClass}`}>{s.label}</div>
                                </div>
                            ))}
                        </div>

                        {/* User Timeline Chart */}
                        <div className="mb-4" style={{ width: '100%', height: 200 }}>
                            <ResponsiveContainer>
                                <AreaChart data={userStats.timeline || []}>
                                    <defs>
                                        <linearGradient id="gradUser" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#ef4444" stopOpacity={0.3} />
                                            <stop offset="95%" stopColor="#ef4444" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                    <XAxis dataKey="label" tick={{ fontSize: 9, fill: axisColor }} />
                                    <YAxis tick={{ fontSize: 9, fill: axisColor }} />
                                    <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                    <Area type="monotone" dataKey="tokens" name="Tokens" stroke="#ef4444" fill="url(#gradUser)" strokeWidth={2} dot={false} activeDot={{ r: 4, strokeWidth: 2, fill: '#ef4444' }} animationDuration={1000} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>

                        {/* User Model Breakdown */}
                        <h4 className={`text-xs font-bold mb-2 ${subClass}`}>Per Model</h4>
                        <div className="space-y-1.5">
                            {(userStats.by_model || []).map((m, i) => {
                                const maxU = Math.max(...(userStats.by_model || []).map(x => Number(x.tokens)), 1);
                                return (
                                    <div key={i}>
                                        <div className="flex justify-between mb-0.5">
                                            <div className="flex items-center gap-1.5">
                                                <span className="w-2 h-2 rounded-full" style={{ background: COLORS[i % COLORS.length] }} />
                                                <span className={`text-[11px] ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{m.model}</span>
                                            </div>
                                            <span className={`text-[11px] font-bold ${textClass}`}>{Number(m.tokens).toLocaleString()}</span>
                                        </div>
                                        <div className={`h-1.5 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                            <div className="h-full rounded-full transition-all duration-500" style={{ width: `${Number(m.tokens)/maxU*100}%`, background: COLORS[i % COLORS.length] }} />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
