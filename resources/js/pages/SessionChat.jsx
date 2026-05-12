import React, { useState, useEffect, useCallback } from 'react';
import { useTheme } from '../contexts/ThemeContext';

export default function SessionChat() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const loadUsers = useCallback(async (silent = false) => {
        if (!silent) setLoading(true);
        if (!silent) setError('');
        try {
            const res = await fetch('/api/a/chat-pro/users', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) {
                const text = await res.text();
                try {
                    const data = JSON.parse(text);
                    const list = Array.isArray(data.users) ? data.users : Array.isArray(data) ? data : [];
                    setUsers(list);
                } catch {
                    setUsers([]);
                    if (!silent) setError('Response bukan JSON valid.');
                }
            } else {
                setUsers([]);
                if (!silent) setError(`Gagal memuat data (${res.status}). Pastikan OPENWEBUI_API_KEY sudah diisi.`);
            }
        } catch (e) {
            setUsers([]);
            if (!silent) setError('Tidak dapat terhubung ke server.');
        } finally {
            if (!silent) setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadUsers();
        const interval = setInterval(() => loadUsers(true), 5000);
        return () => clearInterval(interval);
    }, [loadUsers]);

    const getCsrfToken = () =>
        decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');

    const forceLogout = async (userId, name) => {
        if (!confirm(`Force logout "${name}" dari Chat AI Pro?`)) return;
        try {
            await fetch(`/api/a/chat-pro/logout/${userId}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            loadUsers(true);
        } catch {}
    };

    const deleteUser = async (userId, name) => {
        if (!confirm(`Hapus "${name}" dari Chat AI Pro? Aksi ini tidak bisa dibatalkan.`)) return;
        try {
            await fetch(`/api/a/chat-pro/user/${userId}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            loadUsers(true);
        } catch {}
    };

    const nonAdminUsers = users.filter(u => u.role !== 'admin');

    const card = isDark
        ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl'
        : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]';
    const head = isDark ? 'text-white' : 'text-slate-900';
    const muted = isDark ? 'text-gray-400' : 'text-gray-500';

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-5xl mx-auto">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 animate-fade-in-up">
                <div>
                    <h1 className={`text-2xl lg:text-3xl font-extrabold tracking-tight ${head}`}>
                        Session <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">Chat</span>
                    </h1>
                    <p className={`text-sm mt-1 ${muted}`}>Kelola sesi Open WebUI (Chat AI Pro) per user.</p>
                </div>
                <button
                    onClick={() => loadUsers()}
                    className="ui-btn-ghost"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    Refresh
                </button>
            </div>

            {/* Error */}
            {error && (
                <div className={`p-4 rounded-xl border text-sm font-medium animate-fade-in-down ${
                    isDark ? 'bg-red-500/10 border-red-500/25 text-red-300' : 'bg-red-50 border-red-200 text-red-700'
                }`}>
                    <div className="flex items-start gap-2.5">
                        <svg className="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span>{error}</span>
                    </div>
                </div>
            )}

            {/* Stats */}
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 animate-fade-in-up">
                <div className={`p-4 rounded-2xl border ${card}`}>
                    <div className={`text-2xl font-extrabold tabular-nums ${head}`}>{users.length}</div>
                    <div className={`text-xs font-semibold uppercase tracking-wider mt-1 ${muted}`}>Total Users</div>
                </div>
                <div className={`p-4 rounded-2xl border ${card}`}>
                    <div className={`text-2xl font-extrabold tabular-nums ${head}`}>{nonAdminUsers.length}</div>
                    <div className={`text-xs font-semibold uppercase tracking-wider mt-1 ${muted}`}>Members</div>
                </div>
                <div className={`p-4 rounded-2xl border ${card}`}>
                    <div className={`text-2xl font-extrabold tabular-nums ${head}`}>{users.filter(u => u.role === 'admin').length}</div>
                    <div className={`text-xs font-semibold uppercase tracking-wider mt-1 ${muted}`}>Admins</div>
                </div>
            </div>

            {/* User list */}
            <div className={`rounded-2xl border overflow-hidden animate-fade-in-up ${card}`}>
                <div className={`px-5 py-4 border-b flex items-center justify-between ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                    <h2 className={`text-sm font-bold flex items-center gap-2 ${head}`}>
                        <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-violet-500 to-purple-500 text-white flex items-center justify-center shadow-md">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </span>
                        Chat AI Pro Users
                    </h2>
                    <span className={`text-xs font-semibold px-2 py-0.5 rounded-md ${
                        isDark ? 'bg-emerald-500/15 text-emerald-300' : 'bg-emerald-50 text-emerald-700'
                    }`}>
                        <span className="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse" />
                        Live
                    </span>
                </div>

                {loading ? (
                    <div className="p-4 space-y-3">
                        {[0,1,2,3].map(i => (
                            <div key={i} className="flex items-center gap-3">
                                <div className="ui-skeleton w-10 h-10 rounded-xl" />
                                <div className="flex-1 space-y-2">
                                    <div className="ui-skeleton h-3 w-1/3" />
                                    <div className="ui-skeleton h-2 w-1/4" />
                                </div>
                            </div>
                        ))}
                    </div>
                ) : nonAdminUsers.length === 0 ? (
                    <div className={`px-5 py-12 text-center ${muted}`}>
                        <svg className="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <p className="text-sm font-medium">Belum ada user Chat AI Pro</p>
                        <p className="text-xs mt-1 opacity-70">User akan muncul setelah login ke chat.ultrai.id</p>
                    </div>
                ) : (
                    <div className={`divide-y ${isDark ? 'divide-white/[0.04]' : 'divide-gray-100'}`}>
                        {nonAdminUsers.map((u, i) => (
                            <div
                                key={u.id}
                                className={`group px-5 py-3.5 flex items-center justify-between transition-colors ${isDark ? 'hover:bg-white/[0.03]' : 'hover:bg-red-50/40'}`}
                                style={{ animation: 'fade-in-up 0.4s ease-out both', animationDelay: `${i * 40}ms` }}
                            >
                                <div className="flex items-center gap-3 min-w-0">
                                    <div className="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold bg-gradient-to-br from-violet-500 to-purple-500 text-white shadow-[0_4px_12px_-2px_rgba(139,92,246,0.35)]">
                                        {u.name?.[0]?.toUpperCase() || '?'}
                                    </div>
                                    <div className="min-w-0">
                                        <div className={`text-sm font-semibold truncate ${head}`}>{u.name || '—'}</div>
                                        <div className={`text-xs truncate ${muted}`}>{u.email || '—'}</div>
                                    </div>
                                </div>
                                <div className="flex gap-2 flex-shrink-0">
                                    <button
                                        onClick={() => forceLogout(u.id, u.name || u.email)}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 border ${
                                            isDark
                                                ? 'bg-amber-500/10 text-amber-300 hover:bg-amber-500/20 border-amber-500/20'
                                                : 'bg-amber-50 text-amber-700 hover:bg-amber-100 border-amber-200'
                                        }`}
                                        title="Force logout"
                                    >
                                        Logout
                                    </button>
                                    <button
                                        onClick={() => deleteUser(u.id, u.name || u.email)}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 border ${
                                            isDark
                                                ? 'bg-red-500/10 text-red-300 hover:bg-red-500/20 border-red-500/20'
                                                : 'bg-red-50 text-red-600 hover:bg-red-100 border-red-200'
                                        }`}
                                        title="Hapus user"
                                    >
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
