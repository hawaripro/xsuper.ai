import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import AuthScaffold, { AuthAlert, authInputClass, authLabelClass } from '../components/AuthScaffold';

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '');
}

export default function ResetPassword() {
    const { theme } = useTheme();
    const { t, localizedPath } = useLocale();
    const isDark = theme === 'dark';
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const token = searchParams.get('token') || '';
    const [email, setEmail] = useState(searchParams.get('email') || '');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setErrors({});
        setLoading(true);
        try {
            const r = await fetch('/reset-password', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify({ token, email, password, password_confirmation: confirm }),
            });
            const data = await r.json().catch(() => ({}));
            if (r.status === 422) {
                setErrors(data.errors || {});
                setError(data.message || t('Periksa kembali data Anda.'));
                return;
            }
            if (!r.ok) throw new Error(data.message || t('Reset password gagal.'));
            setDone(true);
            setTimeout(() => navigate(localizedPath('/login')), 1800);
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    const fieldError = (key) => errors[key]?.[0] && <p className="mt-1.5 text-xs text-red-500">{errors[key][0]}</p>;

    if (!token) {
        return (
            <AuthScaffold title={t('Tautan tidak valid')} subtitle={t('Tautan reset password tidak lengkap atau sudah kedaluwarsa.')} footer={<Link to={localizedPath('/forgot-password')} className="font-semibold text-red-500 hover:text-red-600">{t('Minta tautan baru')}</Link>}>
            </AuthScaffold>
        );
    }

    return (
        <AuthScaffold
            title={t('Atur ulang password')}
            subtitle={t('Buat password baru untuk akun Anda')}
            footer={<Link to={localizedPath('/login')} className="font-semibold text-red-500 hover:text-red-600">{t('Kembali ke login')}</Link>}
        >
            <form onSubmit={handleSubmit} noValidate>
                {done && <AuthAlert tone="success" isDark={isDark}>{t('Password berhasil diperbarui. Mengalihkan ke login...')}</AuthAlert>}
                {error && <AuthAlert isDark={isDark}>{error}</AuthAlert>}

                <div className="mb-4">
                    <label htmlFor="rp-email" className={authLabelClass(isDark)}>{t('Email')}</label>
                    <input id="rp-email" type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className={authInputClass(isDark)} placeholder="nama@email.com" />
                    {fieldError('email')}
                </div>
                <div className="mb-4">
                    <label htmlFor="rp-password" className={authLabelClass(isDark)}>{t('Password baru')}</label>
                    <input id="rp-password" type="password" required autoFocus autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} className={authInputClass(isDark)} placeholder={t('Minimal 8 karakter')} />
                    {fieldError('password')}
                </div>
                <div className="mb-6">
                    <label htmlFor="rp-password2" className={authLabelClass(isDark)}>{t('Konfirmasi password baru')}</label>
                    <input id="rp-password2" type="password" required autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} className={authInputClass(isDark)} placeholder={t('Ulangi password baru')} />
                </div>

                <button type="submit" disabled={loading || done} className="ui-btn-primary w-full py-3.5 text-base">
                    {loading ? (
                        <><svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg><span>{t('Menyimpan...')}</span></>
                    ) : (
                        <span>{t('Simpan password baru')}</span>
                    )}
                </button>
            </form>
        </AuthScaffold>
    );
}
