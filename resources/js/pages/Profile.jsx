import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

export default function Profile() {
    const { user, refreshUser } = useAuth();
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [name, setName] = useState(user?.name || '');
    const [email, setEmail] = useState('');

    useEffect(() => {
        const loadProfile = async () => {
            try {
                const res = await fetch('/api/u/me', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    setEmail(data.email || '');
                    setName(data.name || user?.name || '');
                }
            } catch {}
        };
        loadProfile();
    }, []);
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [profileMsg, setProfileMsg] = useState({ type: '', text: '' });
    const [passwordMsg, setPasswordMsg] = useState({ type: '', text: '' });
    const [profileLoading, setProfileLoading] = useState(false);
    const [passwordLoading, setPasswordLoading] = useState(false);

    const getCsrfToken = () => {
        return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');
    };

    const handleProfileUpdate = async (e) => {
        e.preventDefault();
        setProfileMsg({ type: '', text: '' });
        setProfileLoading(true);

        try {
            const res = await fetch('/api/u/p', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ name, email }),
            });

            const data = await res.json();
            if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(', ') || 'Gagal update');

            setProfileMsg({ type: 'success', text: 'Profil berhasil diperbarui!' });
            refreshUser();
        } catch (err) {
            setProfileMsg({ type: 'error', text: err.message });
        } finally {
            setProfileLoading(false);
        }
    };

    const handlePasswordUpdate = async (e) => {
        e.preventDefault();
        setPasswordMsg({ type: '', text: '' });

        if (newPassword !== confirmPassword) {
            setPasswordMsg({ type: 'error', text: 'Password baru tidak cocok' });
            return;
        }

        setPasswordLoading(true);

        try {
            const res = await fetch('/api/u/pw', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    current_password: currentPassword,
                    password: newPassword,
                    password_confirmation: confirmPassword,
                }),
            });

            const data = await res.json();
            if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(', ') || 'Gagal update');

            setPasswordMsg({ type: 'success', text: 'Password berhasil diperbarui!' });
            setCurrentPassword('');
            setNewPassword('');
            setConfirmPassword('');
        } catch (err) {
            setPasswordMsg({ type: 'error', text: err.message });
        } finally {
            setPasswordLoading(false);
        }
    };

    // Shared input classes
    const inputClass = isDark
        ? 'w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all'
        : 'w-full px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 placeholder-gray-400 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all';

    return (
        <div className="p-4 lg:p-6 space-y-6 max-w-3xl mx-auto">
            {/* Header */}
            <div>
                <h1 className={`text-2xl font-extrabold tracking-tight ${isDark ? 'text-white' : 'text-gray-900'}`}>Profil Saya</h1>
                <p className={`text-sm mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Kelola informasi akun dan keamanan Anda</p>
            </div>

            {/* Profile Card */}
            <div className={`p-6 rounded-2xl border ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                <div className={`flex items-center gap-4 mb-6 pb-6 border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                    <div className="w-16 h-16 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white text-2xl font-bold shadow-lg shadow-red-500/20">
                        {user?.name?.[0]?.toUpperCase() || 'U'}
                    </div>
                    <div>
                        <div className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{user?.name}</div>
                        <div className={`text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{user?.email}</div>
                        <span className={`inline-flex mt-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider ${
                            user?.role === 'admin'
                                ? 'bg-red-500/15 text-red-400'
                                : 'bg-blue-500/15 text-blue-400'
                        }`}>
                            {user?.role || 'member'}
                        </span>
                    </div>
                </div>

                {/* Profile Form */}
                <h3 className={`text-sm font-bold mb-4 flex items-center gap-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                    <svg className={`w-4 h-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                    Informasi Profil
                </h3>

                {profileMsg.text && (
                    <div className={`mb-4 p-3 rounded-xl text-sm font-medium ${
                        profileMsg.type === 'success'
                            ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400'
                            : 'bg-red-500/10 border border-red-500/20 text-red-400'
                    }`}>
                        {profileMsg.text}
                    </div>
                )}

                <form onSubmit={handleProfileUpdate} className="space-y-4">
                    <div>
                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Nama</label>
                        <input
                            type="text"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                            className={inputClass}
                        />
                    </div>
                    <div>
                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Email</label>
                        <input
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                            className={inputClass}
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={profileLoading}
                        className="px-6 py-2.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-110 disabled:opacity-50 transition-all shadow-lg shadow-red-500/25"
                    >
                        {profileLoading ? 'Menyimpan...' : 'Simpan Perubahan'}
                    </button>
                </form>
            </div>

            {/* Password Card */}
            <div className={`p-6 rounded-2xl border ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                <h3 className={`text-sm font-bold mb-4 flex items-center gap-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                    <svg className={`w-4 h-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                    Ubah Password
                </h3>

                {passwordMsg.text && (
                    <div className={`mb-4 p-3 rounded-xl text-sm font-medium ${
                        passwordMsg.type === 'success'
                            ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400'
                            : 'bg-red-500/10 border border-red-500/20 text-red-400'
                    }`}>
                        {passwordMsg.text}
                    </div>
                )}

                <form onSubmit={handlePasswordUpdate} className="space-y-4">
                    <div>
                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Password Saat Ini</label>
                        <input
                            type="password"
                            value={currentPassword}
                            onChange={(e) => setCurrentPassword(e.target.value)}
                            required
                            className={inputClass}
                            placeholder="••••••••"
                        />
                    </div>
                    <div>
                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Password Baru</label>
                        <input
                            type="password"
                            value={newPassword}
                            onChange={(e) => setNewPassword(e.target.value)}
                            required
                            minLength={8}
                            className={inputClass}
                            placeholder="Minimal 8 karakter"
                        />
                    </div>
                    <div>
                        <label className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Konfirmasi Password Baru</label>
                        <input
                            type="password"
                            value={confirmPassword}
                            onChange={(e) => setConfirmPassword(e.target.value)}
                            required
                            minLength={8}
                            className={inputClass}
                            placeholder="Ulangi password baru"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={passwordLoading}
                        className={`px-6 py-2.5 rounded-xl border text-sm font-bold disabled:opacity-50 transition-all ${
                            isDark
                                ? 'bg-white/[0.05] border-white/[0.08] text-gray-300 hover:bg-white/[0.08]'
                                : 'bg-gray-50 border-gray-300 text-gray-700 hover:bg-gray-100'
                        }`}
                    >
                        {passwordLoading ? 'Memperbarui...' : 'Ubah Password'}
                    </button>
                </form>
            </div>
        </div>
    );
}
