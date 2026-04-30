import React, { useState, useEffect, useCallback } from 'react';
import { useTheme } from '../contexts/ThemeContext';

function StatCard({ label, value, icon, color, isDark }) {
    const colors = {
        red: { bg: isDark ? 'from-red-500/15 to-red-500/5' : 'from-red-50 to-white', border: isDark ? 'border-red-500/10' : 'border-red-200', text: 'text-red-400', iconBg: isDark ? 'bg-red-500/15' : 'bg-red-100' },
        blue: { bg: isDark ? 'from-blue-500/15 to-blue-500/5' : 'from-blue-50 to-white', border: isDark ? 'border-blue-500/10' : 'border-blue-200', text: 'text-blue-400', iconBg: isDark ? 'bg-blue-500/15' : 'bg-blue-100' },
        emerald: { bg: isDark ? 'from-emerald-500/15 to-emerald-500/5' : 'from-emerald-50 to-white', border: isDark ? 'border-emerald-500/10' : 'border-emerald-200', text: 'text-emerald-400', iconBg: isDark ? 'bg-emerald-500/15' : 'bg-emerald-100' },
        amber: { bg: isDark ? 'from-amber-500/15 to-amber-500/5' : 'from-amber-50 to-white', border: isDark ? 'border-amber-500/10' : 'border-amber-200', text: 'text-amber-400', iconBg: isDark ? 'bg-amber-500/15' : 'bg-amber-100' },
        violet: { bg: isDark ? 'from-violet-500/15 to-violet-500/5' : 'from-violet-50 to-white', border: isDark ? 'border-violet-500/10' : 'border-violet-200', text: 'text-violet-400', iconBg: isDark ? 'bg-violet-500/15' : 'bg-violet-100' },
    };
    const c = colors[color] || colors.red;

    return (
        <div className={`relative p-4 rounded-xl bg-gradient-to-br ${c.bg} border ${c.border} overflow-hidden group hover:scale-[1.02] transition-transform duration-300`}>
            <div className="absolute top-0 right-0 w-20 h-20 bg-current opacity-[0.03] rounded-full blur-2xl -translate-y-1/2 translate-x-1/2" />
            <div className="flex items-start justify-between mb-3">
                <div className={`w-9 h-9 rounded-lg ${c.iconBg} ${c.text} flex items-center justify-center`}>{icon}</div>
            </div>
            <div className={`text-xl font-extrabold ${isDark ? 'text-white' : 'text-gray-900'} tracking-tight`}>{value}</div>
            <div className={`text-[11px] mt-1 font-medium ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{label}</div>
        </div>
    );
}

export default function TokenUsage() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [selectedUser, setSelectedUser] = useState(null);
    const [userStats, setUserStats] = useState(null);

    const cardClass = isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm';
    const textClass = isDark ? 'text-white' : 'text-gray-900';
    const subClass = isDark ? 'text-gray-500' : 'text-gray-400';

    const loadStats = useCallback(async () => {
        try {
            const res = await fetch('/api/usage', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) setStats(await res.json());
        } catch {} finally { setLoading(false); }
    }, []);

    useEffect(() => { loadStats(); }, [loadStats]);

    const loadUserStats = async (userId) => {
        setSelectedUser(userId);
        try {
            const res = await fetch(`/api/usage?user_id=${userId}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) setUserStats(await res.json());
        } catch {}
    };

    if (loading) return <div className="flex items-center justify-center py-20"><div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" /></div>;

    const maxDaily = Math.max(...(stats?.daily || []).map(x => Number(x.tokens)), 1);
    const maxModel = Math.max(...(stats?.by_model || []).map(x => Number(x.tokens)), 1);
    const barColors = ['bg-red-500', 'bg-blue-500', 'bg-emerald-500', 'bg-amber-500', 'bg-violet-500', 'bg-pink-500', 'bg-cyan-500', 'bg-orange-500'];

    return (
        <div className="p-3 lg:p-5 space-y-4 max-w-7xl mx-auto" style={{ fontSize: '90%' }}>
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className={`text-xl font-extrabold tracking-tight ${textClass}`}>Token Usage</h1>
                    <p className={`text-xs mt-1 ${subClass}`}>Monitor penggunaan token AI seluruh platform</p>
                </div>
                <button onClick={loadStats} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}>
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                </button>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <StatCard isDark={isDark} label="Total Tokens" value={(stats?.total_tokens || 0).toLocaleString()} color="red"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>} />
                <StatCard isDark={isDark} label="Total Credits" value={(stats?.total_credits || 0).toFixed(2)} color="blue"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>} />
                <StatCard isDark={isDark} label="Total Requests" value={(stats?.total_requests || 0).toLocaleString()} color="emerald"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>} />
                <StatCard isDark={isDark} label="Active Users" value={stats?.active_users || 0} color="amber"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>} />
                <StatCard isDark={isDark} label="Avg/Request" value={stats?.total_requests ? Math.round((stats?.total_tokens || 0) / stats.total_requests) : 0} color="violet"
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>} />
            </div>

            <div className="grid lg:grid-cols-2 gap-4">
                {/* Usage by Model — colored bars */}
                <div className={`p-4 rounded-xl border ${cardClass}`}>
                    <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                        <svg className="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                        Usage per Model
                    </h3>
                    <div className="space-y-2.5 max-h-72 overflow-y-auto scrollbar-thin pr-1">
                        {(stats?.by_model || []).map((m, i) => {
                            const pct = (Number(m.tokens) / maxModel * 100);
                            const barColor = barColors[i % barColors.length];
                            return (
                                <div key={i}>
                                    <div className="flex items-center justify-between mb-1">
                                        <span className={`text-xs font-medium truncate ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{m.model}</span>
                                        <span className={`text-[10px] font-bold ml-2 ${textClass}`}>{Number(m.tokens).toLocaleString()}</span>
                                    </div>
                                    <div className={`h-2 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                        <div className={`h-full rounded-full ${barColor} transition-all duration-700`} style={{ width: `${pct}%` }} />
                                    </div>
                                </div>
                            );
                        })}
                        {(stats?.by_model || []).length === 0 && <p className={`text-xs text-center py-6 ${subClass}`}>Belum ada data</p>}
                    </div>
                </div>

                {/* Daily Usage — gradient bars */}
                <div className={`p-4 rounded-xl border ${cardClass}`}>
                    <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                        <svg className="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Daily Usage (30 hari)
                    </h3>
                    <div className="space-y-1.5 max-h-72 overflow-y-auto scrollbar-thin pr-1">
                        {(stats?.daily || []).map((d, i) => {
                            const pct = (Number(d.tokens) / maxDaily * 100);
                            return (
                                <div key={i} className="flex items-center gap-2">
                                    <span className={`text-[10px] w-12 flex-shrink-0 font-mono ${subClass}`}>{d.date?.slice(5)}</span>
                                    <div className={`flex-1 h-3 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                        <div className="h-full bg-gradient-to-r from-red-500 via-amber-500 to-emerald-500 rounded-full transition-all duration-700" style={{ width: `${pct}%` }} />
                                    </div>
                                    <span className={`text-[10px] w-16 text-right font-mono font-bold ${textClass}`}>{Number(d.tokens).toLocaleString()}</span>
                                </div>
                            );
                        })}
                        {(stats?.daily || []).length === 0 && <p className={`text-xs text-center py-6 ${subClass}`}>Belum ada data</p>}
                    </div>
                </div>
            </div>

            {/* Top Users — with colored rank badges */}
            <div className={`p-4 rounded-xl border ${cardClass}`}>
                <h3 className={`text-sm font-bold mb-3 flex items-center gap-2 ${textClass}`}>
                    <svg className="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Top Users
                </h3>
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead>
                            <tr className={`border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                                <th className={`text-left px-3 py-2 text-[10px] font-bold uppercase tracking-wider ${subClass}`}>#</th>
                                <th className={`text-left px-3 py-2 text-[10px] font-bold uppercase tracking-wider ${subClass}`}>User</th>
                                <th className={`text-right px-3 py-2 text-[10px] font-bold uppercase tracking-wider ${subClass}`}>Tokens</th>
                                <th className={`text-right px-3 py-2 text-[10px] font-bold uppercase tracking-wider ${subClass}`}>Credits</th>
                                <th className={`text-right px-3 py-2 text-[10px] font-bold uppercase tracking-wider ${subClass}`}>Requests</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(stats?.top_users || []).map((u, i) => {
                                const rankColors = ['bg-amber-500 text-white', 'bg-gray-400 text-white', 'bg-amber-700 text-white'];
                                const rankClass = i < 3 ? rankColors[i] : (isDark ? 'bg-white/[0.05] text-gray-400' : 'bg-gray-100 text-gray-500');
                                return (
                                    <tr key={i} className={`border-b cursor-pointer transition-colors ${isDark ? 'border-white/[0.03] hover:bg-white/[0.03]' : 'border-gray-100 hover:bg-gray-50'}`}
                                        onClick={() => loadUserStats(u.user_id)}>
                                        <td className="px-3 py-2.5">
                                            <span className={`inline-flex items-center justify-center w-6 h-6 rounded-lg text-[10px] font-bold ${rankClass}`}>{i + 1}</span>
                                        </td>
                                        <td className={`px-3 py-2.5 text-xs font-medium ${textClass}`}>{u.user?.name || 'User #' + u.user_id}</td>
                                        <td className={`px-3 py-2.5 text-xs text-right font-bold ${textClass}`}>{Number(u.tokens).toLocaleString()}</td>
                                        <td className={`px-3 py-2.5 text-xs text-right ${subClass}`}>{Number(u.credits).toFixed(2)}</td>
                                        <td className={`px-3 py-2.5 text-xs text-right ${subClass}`}>{u.requests}</td>
                                    </tr>
                                );
                            })}
                            {(stats?.top_users || []).length === 0 && (
                                <tr><td colSpan="5" className={`text-center py-10 text-xs ${subClass}`}>Belum ada data penggunaan</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* User Detail Modal */}
            {selectedUser && userStats && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => { setSelectedUser(null); setUserStats(null); }} />
                    <div className={`relative w-full max-w-lg max-h-[80vh] overflow-y-auto rounded-2xl border p-5 scrollbar-thin ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
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

                        <h4 className={`text-xs font-bold mb-2 ${subClass}`}>Per Model</h4>
                        <div className="space-y-1.5 mb-4">
                            {(userStats.by_model || []).map((m, i) => {
                                const maxU = Math.max(...(userStats.by_model || []).map(x => Number(x.tokens)), 1);
                                const pct = (Number(m.tokens) / maxU * 100);
                                return (
                                    <div key={i}>
                                        <div className="flex justify-between mb-0.5">
                                            <span className={`text-[11px] ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{m.model}</span>
                                            <span className={`text-[11px] font-bold ${textClass}`}>{Number(m.tokens).toLocaleString()}</span>
                                        </div>
                                        <div className={`h-1.5 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                            <div className={`h-full rounded-full ${barColors[i % barColors.length]} transition-all duration-500`} style={{ width: `${pct}%` }} />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <h4 className={`text-xs font-bold mb-2 ${subClass}`}>Daily (30 hari)</h4>
                        <div className="space-y-1 max-h-40 overflow-y-auto scrollbar-thin">
                            {(userStats.daily || []).map((d, i) => (
                                <div key={i} className="flex justify-between">
                                    <span className={`text-[10px] font-mono ${subClass}`}>{d.date}</span>
                                    <span className={`text-[10px] font-bold font-mono ${textClass}`}>{Number(d.tokens).toLocaleString()}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
