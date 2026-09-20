import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';

const PERMISSION_LABELS = {
    chat: 'Chat AI',
    chat_history: 'Chat History',
    model_original: 'Model Original',
    model_authentic: 'Model Authentic',
    video_generator: 'Video Generator',
    audio_generator: 'Audio Studio',
    video_downloader: 'Video Downloader',
    media_converter: 'Media Converter',
    ai_api: 'AI API',
    ai_dashboard: 'AI Dashboard',
    ai_dashboard_official: 'AI Dashboard Official',
};

const DEFAULT_PERMS = {
    chat: true, chat_history: true, model_original: true,
    model_authentic: false, video_generator: false,
    audio_generator: true, video_downloader: true, media_converter: true,
    ai_api: false, ai_dashboard: false, ai_dashboard_official: false,
};

export default function AdminUsers() {
    const { t, localizedPath } = useLocale();
    const navigate = useNavigate();
    const { user: currentUser } = useAuth();
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [showModal, setShowModal] = useState(false);
    const [editUser, setEditUser] = useState(null);
    const [formData, setFormData] = useState({ name: '', email: '', password: '', role: 'member', duration: '30d', permissions: { ...DEFAULT_PERMS } });
    const [formError, setFormError] = useState('');
    const [formLoading, setFormLoading] = useState(false);
    const [deleteConfirm, setDeleteConfirm] = useState(null);

    const getCsrfToken = () => {
        return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');
    };

    const fetchUsers = useCallback(async () => {
        try {
            const res = await fetch('/api/a/u', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setUsers(data.users || data);
            }
        } catch (err) {
            console.error('Failed to fetch users:', err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchUsers();
        const interval = setInterval(fetchUsers, 5000); // Auto-refresh every 5s
        return () => clearInterval(interval);
    }, [fetchUsers]);

    const openCreate = () => {
        setEditUser(null);
        setFormData({ name: '', email: '', password: '', role: 'member', duration: '30d', permissions: { ...DEFAULT_PERMS } });
        setFormError('');
        setShowModal(true);
    };

    const openEdit = (u) => {
        setEditUser(u);
        setFormData({
            name: u.name, email: u.email, password: '', role: u.role || 'member', duration: '',
            permissions: u.active_permissions || u.permissions || { ...DEFAULT_PERMS },
        });
        setFormError('');
        setShowModal(true);
    };

    const togglePerm = (key) => {
        setFormData(prev => ({
            ...prev,
            permissions: { ...prev.permissions, [key]: !prev.permissions[key] }
        }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setFormError('');
        setFormLoading(true);

        try {
            const url = editUser ? `/api/a/u/${editUser.id}` : '/api/a/u';
            const method = editUser ? 'PUT' : 'POST';
            const body = { ...formData };
            if (editUser && !body.password) delete body.password;
            if (body.role === 'admin') delete body.permissions;

            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(body),
            });

            if (!res.ok) {
                const data = await res.json();
                throw new Error(data.message || Object.values(data.errors || {}).flat().join(', ') || 'Gagal menyimpan');
            }

            setShowModal(false);
            fetchUsers();
        } catch (err) {
            setFormError(err.message);
        } finally {
            setFormLoading(false);
        }
    };

    const handleDelete = async (id) => {
        try {
            await fetch(`/api/a/u/${id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            setDeleteConfirm(null);
            fetchUsers();
        } catch (err) {
            console.error('Delete failed:', err);
        }
    };

    const filteredUsers = users.filter(u =>
        u.name?.toLowerCase().includes(search.toLowerCase()) ||
        u.email?.toLowerCase().includes(search.toLowerCase())
    );

    // Shared input classes
    const inputClass = isDark
        ? 'w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all'
        : 'w-full px-4 py-3 rounded-xl bg-white border border-gray-200 text-slate-900 placeholder-gray-400 text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all';

    const selectClass = isDark
        ? 'w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all'
        : 'w-full px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all';

    const optionBg = isDark ? 'bg-gray-900' : 'bg-white';

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-7xl mx-auto">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 animate-fade-in-up">
                <div>
                    <h1 className={`text-2xl lg:text-3xl font-extrabold tracking-tight ${isDark ? 'text-white' : 'text-slate-900'}`}>{t("Kelola ")}<span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">{t("Users")}</span>
                    </h1>
                    <p className={`text-sm mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t("Manajemen akun pengguna platform XSuper.ai.")}</p>
                </div>
                <button onClick={openCreate} className="ui-btn-primary">
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>{t("Tambah User")}</button>
            </div>

            {/* Search */}
            <div className="flex flex-col sm:flex-row gap-3 animate-fade-in">
                <div className="relative flex-1 group">
                    <svg className={`absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors ${isDark ? 'text-gray-500 group-focus-within:text-red-400' : 'text-gray-400 group-focus-within:text-red-500'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t("Cari berdasarkan nama atau email...")}
                        className={`w-full pl-10 pr-4 py-2.5 rounded-xl text-sm transition-all duration-200 focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 ${
                            isDark
                                ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder-gray-600 hover:border-red-400/30'
                                : 'bg-gray-50/70 border border-gray-200 text-slate-900 placeholder-gray-400 hover:border-red-400/30 focus:bg-white'
                        }`}
                    />
                </div>
                <div className={`flex items-center gap-2 px-4 py-2.5 rounded-xl border ${isDark ? 'bg-white/[0.03] border-white/[0.06]' : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]'}`}>
                    <svg className={`w-4 h-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /></svg>
                    <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-500'}`}>{t("Total:")}</span>
                    <span className={`text-sm font-bold tabular-nums ${isDark ? 'text-white' : 'text-slate-900'}`}>{users.length}</span>
                    <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t('users')}</span>
                </div>
            </div>

            {/* Table */}
            <div className={`rounded-2xl border overflow-hidden animate-fade-in-up ${isDark ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]'}`}>
                {loading ? (
                    <div className="p-4 space-y-3">
                        {[0, 1, 2, 3, 4].map(i => (
                            <div key={i} className="flex items-center gap-3">
                                <div className="ui-skeleton w-10 h-10 rounded-xl" />
                                <div className="flex-1 space-y-2">
                                    <div className="ui-skeleton h-3 w-1/3" />
                                    <div className="ui-skeleton h-2 w-1/4" />
                                </div>
                                <div className="ui-skeleton h-6 w-16 rounded-md" />
                                <div className="ui-skeleton h-8 w-24 rounded-md" />
                            </div>
                        ))}
                    </div>
                ) : filteredUsers.length === 0 ? (
                    <div className={`flex flex-col items-center justify-center gap-3 py-16 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                        <div className={`w-14 h-14 rounded-2xl flex items-center justify-center ${isDark ? 'bg-white/[0.04]' : 'bg-red-50'}`}>
                            <svg className="w-7 h-7 text-red-500" fill="none" stroke="currentColor" strokeWidth="1.6" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /><line x1="11" y1="8" x2="11" y2="14" /><line x1="8" y1="11" x2="14" y2="11" /></svg>
                        </div>
                        <p className="text-sm font-medium">{t("Tidak ada user ditemukan")}</p>
                        <p className="text-xs">{t("Coba ubah kata kunci pencarian.")}</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto scrollbar-thin">
                        <table className="w-full min-w-[680px]">
                            <thead>
                                <tr className={`border-b ${isDark ? 'border-white/[0.06] bg-white/[0.02]' : 'border-gray-200 bg-gray-50/60'}`}>
                                    <th className={`text-left px-5 py-3.5 text-[10px] font-bold uppercase tracking-[0.12em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t("User")}</th>
                                    <th className={`text-left px-5 py-3.5 text-[10px] font-bold uppercase tracking-[0.12em] hidden sm:table-cell ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t("Email")}</th>
                                    <th className={`text-left px-5 py-3.5 text-[10px] font-bold uppercase tracking-[0.12em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t("Role")}</th>
                                    <th className={`text-left px-5 py-3.5 text-[10px] font-bold uppercase tracking-[0.12em] hidden md:table-cell ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t("Masa Aktif")}</th>
                                    <th className={`text-right px-5 py-3.5 text-[10px] font-bold uppercase tracking-[0.12em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t("Aksi")}</th>
                                </tr>
                            </thead>
                            <tbody className="stagger">
                                {filteredUsers.map((u) => (
                                    <tr key={u.id} className={`group border-b transition-all duration-200 ${isDark ? 'border-white/[0.04] hover:bg-white/[0.03]' : 'border-gray-100 hover:bg-red-50/40'}`}>
                                        <td className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className={`relative w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold transition-transform duration-200 group-hover:scale-105 ${u.role === 'admin' ? 'bg-gradient-to-br from-red-500 to-red-600 text-white shadow-[0_6px_16px_-4px_rgba(239,68,68,0.45)]' : 'bg-gradient-to-br from-blue-500 to-indigo-500 text-white shadow-[0_6px_16px_-4px_rgba(59,130,246,0.35)]'}`}>
                                                    {u.name?.[0]?.toUpperCase() || '?'}
                                                    <span className="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-gray-900" />
                                                </div>
                                                <div className="min-w-0">
                                                    <div className={`text-sm font-semibold truncate ${isDark ? 'text-white' : 'text-slate-900'} group-hover:text-red-500 transition-colors`}>{u.name}</div>
                                                    <div className={`text-xs truncate sm:hidden ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{u.email}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className={`px-5 py-4 text-sm hidden sm:table-cell truncate max-w-[220px] ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>{u.email}</td>
                                        <td className="px-5 py-4">
                                            <span className={`inline-flex px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider border ${u.role === 'admin' ? (isDark ? 'bg-red-500/15 text-red-300 border-red-500/25' : 'bg-red-50 text-red-600 border-red-200') : (isDark ? 'bg-blue-500/15 text-blue-300 border-blue-500/25' : 'bg-blue-50 text-blue-600 border-blue-200')}`}>
                                                {u.role || 'member'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4 hidden md:table-cell">
                                            {u.role === 'admin' ? (
                                                <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>∞ Unlimited</span>
                                            ) : u.is_expired ? (
                                                <span className="text-xs font-bold px-2 py-0.5 rounded-md bg-red-500/15 text-red-400">{t("Expired")}</span>
                                            ) : u.days_remaining !== null && u.days_remaining !== undefined ? (
                                                <span className={`text-xs font-bold px-2 py-0.5 rounded-md ${u.days_remaining <= 3 ? 'bg-red-500/15 text-red-400' : u.days_remaining <= 7 ? 'bg-amber-500/15 text-amber-400' : 'bg-emerald-500/15 text-emerald-400'}`}>
                                                    {u.days_remaining} {t("hari")}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-emerald-400">∞ Unlimited</span>
                                            )}
                                        </td>
                                        <td className="px-5 py-4">
                                            <div className="flex items-center justify-end gap-1">
                                                <button onClick={() => navigate(localizedPath('/admin/security'))} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-emerald-400 hover:bg-emerald-500/10' : 'text-gray-400 hover:text-emerald-500 hover:bg-emerald-50'}`} title={t("Kelola perangkat di Keamanan")}>
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                                </button>
                                                <button onClick={() => openEdit(u)} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-blue-400 hover:bg-blue-500/10' : 'text-gray-400 hover:text-blue-500 hover:bg-blue-50'}`} title={t("Edit")}>
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                                                </button>
                                                {u.id !== currentUser?.id && (
                                                    <button onClick={() => setDeleteConfirm(u)} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-red-400 hover:bg-red-500/10' : 'text-gray-400 hover:text-red-500 hover:bg-red-50'}`} title={t("Hapus")}>
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Create/Edit Modal */}
            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 animate-fade-in" role="dialog" aria-modal="true">
                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={() => setShowModal(false)} />
                    <div className={`relative w-full max-w-lg max-h-[90vh] overflow-y-auto border rounded-2xl shadow-2xl p-6 scrollbar-thin animate-scale-in ${isDark ? 'bg-gray-900/95 border-white/[0.08] backdrop-blur-2xl' : 'bg-white border-gray-200/80 shadow-[0_32px_64px_-16px_rgba(15,23,42,0.25)]'}`}>
                        <div className="flex items-center justify-between mb-6">
                            <h3 className={`text-lg font-bold flex items-center gap-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                                <span className="w-8 h-8 rounded-lg bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center shadow-md">
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                        {editUser ? <><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></> : <><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></>}
                                    </svg>
                                </span>
                                {editUser ? 'Edit User' : 'Tambah User Baru'}
                            </h3>
                            <button onClick={() => setShowModal(false)} className={`p-2 rounded-xl transition-colors ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-500 hover:text-red-500 hover:bg-red-50'}`} aria-label={t("Close")}>
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>

                        {formError && (
                            <div role="alert" className={`mb-4 p-3.5 rounded-xl border text-sm font-medium flex items-start gap-2.5 animate-fade-in-down ${isDark ? 'bg-red-500/10 border-red-500/25 text-red-300' : 'bg-red-50 border-red-200 text-red-700'}`}>
                                <svg className="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
                                <span className="flex-1">{formError}</span>
                            </div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t("Nama")}</label>
                                <input type="text" value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} required
                                    className={inputClass} placeholder={t("Nama lengkap")} />
                            </div>
                            <div>
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t("Email")}</label>
                                <input type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} required
                                    className={inputClass} placeholder="email@example.com" />
                            </div>
                            <div>
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                    Password {editUser && <span className={`normal-case ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>(kosongkan jika tidak diubah)</span>}
                                </label>
                                <input type="password" value={formData.password} onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                                    {...(!editUser ? { required: true } : {})}
                                    className={inputClass} placeholder="••••••••" minLength={editUser ? 0 : 8} />
                            </div>
                            <div>
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t("Role")}</label>
                                <select value={formData.role} onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                                    className={selectClass}>
                                    <option value="member" className={optionBg}>{t("Member")}</option>
                                    <option value="admin" className={optionBg}>{t("Admin")}</option>
                                </select>
                            </div>

                            {formData.role !== 'admin' && (
                                <>
                                    {/* Duration */}
                                    <div>
                                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                            {editUser ? 'Tambah Durasi' : 'Durasi Akses'}
                                        </label>
                                        <select value={formData.duration} onChange={(e) => setFormData({ ...formData, duration: e.target.value })}
                                            className={selectClass}>
                                            {editUser && <option value="" className={optionBg}>{t("Tidak diubah")}</option>}
                                            <option value="1d" className={optionBg}>1 Hari</option>
                                            <option value="7d" className={optionBg}>1 Minggu</option>
                                            <option value="30d" className={optionBg}>1 Bulan</option>
                                            <option value="90d" className={optionBg}>3 Bulan</option>
                                            <option value="180d" className={optionBg}>6 Bulan</option>
                                            <option value="365d" className={optionBg}>12 Bulan</option>
                                            <option value="unlimited" className={optionBg}>∞ Unlimited</option>
                                            {editUser && <option value="clear" className={optionBg}>{t("Reset Expired")}</option>}
                                        </select>
                                        {editUser && editUser.expires_at && (
                                            <p className={`mt-1.5 text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                                Expired: {new Date(editUser.expires_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
                                                {editUser.days_remaining !== null && ` (${editUser.days_remaining} ${t("hari lagi")})`}
                                            </p>
                                        )}
                                    </div>

                                    {/* Permissions */}
                                    <div>
                                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-3 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t("Hak Akses")}</label>
                                        <div className="grid grid-cols-2 gap-2">
                                            {Object.entries(PERMISSION_LABELS).map(([key, label]) => (
                                                <button key={key} type="button" onClick={() => togglePerm(key)}
                                                    className={`flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-all ${
                                                        formData.permissions[key]
                                                            ? 'bg-red-500/15 border border-red-500/20 text-red-400'
                                                            : isDark
                                                                ? 'bg-white/[0.03] border border-white/[0.06] text-gray-500'
                                                                : 'bg-gray-50 border border-gray-200 text-gray-400'
                                                    }`}>
                                                    <span className={`w-4 h-4 rounded-md flex items-center justify-center text-[10px] ${
                                                        formData.permissions[key]
                                                            ? 'bg-red-500 text-white'
                                                            : isDark ? 'bg-white/[0.06] text-transparent' : 'bg-gray-200 text-transparent'
                                                    }`}>✓</span>
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </>
                            )}

                            <div className="flex gap-3 pt-2">
                                <button type="button" onClick={() => setShowModal(false)}
                                    className={`flex-1 py-3 rounded-xl border text-sm font-medium transition-all ${
                                        isDark
                                            ? 'bg-white/[0.05] border-white/[0.08] text-gray-400 hover:bg-white/[0.08]'
                                            : 'bg-gray-50 border-gray-300 text-gray-500 hover:bg-gray-100'
                                    }`}>{t("Batal")}</button>
                                <button type="submit" disabled={formLoading}
                                    className="flex-1 py-3 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-105 hover:-translate-y-0.5 hover:shadow-[0_12px_28px_-6px_rgba(239,68,68,0.45)] disabled:opacity-50 transition-all shadow-[0_10px_24px_-4px_rgba(239,68,68,0.35)]">
                                    {formLoading ? 'Menyimpan...' : (editUser ? 'Update' : 'Simpan')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Delete Modal */}
            {deleteConfirm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 animate-fade-in" role="dialog" aria-modal="true">
                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={() => setDeleteConfirm(null)} />
                    <div className={`relative w-full max-w-sm border rounded-2xl shadow-2xl p-6 text-center animate-scale-in ${isDark ? 'bg-gray-900/95 border-white/[0.08] backdrop-blur-2xl' : 'bg-white border-gray-200/80 shadow-[0_32px_64px_-16px_rgba(15,23,42,0.25)]'}`}>
                        <div className={`w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4 animate-pop-in ${isDark ? 'bg-red-500/15 ring-4 ring-red-500/20' : 'bg-red-50 ring-4 ring-red-100'}`}>
                            <svg className={`w-8 h-8 ${isDark ? 'text-red-400' : 'text-red-500'}`} fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <h3 className={`text-lg font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>{t("Hapus User?")}</h3>
                        <p className={`text-sm mb-6 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                            {t("Yakin ingin menghapus")} <span className={`font-semibold ${isDark ? 'text-white' : 'text-slate-900'}`}>{deleteConfirm.name}</span>?
                            <br />
                            <span className="text-xs opacity-75">{t("Aksi ini tidak dapat dibatalkan.")}</span>
                        </p>
                        <div className="flex gap-3">
                            <button onClick={() => setDeleteConfirm(null)} className="ui-btn-ghost flex-1 justify-center">{t("Batal")}</button>
                            <button onClick={() => handleDelete(deleteConfirm.id)} className="inline-flex items-center justify-center gap-2 flex-1 py-3 px-4 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold shadow-[0_10px_24px_-4px_rgba(239,68,68,0.45)] hover:brightness-105 hover:-translate-y-0.5 hover:shadow-[0_16px_36px_-6px_rgba(239,68,68,0.55)] active:translate-y-0 active:scale-[0.98] transition-all">
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                                {t("Hapus")}
</button>
                        </div>
                    </div>
                </div>
            )}

        </div>
    );
}
