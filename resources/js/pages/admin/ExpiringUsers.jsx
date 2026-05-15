import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function ExpiringUsers() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [days, setDays] = useState(7);

    const load = async () => {
        setLoading(true);
        try {
            const res = await fetch(`/api/a/stats/expiring?days=${days}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) setData(await res.json());
        } catch {} finally { setLoading(false); }
    };

    useEffect(() => { load(); }, [days]);

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Expiring Users</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Pengguna yang masa aktifnya akan segera berakhir.</p>
            </div>

            {/* Filter days */}
            <div className="flex flex-wrap gap-2">
                {[3, 7, 14, 30].map(d => (
                    <button key={d} onClick={() => setDays(d)}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all border ${
                            days === d
                                ? (isDark ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-red-50 text-red-600 border-red-200')
                                : (isDark ? 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10' : 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200')
                        }`}
                    >
                        {d} hari
                    </button>
                ))}
            </div>

            {loading ? (
                <div className="flex items-center justify-center py-20">
                    <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                </div>
            ) : (
                <div className="space-y-6">
                    {/* Expiring soon */}
                    <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-slate-900'}`}>
                                Akan Expired ({data?.expiring_count || 0})
                            </h3>
                            <span className={`px-2 py-0.5 rounded-md text-[10px] font-bold border ${isDark ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-amber-50 text-amber-600 border-amber-200'}`}>
                                ≤ {days} hari
                            </span>
                        </div>
                        {data?.expiring?.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className={isDark ? 'text-gray-500' : 'text-gray-400'}>
                                            <th className="text-left pb-3 font-medium">User</th>
                                            <th className="text-right pb-3 font-medium">Sisa Hari</th>
                                            <th className="text-right pb-3 font-medium">Expired</th>
                                        </tr>
                                    </thead>
                                    <tbody className={`divide-y ${isDark ? 'divide-white/5' : 'divide-gray-100'}`}>
                                        {data.expiring.map(user => (
                                            <tr key={user.id}>
                                                <td className="py-2.5">
                                                    <div className={`font-medium ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>{user.name}</div>
                                                    <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>{user.email}</div>
                                                </td>
                                                <td className="py-2.5 text-right">
                                                    <span className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold ${
                                                        user.days_remaining <= 1
                                                            ? 'bg-red-500/10 text-red-500'
                                                            : user.days_remaining <= 3
                                                                ? 'bg-amber-500/10 text-amber-500'
                                                                : 'bg-blue-500/10 text-blue-500'
                                                    }`}>
                                                        {user.days_remaining} hari
                                                    </span>
                                                </td>
                                                <td className={`py-2.5 text-right ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                                    {new Date(user.expires_at).toLocaleDateString('id-ID')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className={`text-xs ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Tidak ada user yang akan expired dalam {days} hari.</p>
                        )}
                    </div>

                    {/* Already expired */}
                    <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-slate-900'}`}>
                                Sudah Expired ({data?.expired_count || 0})
                            </h3>
                            <span className={`px-2 py-0.5 rounded-md text-[10px] font-bold border ${isDark ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-red-50 text-red-600 border-red-200'}`}>
                                Inactive
                            </span>
                        </div>
                        {data?.expired?.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className={isDark ? 'text-gray-500' : 'text-gray-400'}>
                                            <th className="text-left pb-3 font-medium">User</th>
                                            <th className="text-right pb-3 font-medium">Expired Sejak</th>
                                            <th className="text-right pb-3 font-medium">Hari Lalu</th>
                                        </tr>
                                    </thead>
                                    <tbody className={`divide-y ${isDark ? 'divide-white/5' : 'divide-gray-100'}`}>
                                        {data.expired.map(user => (
                                            <tr key={user.id}>
                                                <td className="py-2.5">
                                                    <div className={`font-medium ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>{user.name}</div>
                                                    <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>{user.email}</div>
                                                </td>
                                                <td className={`py-2.5 text-right ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                                    {new Date(user.expires_at).toLocaleDateString('id-ID')}
                                                </td>
                                                <td className="py-2.5 text-right">
                                                    <span className="text-red-500 font-medium">{user.days_expired}d ago</span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className={`text-xs ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Tidak ada user yang expired.</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
