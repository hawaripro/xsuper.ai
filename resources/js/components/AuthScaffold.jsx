import { Link, useLocation } from 'react-router-dom';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import UltrLogo from './UltrLogo';

/**
 * Shared chrome for guest auth pages (login, register, forgot/reset password):
 * animated background, language + theme toggles, brand mark, heading, and the
 * card that wraps the page's form.
 */
export default function AuthScaffold({ title, subtitle, children, footer }) {
    const { theme, toggleTheme } = useTheme();
    const { locale, t, otherLocalePath } = useLocale();
    const location = useLocation();
    const isDark = theme === 'dark';

    return (
        <div className={`min-h-dvh flex items-center justify-center relative overflow-hidden px-4 py-10 ${isDark ? 'bg-[#030712]' : 'bg-[#fafbfc]'}`}>
            <div className="absolute inset-0 -z-10">
                <div className={`absolute top-[-20%] right-[-15%] w-[560px] h-[560px] rounded-full blur-[120px] animate-aurora ${isDark ? 'bg-red-500/20' : 'bg-red-200/50'}`} />
                <div className={`absolute bottom-[-20%] left-[-10%] w-[480px] h-[480px] rounded-full blur-[120px] animate-aurora ${isDark ? 'bg-orange-500/15' : 'bg-orange-200/40'}`} style={{ animationDelay: '3s' }} />
                <div className="absolute inset-0 hero-dots opacity-60" />
                <div className="absolute inset-0 ui-grid-bg opacity-60" />
            </div>

            <Link to={otherLocalePath(location.pathname)} className={`absolute right-16 top-5 z-20 grid h-9 min-w-9 place-items-center rounded-xl border px-2 text-[11px] font-bold ${isDark ? 'border-white/10 text-slate-300 hover:bg-white/10' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100'}`} aria-label={locale === 'en' ? 'Ganti ke bahasa Indonesia' : 'Switch to English'}>{locale === 'en' ? 'ID' : 'EN'}</Link>
            <button onClick={toggleTheme} className={`absolute top-5 right-5 p-2.5 rounded-xl backdrop-blur-xl border transition-all duration-200 z-10 ${isDark ? 'bg-white/5 border-white/10 text-gray-300 hover:bg-white/10 hover:text-white' : 'bg-white/80 border-gray-200 text-gray-500 hover:bg-white hover:text-red-500 hover:shadow-md'}`} aria-label="Toggle theme">
                {isDark ? (
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="4" /><line x1="12" y1="2" x2="12" y2="4" /><line x1="12" y1="20" x2="12" y2="22" /><line x1="4.93" y1="4.93" x2="6.34" y2="6.34" /><line x1="17.66" y1="17.66" x2="19.07" y2="19.07" /><line x1="2" y1="12" x2="4" y2="12" /><line x1="20" y1="12" x2="22" y2="12" /><line x1="4.93" y1="19.07" x2="6.34" y2="17.66" /><line x1="17.66" y1="6.34" x2="19.07" y2="4.93" /></svg>
                ) : (
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /></svg>
                )}
            </button>

            <div className="w-full max-w-[440px] animate-fade-in-up">
                <div className="text-center mb-8 animate-fade-in-down">
                    <Link to={locale === 'en' ? '/en' : '/'} className="inline-flex items-center gap-2.5 mb-4 group">
                        <UltrLogo className="w-11 h-11 group-hover:scale-105 group-hover:-rotate-3 transition-transform duration-300" />
                        <span className="text-3xl font-black tracking-tight flex items-center gap-[2px]">
                            <span className={isDark ? 'text-white' : 'text-slate-900'}>XSuper</span>
                            <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">.ai</span>
                        </span>
                    </Link>
                    <h1 className={`text-xl font-bold ${isDark ? 'text-white' : 'text-slate-900'}`}>{title}</h1>
                    {subtitle && <p className={`mt-1 text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{subtitle}</p>}
                </div>

                <div className={`relative backdrop-blur-2xl border rounded-2xl p-7 sm:p-8 shadow-2xl ${isDark ? 'bg-gray-900/85 border-white/[0.08]' : 'bg-white/95 border-gray-200/80 shadow-[0_32px_64px_-16px_rgba(15,23,42,0.15)]'}`}>
                    {children}
                </div>

                {footer && <div className="mt-6 text-center text-sm">{footer}</div>}
                <p className={`text-center text-xs mt-8 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                    &copy; {new Date().getFullYear()} XSuper.ai. {t('Semua hak dilindungi.')}
                </p>
            </div>
        </div>
    );
}

// Shared field classes so all auth forms match the Login visual language.
export const authLabelClass = (isDark) => `block text-xs font-bold uppercase tracking-[0.12em] mb-2 ${isDark ? 'text-gray-400' : 'text-slate-600'}`;
export const authInputClass = (isDark) => `w-full px-4 py-3 rounded-xl text-sm transition-all duration-200 ${isDark ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder-gray-600 focus:bg-white/[0.06]' : 'bg-gray-50/70 border border-gray-200 text-slate-900 placeholder-gray-400 focus:bg-white'} focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 hover:border-red-400/30`;

export function AuthAlert({ tone = 'error', children, isDark }) {
    const styles = tone === 'success'
        ? (isDark ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-300' : 'bg-emerald-50 border-emerald-200 text-emerald-700')
        : (isDark ? 'bg-red-500/10 border-red-500/25 text-red-300' : 'bg-red-50 border-red-200 text-red-700');
    return <div role={tone === 'success' ? 'status' : 'alert'} aria-live="polite" className={`mb-5 p-3.5 rounded-xl border text-sm font-medium ${styles}`}>{children}</div>;
}

export function GoogleAuthButton({ label, intent }) {
    const href = intent === 'register' ? '/auth/google?intent=register' : '/auth/google';
    return (
        <a href={href} className="w-full inline-flex items-center justify-center gap-3 py-3 px-4 rounded-xl text-sm font-semibold border transition-all duration-200 hover:-translate-y-0.5 bg-white border-gray-200 text-slate-700 hover:bg-gray-50 hover:border-gray-300 hover:shadow-md dark:bg-white/[0.04] dark:border-white/[0.08] dark:text-white dark:hover:bg-white/[0.08]">
            <svg className="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
            </svg>
            {label}
        </a>
    );
}
