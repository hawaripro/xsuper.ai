import React, { useState, useEffect } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function BroadcastCRM() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [message, setMessage] = useState('');
    const [target, setTarget] = useState('all');
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await fetch('/api/a/u', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    setUsers(data.users || []);
                }
            } catch {} finally { setLoading(false); }
        };
        load();
    }, []);

    const memberCount = users.filter(u => u.role === 'member').length;
    const activeCount = users.filter(u => u.role === 'member' && !u.is_expired).length;
    const expiredCount = users.filter(u => u.role === 'member' && u.is_expired).length;

    const handleSend = async () => {
        if (!message.trim()) return;
        setSending(true);
        // Placeholder — will integrate with actual notification system
        await new Promise(r => setTimeout(r, 1500));
        setSending(false);
        setSent(true);
        setMessage('');
        setTimeout(() => setSent(false), 3000);
    };

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Broadcast & CRM</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Kirim pesan broadcast ke pengguna.</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Compose */}
                <div className={`lg:col-span-2 rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Kirim Broadcast</h3>

                    <div className="space-y-4">
                        <div>
                            <label className={`text-xs font-medium mb-1.5 block ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Target</label>
                            <div className="flex flex-wrap gap-2">
                                {[
                                    { id: 'all', label: `Semua Member (${memberCount})` },
                                    { id: 'active', label: `Aktif (${activeCount})` },
                                    { id: 'expired', label: `Expired (${expiredCount})` },
                                ].map(t => (
                                    <button key={t.id} onClick={() => setTarget(t.id)}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-all ${
                                            target === t.id
                                                ? (isDark ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-red-50 text-red-600 border-red-200')
                                                : (isDark ? 'bg-white/5 text-gray-400 border-white/10' : 'bg-gray-100 text-gray-600 border-gray-200')
                                        }`}
                                    >{t.label}</button>
                                ))}
                            </div>
                        </div>

                        <div>
                            <label className={`text-xs font-medium mb-1.5 block ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Pesan</label>
                            <textarea
                                value={message} onChange={e => setMessage(e.target.value)}
                                rows={5} placeholder="Tulis pesan broadcast..."
                                className={`w-full px-4 py-3 rounded-xl text-sm border resize-none ${isDark ? 'bg-white/5 border-white/10 text-white placeholder-gray-600' : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400'}`}
                            />
                        </div>

                        <div className="flex items-center gap-3">
                            <button onClick={handleSend} disabled={!message.trim() || sending}
                                className={`px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-all ${
                                    message.trim() && !sending
                                        ? 'bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 shadow-lg shadow-red-500/25'
                                        : 'bg-gray-400 cursor-not-allowed opacity-50'
                                }`}
                            >
                                {sending ? 'Mengirim...' : 'Kirim Broadcast'}
                            </button>
                            {sent && <span className="text-xs text-emerald-500 font-medium">✓ Broadcast terkirim!</span>}
                        </div>
                    </div>
                </div>

                {/* Stats sidebar */}
                <div className="space-y-4">
                    <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                        <h3 className={`text-sm font-semibold mb-3 ${isDark ? 'text-white' : 'text-slate-900'}`}>Statistik User</h3>
                        <div className="space-y-3">
                            <div className="flex justify-between">
                                <span className={`text-xs ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Total Member</span>
                                <span className={`text-xs font-bold ${isDark ? 'text-white' : 'text-slate-900'}`}>{memberCount}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className={`text-xs ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Aktif</span>
                                <span className="text-xs font-bold text-emerald-500">{activeCount}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className={`text-xs ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Expired</span>
                                <span className="text-xs font-bold text-red-500">{expiredCount}</span>
                            </div>
                        </div>
                    </div>
                    <div className={`rounded-xl border p-4 ${isDark ? 'bg-amber-500/5 border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                        <p className={`text-xs ${isDark ? 'text-amber-300' : 'text-amber-700'}`}>
                            ⚠️ Broadcast saat ini dalam mode simulasi. Integrasi WhatsApp/Email akan ditambahkan.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
