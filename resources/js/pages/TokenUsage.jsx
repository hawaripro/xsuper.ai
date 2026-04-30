import React, { useState, useEffect, useCallback } from 'react';
import { useTheme } from '../contexts/ThemeContext';

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

    return (
        <div className="p-3 lg:p-5 space-y-4 max-w-7xl mx-auto" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-xl font-extrabold tracking-tight ${textClass}`}>Token Usage</h1>
                <p className={`text-xs mt-1 ${subClass}`}>Monitor penggunaan token AI seluruh platform</p>
            </div>

            {/* Overview Stats */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                {[
                    { label: 'Total Tokens', value: (stats?.total_tokens || 0).toLocaleString(), color: 'red' },
                    { label: 'Total Credits', value: (stats?.total_credits || 0).toFixed(2), color: 'blue' },
                    { label: 'Total Requests', value: (stats?.total_requests || 0).toLocaleString(), color: 'emerald' },
                    { label: 'Active Users', value: stats?.active_users || 0, color: 'amber' },
                ].map((s, i) => (
                    <div key={i} className={`p-4 rounded-xl border ${cardClass}`}>
                        <div className={`text-xl font-extrabold ${textClass}`}>{s.value}</div>
                        <div className={`text-[11px] mt-1 ${subClass}`}>{s.label}</div>
                    </div>
                ))}
            </div>

            <div className="grid lg:grid-cols-2 gap-4">
                {/* Usage by Model */}
                <div className={`p-4 rounded-xl border ${cardClass}`}>
                    <h3 className={`text-sm font-bold mb-3 ${textClass}`}>Usage per Model</h3>
                    <div className="space-y-2 max-h-64 overflow-y-auto scrollbar-thin">
                        {(stats?.by_model || []).map((m, i) => (
                            <div key={i} className="flex items-center justify-between">
                                <span className={`text-xs font-medium truncate flex-1 ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{m.model}</span>
                                <div className="flex items-center gap-3 ml-2">
                                    <span className={`text-xs ${subClass}`}>{m.requests} req</span>
                                    <span className={`text-xs font-bold ${textClass}`}>{Number(m.tokens).toLocaleString()}</span>
                                </div>
                            </div>
                        ))}
                        {(stats?.by_model || []).length === 0 && <p className={`text-xs text-center py-4 ${subClass}`}>Belum ada data</p>}
                    </div>
                </div>

                {/* Daily Usage (last 30 days) */}
                <div className={`p-4 rounded-xl border ${cardClass}`}>
                    <h3 className={`text-sm font-bold mb-3 ${textClass}`}>Usage 30 Hari Terakhir</h3>
                    <div className="space-y-1 max-h-64 overflow-y-auto scrollbar-thin">
                        {(stats?.daily || []).map((d, i) => {
                            const maxTokens = Math.max(...(stats?.daily || []).map(x => Number(x.tokens)));
                            const pct = maxTokens > 0 ? (Number(d.tokens) / maxTokens * 100) : 0;
                            return (
                                <div key={i} className="flex items-center gap-2">
                                    <span className={`text-[10px] w-16 flex-shrink-0 ${subClass}`}>{d.date?.slice(5)}</span>
                                    <div className={`flex-1 h-4 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                        <div className="h-full bg-gradient-to-r from-red-500 to-red-400 rounded-full" style={{ width: `${pct}%` }} />
                                    </div>
                                    <span className={`text-[10px] w-14 text-right ${subClass}`}>{Number(d.tokens).toLocaleString()}</span>
                                </div>
                            );
                        })}
                        {(stats?.daily || []).length === 0 && <p className={`text-xs text-center py-4 ${subClass}`}>Belum ada data</p>}
                    </div>
                </div>
            </div>

            {/* Top Users */}
            <div className={`p-4 rounded-xl border ${cardClass}`}>
                <h3 className={`text-sm font-bold mb-3 ${textClass}`}>Top Users</h3>
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
                            {(stats?.top_users || []).map((u, i) => (
                                <tr key={i} className={`border-b cursor-pointer transition-colors ${isDark ? 'border-white/[0.03] hover:bg-white/[0.02]' : 'border-gray-100 hover:bg-gray-50'}`}
                                    onClick={() => loadUserStats(u.user_id)}>
                                    <td className={`px-3 py-2 text-xs ${subClass}`}>{i + 1}</td>
                                    <td className={`px-3 py-2 text-xs font-medium ${textClass}`}>{u.user?.name || 'User #' + u.user_id}</td>
                                    <td className={`px-3 py-2 text-xs text-right font-bold ${textClass}`}>{Number(u.tokens).toLocaleString()}</td>
                                    <td className={`px-3 py-2 text-xs text-right ${subClass}`}>{Number(u.credits).toFixed(2)}</td>
                                    <td className={`px-3 py-2 text-xs text-right ${subClass}`}>{u.requests}</td>
                                </tr>
                            ))}
                            {(stats?.top_users || []).length === 0 && (
                                <tr><td colSpan="5" className={`text-center py-8 text-xs ${subClass}`}>Belum ada data</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* User Detail Modal */}
            {selectedUser && userStats && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => { setSelectedUser(null); setUserStats(null); }} />
                    <div className={`relative w-full max-w-lg max-h-[80vh] overflow-y-auto rounded-2xl border p-5 ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className={`text-base font-bold ${textClass}`}>Detail Usage</h3>
                            <button onClick={() => { setSelectedUser(null); setUserStats(null); }} className={`p-1.5 rounded-lg ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}>
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div className="grid grid-cols-3 gap-2 mb-4">
                            <div className={`p-3 rounded-lg text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                                <div className={`text-sm font-bold ${textClass}`}>{(userStats.total_tokens || 0).toLocaleString()}</div>
                                <div className={`text-[10px] ${subClass}`}>Tokens</div>
                            </div>
                            <div className={`p-3 rounded-lg text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                                <div className={`text-sm font-bold ${textClass}`}>{(userStats.total_credits || 0).toFixed(2)}</div>
                                <div className={`text-[10px] ${subClass}`}>Credits</div>
                            </div>
                            <div className={`p-3 rounded-lg text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                                <div className={`text-sm font-bold ${textClass}`}>{userStats.total_requests || 0}</div>
                                <div className={`text-[10px] ${subClass}`}>Requests</div>
                            </div>
                        </div>

                        <h4 className={`text-xs font-bold mb-2 ${subClass}`}>Per Model</h4>
                        <div className="space-y-1 mb-4">
                            {(userStats.by_model || []).map((m, i) => (
                                <div key={i} className="flex justify-between">
                                    <span className={`text-xs ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{m.model}</span>
                                    <span className={`text-xs font-bold ${textClass}`}>{Number(m.tokens).toLocaleString()}</span>
                                </div>
                            ))}
                        </div>

                        <h4 className={`text-xs font-bold mb-2 ${subClass}`}>Daily (30 hari)</h4>
                        <div className="space-y-1 max-h-40 overflow-y-auto scrollbar-thin">
                            {(userStats.daily || []).map((d, i) => (
                                <div key={i} className="flex justify-between">
                                    <span className={`text-[10px] ${subClass}`}>{d.date}</span>
                                    <span className={`text-[10px] font-bold ${textClass}`}>{Number(d.tokens).toLocaleString()} tokens</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
