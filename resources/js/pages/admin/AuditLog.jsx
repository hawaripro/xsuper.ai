import React, { useState } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

// Simulated audit log entries (will be replaced with real API)
const MOCK_LOGS = [
    { id: 1, action: 'user.approve_order', actor: 'Admin', target: 'Order #12', detail: 'Approved 30-day package', created_at: new Date(Date.now() - 3600000).toISOString() },
    { id: 2, action: 'user.update_permissions', actor: 'Admin', target: 'john@email.com', detail: 'Enabled model_authentic', created_at: new Date(Date.now() - 7200000).toISOString() },
    { id: 3, action: 'user.create', actor: 'System', target: 'jane@email.com', detail: 'Google OAuth registration', created_at: new Date(Date.now() - 86400000).toISOString() },
    { id: 4, action: 'user.reject_order', actor: 'Admin', target: 'Order #11', detail: 'Rejected - invalid payment', created_at: new Date(Date.now() - 172800000).toISOString() },
    { id: 5, action: 'system.deploy', actor: 'System', target: 'Production', detail: 'Build deployed successfully', created_at: new Date(Date.now() - 259200000).toISOString() },
];

const ACTION_STYLE = {
    'user.approve_order': { color: 'text-emerald-500', bg: 'bg-emerald-500/10' },
    'user.reject_order': { color: 'text-red-500', bg: 'bg-red-500/10' },
    'user.update_permissions': { color: 'text-blue-500', bg: 'bg-blue-500/10' },
    'user.create': { color: 'text-violet-500', bg: 'bg-violet-500/10' },
    'system.deploy': { color: 'text-amber-500', bg: 'bg-amber-500/10' },
};

export default function AuditLog() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [logs] = useState(MOCK_LOGS);

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Audit Log</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Riwayat aktivitas dan perubahan sistem.</p>
            </div>

            <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                <div className="space-y-0">
                    {logs.map((log, i) => {
                        const style = ACTION_STYLE[log.action] || { color: 'text-gray-500', bg: 'bg-gray-500/10' };
                        return (
                            <div key={log.id} className={`flex items-start gap-4 py-4 ${i < logs.length - 1 ? (isDark ? 'border-b border-white/5' : 'border-b border-gray-100') : ''}`}>
                                <div className={`w-8 h-8 rounded-lg ${style.bg} flex items-center justify-center flex-shrink-0 mt-0.5`}>
                                    <span className={`text-xs font-bold ${style.color}`}>{log.action.split('.')[0][0].toUpperCase()}</span>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className={`text-xs font-semibold ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>{log.actor}</span>
                                        <span className={`text-[10px] px-1.5 py-0.5 rounded font-mono ${style.bg} ${style.color}`}>{log.action}</span>
                                        <span className={`text-xs ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>→ {log.target}</span>
                                    </div>
                                    <p className={`text-xs mt-0.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{log.detail}</p>
                                </div>
                                <span className={`text-[10px] flex-shrink-0 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                                    {new Date(log.created_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className={`rounded-xl border p-4 ${isDark ? 'bg-amber-500/5 border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                <p className={`text-xs ${isDark ? 'text-amber-300' : 'text-amber-700'}`}>
                    ⚠️ Audit log saat ini menampilkan data contoh. Integrasi dengan event tracking akan ditambahkan.
                </p>
            </div>
        </div>
    );
}
