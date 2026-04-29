import React, { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';

export default function AdminUsers() {
    const { user: currentUser } = useAuth();
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [showModal, setShowModal] = useState(false);
    const [editUser, setEditUser] = useState(null);
    const [formData, setFormData] = useState({ name: '', email: '', password: '', role: 'member', duration: '30d' });
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
    }, [fetchUsers]);

    const openCreate = () => {
        setEditUser(null);
        setFormData({ name: '', email: '', password: '', role: 'member', duration: '30d' });
        setFormError('');
        setShowModal(true);
    };

    const openEdit = (u) => {
        setEditUser(u);
        setFormData({ name: u.name, email: u.email, password: '', role: u.role || 'member', duration: '' });
        setFormError('');
        setShowModal(true);
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
                headers: {
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
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

    return (
        <div className="p-4 lg:p-6 space-y-6 max-w-7xl mx-auto">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-extrabold text-white tracking-tight">Kelola Users</h1>
                    <p className="text-gray-500 text-sm mt-1">Manajemen akun pengguna platform UltrAI</p>
                </div>
                <button
                    onClick={openCreate}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-110 transition-all shadow-lg shadow-red-500/25 hover:shadow-red-500/40"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                    Tambah User
                </button>
            </div>

            {/* Search & Stats */}
            <div className="flex flex-col sm:flex-row gap-3">
                <div className="relative flex-1">
                    <svg className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Cari user..."
                        className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                    />
                </div>
                <div className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                    <span className="text-xs text-gray-500">Total:</span>
                    <span className="text-sm font-bold text-white">{users.length}</span>
                    <span className="text-xs text-gray-500">users</span>
                </div>
            </div>

            {/* Users Table */}
            <div className="rounded-2xl bg-gray-900/50 border border-white/[0.06] backdrop-blur-xl overflow-hidden">
                {loading ? (
                    <div className="flex items-center justify-center py-20">
                        <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    </div>
                ) : filteredUsers.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 text-gray-500">
                        <svg className="w-12 h-12 mb-3 text-gray-700" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></svg>
                        <p className="text-sm">Tidak ada user ditemukan</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b border-white/[0.06]">
                                    <th className="text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500">User</th>
                                    <th className="text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 hidden sm:table-cell">Email</th>
                                    <th className="text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500">Role</th>
                                    <th className="text-left px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 hidden md:table-cell">Masa Aktif</th>
                                    <th className="text-right px-5 py-4 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredUsers.map((u) => (
                                    <tr key={u.id} className="border-b border-white/[0.03] hover:bg-white/[0.02] transition-colors">
                                        <td className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className={`w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold ${
                                                    u.role === 'admin'
                                                        ? 'bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/20'
                                                        : 'bg-white/[0.08] text-gray-300'
                                                }`}>
                                                    {u.name?.[0]?.toUpperCase() || '?'}
                                                </div>
                                                <div>
                                                    <div className="text-sm font-semibold text-white">{u.name}</div>
                                                    <div className="text-xs text-gray-500 sm:hidden">{u.email}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-5 py-4 text-sm text-gray-400 hidden sm:table-cell">{u.email}</td>
                                        <td className="px-5 py-4">
                                            <span className={`inline-flex px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase tracking-wider ${
                                                u.role === 'admin'
                                                    ? 'bg-red-500/15 text-red-400 border border-red-500/20'
                                                    : 'bg-blue-500/15 text-blue-400 border border-blue-500/20'
                                            }`}>
                                                {u.role || 'member'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4 hidden md:table-cell">
                                            {u.role === 'admin' ? (
                                                <span className="text-xs text-gray-500">∞ Unlimited</span>
                                            ) : u.is_expired ? (
                                                <span className="text-xs font-bold px-2 py-0.5 rounded-md bg-red-500/15 text-red-400">Expired</span>
                                            ) : u.days_remaining !== null && u.days_remaining !== undefined ? (
                                                <span className={`text-xs font-bold px-2 py-0.5 rounded-md ${
                                                    u.days_remaining <= 3 ? 'bg-red-500/15 text-red-400' :
                                                    u.days_remaining <= 7 ? 'bg-amber-500/15 text-amber-400' :
                                                    'bg-emerald-500/15 text-emerald-400'
                                                }`}>
                                                    {u.days_remaining} hari
                                                </span>
                                            ) : (
                                                <span className="text-xs text-gray-600">Belum diset</span>
                                            )}
                                        </td>
                                        <td className="px-5 py-4">
                                            <div className="flex items-center justify-end gap-1">
                                                <button
                                                    onClick={() => openEdit(u)}
                                                    className="p-2 rounded-lg text-gray-500 hover:text-blue-400 hover:bg-blue-500/10 transition-all"
                                                    title="Edit"
                                                >
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                                                </button>
                                                {u.id !== currentUser?.id && (
                                                    <button
                                                        onClick={() => setDeleteConfirm(u)}
                                                        className="p-2 rounded-lg text-gray-500 hover:text-red-400 hover:bg-red-500/10 transition-all"
                                                        title="Hapus"
                                                    >
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
                    <div className="relative w-full max-w-md bg-gray-900 border border-white/[0.08] rounded-2xl shadow-2xl p-6">
                        <div className="flex items-center justify-between mb-6">
                            <h3 className="text-lg font-bold text-white">
                                {editUser ? 'Edit User' : 'Tambah User Baru'}
                            </h3>
                            <button onClick={() => setShowModal(false)} className="p-1.5 rounded-lg text-gray-500 hover:text-white hover:bg-white/10 transition-colors">
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                            </button>
                        </div>

                        {formError && (
                            <div className="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                                {formError}
                            </div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">Nama</label>
                                <input
                                    type="text"
                                    value={formData.name}
                                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                    required
                                    className="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                                    placeholder="Nama lengkap"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">Email</label>
                                <input
                                    type="email"
                                    value={formData.email}
                                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                    required
                                    className="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                                    placeholder="email@example.com"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">
                                    Password {editUser && <span className="text-gray-600 normal-case">(kosongkan jika tidak diubah)</span>}
                                </label>
                                <input
                                    type="password"
                                    value={formData.password}
                                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                                    {...(!editUser ? { required: true } : {})}
                                    className="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                                    placeholder="••••••••"
                                    minLength={editUser ? 0 : 8}
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">Role</label>
                                <select
                                    value={formData.role}
                                    onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                                    className="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                                >
                                    <option value="member" className="bg-gray-900">Member</option>
                                    <option value="admin" className="bg-gray-900">Admin</option>
                                </select>
                            </div>
                            {formData.role !== 'admin' && (
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">
                                        {editUser ? 'Tambah Durasi' : 'Durasi Akses'}
                                    </label>
                                    <select
                                        value={formData.duration}
                                        onChange={(e) => setFormData({ ...formData, duration: e.target.value })}
                                        className="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                                    >
                                        {editUser && <option value="" className="bg-gray-900">Tidak diubah</option>}
                                        <option value="1d" className="bg-gray-900">1 Hari</option>
                                        <option value="7d" className="bg-gray-900">1 Minggu</option>
                                        <option value="30d" className="bg-gray-900">1 Bulan</option>
                                        <option value="90d" className="bg-gray-900">3 Bulan</option>
                                        <option value="180d" className="bg-gray-900">6 Bulan</option>
                                        <option value="365d" className="bg-gray-900">12 Bulan</option>
                                        {editUser && <option value="clear" className="bg-gray-900">Hapus Expired</option>}
                                    </select>
                                    {editUser && editUser.expires_at && (
                                        <p className="mt-1.5 text-[11px] text-gray-500">
                                            Expired saat ini: {new Date(editUser.expires_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
                                            {editUser.days_remaining !== null && ` (${editUser.days_remaining} hari lagi)`}
                                        </p>
                                    )}
                                </div>
                            )}
                            <div className="flex gap-3 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setShowModal(false)}
                                    className="flex-1 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-gray-400 text-sm font-medium hover:bg-white/[0.08] transition-all"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={formLoading}
                                    className="flex-1 py-3 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-red-500/25"
                                >
                                    {formLoading ? 'Menyimpan...' : (editUser ? 'Update' : 'Simpan')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Delete Confirmation Modal */}
            {deleteConfirm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setDeleteConfirm(null)} />
                    <div className="relative w-full max-w-sm bg-gray-900 border border-white/[0.08] rounded-2xl shadow-2xl p-6 text-center">
                        <div className="w-14 h-14 rounded-2xl bg-red-500/15 flex items-center justify-center mx-auto mb-4">
                            <svg className="w-7 h-7 text-red-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><line x1="10" y1="11" x2="10" y2="17" /><line x1="14" y1="11" x2="14" y2="17" /></svg>
                        </div>
                        <h3 className="text-lg font-bold text-white mb-2">Hapus User?</h3>
                        <p className="text-sm text-gray-400 mb-6">
                            Yakin ingin menghapus <span className="text-white font-semibold">{deleteConfirm.name}</span>? Aksi ini tidak bisa dibatalkan.
                        </p>
                        <div className="flex gap-3">
                            <button
                                onClick={() => setDeleteConfirm(null)}
                                className="flex-1 py-2.5 rounded-xl bg-white/[0.05] border border-white/[0.08] text-gray-400 text-sm font-medium hover:bg-white/[0.08] transition-all"
                            >
                                Batal
                            </button>
                            <button
                                onClick={() => handleDelete(deleteConfirm.id)}
                                className="flex-1 py-2.5 rounded-xl bg-red-500 text-white text-sm font-bold hover:bg-red-600 transition-all"
                            >
                                Hapus
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
