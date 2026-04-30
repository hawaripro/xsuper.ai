import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

const PERMISSION_LABELS = {
    chat: 'Chat AI',
    chat_history: 'Chat History',
    model_original: 'Model Original',
    model_authentic: 'Model Authentic',
    model_codex: 'Model Codex',
    model_wavespeed: 'Model Wavespeed',
    model_yepapi: 'Model YepAPI',
    ai_api: 'AI API',
    ai_dashboard: 'AI Dashboard',
};

const DEFAULT_PERMS = {
    chat: true, chat_history: true, model_original: true,
    model_authentic: false, model_codex: false, model_wavespeed: false,
    model_yepapi: false, ai_api: false, ai_dashboard: false,
};

export default function AdminUsers() {
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
    const [keyModal, setKeyModal] = useState(null); // user object
    const [apiKeys, setApiKeys] = useState([]);
    const [keyLoading, setKeyLoading] = useState(false);
    const [copiedKey, setCopiedKey] = useState('');
    const [deleteKeyConfirm, setDeleteKeyConfirm] = useState(null);
    const [deviceModal, setDeviceModal] = useState(null);
    const [devices, setDevices] = useState([]);

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

    // API Key functions
    const openKeyModal = async (u) => {
        setKeyModal(u);
        setKeyLoading(true);
        try {
            const res = await fetch(`/api/k/list?user_id=${u.id}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) { const d = await res.json(); setApiKeys(d.keys || []); }
        } catch {} finally { setKeyLoading(false); }
    };

    const generateKey = async () => {
        if (!keyModal) return;
        setKeyLoading(true);
        try {
            const res = await fetch('/api/k/create', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ user_id: keyModal.id, name: 'API Key' }),
            });
            if (res.ok) { openKeyModal(keyModal); }
        } catch {} finally { setKeyLoading(false); }
    };

    const toggleKey = async (keyId) => {
        try {
            await fetch(`/api/k/toggle/${keyId}`, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() } });
            openKeyModal(keyModal);
        } catch {}
    };

    const regenKey = async (keyId) => {
        setDeleteKeyConfirm({ id: keyId, action: 'regen' });
    };

    const deleteKey = async (keyId) => {
        setDeleteKeyConfirm({ id: keyId, action: 'delete' });
    };

    const confirmKeyAction = async () => {
        if (!deleteKeyConfirm) return;
        try {
            if (deleteKeyConfirm.action === 'delete') {
                await fetch(`/api/k/${deleteKeyConfirm.id}`, { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() } });
            } else {
                await fetch(`/api/k/regen/${deleteKeyConfirm.id}`, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() } });
            }
            setDeleteKeyConfirm(null);
            openKeyModal(keyModal);
        } catch {}
    };

    const copyKey = (key) => {
        navigator.clipboard.writeText(key);
        setCopiedKey(key);
        setTimeout(() => setCopiedKey(''), 2000);
    };

    // Device functions
    const loadDevices = async (userId) => {
        try {
            const res = await fetch(`/api/d/list?user_id=${userId}`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            if (res.ok) { const d = await res.json(); setDevices(d.devices || []); }
        } catch {}
    };

    const openDeviceModal = async (u) => {
        setDeviceModal(u);
        loadDevices(u.id);
    };

    // Auto-refresh devices when modal is open
    useEffect(() => {
        if (!deviceModal) return;
        const interval = setInterval(() => loadDevices(deviceModal.id), 3000);
        return () => clearInterval(interval);
    }, [deviceModal]);

    const updateDeviceStatus = async (deviceId, status) => {
        try {
            await fetch(`/api/d/${deviceId}`, {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ status }),
            });
            if (deviceModal) loadDevices(deviceModal.id);
        } catch {}
    };

    const deleteDevice = async (deviceId) => {
        try {
            await fetch(`/api/d/${deviceId}`, {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            if (deviceModal) loadDevices(deviceModal.id);
        } catch {}
    };

    const filteredUsers = users.filter(u =>
        u.name?.toLowerCase().includes(search.toLowerCase()) ||
        u.email?.toLowerCase().includes(search.toLowerCase())
    );

    // Shared input classes
    const inputClass = isDark
        ? 'w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all'
        : 'w-full px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 placeholder-gray-400 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all';

    const selectClass = isDark
        ? 'w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all'
        : 'w-full px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all';

    const optionBg = isDark ? 'bg-gray-900' : 'bg-white';

    return (
        <div className="p-4 lg:p-6 space-y-6 max-w-7xl mx-auto">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className={`text-2xl font-extrabold tracking-tight ${isDark ? 'text-white' : 'text-gray-900'}`}>Kelola Users</h1>
                    <p className={`text-sm mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Manajemen akun pengguna platform UltrAI</p>
                </div>
                <button onClick={openCreate} className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-110 transition-all shadow-lg shadow-red-500/25">
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                    Tambah User
                </button>
            </div>

            {/* Search */}
            <div className="flex flex-col sm:flex-row gap-3">
                <div className="relative flex-1">
                    <svg className={`absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari user..."
                        className={`w-full pl-10 pr-4 py-2.5 rounded-xl text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all ${
                            isDark
                                ? 'bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600'
                                : 'bg-white border border-gray-300 text-gray-900 placeholder-gray-400'
                        }`} />
                </div>
                <div className={`flex items-center gap-2 px-4 py-2.5 rounded-xl ${isDark ? 'bg-white/[0.03] border border-white/[0.06]' : 'bg-gray-50 border border-gray-200'}`}>
                    <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Total:</span>
                    <span className={`text-sm font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{users.length}</span>
                    <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>users</span>
                </div>
            </div>

            {/* Table */}
            <div className={`rounded-2xl border overflow-hidden ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                {loading ? (
                    <div className="flex items-center justify-center py-20">
                        <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    </div>
                ) : filteredUsers.length === 0 ? (
                    <div className={`flex flex-col items-center justify-center py-20 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                        <p className="text-sm">Tidak ada user ditemukan</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className={`border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                                    <th className={`text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>User</th>
                                    <th className={`text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] hidden sm:table-cell ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Email</th>
                                    <th className={`text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Role</th>
                                    <th className={`text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] hidden md:table-cell ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Masa Aktif</th>
                                    <th className={`text-right px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredUsers.map((u) => (
                                    <tr key={u.id} className={`border-b transition-colors ${isDark ? 'border-white/[0.03] hover:bg-white/[0.02]' : 'border-gray-100 hover:bg-gray-50'}`}>
                                        <td className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className={`w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold ${u.role === 'admin' ? 'bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/20' : isDark ? 'bg-white/[0.08] text-gray-300' : 'bg-gray-100 text-gray-600'}`}>
                                                    {u.name?.[0]?.toUpperCase() || '?'}
                                                </div>
                                                <div>
                                                    <div className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{u.name}</div>
                                                    <div className={`text-xs sm:hidden ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{u.email}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className={`px-5 py-4 text-sm hidden sm:table-cell ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{u.email}</td>
                                        <td className="px-5 py-4">
                                            <span className={`inline-flex px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase tracking-wider ${u.role === 'admin' ? 'bg-red-500/15 text-red-400 border border-red-500/20' : 'bg-blue-500/15 text-blue-400 border border-blue-500/20'}`}>
                                                {u.role || 'member'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4 hidden md:table-cell">
                                            {u.role === 'admin' ? (
                                                <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>∞ Unlimited</span>
                                            ) : u.is_expired ? (
                                                <span className="text-xs font-bold px-2 py-0.5 rounded-md bg-red-500/15 text-red-400">Expired</span>
                                            ) : u.days_remaining !== null && u.days_remaining !== undefined ? (
                                                <span className={`text-xs font-bold px-2 py-0.5 rounded-md ${u.days_remaining <= 3 ? 'bg-red-500/15 text-red-400' : u.days_remaining <= 7 ? 'bg-amber-500/15 text-amber-400' : 'bg-emerald-500/15 text-emerald-400'}`}>
                                                    {u.days_remaining} hari
                                                </span>
                                            ) : (
                                                <span className="text-xs text-emerald-400">∞ Unlimited</span>
                                            )}
                                        </td>
                                        <td className="px-5 py-4">
                                            <div className="flex items-center justify-end gap-1">
                                                <button onClick={() => openDeviceModal(u)} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-emerald-400 hover:bg-emerald-500/10' : 'text-gray-400 hover:text-emerald-500 hover:bg-emerald-50'}`} title="Devices">
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                                </button>
                                                <button onClick={() => openKeyModal(u)} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-amber-400 hover:bg-amber-500/10' : 'text-gray-400 hover:text-amber-500 hover:bg-amber-50'}`} title="API Key">
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                                </button>
                                                <button onClick={() => openEdit(u)} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-blue-400 hover:bg-blue-500/10' : 'text-gray-400 hover:text-blue-500 hover:bg-blue-50'}`} title="Edit">
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                                                </button>
                                                {u.id !== currentUser?.id && (
                                                    <button onClick={() => setDeleteConfirm(u)} className={`p-2 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-red-400 hover:bg-red-500/10' : 'text-gray-400 hover:text-red-500 hover:bg-red-50'}`} title="Hapus">
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
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setShowModal(false)} />
                    <div className={`relative w-full max-w-lg max-h-[90vh] overflow-y-auto border rounded-2xl shadow-2xl p-6 scrollbar-thin ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-6">
                            <h3 className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{editUser ? 'Edit User' : 'Tambah User Baru'}</h3>
                            <button onClick={() => setShowModal(false)} className={`p-1.5 rounded-lg transition-colors ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}>
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>

                        {formError && (
                            <div className="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">{formError}</div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Nama</label>
                                <input type="text" value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} required
                                    className={inputClass} placeholder="Nama lengkap" />
                            </div>
                            <div>
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Email</label>
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
                                <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Role</label>
                                <select value={formData.role} onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                                    className={selectClass}>
                                    <option value="member" className={optionBg}>Member</option>
                                    <option value="admin" className={optionBg}>Admin</option>
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
                                            {editUser && <option value="" className={optionBg}>Tidak diubah</option>}
                                            <option value="1d" className={optionBg}>1 Hari</option>
                                            <option value="7d" className={optionBg}>1 Minggu</option>
                                            <option value="30d" className={optionBg}>1 Bulan</option>
                                            <option value="90d" className={optionBg}>3 Bulan</option>
                                            <option value="180d" className={optionBg}>6 Bulan</option>
                                            <option value="365d" className={optionBg}>12 Bulan</option>
                                            <option value="unlimited" className={optionBg}>∞ Unlimited</option>
                                            {editUser && <option value="clear" className={optionBg}>Reset Expired</option>}
                                        </select>
                                        {editUser && editUser.expires_at && (
                                            <p className={`mt-1.5 text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                                Expired: {new Date(editUser.expires_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
                                                {editUser.days_remaining !== null && ` (${editUser.days_remaining} hari lagi)`}
                                            </p>
                                        )}
                                    </div>

                                    {/* Permissions */}
                                    <div>
                                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-3 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Hak Akses</label>
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
                                    }`}>
                                    Batal
                                </button>
                                <button type="submit" disabled={formLoading}
                                    className="flex-1 py-3 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-red-500/25">
                                    {formLoading ? 'Menyimpan...' : (editUser ? 'Update' : 'Simpan')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Delete Modal */}
            {deleteConfirm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setDeleteConfirm(null)} />
                    <div className={`relative w-full max-w-sm border rounded-2xl shadow-2xl p-6 text-center ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className="w-14 h-14 rounded-2xl bg-red-500/15 flex items-center justify-center mx-auto mb-4">
                            <svg className="w-7 h-7 text-red-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                        </div>
                        <h3 className={`text-lg font-bold mb-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>Hapus User?</h3>
                        <p className={`text-sm mb-6 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                            Yakin ingin menghapus <span className={`font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{deleteConfirm.name}</span>?
                        </p>
                        <div className="flex gap-3">
                            <button onClick={() => setDeleteConfirm(null)} className={`flex-1 py-2.5 rounded-xl border text-sm font-medium transition-all ${
                                isDark
                                    ? 'bg-white/[0.05] border-white/[0.08] text-gray-400 hover:bg-white/[0.08]'
                                    : 'bg-gray-50 border-gray-300 text-gray-500 hover:bg-gray-100'
                            }`}>Batal</button>
                            <button onClick={() => handleDelete(deleteConfirm.id)} className="flex-1 py-2.5 rounded-xl bg-red-500 text-white text-sm font-bold hover:bg-red-600 transition-all">Hapus</button>
                        </div>
                    </div>
                </div>
            )}
            {/* Delete/Regen API Key Confirm Modal */}
            {deleteKeyConfirm && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setDeleteKeyConfirm(null)} />
                    <div className={`relative w-full max-w-sm rounded-2xl border p-6 text-center shadow-2xl ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className={`w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-4 ${
                            deleteKeyConfirm.action === 'delete' ? 'bg-red-500/15' : 'bg-amber-500/15'
                        }`}>
                            {deleteKeyConfirm.action === 'delete' ? (
                                <svg className="w-7 h-7 text-red-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><line x1="10" y1="11" x2="10" y2="17" /><line x1="14" y1="11" x2="14" y2="17" /></svg>
                            ) : (
                                <svg className="w-7 h-7 text-amber-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            )}
                        </div>
                        <h3 className={`text-lg font-bold mb-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            {deleteKeyConfirm.action === 'delete' ? 'Hapus API Key?' : 'Regenerate API Key?'}
                        </h3>
                        <p className={`text-sm mb-6 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                            {deleteKeyConfirm.action === 'delete'
                                ? 'API key ini akan dihapus permanen. User tidak bisa menggunakannya lagi.'
                                : 'Key lama akan diganti dengan key baru. Key lama tidak bisa dipakai lagi.'
                            }
                        </p>
                        <div className="flex gap-3">
                            <button onClick={() => setDeleteKeyConfirm(null)} className={`flex-1 py-2.5 rounded-xl border text-sm font-medium transition-all ${
                                isDark ? 'bg-white/[0.05] border-white/[0.08] text-gray-400 hover:bg-white/[0.08]' : 'bg-gray-50 border-gray-300 text-gray-500 hover:bg-gray-100'
                            }`}>Batal</button>
                            <button onClick={confirmKeyAction} className={`flex-1 py-2.5 rounded-xl text-white text-sm font-bold transition-all ${
                                deleteKeyConfirm.action === 'delete' ? 'bg-red-500 hover:bg-red-600' : 'bg-amber-500 hover:bg-amber-600'
                            }`}>
                                {deleteKeyConfirm.action === 'delete' ? 'Hapus' : 'Regenerate'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Device Modal */}
            {deviceModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setDeviceModal(null)} />
                    <div className={`relative w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-2xl border p-5 scrollbar-thin ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h3 className={`text-base font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>Perangkat</h3>
                                    {devices.filter(d => d.status === 'pending').length > 0 && (
                                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-400 animate-pulse">
                                            {devices.filter(d => d.status === 'pending').length} pending
                                        </span>
                                    )}
                                </div>
                                <p className={`text-xs mt-0.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                    {deviceModal.name} — {deviceModal.role === 'admin' ? '∞ Unlimited' : 'Max 2 device'}
                                    {' · '}{devices.filter(d => d.status === 'active').length} aktif
                                </p>
                            </div>
                            <button onClick={() => setDeviceModal(null)} className={`p-1.5 rounded-lg ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}>
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        {/* Pending devices first */}
                        {devices.filter(d => d.status === 'pending').length > 0 && (
                            <div className={`mb-3 p-3 rounded-xl border ${isDark ? 'bg-amber-500/[0.05] border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                                <p className="text-xs font-bold text-amber-400 mb-2">⏳ Menunggu Persetujuan</p>
                                {devices.filter(d => d.status === 'pending').map(d => (
                                    <div key={d.id} className={`flex items-center justify-between p-2 rounded-lg mb-1 ${isDark ? 'bg-black/20' : 'bg-white'}`}>
                                        <div>
                                            <span className={`text-xs font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{d.device_name}</span>
                                            <span className={`text-[10px] ml-1.5 px-1.5 py-0.5 rounded ${d.device_type === 'plugin' ? 'bg-violet-500/15 text-violet-400' : d.device_type === 'mobile' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-blue-500/15 text-blue-400'}`}>{d.device_type}</span>
                                            <div className={`text-[9px] mt-0.5 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>IP: {d.ip_address} · {d.last_active_at ? new Date(d.last_active_at).toLocaleString('id-ID') : '-'}</div>
                                        </div>
                                        <div className="flex gap-1.5">
                                            <button onClick={() => updateDeviceStatus(d.id, 'active')} className="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-emerald-500 text-white hover:bg-emerald-600">Setujui</button>
                                            <button onClick={() => updateDeviceStatus(d.id, 'blocked')} className="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-red-500/10 text-red-400 hover:bg-red-500/20">Tolak</button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {devices.filter(d => d.status !== 'pending').length === 0 && devices.filter(d => d.status === 'pending').length === 0 ? (
                            <div className={`text-center py-8 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                                <p className="text-sm">Belum ada perangkat terdaftar</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {devices.filter(d => d.status !== 'pending').map(d => (
                                    <div key={d.id} className={`p-3 rounded-xl border ${isDark ? 'bg-white/[0.02] border-white/[0.06]' : 'bg-gray-50 border-gray-200'}`}>
                                        <div className="flex items-center justify-between mb-1.5">
                                            <div className="flex items-center gap-2">
                                                <span className={`w-2 h-2 rounded-full ${d.status === 'active' ? 'bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.5)]' : 'bg-red-500'}`} />
                                                <span className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{d.device_name}</span>
                                                <span className={`text-[10px] px-1.5 py-0.5 rounded ${d.device_type === 'browser' ? 'bg-blue-500/15 text-blue-400' : d.device_type === 'plugin' ? 'bg-violet-500/15 text-violet-400' : d.device_type === 'mobile' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-gray-500/15 text-gray-400'}`}>{d.device_type}</span>
                                            </div>
                                            <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-md ${d.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'}`}>{d.status}</span>
                                        </div>
                                        <div className={`text-[10px] space-y-0.5 mb-2 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                            <div>IP: {d.ip_address || '-'}</div>
                                            <div>Last active: {d.last_active_at ? new Date(d.last_active_at).toLocaleString('id-ID') : '-'}</div>
                                        </div>
                                        <div className="flex gap-1.5">
                                            {d.status !== 'active' && (
                                                <button onClick={() => updateDeviceStatus(d.id, 'active')} className={`flex-1 py-1.5 rounded-lg text-[11px] font-medium ${isDark ? 'bg-emerald-500/10 text-emerald-400' : 'bg-emerald-50 text-emerald-600'}`}>Aktifkan</button>
                                            )}
                                            {d.status !== 'blocked' && (
                                                <button onClick={() => updateDeviceStatus(d.id, 'blocked')} className={`flex-1 py-1.5 rounded-lg text-[11px] font-medium ${isDark ? 'bg-red-500/10 text-red-400' : 'bg-red-50 text-red-600'}`}>Block</button>
                                            )}
                                            <button onClick={() => deleteDevice(d.id)} className={`py-1.5 px-3 rounded-lg text-[11px] font-medium ${isDark ? 'bg-white/[0.05] text-gray-400' : 'bg-gray-100 text-gray-500'}`}>Hapus</button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* API Key Modal */}
            {keyModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setKeyModal(null)} />
                    <div className={`relative w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-2xl border p-6 scrollbar-thin ${isDark ? 'bg-gray-900 border-white/[0.08]' : 'bg-white border-gray-200'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h3 className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>API Keys</h3>
                                <p className={`text-xs mt-0.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{keyModal.name} ({keyModal.email})</p>
                            </div>
                            <button onClick={() => setKeyModal(null)} className={`p-1.5 rounded-lg transition-colors ${isDark ? 'text-gray-500 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}>
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        {/* Generate Button */}
                        <button onClick={generateKey} disabled={keyLoading}
                            className="w-full mb-4 py-2.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-red-500/25">
                            {keyLoading ? 'Loading...' : '+ Generate API Key Baru'}
                        </button>

                        {/* Keys List */}
                        {apiKeys.length === 0 ? (
                            <div className={`text-center py-8 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                                <svg className="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                <p className="text-sm">Belum ada API key</p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {apiKeys.map(k => (
                                    <div key={k.id} className={`p-4 rounded-xl border ${isDark ? 'bg-white/[0.02] border-white/[0.06]' : 'bg-gray-50 border-gray-200'}`}>
                                        {/* Key header */}
                                        <div className="flex items-center justify-between mb-2">
                                            <div className="flex items-center gap-2">
                                                <span className={`w-2 h-2 rounded-full ${k.is_active ? 'bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.5)]' : 'bg-red-500'}`} />
                                                <span className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-gray-900'}`}>{k.name}</span>
                                            </div>
                                            <span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-md ${k.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'}`}>
                                                {k.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </div>

                                        {/* Key value */}
                                        <div className={`flex items-center gap-2 p-2 rounded-lg mb-3 ${isDark ? 'bg-black/30' : 'bg-gray-100'}`}>
                                            <code className={`flex-1 text-xs font-mono truncate ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{k.key}</code>
                                            <button onClick={() => copyKey(k.key)}
                                                className={`px-2 py-1 rounded-md text-[10px] font-bold transition-all ${copiedKey === k.key ? 'bg-emerald-500/20 text-emerald-400' : isDark ? 'bg-white/[0.05] text-gray-400 hover:bg-white/[0.1]' : 'bg-gray-200 text-gray-500 hover:bg-gray-300'}`}>
                                                {copiedKey === k.key ? '✓ Copied' : 'Copy'}
                                            </button>
                                        </div>

                                        {/* Stats */}
                                        <div className="grid grid-cols-3 gap-2 mb-3">
                                            <div className={`text-center p-1.5 rounded-lg ${isDark ? 'bg-white/[0.03]' : 'bg-white'}`}>
                                                <div className={`text-xs font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{k.total_requests}</div>
                                                <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Requests</div>
                                            </div>
                                            <div className={`text-center p-1.5 rounded-lg ${isDark ? 'bg-white/[0.03]' : 'bg-white'}`}>
                                                <div className={`text-xs font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{k.rate_limit}/m</div>
                                                <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Rate Limit</div>
                                            </div>
                                            <div className={`text-center p-1.5 rounded-lg ${isDark ? 'bg-white/[0.03]' : 'bg-white'}`}>
                                                <div className={`text-xs font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{k.last_used_at ? new Date(k.last_used_at).toLocaleDateString('id-ID') : '-'}</div>
                                                <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Last Used</div>
                                            </div>
                                        </div>

                                        {/* Actions */}
                                        <div className="flex gap-2">
                                            <button onClick={() => toggleKey(k.id)}
                                                className={`flex-1 py-2 rounded-lg text-xs font-medium transition-all ${k.is_active
                                                    ? isDark ? 'bg-amber-500/10 text-amber-400 hover:bg-amber-500/20' : 'bg-amber-50 text-amber-600 hover:bg-amber-100'
                                                    : isDark ? 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100'
                                                }`}>
                                                {k.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                            </button>
                                            <button onClick={() => regenKey(k.id)}
                                                className={`flex-1 py-2 rounded-lg text-xs font-medium transition-all ${isDark ? 'bg-blue-500/10 text-blue-400 hover:bg-blue-500/20' : 'bg-blue-50 text-blue-600 hover:bg-blue-100'}`}>
                                                Regenerate
                                            </button>
                                            <button onClick={() => deleteKey(k.id)}
                                                className={`py-2 px-3 rounded-lg text-xs font-medium transition-all ${isDark ? 'bg-red-500/10 text-red-400 hover:bg-red-500/20' : 'bg-red-50 text-red-600 hover:bg-red-100'}`}>
                                                Hapus
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Usage Info */}
                        <div className={`mt-4 p-3 rounded-xl ${isDark ? 'bg-white/[0.02] border border-white/[0.06]' : 'bg-gray-50 border border-gray-200'}`}>
                            <p className={`text-xs font-medium ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Cara pakai di OpenCode / Cursor:</p>
                            <div className={`mt-2 p-2 rounded-lg font-mono text-[11px] ${isDark ? 'bg-black/30 text-gray-300' : 'bg-gray-100 text-gray-600'}`}>
                                Base URL: https://api.ultrai.id/v1<br/>
                                API Key: ultrai-xxxxxxxxxx
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
