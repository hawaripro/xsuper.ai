import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import AuthScaffold, { AuthAlert, authInputClass, authLabelClass } from '../components/AuthScaffold';

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '');
}

export default function ForgotPassword() {
    const { theme } = useTheme();
    const { t, localizedPath } = useLocale();
    const isDark = theme === 'dark';
    const [email, setEmail] = useState('');
    const [status, setStatus] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setStatus('');
        setError('');
        setLoading(true);
        try {
            const r = await fetch('/forgot-password', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ email }),
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data?.errors?.email?.[0] || data.message || t('Tidak dapat mengirim tautan reset.'));
            setStatus(data.status || t('Tautan reset password telah dikirim ke email Anda jika terdaftar.'));
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <AuthScaffold
            title={t('Lupa password')}
            subtitle={t('Masukkan email Anda untuk menerima tautan reset')}
            footer={<Link to={localizedPath('/login')} className="font-semibold text-red-500 hover:text-red-600">{t('Kembali ke login')}</Link>}
        >
            <form onSubmit={handleSubmit} noValidate>
                {status && <AuthAlert tone="success" isDark={isDark}>{status}</AuthAlert>}
                {error && <AuthAlert isDark={isDark}>{error}</AuthAlert>}

                <div className="mb-6">
                    <label htmlFor="fp-email" className={authLabelClass(isDark)}>{t('Email')}</label>
                    <input id="fp-email" type="email" required autoFocus autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className={authInputClass(isDark)} placeholder="nama@email.com" />
                </div>

                <button type="submit" disabled={loading} className="ui-btn-primary w-full py-3.5 text-base">
                    {loading ? (
                        <><svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg><span>{t('Mengirim...')}</span></>
                    ) : (
                        <span>{t('Kirim tautan reset')}</span>
                    )}
                </button>
            </form>
        </AuthScaffold>
    );
}
