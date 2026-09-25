import React, { useState, useEffect, useRef } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import { apiRequest } from '../lib/api';

/* ============================================================
   Email activation (OTP) — required before the account unlocks
   ============================================================ */
function EmailActivationSection({ isDark, card, head, muted }) {
    const { t } = useLocale();
    const { user, refreshUser } = useAuth();
    const [code, setCode] = useState('');
    const [status, setStatus] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState('');
    const [cooldown, setCooldown] = useState(0);

    useEffect(() => {
        if (cooldown <= 0) return undefined;
        const timer = setInterval(() => setCooldown(value => Math.max(0, value - 1)), 1000);
        return () => clearInterval(timer);
    }, [cooldown > 0]);

    if (!user || user.email_verified !== false) return null;

    const sendCode = async () => {
        setBusy('send');
        setError('');
        setStatus('');
        try {
            await apiRequest('/api/u/verify-email/send', { method: 'POST' });
            setStatus(t('Kode aktivasi dikirim. Periksa kotak masuk (dan folder spam) email Anda.'));
            setCooldown(60);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    const verify = async (event) => {
        event.preventDefault();
        setBusy('verify');
        setError('');
        try {
            await apiRequest('/api/u/verify-email', { method: 'POST', body: { code } });
            setStatus(t('Email berhasil diaktifkan. Semua fitur terbuka.'));
            await refreshUser();
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    return (
        <section className={`p-5 lg:p-6 rounded-2xl border-2 ${isDark ? 'border-amber-500/40 bg-amber-500/5' : 'border-amber-300 bg-amber-50'} animate-fade-in-up`} aria-labelledby="email-activation-title" data-email-activation>
            <h2 id="email-activation-title" className={`text-sm font-bold flex items-center gap-2 ${head}`}>
                <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center shadow-md">
                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" /></svg>
                </span>
                {t('Aktivasi email diperlukan')}
            </h2>
            <p className={`mt-2 text-xs leading-5 ${muted}`}>{t('Akun Anda belum aktif. Kirim kode ke')} <strong>{user.email}</strong> {t('lalu masukkan 6 digit kodenya di sini. Fitur lain terkunci sampai email aktif.')}</p>
            {status && <p role="status" className={`mt-3 rounded-xl border p-3 text-xs ${isDark ? 'border-emerald-500/25 bg-emerald-500/5 text-emerald-300' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}>{status}</p>}
            {error && <p role="alert" className="mt-3 rounded-xl border border-red-500/25 bg-red-500/5 p-3 text-xs text-red-700 dark:text-red-300">{error}</p>}
            <form onSubmit={verify} className="mt-4 flex flex-wrap items-end gap-3">
                <div className="min-w-40 flex-1">
                    <label htmlFor="email-otp" className={`block text-xs font-bold uppercase tracking-[0.12em] mb-2 ${isDark ? 'text-gray-400' : 'text-slate-600'}`}>{t('Kode aktivasi')}</label>
                    <input id="email-otp" inputMode="numeric" autoComplete="one-time-code" maxLength={6} required value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))} className={`w-full px-4 py-3 rounded-xl font-mono text-sm tracking-[0.3em] ${isDark ? 'bg-white/[0.04] border border-white/[0.08] text-white' : 'bg-white border border-gray-200 text-slate-900'} focus:outline-none focus:border-amber-500/60 focus:ring-4 focus:ring-amber-500/15`} placeholder="123456" />
                </div>
                <button type="submit" className="ui-btn-primary min-h-11 px-5" disabled={busy !== '' || code.length !== 6}>{busy === 'verify' ? t('Memeriksa…') : t('Aktifkan')}</button>
                <button type="button" className="ui-btn-ghost min-h-11" disabled={busy !== '' || cooldown > 0} onClick={sendCode}>{busy === 'send' ? t('Mengirim…') : cooldown > 0 ? `${t('Kirim ulang')} (${cooldown}s)` : t('Kirim kode')}</button>
            </form>
        </section>
    );
}

/* ============================================================
   Two-factor authentication (TOTP) enrolment and management
   ============================================================ */
function TwoFactorSection({ isDark, card, head, muted }) {
    const { t } = useLocale();
    const [security, setSecurity] = useState(null);
    const [error, setError] = useState('');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState(null);
    const [mode, setMode] = useState('idle'); // idle | enable | disable | regenerate

    const load = async () => {
        try {
            const data = await apiRequest('/api/u/security');
            setSecurity(data.two_factor);
        } catch (requestError) {
            setError(requestError.message);
        }
    };
    useEffect(() => { load(); }, []);

    const run = async (action, request) => {
        setBusy(action);
        setError('');
        try {
            const data = await request();
            setSecurity(data.two_factor);
            if (Array.isArray(data.recovery_codes)) setRecoveryCodes(data.recovery_codes);
            setPassword('');
            setCode('');
            return true;
        } catch (requestError) {
            setError(requestError.message);
            return false;
        } finally {
            setBusy('');
        }
    };

    const start = async (event) => {
        event.preventDefault();
        if (mode === 'enable') {
            await run('enable', () => apiRequest('/api/u/security/two-factor', { method: 'POST', body: { password } }));
        } else if (mode === 'disable') {
            if (await run('disable', () => apiRequest('/api/u/security/two-factor', { method: 'DELETE', body: { password } }))) { setMode('idle'); setRecoveryCodes(null); }
        } else if (mode === 'regenerate') {
            if (await run('regenerate', () => apiRequest('/api/u/security/two-factor/recovery-codes', { method: 'POST', body: { password } }))) setMode('idle');
        }
    };

    const confirm = async (event) => {
        event.preventDefault();
        if (await run('confirm', () => apiRequest('/api/u/security/two-factor/confirm', { method: 'POST', body: { code } }))) setMode('idle');
    };

    const inputClass = `w-full px-4 py-3 rounded-xl text-sm transition-all duration-200 ${isDark ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder-gray-600' : 'bg-gray-50/70 border border-gray-200 text-slate-900 placeholder-gray-400'} focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15`;
    const labelClass = `block text-xs font-bold uppercase tracking-[0.12em] mb-2 ${isDark ? 'text-gray-400' : 'text-slate-600'}`;
    const enabled = !!security?.enabled;
    const pending = !!security?.pending;

    return (
        <section className={`p-5 lg:p-6 rounded-2xl border ${card} animate-fade-in-up`} aria-labelledby="two-factor-title" data-two-factor>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="two-factor-title" className={`text-sm font-bold flex items-center gap-2 ${head}`}>
                        <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 text-white flex items-center justify-center shadow-md">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="m9 12 2 2 4-4" /></svg>
                        </span>
                        {t("Autentikasi dua langkah")}
                    </h2>
                    <p className={`mt-2 text-xs leading-5 ${muted}`}>{t("Lindungi akun dengan kode dari aplikasi authenticator (Google Authenticator, Authy, 1Password) setiap kali masuk.")}</p>
                </div>
                {security && (
                    <span className={`ui-status ${enabled ? 'ui-status-good' : pending ? 'ui-status-warn' : 'ui-status-neutral'}`}>
                        {t(enabled ? 'Aktif' : pending ? 'Menunggu konfirmasi' : 'Nonaktif')}
                    </span>
                )}
            </div>

            {error && <p role="alert" className="mt-3 rounded-xl border border-red-500/25 bg-red-500/5 p-3 text-xs text-red-700 dark:text-red-300">{error}</p>}

            {security && !pending && mode === 'idle' && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {enabled ? (
                        <>
                            <button type="button" className="ui-btn-ghost" onClick={() => setMode('regenerate')}>{t("Buat ulang kode pemulihan")}</button>
                            <button type="button" className="ui-btn-ghost text-red-600 dark:text-red-300" onClick={() => setMode('disable')}>{t("Nonaktifkan")}</button>
                        </>
                    ) : (
                        <button type="button" className="ui-btn-ghost" onClick={() => setMode('enable')}>{t("Aktifkan dua langkah")}</button>
                    )}
                    {enabled && <span className={`self-center text-xs ${muted}`}>{security.recovery_codes_remaining} {t("kode pemulihan tersisa")}</span>}
                </div>
            )}

            {security && !pending && mode !== 'idle' && (
                <form onSubmit={start} className="mt-4 space-y-3">
                    <label htmlFor="two-factor-password" className={labelClass}>{t("Konfirmasi password Anda")}</label>
                    <input id="two-factor-password" type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} className={inputClass} placeholder="••••••••" />
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="ui-btn-ghost" disabled={!!busy}>
                            {busy ? t("Memproses...") : t(mode === 'enable' ? 'Lanjutkan' : mode === 'disable' ? 'Nonaktifkan dua langkah' : 'Buat ulang kode')}
                        </button>
                        <button type="button" className="ui-btn-ghost" onClick={() => { setMode('idle'); setPassword(''); setError(''); }}>{t("Batal")}</button>
                    </div>
                </form>
            )}

            {pending && (
                <form onSubmit={confirm} className="mt-4 grid gap-4 md:grid-cols-[176px_minmax(0,1fr)]">
                    {/* Fortify renders the QR with hard width/height="192". Tailwind's
                        preflight only constrains img/video, never svg, so it overflowed
                        this 176px track and painted over the column beside it. */}
                    <div className="qr-frame rounded-xl border border-slate-200 bg-white p-2 dark:border-white/10" aria-label={t("Kode QR authenticator")} dangerouslySetInnerHTML={{ __html: security.qr_svg }} />
                    <div className="space-y-3">
                        <p className={`text-xs leading-5 ${muted}`}>{t("Pindai kode QR dengan aplikasi authenticator, atau masukkan kunci ini secara manual, lalu ketik kode 6 digit yang muncul.")}</p>
                        <code className={`block break-all rounded-lg px-3 py-2 font-mono text-xs ${isDark ? 'bg-white/5 text-gray-200' : 'bg-gray-100 text-slate-700'}`}>{security.secret}</code>
                        <label htmlFor="two-factor-code" className={labelClass}>{t("Kode autentikasi")}</label>
                        <input id="two-factor-code" inputMode="numeric" autoComplete="one-time-code" required value={code} onChange={(event) => setCode(event.target.value)} className={`${inputClass} font-mono tracking-[0.2em]`} placeholder="123456" />
                        <div className="flex flex-wrap gap-2">
                            <button type="submit" className="ui-btn-ghost" disabled={!!busy}>{busy ? t("Memproses...") : t("Konfirmasi & aktifkan")}</button>
                            <button type="button" className="ui-btn-ghost" disabled={!!busy} onClick={() => { setMode('disable'); }}>{t("Batalkan pengaktifan")}</button>
                        </div>
                        {mode === 'disable' && (
                            <div className="space-y-2">
                                <input type="password" autoComplete="current-password" aria-label={t("Konfirmasi password Anda")} value={password} onChange={(event) => setPassword(event.target.value)} className={inputClass} placeholder={t("Password untuk membatalkan")} />
                                <button type="button" className="ui-btn-ghost" disabled={!!busy || !password} onClick={() => run('disable', () => apiRequest('/api/u/security/two-factor', { method: 'DELETE', body: { password } })).then((ok) => ok && setMode('idle'))}>{t("Konfirmasi pembatalan")}</button>
                            </div>
                        )}
                    </div>
                </form>
            )}

            {recoveryCodes && (
                <div className={`mt-4 rounded-xl border p-4 ${isDark ? 'border-amber-500/30 bg-amber-500/5' : 'border-amber-200 bg-amber-50'}`} role="status">
                    <p className={`text-xs font-semibold ${isDark ? 'text-amber-200' : 'text-amber-800'}`}>{t("Simpan kode pemulihan ini di tempat aman. Setiap kode hanya bisa dipakai sekali dan tidak akan ditampilkan lagi.")}</p>
                    <ul className="mt-3 grid grid-cols-2 gap-1 font-mono text-xs sm:grid-cols-4">
                        {recoveryCodes.map((item) => <li key={item} className={`rounded-md px-2 py-1 ${isDark ? 'bg-black/30 text-gray-100' : 'bg-white text-slate-800'}`}>{item}</li>)}
                    </ul>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" className="ui-btn-ghost" onClick={() => navigator.clipboard?.writeText(recoveryCodes.join('\n'))}>{t("Salin kode")}</button>
                        <button type="button" className="ui-btn-ghost" onClick={() => setRecoveryCodes(null)}>{t("Sudah saya simpan")}</button>
                    </div>
                </div>
            )}
        </section>
    );
}

/* ============================================================
   Duration Countdown — shows remaining time for member
   For ≤5 days: shows HH:MM:SS countdown
   For >5 days: shows X hari Y jam
   ============================================================ */
function DurationCountdown({ user, isDark }) {
    const { t } = useLocale();
    const [now, setNow] = useState(new Date());

    useEffect(() => {
        const timer = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    if (!user?.membership?.expires_at) {
        return (
            <div className={`mt-2 text-xs font-medium ${isDark ? 'text-emerald-400' : 'text-emerald-600'}`}>
                {t("Belum berlangganan")}
            </div>
        );
    }

    const expiry = new Date(user.membership.expires_at);
    const diff = expiry - now;

    if (!user.membership.active || diff <= 0) {
        return (
            <div className={`mt-2 px-3 py-1.5 rounded-lg text-xs font-bold ${isDark ? 'bg-red-500/15 text-red-400 border border-red-500/20' : 'bg-red-50 text-red-600 border border-red-200'}`}>
                {t("Langganan berakhir. Saldo dan token tetap dapat dipakai.")}
            </div>
        );
    }

    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

    // For ≤5 days: show countdown timer (HH:MM:SS)
    const isUrgent = days <= 5;
    const pad = (n) => String(n).padStart(2, '0');

    let display;
    if (days === 0) {
        display = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    } else if (isUrgent) {
        display = `${days} ${t("hari")} ${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    } else {
        display = `${days} ${t("hari")} ${hours} ${t("jam")}`;
    }

    const colorClass = days <= 1
        ? (isDark ? 'bg-red-500/15 text-red-400 border-red-500/20' : 'bg-red-50 text-red-600 border-red-200')
        : days <= 5
            ? (isDark ? 'bg-amber-500/15 text-amber-400 border-amber-500/20' : 'bg-amber-50 text-amber-600 border-amber-200')
            : (isDark ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20' : 'bg-emerald-50 text-emerald-600 border-emerald-200');

    return (
        <div className={`mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border ${colorClass}`}>
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
            </svg>
            {display}
        </div>
    );
}

/* ============================================================
   Small reusable field input
   ============================================================ */
function Field({ label, icon, type = 'text', value, onChange, required, minLength, placeholder, autoComplete, isDark, id }) {
    const [focused, setFocused] = useState(false);

    const inputClass = `
        w-full py-3 rounded-xl text-sm transition-all duration-200
        ${icon ? 'pl-10 pr-4' : 'px-4'}
        ${isDark
            ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder-gray-600 focus:bg-white/[0.06]'
            : 'bg-gray-50/70 border border-gray-200 text-slate-900 placeholder-gray-400 focus:bg-white'
        }
        focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15
        hover:border-red-400/30
    `;

    return (
        <div>
            <label
                htmlFor={id}
                className={`block text-xs font-bold uppercase tracking-[0.12em] mb-2 ${isDark ? 'text-gray-400' : 'text-slate-600'}`}
            >
                {label}
            </label>
            <div className="relative">
                {icon && (
                    <span className={`absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors ${
                        focused
                            ? 'text-red-500'
                            : isDark ? 'text-gray-500' : 'text-gray-400'
                    }`}>
                        {icon}
                    </span>
                )}
                <input
                    id={id}
                    type={type}
                    value={value}
                    onChange={onChange}
                    required={required}
                    minLength={minLength}
                    placeholder={placeholder}
                    autoComplete={autoComplete}
                    onFocus={() => setFocused(true)}
                    onBlur={() => setFocused(false)}
                    className={inputClass}
                />
            </div>
        </div>
    );
}

/* ============================================================
   Alert / feedback message
   ============================================================ */
function Alert({ type, text, isDark }) {
    if (!text) return null;
    const isSuccess = type === 'success';
    const styles = isSuccess
        ? (isDark
            ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-300'
            : 'bg-emerald-50 border-emerald-200 text-emerald-700')
        : (isDark
            ? 'bg-red-500/10 border-red-500/25 text-red-300'
            : 'bg-red-50 border-red-200 text-red-700');

    return (
        <div
            role={isSuccess ? 'status' : 'alert'}
            aria-live="polite"
            className={`mb-4 p-3.5 rounded-xl border text-sm font-medium flex items-start gap-2.5 animate-fade-in-down ${styles}`}
        >
            {isSuccess ? (
                <svg className="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                    <polyline points="20 6 9 17 4 12" />
                </svg>
            ) : (
                <svg className="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
            )}
            <span className="flex-1">{text}</span>
        </div>
    );
}

export default function Profile() {
    const { t } = useLocale();
    const { user, refreshUser, syncMembership } = useAuth();

    // Membership can change after login (order approval, expiry, admin edit); show the server's current state.
    useEffect(() => {
        let active = true;
        apiRequest('/api/user').then((data) => { if (active) syncMembership(data?.membership); }).catch(() => {});
        return () => { active = false; };
    }, [syncMembership]);

    const { theme } = useTheme();
    const isDark = theme === 'dark';

    const [name, setName] = useState(user?.name || '');
    const [email, setEmail] = useState('');
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [profileMsg, setProfileMsg] = useState({ type: '', text: '' });
    const [passwordMsg, setPasswordMsg] = useState({ type: '', text: '' });
    const [profileLoading, setProfileLoading] = useState(false);
    const [passwordLoading, setPasswordLoading] = useState(false);
    const editedFields = useRef({ name: false, email: false });

    useEffect(() => {
        const loadProfile = async () => {
            try {
                const res = await fetch('/api/u/me', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (!editedFields.current.email) setEmail(data.email || '');
                    if (!editedFields.current.name) setName(data.name || user?.name || '');
                }
            } catch {}
        };
        loadProfile();
    }, []);

    const getCsrfToken = () =>
        decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');

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
            setPasswordMsg({ type: 'error', text: 'Password baru tidak cocok.' });
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

    const card = isDark
        ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl'
        : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]';

    const head = isDark ? 'text-white' : 'text-slate-900';
    const muted = isDark ? 'text-gray-400' : 'text-gray-500';
    const isAdmin = user?.role === 'admin';

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-3xl mx-auto">
            <EmailActivationSection isDark={isDark} card={card} head={head} muted={muted} />
            {/* Header */}
            <div className="animate-fade-in-up">
                <h1 className={`text-2xl lg:text-3xl font-extrabold tracking-tight ${head}`}>{t("Profil Saya")}</h1>
                <p className={`text-sm mt-1 ${muted}`}>{t("Kelola informasi akun dan keamanan Anda.")}</p>
            </div>

            {/* Profile summary */}
            <section className={`relative overflow-hidden p-5 lg:p-6 rounded-2xl border ${card} animate-fade-in-up`}>
                <div className="absolute -top-20 -right-20 w-60 h-60 rounded-full bg-red-500/10 blur-[80px] pointer-events-none" />

                <div className="relative flex flex-col sm:flex-row sm:items-center gap-5 pb-5 border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}">
                    <div className="relative flex-shrink-0">
                        <div className="relative w-20 h-20 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white text-3xl font-black shadow-[0_12px_32px_-8px_rgba(239,68,68,0.5)]">
                            {user?.name?.[0]?.toUpperCase() || 'U'}
                            <span className="absolute inset-0 rounded-2xl ring-1 ring-white/30 pointer-events-none" />
                        </div>
                        <span className="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-emerald-500 border-4 border-white dark:border-gray-900 flex items-center justify-center">
                            <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" strokeWidth="3" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
                        </span>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className={`text-xl font-bold truncate ${head}`}>{user?.name || '—'}</div>
                        <div className={`text-sm truncate ${muted}`}>{user?.email || email || '—'}</div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider border ${
                                isAdmin
                                    ? (isDark ? 'bg-red-500/15 text-red-300 border-red-500/25' : 'bg-red-50 text-red-600 border-red-200')
                                    : (isDark ? 'bg-blue-500/15 text-blue-300 border-blue-500/25' : 'bg-blue-50 text-blue-600 border-blue-200')
                            }`}>
                                <svg className="w-3 h-3" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>
                                {user?.role || 'member'}
                            </span>
                            <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-semibold border ${
                                isDark ? 'bg-white/[0.04] text-gray-300 border-white/[0.08]' : 'bg-gray-100 text-gray-600 border-gray-200'
                            }`}>
                                <span className="relative flex w-1.5 h-1.5">
                                    <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-70 animate-ping" />
                                    <span className="relative inline-flex w-1.5 h-1.5 rounded-full bg-emerald-500" />
                                </span>
                                {t("Akun aktif")}
                            </span>
                        </div>
                        {/* Duration countdown */}
                        {!isAdmin && <DurationCountdown user={user} isDark={isDark} />}
                    </div>
                </div>

                {/* Profile form */}
                <form onSubmit={handleProfileUpdate} className="relative pt-5 space-y-4">
                    <h2 className={`text-sm font-bold flex items-center gap-2 ${head}`}>
                        <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center shadow-md">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                        </span>
                        {t("Informasi Profil")}
                    </h2>

                    <Alert type={profileMsg.type} text={profileMsg.text} isDark={isDark} />

                    <Field
                        id="profile-name"
                        label={t("Nama Lengkap")}
                        value={name}
                        onChange={(e) => { editedFields.current.name = true; setName(e.target.value); }}
                        required
                        autoComplete="name"
                        isDark={isDark}
                        icon={<svg fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>}
                    />

                    <Field
                        id="profile-email"
                        label={t("Email")}
                        type="email"
                        value={email}
                        onChange={(e) => { editedFields.current.email = true; setEmail(e.target.value); }}
                        required
                        autoComplete="email"
                        isDark={isDark}
                        icon={<svg fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" /></svg>}
                    />

                    <div className="pt-2">
                        <button
                            type="submit"
                            disabled={profileLoading}
                            className="ui-btn-primary"
                        >
                            {profileLoading ? (
                                <>
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                    {t("Menyimpan...")}
                                </>
                            ) : (
                                <>
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
                                    {t("Simpan Perubahan")}
                                </>
                            )}
                        </button>
                    </div>
                </form>
            </section>

            {/* Password section */}
            <section className={`p-5 lg:p-6 rounded-2xl border ${card} animate-fade-in-up`}>
                <form onSubmit={handlePasswordUpdate} className="space-y-4">
                    <h2 className={`text-sm font-bold flex items-center gap-2 ${head}`}>
                        <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center shadow-md">
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                        </span>
                        {t("Ubah Password")}
                    </h2>

                    <Alert type={passwordMsg.type} text={passwordMsg.text} isDark={isDark} />

                    <Field
                        id="current-password"
                        label={t("Password Saat Ini")}
                        type="password"
                        value={currentPassword}
                        onChange={(e) => setCurrentPassword(e.target.value)}
                        required
                        placeholder="••••••••"
                        autoComplete="current-password"
                        isDark={isDark}
                        icon={<svg fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>}
                    />
                    <Field
                        id="new-password"
                        label={t("Password Baru")}
                        type="password"
                        value={newPassword}
                        onChange={(e) => setNewPassword(e.target.value)}
                        required
                        minLength={8}
                        placeholder={t("Minimal 8 karakter")}
                        autoComplete="new-password"
                        isDark={isDark}
                        icon={<svg fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M12 15v2" /><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>}
                    />
                    <Field
                        id="confirm-password"
                        label={t("Konfirmasi Password Baru")}
                        type="password"
                        value={confirmPassword}
                        onChange={(e) => setConfirmPassword(e.target.value)}
                        required
                        minLength={8}
                        placeholder={t("Ulangi password baru")}
                        autoComplete="new-password"
                        isDark={isDark}
                        icon={<svg fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>}
                    />

                    <div className="pt-2">
                        <button
                            type="submit"
                            disabled={passwordLoading}
                            className="ui-btn-ghost"
                        >
                            {passwordLoading ? (
                                <>
                                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                    {t("Memperbarui...")}
                                </>
                            ) : (
                                <>
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M12 15v2" /><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                                    {t("Ubah Password")}
                                </>
                            )}
                        </button>
                    </div>
                </form>
            </section>

            <TwoFactorSection isDark={isDark} card={card} head={head} muted={muted} />
        </div>
    );
}
