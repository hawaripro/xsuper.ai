import React, { useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import UltrLogo from '../components/UltrLogo';

export default function Login() {
    const { login, completeTwoFactor } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const { locale, t, localizedPath, otherLocalePath } = useLocale();
    const isDark = theme === 'dark';
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [challenge, setChallenge] = useState(false);
    const [useRecovery, setUseRecovery] = useState(false);
    const [otp, setOtp] = useState('');

    // Check for Google OAuth error from URL params
    React.useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const googleError = params.get('error');
        if (googleError === 'not_registered') {
            setError(t('Akun belum terdaftar. Hubungi admin untuk mendapatkan akses.'));
        } else if (googleError === 'google_failed') {
            setError(t('Login Google gagal. Silakan coba lagi.'));
        }
    }, [t]);

    const finish = () => {
        const redirect = searchParams.get('redirect');
        if (redirect === 'dash') {
            window.location.href = 'https://dash.ultrai.id';
            return;
        }
        navigate(localizedPath('/dashboard'));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            if (challenge) {
                await completeTwoFactor(useRecovery ? { recoveryCode: otp.trim() } : { code: otp.trim() });
                finish();
                return;
            }
            const result = await login(email, password);
            if (result && result.twoFactor) {
                setChallenge(true);
                setOtp('');
                return;
            }
            finish();
        } catch (err) {
            if (challenge && err.status === 419) {
                // The challenge session expired; start over with credentials.
                setChallenge(false);
                setOtp('');
            }
            setError(err.message || t('Login gagal. Periksa email dan password Anda.'));
        } finally {
            setLoading(false);
        }
    };

    const labelClass = `block text-xs font-bold uppercase tracking-[0.12em] mb-2 ${isDark ? 'text-gray-400' : 'text-slate-600'}`;
    const inputWrap  = 'relative group';
    const inputClass = `
        w-full px-4 py-3 rounded-xl text-sm transition-all duration-200
        ${isDark
            ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder-gray-600 focus:bg-white/[0.06]'
            : 'bg-gray-50/70 border border-gray-200 text-slate-900 placeholder-gray-400 focus:bg-white'
        }
        focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15
        hover:border-red-400/30
    `;

    return (
        <div className={`min-h-dvh flex items-center justify-center relative overflow-hidden px-4 py-10 ${isDark ? 'bg-[#030712]' : 'bg-[#fafbfc]'}`}>
            {/* =========================
                Background
               ========================= */}
            <div className="absolute inset-0 -z-10">
                <div
                    className={`absolute top-[-20%] right-[-15%] w-[560px] h-[560px] rounded-full blur-[120px] animate-aurora ${isDark ? 'bg-red-500/20' : 'bg-red-200/50'}`}
                />
                <div
                    className={`absolute bottom-[-20%] left-[-10%] w-[480px] h-[480px] rounded-full blur-[120px] animate-aurora ${isDark ? 'bg-orange-500/15' : 'bg-orange-200/40'}`}
                    style={{ animationDelay: '3s' }}
                />
                <div className="absolute inset-0 hero-dots opacity-60" />
                <div className="absolute inset-0 ui-grid-bg opacity-60" />
            </div>

            <Link to={otherLocalePath(location.pathname)} className={`absolute right-16 top-5 z-20 grid h-9 min-w-9 place-items-center rounded-xl border px-2 text-[11px] font-bold ${isDark ? 'border-white/10 text-slate-300 hover:bg-white/10' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100'}`} aria-label={locale === 'en' ? 'Ganti ke bahasa Indonesia' : 'Switch to English'}>{locale === 'en' ? 'ID' : 'EN'}</Link>
            {/* Theme toggle floating */}
            <button
                onClick={toggleTheme}
                className={`absolute top-5 right-5 p-2.5 rounded-xl backdrop-blur-xl border transition-all duration-200 z-10 ${
                    isDark
                        ? 'bg-white/5 border-white/10 text-gray-300 hover:bg-white/10 hover:text-white'
                        : 'bg-white/80 border-gray-200 text-gray-500 hover:bg-white hover:text-red-500 hover:shadow-md'
                }`}
                aria-label="Toggle theme"
            >
                {isDark ? (
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="4" /><line x1="12" y1="2" x2="12" y2="4" /><line x1="12" y1="20" x2="12" y2="22" /><line x1="4.93" y1="4.93" x2="6.34" y2="6.34" /><line x1="17.66" y1="17.66" x2="19.07" y2="19.07" /><line x1="2" y1="12" x2="4" y2="12" /><line x1="20" y1="12" x2="22" y2="12" /><line x1="4.93" y1="19.07" x2="6.34" y2="17.66" /><line x1="17.66" y1="6.34" x2="19.07" y2="4.93" /></svg>
                ) : (
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /></svg>
                )}
            </button>

            <div className="w-full max-w-[440px] animate-fade-in-up">
                {/* Logo */}
                <div className="text-center mb-8 animate-fade-in-down">
                    <Link to={locale === 'en' ? '/en' : '/'} className="inline-flex items-center gap-2.5 mb-4 group">
                        <UltrLogo className="w-11 h-11 group-hover:scale-105 group-hover:-rotate-3 transition-transform duration-300" />
                        <span className="text-3xl font-black tracking-tight flex items-center gap-[2px]">
                            <span className={isDark ? 'text-white' : 'text-slate-900'}>Ultr</span>
                            <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">AI</span>
                        </span>
                    </Link>
                    <h1 className={`text-xl font-bold ${isDark ? 'text-white' : 'text-slate-900'}`}>{t('Selamat Datang Kembali')}</h1>
                    <p className={`mt-1 text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t('Masuk untuk mengakses dashboard UltrAI')}</p>
                </div>

                {/* Card */}
                <div className="relative animate-scale-in">
                    {/* Glow */}
                    <div className={`absolute -inset-1 rounded-3xl blur-2xl transition-opacity ${
                        isDark
                            ? 'bg-gradient-to-r from-red-500/25 via-orange-500/15 to-red-500/25 opacity-70'
                            : 'bg-gradient-to-r from-red-300/40 via-orange-200/30 to-red-300/40 opacity-80'
                    }`} />

                    <form
                        onSubmit={handleSubmit}
                        className={`relative backdrop-blur-2xl border rounded-2xl p-7 sm:p-8 shadow-2xl ${
                            isDark
                                ? 'bg-gray-900/85 border-white/[0.08]'
                                : 'bg-white/95 border-gray-200/80 shadow-[0_32px_64px_-16px_rgba(15,23,42,0.15)]'
                        }`}
                    >
                        {/* Error */}
                        {error && (
                            <div
                                role="alert"
                                aria-live="polite"
                                className={`mb-5 p-3.5 rounded-xl border text-sm font-medium flex items-start gap-2.5 animate-shake ${
                                    isDark
                                        ? 'bg-red-500/10 border-red-500/25 text-red-300'
                                        : 'bg-red-50 border-red-200 text-red-700'
                                }`}
                            >
                                <svg className="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                                <span className="flex-1">{error}</span>
                            </div>
                        )}

                        {challenge ? (
                            <div className="mb-6">
                                <p className={`mb-4 text-sm leading-6 ${isDark ? 'text-gray-300' : 'text-slate-600'}`}>
                                    {t(useRecovery ? 'Masukkan salah satu kode pemulihan yang Anda simpan saat mengaktifkan autentikasi dua langkah.' : 'Akun ini dilindungi autentikasi dua langkah. Masukkan kode 6 digit dari aplikasi authenticator Anda.')}
                                </p>
                                <label htmlFor="login-otp" className={labelClass}>{t(useRecovery ? 'Kode pemulihan' : 'Kode autentikasi')}</label>
                                <input
                                    id="login-otp"
                                    type="text"
                                    inputMode={useRecovery ? 'text' : 'numeric'}
                                    autoComplete="one-time-code"
                                    value={otp}
                                    onChange={(e) => setOtp(e.target.value)}
                                    placeholder={useRecovery ? 'xxxxx-xxxxx' : '123456'}
                                    required
                                    autoFocus
                                    className={`${inputClass} tracking-[0.2em] font-mono`}
                                />
                                <div className="mt-3 flex flex-wrap justify-between gap-2 text-xs">
                                    <button type="button" className={`font-medium ${isDark ? 'text-gray-400 hover:text-white' : 'text-slate-500 hover:text-red-600'}`} onClick={() => { setUseRecovery((value) => !value); setOtp(''); setError(''); }}>
                                        {t(useRecovery ? 'Gunakan kode authenticator' : 'Gunakan kode pemulihan')}
                                    </button>
                                    <button type="button" className={`font-medium ${isDark ? 'text-gray-400 hover:text-white' : 'text-slate-500 hover:text-red-600'}`} onClick={() => { setChallenge(false); setUseRecovery(false); setOtp(''); setError(''); }}>
                                        {t('Kembali ke login')}
                                    </button>
                                </div>
                            </div>
                        ) : (
                        <>
                        {/* Email */}
                        <div className="mb-5">
                            <label htmlFor="login-email" className={labelClass}>{t('Email')}</label>
                            <div className={inputWrap}>
                                <svg className={`absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors ${isDark ? 'text-gray-500 group-focus-within:text-red-400' : 'text-gray-400 group-focus-within:text-red-500'}`} fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                    <polyline points="22,6 12,13 2,6" />
                                </svg>
                                <input
                                    id="login-email"
                                    type="email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    placeholder="nama@email.com"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    className={`${inputClass} pl-10`}
                                />
                            </div>
                        </div>

                        {/* Password */}
                        <div className="mb-6">
                            <label htmlFor="login-password" className={labelClass}>{t('Password')}</label>
                            <div className={inputWrap}>
                                <svg className={`absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors ${isDark ? 'text-gray-500 group-focus-within:text-red-400' : 'text-gray-400 group-focus-within:text-red-500'}`} fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg>
                                <input
                                    id="login-password"
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    placeholder="••••••••"
                                    required
                                    autoComplete="current-password"
                                    className={`${inputClass} pl-10 pr-11`}
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className={`absolute right-2 top-1/2 -translate-y-1/2 p-2 rounded-lg transition-colors ${
                                        isDark
                                            ? 'text-gray-500 hover:text-gray-200 hover:bg-white/5'
                                            : 'text-gray-400 hover:text-red-500 hover:bg-red-50'
                                    }`}
                                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                                >
                                    {showPassword ? (
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
                                    ) : (
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                                    )}
                                </button>
                            </div>
                        </div>
                        <div className="-mt-3 mb-2 text-right">
                            <Link to={localizedPath('/forgot-password')} className={`text-xs font-semibold ${isDark ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-red-600'}`}>{t('Lupa password?')}</Link>
                        </div>
                        </>
                        )}

                        {/* Submit */}
                        <button
                            type="submit"
                            disabled={loading}
                            className="ui-btn-primary w-full py-3.5 text-base"
                        >
                            {loading ? (
                                <>
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                    <span>{t('Memproses...')}</span>
                                </>
                            ) : (
                                <>
                                    <span>{t(challenge ? 'Verifikasi' : 'Masuk')}</span>
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                                </>
                            )}
                        </button>

                        {!challenge && (<>
                        {/* Divider */}
                        <div className="relative my-6">
                            <div className="absolute inset-0 flex items-center">
                                <div className={`w-full border-t ${isDark ? 'border-white/[0.08]' : 'border-gray-200'}`} />
                            </div>
                            <div className="relative flex justify-center text-xs">
                                <span className={`px-3 ${isDark ? 'bg-gray-900/85 text-gray-500' : 'bg-white/95 text-gray-400'}`}>{t('atau')}</span>
                            </div>
                        </div>

                        {/* Google Login */}
                        <a
                            href="/auth/google"
                            className={`w-full inline-flex items-center justify-center gap-3 py-3 px-4 rounded-xl text-sm font-semibold border transition-all duration-200 hover:-translate-y-0.5 ${
                                isDark
                                    ? 'bg-white/[0.04] border-white/[0.08] text-white hover:bg-white/[0.08]'
                                    : 'bg-white border-gray-200 text-slate-700 hover:bg-gray-50 hover:border-gray-300 hover:shadow-md'
                            }`}
                        >
                            <svg className="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                            </svg>
                            {t('Masuk dengan Google')}
                        </a>
                        </>)}

                        {!challenge && (
                            <p className={`mt-6 text-center text-sm ${isDark ? 'text-gray-500' : 'text-gray-500'}`}>
                                {t('Belum punya akun?')} <Link to={localizedPath('/register')} className="font-semibold text-red-500 hover:text-red-600">{t('Daftar')}</Link>
                            </p>
                        )}

                        {/* Back */}
                        <div className="mt-6 text-center">
                            <Link
                                to={locale === 'en' ? '/en' : '/'}
                                className={`inline-flex items-center gap-1.5 text-sm font-medium transition-colors ${
                                    isDark ? 'text-gray-500 hover:text-gray-200' : 'text-gray-500 hover:text-red-500'
                                }`}
                            >
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                                {locale === 'en' ? 'Back to homepage' : 'Kembali ke beranda'}
                            </Link>
                        </div>
                    </form>
                </div>

                {/* Footer */}
                <p className={`text-center text-xs mt-8 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                    &copy; {new Date().getFullYear()} UltrAI. All rights reserved.
                </p>
            </div>
        </div>
    );
}
