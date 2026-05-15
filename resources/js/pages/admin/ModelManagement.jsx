import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function ModelManagement() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [models, setModels] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await fetch('/api/c/am', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    setModels(data.models || []);
                }
            } catch {} finally { setLoading(false); }
        };
        load();
    }, []);

    const tiers = [...new Set(models.map(m => m.tier))].sort();

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Model Management</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Daftar model AI yang tersedia, alias, dan tier akses.</p>
            </div>

            {loading ? (
                <div className="flex items-center justify-center py-20">
                    <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                </div>
            ) : (
                <div className="space-y-6">
                    {/* Summary */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className={`rounded-2xl border p-4 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                            <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Total Models</div>
                            <div className={`text-2xl font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{models.length}</div>
                        </div>
                        {tiers.map(tier => (
                            <div key={tier} className={`rounded-2xl border p-4 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                                <div className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{tier}</div>
                                <div className={`text-2xl font-bold mt-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>{models.filter(m => m.tier === tier).length}</div>
                            </div>
                        ))}
                    </div>

                    {/* Models by tier */}
                    {tiers.map(tier => (
                        <div key={tier} className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                            <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                                {tier} <span className={`text-xs font-normal ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>({models.filter(m => m.tier === tier).length} models)</span>
                            </h3>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className={isDark ? 'text-gray-500' : 'text-gray-400'}>
                                            <th className="text-left pb-3 font-medium">Model ID</th>
                                            <th className="text-left pb-3 font-medium">Display Name</th>
                                            <th className="text-left pb-3 font-medium">Provider</th>
                                            <th className="text-left pb-3 font-medium">Category</th>
                                        </tr>
                                    </thead>
                                    <tbody className={`divide-y ${isDark ? 'divide-white/5' : 'divide-gray-100'}`}>
                                        {models.filter(m => m.tier === tier).map(model => (
                                            <tr key={model.id}>
                                                <td className={`py-2.5 font-mono ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{model.id}</td>
                                                <td className={`py-2.5 ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>{model.name || model.id}</td>
                                                <td className={`py-2.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{model.provider || '-'}</td>
                                                <td className="py-2.5">
                                                    <span className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-medium ${isDark ? 'bg-white/5 text-gray-400' : 'bg-gray-100 text-gray-500'}`}>
                                                        {model.category || 'chat'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
