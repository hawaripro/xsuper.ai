import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import { useAuth } from '../contexts/AuthContext';
import AuthScaffold, { AuthAlert, GoogleAuthButton, authInputClass, authLabelClass } from '../components/AuthScaffold';

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '');
}

export default function Register() {
    const { theme } = useTheme();
    const { t, localizedPath } = useLocale();
    const { refreshUser } = useAuth();
    const isDark = theme === 'dark';
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        // A ?ref=CODE on the register link is remembered server-side; surface a hint and keep it on the Google button.
        if (searchParams.get('error') === 'disposable_email') {
            setError(t('Gunakan alamat email tetap. Email sekali pakai tidak diperbolehkan.'));
        }
    }, [searchParams, t]);

    const change = (key) => (e) => setForm((prev) => ({ ...prev, [key]: e.target.value }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setErrors({});
        setLoading(true);
        try {
            const r = await fetch('/register', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': getCsrfToken() },
                body: JSON.stringify(form),
            });
            const data = await r.json().catch(() => ({}));
            if (r.status === 422) {
                setErrors(data.errors || {});
                setError(data.message || t('Periksa kembali data Anda.'));
                return;
            }
            if (!r.ok) throw new Error(data.message || t('Pendaftaran gagal.'));
            await refreshUser();
            navigate(localizedPath('/dashboard'));
        } catch (err) {
            setError(err.message || t('Pendaftaran gagal.'));
        } finally {
            setLoading(false);
        }
    };

    const refCode = searchParams.get('ref');
    const fieldError = (key) => errors[key]?.[0] && <p className="mt-1.5 text-xs text-red-500">{errors[key][0]}</p>;

    return (
        <AuthScaffold
            title={t('Buat akun UltrAI')}
            subtitle={t('Daftar untuk mulai memakai workspace UltrAI')}
            footer={<span className={isDark ? 'text-gray-500' : 'text-gray-500'}>{t('Sudah punya akun?')} <Link to={localizedPath('/login')} className="font-semibold text-red-500 hover:text-red-600">{t('Masuk')}</Link></span>}
        >
            <form onSubmit={handleSubmit} noValidate>
                {error && <AuthAlert isDark={isDark}>{error}</AuthAlert>}
                {refCode && <div className={`mb-5 rounded-xl border p-3 text-xs ${isDark ? 'border-emerald-500/25 bg-emerald-500/5 text-emerald-300' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}>{t('Anda diundang lewat kode referral')} <strong className="font-mono">{refCode}</strong>. {t('Bonus berlaku setelah pembelian pertama disetujui.')}</div>}

                <div className="mb-4">
                    <label htmlFor="reg-name" className={authLabelClass(isDark)}>{t('Nama lengkap')}</label>
                    <input id="reg-name" type="text" required autoFocus autoComplete="name" value={form.name} onChange={change('name')} className={authInputClass(isDark)} placeholder={t('Nama Anda')} />
                    {fieldError('name')}
                </div>
                <div className="mb-4">
                    <label htmlFor="reg-email" className={authLabelClass(isDark)}>{t('Email')}</label>
                    <input id="reg-email" type="email" required autoComplete="email" value={form.email} onChange={change('email')} className={authInputClass(isDark)} placeholder="nama@email.com" />
                    {fieldError('email')}
                </div>
                <div className="mb-4">
                    <label htmlFor="reg-password" className={authLabelClass(isDark)}>{t('Password')}</label>
                    <input id="reg-password" type="password" required autoComplete="new-password" value={form.password} onChange={change('password')} className={authInputClass(isDark)} placeholder={t('Minimal 8 karakter')} />
                    {fieldError('password')}
                </div>
                <div className="mb-6">
                    <label htmlFor="reg-password2" className={authLabelClass(isDark)}>{t('Konfirmasi password')}</label>
                    <input id="reg-password2" type="password" required autoComplete="new-password" value={form.password_confirmation} onChange={change('password_confirmation')} className={authInputClass(isDark)} placeholder={t('Ulangi password')} />
                </div>

                <button type="submit" disabled={loading} className="ui-btn-primary w-full py-3.5 text-base">
                    {loading ? (
                        <><svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg><span>{t('Memproses...')}</span></>
                    ) : (
                        <span>{t('Daftar')}</span>
                    )}
                </button>

                <div className="relative my-6">
                    <div className="absolute inset-0 flex items-center"><div className={`w-full border-t ${isDark ? 'border-white/[0.08]' : 'border-gray-200'}`} /></div>
                    <div className="relative flex justify-center text-xs"><span className={`px-3 ${isDark ? 'bg-gray-900/85 text-gray-500' : 'bg-white/95 text-gray-400'}`}>{t('atau')}</span></div>
                </div>

                <GoogleAuthButton label={t('Daftar dengan Google')} intent="register" />

                <p className={`mt-5 text-center text-xs leading-5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{t('Akun baru tetap membutuhkan persetujuan perangkat sebelum fitur berbayar aktif.')}</p>
            </form>
        </AuthScaffold>
    );
}
