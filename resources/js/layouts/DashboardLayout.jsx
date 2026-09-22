import React, { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import { UltrLockup } from '../components/UltrLogo';
import AnnouncementRibbon from '../components/AnnouncementRibbon';
import DashboardSearch from '../components/dashboard/DashboardSearch';
import NotificationMenu from '../components/dashboard/NotificationMenu';
import Icons from './SidebarIcons';
import '../components/dashboard/dashboard-workspace.css';

const iconTones = {
    red: 'text-red-500 bg-red-500/10 group-hover:bg-red-500/15',
    emerald: 'text-emerald-600 bg-emerald-500/10 group-hover:bg-emerald-500/15 dark:text-emerald-400',
    blue: 'text-blue-500 bg-blue-500/10 group-hover:bg-blue-500/15',
    violet: 'text-violet-500 bg-violet-500/10 group-hover:bg-violet-500/15 dark:text-violet-400',
    fuchsia: 'text-fuchsia-600 bg-fuchsia-500/10 group-hover:bg-fuchsia-500/15 dark:text-fuchsia-400',
    amber: 'text-amber-600 bg-amber-500/10 group-hover:bg-amber-500/15 dark:text-amber-400',
    orange: 'text-orange-600 bg-orange-500/10 group-hover:bg-orange-500/15 dark:text-orange-400',
    cyan: 'text-cyan-600 bg-cyan-500/10 group-hover:bg-cyan-500/15 dark:text-cyan-400',
    pink: 'text-pink-500 bg-pink-500/10 group-hover:bg-pink-500/15',
    indigo: 'text-indigo-500 bg-indigo-500/10 group-hover:bg-indigo-500/15 dark:text-indigo-400',
    teal: 'text-teal-600 bg-teal-500/10 group-hover:bg-teal-500/15 dark:text-teal-400',
    slate: 'text-slate-500 bg-slate-500/10 group-hover:bg-slate-500/15 dark:text-slate-400',
};

export default function DashboardLayout({ children }) {
    const { user, logout } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const { locale, t, localizedPath, otherLocalePath } = useLocale();
    const location = useLocation();
    const navigate = useNavigate();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [isDesktop, setIsDesktop] = useState(() => window.matchMedia('(min-width: 1024px)').matches);
    const [loggingOut, setLoggingOut] = useState(false);
    const [logoutError, setLogoutError] = useState('');
    const sidebar = useRef(null);
    const menuButton = useRef(null);
    const shell = useRef(null);
    const ribbonWrap = useRef(null);

    // The ribbon owns the very top of the viewport; the topbar and sidebar stick
    // right below it. Its height is dynamic (absent without an announcement).
    useEffect(() => {
        const wrap = ribbonWrap.current;
        const host = shell.current;
        if (!wrap || !host) return;
        const apply = () => host.style.setProperty('--dw-ribbon-h', `${Math.round(wrap.getBoundingClientRect().height)}px`);
        apply();
        const observer = new ResizeObserver(apply);
        observer.observe(wrap);
        return () => observer.disconnect();
    }, []);

    useEffect(() => { setSidebarOpen(false); }, [location.key]);
    useEffect(() => {
        const query = window.matchMedia('(min-width: 1024px)');
        const update = () => { setIsDesktop(query.matches); if (query.matches) setSidebarOpen(false); };
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, []);
    useEffect(() => {
        if (!sidebarOpen || isDesktop) return;
        const previousOverflow = document.body.style.overflow;
        const previousFocus = document.activeElement;
        document.body.style.overflow = 'hidden';
        const firstControl = sidebar.current?.querySelector('a, button');
        firstControl?.focus({ preventScroll: true });
        const handleKey = event => {
            if (event.key === 'Escape') { event.preventDefault(); setSidebarOpen(false); }
            if (event.key !== 'Tab') return;
            const controls = [...(sidebar.current?.querySelectorAll('a[href], button:not(:disabled)') || [])].filter(element => element.getClientRects().length);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && (document.activeElement === first || !sidebar.current?.contains(document.activeElement))) { event.preventDefault(); last?.focus(); }
            if (!event.shiftKey && (document.activeElement === last || !sidebar.current?.contains(document.activeElement))) { event.preventDefault(); first?.focus(); }
        };
        document.addEventListener('keydown', handleKey);
        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', handleKey);
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
        };
    }, [sidebarOpen, isDesktop]);

    const isDark = theme === 'dark';
    const isAdmin = user?.role === 'admin';
    const perms = user?.permissions || {};
    const allowed = (permission, defaultValue = true) => isAdmin || (perms[permission] ?? defaultValue) === true;
    const hasChat = allowed('chat');
    const userNav = [
        { name: 'Overview', href: '/dashboard', icon: Icons.dashboard, tone: 'red' },
        ...(hasChat ? [{ name: 'Chat', href: '/chat', icon: Icons.chat, tone: 'emerald' }] : []),
        { name: 'Generate Gambar', href: '/generate-image', icon: Icons.image, tone: 'fuchsia' },
        ...(allowed('video_generator', false) ? [{ name: 'Generate Video', href: '/video', icon: Icons.video, tone: 'violet' }] : []),
        ...(allowed('audio_generator') ? [{ name: 'Audio', href: '/audio', icon: Icons.audio, tone: 'pink' }] : []),
        ...(allowed('video_generator', false) ? [{ name: 'Avatar', href: '/avatar', icon: Icons.profile, tone: 'violet' }] : []),
        ...(allowed('image_generator') ? [{ name: 'Studio 3D', href: '/3d', icon: Icons.cube, tone: 'cyan' }] : []),
        ...(allowed('video_downloader') ? [{ name: 'Downloads', href: '/downloads', icon: Icons.download, tone: 'blue' }] : []),
        ...(allowed('media_converter') ? [{ name: 'Converter', href: '/converter', icon: Icons.convert, tone: 'teal' }] : []),
        ...(allowed('media_converter') ? [{ name: 'Hapus Latar', href: '/remove-background', icon: Icons.image, tone: 'fuchsia' }] : []),
        { name: 'Library', href: '/library', icon: Icons.history, tone: 'blue' },
        { name: 'Template Prompt', href: '/templates', icon: Icons.template, tone: 'amber' },
        { name: 'Usage & Billing', href: '/token-usage', icon: Icons.token, tone: 'cyan' },
        { name: 'Deposit', href: '/deposit', icon: Icons.paket, tone: 'orange' },
        { name: 'Referral', href: '/referral', icon: Icons.referral, tone: 'pink' },
        { name: 'Inbox', href: '/notifications', icon: Icons.bell, tone: 'indigo' },
        { name: 'Help & Support', href: '/bantuan', icon: Icons.help, tone: 'teal' },
        { name: 'Profil', href: '/profile', icon: Icons.profile, tone: 'slate' },
    ];
    const adminNav = [
        { name: 'Admin Overview', href: '/admin/overview', icon: Icons.revenue, tone: 'emerald' },
        { name: 'People & Access', href: '/admin/users', icon: Icons.users, tone: 'blue' },
        { name: 'Orders & Billing', href: '/admin/operations', icon: Icons.orders, tone: 'orange' },
        { name: 'Usage', href: '/admin/token-usage', icon: Icons.token, tone: 'cyan' },
        { name: 'AI Catalog', href: '/admin/ai', icon: Icons.model, tone: 'violet' },
        { name: 'Content & Support', href: '/admin/content', icon: Icons.feedback, tone: 'amber' },
        { name: 'System Activity', href: '/admin/system', icon: Icons.audit, tone: 'indigo' },
        { name: 'Pricing Settings', href: '/admin/settings', icon: Icons.settings, tone: 'slate' },
        { name: 'API Keys', href: '/admin/api-keys', icon: Icons.key, tone: 'red' },
        { name: 'Keamanan', href: '/admin/security', icon: Icons.audit, tone: 'emerald' },
    ];
    // Match the deepest nav entry so child routes (e.g. /admin/ai/:id, /admin/ai/queue)
    // keep their parent highlighted and titled instead of falling back to "Dashboard".
    const navItems = [...userNav, ...(isAdmin ? adminNav : [])];
    const activeNav = navItems
        .filter(item => { const target = localizedPath(item.href); return location.pathname === target || location.pathname.startsWith(`${target}/`); })
        .sort((a, b) => localizedPath(b.href).length - localizedPath(a.href).length)[0] || null;
    const isActive = href => activeNav?.href === href;
    const currentPage = t(activeNav?.name || 'Dashboard');

    async function handleLogout() {
        setLoggingOut(true);
        setLogoutError('');
        try { await logout(); navigate(localizedPath('/login')); }
        catch (error) { setLogoutError(error.message || t('Tidak dapat keluar. Coba lagi.')); }
        finally { setLoggingOut(false); }
    }

    function renderNavItem(item) {
        const active = isActive(item.href);
        return (
            <Link key={item.href} to={localizedPath(item.href)} onClick={() => setSidebarOpen(false)} aria-current={active ? 'page' : undefined}
                className={`dw-nav-item group relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors duration-200 ${active
                    ? (isDark ? 'bg-gradient-to-r from-red-500/20 via-red-500/10 to-transparent text-red-300 shadow-[inset_0_0_0_1px_rgba(239,68,68,0.22)]' : 'bg-gradient-to-r from-red-50 via-red-50/60 to-transparent text-red-600 shadow-[inset_0_0_0_1px_rgba(239,68,68,0.15)]')
                    : (isDark ? 'text-gray-400 hover:text-white hover:bg-white/[0.06]' : 'text-gray-600 hover:text-slate-900 hover:bg-gray-100/70')}`}>
                {active && <span className="absolute left-0 top-1/2 -translate-y-1/2 h-6 w-[3px] rounded-r-full bg-gradient-to-b from-red-500 to-red-600" aria-hidden="true" />}
                <span aria-hidden="true" className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg transition-transform duration-200 motion-safe:group-hover:scale-110 [&>svg]:h-4 [&>svg]:w-4 ${iconTones[item.tone]} ${active ? 'ring-1 ring-current/25' : ''}`}>{item.icon}</span>
                <span className="flex-1">{t(item.name)}</span>
                {active && <span className="w-1.5 h-1.5 shrink-0 rounded-full bg-red-500" aria-hidden="true" />}
            </Link>
        );
    }

    return (
        <div ref={shell} className={`dashboard-shell xsuper-workspace-shell min-h-dvh flex flex-col ${isDark ? 'bg-[#030712]' : 'bg-[#fafbfc]'} relative`}>
            <a className="dw-skip-link" href="#dashboard-content">{t('Lewati ke konten')}</a>
            <div ref={ribbonWrap} className="sticky top-0 z-[80] w-full" inert={sidebarOpen && !isDesktop}><AnnouncementRibbon surface="dashboard" /></div>
            {sidebarOpen && !isDesktop && <button type="button" className="dw-nav-overlay" tabIndex={-1} onClick={() => setSidebarOpen(false)} aria-label={t('Tutup menu')} />}
            <div className="flex min-h-0 flex-1">
                <aside ref={sidebar} id="dashboard-navigation" aria-label={t('Navigasi dashboard')} role={!isDesktop && sidebarOpen ? 'dialog' : undefined} aria-modal={!isDesktop && sidebarOpen ? true : undefined} inert={!isDesktop && !sidebarOpen} className={`dw-sidebar ${sidebarOpen ? 'is-open' : ''}`}>
                    <div className={`h-16 shrink-0 flex items-center justify-between px-5 border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200/70'}`}>
                        <Link to={localizedPath('/dashboard')} onClick={() => setSidebarOpen(false)} className="flex items-center gap-2 group" aria-label="XSuper.ai home">
                            <UltrLockup height={30} className="motion-safe:group-hover:scale-105 transition-transform duration-200" />
                        </Link>
                        <button type="button" onClick={() => setSidebarOpen(false)} className="dw-shell-control dw-mobile-control" aria-label={t('Tutup menu')}>{Icons.close}</button>
                    </div>
                    <nav className="dw-navigation">
                        <p className="px-3 mb-2 text-[11px] font-semibold tracking-wider uppercase text-slate-500 dark:text-slate-400">{t('Workspace')}</p>
                        {userNav.map(renderNavItem)}
                        {isAdmin && <><p className="pt-5 pb-2 px-3 text-[11px] font-semibold tracking-wider uppercase text-slate-500 dark:text-slate-400">{t('Operations')}</p>{adminNav.map(renderNavItem)}</>}
                    </nav>
                    <div className={`p-3 shrink-0 border-t ${isDark ? 'border-white/[0.06]' : 'border-gray-200/70'}`}>
                        <div className={`flex items-center gap-1 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                            <Link to={localizedPath('/profile')} onClick={() => setSidebarOpen(false)} className="group flex flex-1 min-w-0 items-center gap-3 p-2.5 rounded-xl">
                                <span className="w-10 h-10 shrink-0 rounded-xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white text-sm font-bold shadow-[0_6px_16px_-4px_rgba(239,68,68,0.35)]">{user?.name?.[0]?.toUpperCase() || 'U'}</span>
                                <span className="min-w-0"><span className={`block text-sm font-semibold truncate ${isDark ? 'text-white' : 'text-slate-900'}`}>{user?.name || t('Pengguna')}</span><span className="block text-[11px] text-slate-500 dark:text-slate-400">{isAdmin ? t('Administrator') : t('Member')}</span></span>
                            </Link>
                            <button type="button" onClick={handleLogout} disabled={loggingOut} className="dw-shell-control mr-1 disabled:opacity-50" aria-label={t('Keluar')} title={t('Keluar')}>{Icons.logout}</button>
                        </div>
                        {logoutError && <p role="alert" className="mt-2 text-xs text-red-600 dark:text-red-300">{logoutError}</p>}
                    </div>
                </aside>
                <div className="flex-1 flex flex-col min-w-0 relative" inert={sidebarOpen && !isDesktop}>
                    <header className="dw-topbar sticky z-30 flex items-center justify-between px-4 lg:px-6" style={{ top: 'var(--dw-ribbon-h, 0px)' }}>
                        <div className="flex items-center gap-3 min-w-0">
                            <button ref={menuButton} type="button" onClick={() => setSidebarOpen(true)} className="dw-shell-control dw-mobile-control" aria-label={t('Buka menu')} aria-expanded={sidebarOpen} aria-controls="dashboard-navigation">{Icons.menu}</button>
                            <nav className="hidden xl:flex items-center gap-2 text-xs min-w-0" aria-label={t('Breadcrumb')}><Link to={localizedPath('/dashboard')} className="text-slate-500 dark:text-slate-400">XSuper.ai</Link><span aria-hidden="true" className="text-slate-400 [&>svg]:w-3 [&>svg]:h-3">{Icons.chevron}</span><span className="font-semibold truncate" aria-current="page">{currentPage}</span></nav>
                            <span className="hidden sm:block xl:hidden text-xs font-semibold truncate">{currentPage}</span>
                        </div>
                        <div className="dw-topbar-tools">
                            <div className="dw-topbar-search"><DashboardSearch /></div>
                            <NotificationMenu />
                            <Link to={otherLocalePath(`${location.pathname}${location.search}${location.hash}`)} className="dw-shell-control text-[11px] font-bold" aria-label={locale === 'en' ? 'Ganti ke bahasa Indonesia' : 'Switch to English'}>{locale === 'en' ? 'ID' : 'EN'}</Link>
                            <button type="button" onClick={toggleTheme} className="dw-shell-control" title={isDark ? t('Mode terang') : t('Mode gelap')} aria-label={isDark ? t('Mode terang') : t('Mode gelap')}>{isDark ? Icons.sun : Icons.moon}</button>
                            <span className="dw-shell-role hidden 2xl:block">{isAdmin ? t('Administrator') : t('Member')}</span>
                        </div>
                    </header>
                    <main id="dashboard-content" tabIndex={-1} className="flex-1 min-w-0">{children}</main>
                </div>
            </div>
        </div>
    );
}
