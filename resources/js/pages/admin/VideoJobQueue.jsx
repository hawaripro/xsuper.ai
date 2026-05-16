import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

const STATUS_STYLE = {
    completed: { label: 'Completed', cls: 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' },
    processing: { label: 'Processing', cls: 'bg-blue-500/10 text-blue-500 border-blue-500/20' },
    pending: { label: 'Pending', cls: 'bg-amber-500/10 text-amber-500 border-amber-500/20' },
    failed: { label: 'Failed', cls: 'bg-red-500/10 text-red-500 border-red-500/20' },
};

export default function VideoJobQueue() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [jobs, setJobs] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await fetch('/api/v/history', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    setJobs(data.jobs || data.history || []);
                }
            } catch {} finally { setLoading(false); }
        };
        load();
    }, []);

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Video Job Queue</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Monitor antrian dan status job video generation.</p>
            </div>

            <div className={`rounded-2xl border overflow-hidden ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                {loading ? (
                    <div className="flex items-center justify-center py-20">
                        <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    </div>
                ) : jobs.length === 0 ? (
                    <div className="py-16 text-center">
                        <p className={`text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Belum ada video job.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className={`border-b ${isDark ? 'border-white/5 text-gray-500' : 'border-gray-100 text-gray-400'}`}>
                                    <th className="text-left p-4 font-medium">Job ID</th>
                                    <th className="text-left p-4 font-medium">Prompt</th>
                                    <th className="text-left p-4 font-medium">Model</th>
                                    <th className="text-center p-4 font-medium">Status</th>
                                    <th className="text-right p-4 font-medium">Waktu</th>
                                </tr>
                            </thead>
                            <tbody className={`divide-y ${isDark ? 'divide-white/5' : 'divide-gray-50'}`}>
                                {jobs.map((job, i) => {
                                    const st = STATUS_STYLE[job.status] || STATUS_STYLE.pending;
                                    return (
                                        <tr key={job.id || i} className={`transition-colors ${isDark ? 'hover:bg-white/[0.02]' : 'hover:bg-gray-50'}`}>
                                            <td className={`p-4 font-mono ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>{job.job_id || job.id || '-'}</td>
                                            <td className={`p-4 max-w-[200px] truncate ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{job.prompt || '-'}</td>
                                            <td className={`p-4 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{job.model || '-'}</td>
                                            <td className="p-4 text-center">
                                                <span className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold border ${st.cls}`}>{st.label}</span>
                                            </td>
                                            <td className={`p-4 text-right ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{job.created_at ? new Date(job.created_at).toLocaleString('id-ID') : '-'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
