import React, { useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useNavigate, useSearchParams } from 'react-router-dom';

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            await login(email, password);

            // If redirected from dash.ultrai.id, redirect back after login
            const redirect = searchParams.get('redirect');
            if (redirect === 'dash') {
                // Cookie dash_token sudah di-set oleh server saat login (admin only)
                window.location.href = 'https://dash.ultrai.id';
                return;
            }

            navigate('/dashboard');
        } catch (err) {
            setError(err.message || 'Login gagal. Periksa email dan password Anda.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-gray-950 flex items-center justify-center relative overflow-hidden px-4">
            {/* Background effects */}
            <div className="absolute inset-0 -z-10">
                <div className="absolute top-1/4 right-1/4 w-[500px] h-[500px] bg-red-500/[0.07] rounded-full blur-[120px] animate-blob" />
                <div className="absolute bottom-1/4 left-1/4 w-[400px] h-[400px] bg-red-600/[0.05] rounded-full blur-[100px] animate-blob-reverse" />
                <div className="absolute inset-0" style={{
                    backgroundImage: 'radial-gradient(circle, rgba(239,68,68,0.03) 1px, transparent 1px)',
                    backgroundSize: '32px 32px'
                }} />
            </div>

            <div className="w-full max-w-[420px]">
                {/* Logo */}
                <div className="text-center mb-8">
                    <a href="/" className="inline-flex items-center gap-[3px] text-3xl font-black tracking-tight mb-3">
                        <span className="text-white">Ultr</span>
                        <span className="bg-gradient-to-r from-red-500 to-red-400 bg-clip-text text-transparent">AI</span>
                    </a>
                    <p className="text-gray-500 text-sm">Masuk ke dashboard Anda</p>
                </div>

                {/* Card */}
                <div className="relative">
                    {/* Glow behind card */}
                    <div className="absolute -inset-1 bg-gradient-to-r from-red-500/20 via-transparent to-red-500/20 rounded-3xl blur-xl opacity-50" />

                    <form
                        onSubmit={handleSubmit}
                        className="relative bg-gray-900/80 backdrop-blur-2xl border border-white/[0.08] rounded-2xl p-8 shadow-2xl"
                    >
                        {/* Error */}
                        {error && (
                            <div className="mb-6 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm font-medium animate-shake">
                                {error}
                            </div>
                        )}

                        {/* Email */}
                        <div className="mb-5">
                            <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">
                                Email
                            </label>
                            <input
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="nama@email.com"
                                required
                                autoFocus
                                className="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                            />
                        </div>

                        {/* Password */}
                        <div className="mb-6">
                            <label className="block text-xs font-bold uppercase tracking-[0.1em] text-gray-400 mb-2">
                                Password
                            </label>
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    placeholder="••••••••"
                                    required
                                    className="w-full px-4 py-3 pr-12 rounded-xl bg-white/[0.05] border border-white/[0.08] text-white placeholder-gray-600 text-sm focus:outline-none focus:border-red-500/50 focus:ring-2 focus:ring-red-500/20 transition-all"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-gray-500 hover:text-gray-300 transition-colors"
                                >
                                    {showPassword ? (
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
                                    ) : (
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                                    )}
                                </button>
                            </div>
                        </div>

                        {/* Submit */}
                        <button
                            type="submit"
                            disabled={loading}
                            className="relative w-full py-3.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white font-bold text-sm hover:brightness-110 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg shadow-red-500/25 hover:shadow-red-500/40 overflow-hidden btn-shimmer"
                        >
                            {loading ? (
                                <span className="flex items-center justify-center gap-2">
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                    Memproses...
                                </span>
                            ) : 'Masuk'}
                        </button>

                        {/* Back to home */}
                        <div className="mt-6 text-center">
                            <a href="/" className="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                                ← Kembali ke beranda
                            </a>
                        </div>
                    </form>
                </div>

                {/* Footer */}
                <p className="text-center text-xs text-gray-600 mt-8">
                    &copy; 2026 UltrAI. All rights reserved.
                </p>
            </div>
        </div>
    );
}
