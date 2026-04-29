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

    useEffect(() => { fetchUsers(); }, [fetchUsers]);

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
        </div>
    );
}
