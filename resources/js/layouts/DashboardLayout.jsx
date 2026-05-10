import React, { useState, useEffect } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import UltrLogo from '../components/UltrLogo';

/* ============================================================
   Icons — single, consistent stroke family
   ============================================================ */
const Icons = {
    dashboard: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5" />
            <rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" />
            <rect x="14" y="14" width="7" height="7" rx="1.5" />
        </svg>
    ),
    chat: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
        </svg>
    ),
    video: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <rect x="2" y="2" width="20" height="20" rx="3" />
            <polygon points="10 8 16 12 10 16 10 8" />
        </svg>
    ),
    users: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
    ),
    chart: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
        </svg>
    ),
    profile: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
            <circle cx="12" cy="7" r="4" />
        </svg>
    ),
    logout: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
        </svg>
    ),
    menu: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
    ),
    close: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
    ),
    external: (
        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
            <polyline points="15 3 21 3 21 9" />
            <line x1="10" y1="14" x2="21" y2="3" />
        </svg>
    ),
    home: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
        </svg>
    ),
    api: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="16 18 22 12 16 6" />
            <polyline points="8 6 2 12 8 18" />
        </svg>
    ),
    sun: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="4" />
            <line x1="12" y1="2" x2="12" y2="4" />
            <line x1="12" y1="20" x2="12" y2="22" />
            <line x1="4.93" y1="4.93" x2="6.34" y2="6.34" />
            <line x1="17.66" y1="17.66" x2="19.07" y2="19.07" />
            <line x1="2" y1="12" x2="4" y2="12" />
            <line x1="20" y1="12" x2="22" y2="12" />
            <line x1="4.93" y1="19.07" x2="6.34" y2="17.66" />
            <line x1="17.66" y1="6.34" x2="19.07" y2="4.93" />
        </svg>
    ),
    moon: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>
    ),
};

export default function DashboardLayout({ children }) {
    const { user, logout } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const location = useLocation();
    const navigate = useNavigate();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);
    }, []);

    // Lock body scroll when mobile sidebar open
    useEffect(() => {
        if (sidebarOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => { document.body.style.overflow = ''; };
    }, [sidebarOpen]);

    const isDark = theme === 'dark';
    const isAdmin = user?.role === 'admin';

    const perms = user?.permissions || {};
    const hasChat = isAdmin || (perms.chat !== false);
    const hasVideo = isAdmin || (perms.video_generator === true);
    const hasDashboardOfficial = isAdmin || (perms.ai_dashboard_official === true);

    const navigation = [
        { name: 'Dashboard', href: '/dashboard', icon: Icons.dashboard },
        ...(hasChat ? [{ name: 'Chat AI', href: '/chat', icon: Icons.chat }] : []),
        ...(hasVideo ? [{ name: 'Video Generator', href: '/video', icon: Icons.video }] : []),
        ...(isAdmin ? [
            { name: 'Kelola Users', href: '/admin', icon: Icons.users },
            { name: 'Token Usage', href: '/usage', icon: Icons.chart },
        ] : []),
        { name: 'Profil', href: '/profile', icon: Icons.profile },
    ];

    const externalLinks = [
        { name: 'Landing Page', href: '/', icon: Icons.home, internal: true },
        ...(hasDashboardOfficial ? [
            { name: 'AI Dashboard Official', href: 'https://app.ultrai.id', icon: Icons.external },
        ] : []),
        ...(isAdmin ? [
            { name: 'AI API', href: 'https://api.ultrai.id', icon: Icons.api },
            { name: 'AI Dashboard', href: 'https://dash.ultrai.id', icon: Icons.external },
        ] : []),
    ];

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    const isActive = (href) => location.pathname === href;
    const currentPage = navigation.find(n => isActive(n.href))?.name || 'Dashboard';

    /* ---------- Theme-dependent classes ---------- */
    const rootBg = isDark ? 'bg-[#030712]' : 'bg-[#fafbfc]';

    const sidebarBg = isDark
        ? 'bg-gray-900/75 border-white/[0.06] backdrop-blur-2xl'
        : 'bg-white/95 border-gray-200/80 backdrop-blur-xl';

    const topbarBg = isDark
        ? 'border-white/[0.06] bg-gray-950/75 backdrop-blur-xl'
        : 'border-gray-200/70 bg-white/80 backdrop-blur-xl';

    const sectionLabel = isDark ? 'text-gray-500' : 'text-gray-400';

    return (
        <div className={`min-h-dvh flex ${rootBg} relative overflow-x-hidden`}>
            {/* =============================================
                Ambient background — bright aurora
               ============================================= */}
            <div className="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
                <div
                    className="ui-aurora animate-aurora"
                    style={{
                        top: '-180px',
                        right: '-140px',
                        background: isDark
                            ? 'radial-gradient(circle, rgba(239,68,68,0.32) 0%, transparent 70%)'
                            : 'radial-gradient(circle, rgba(254,205,211,0.6) 0%, transparent 70%)',
                    }}
                />
                <div
                    className="ui-aurora animate-aurora"
                    style={{
                        bottom: '-160px',
                        left: '-120px',
                        animationDelay: '4s',
                        background: isDark
                            ? 'radial-gradient(circle, rgba(251,146,60,0.20) 0%, transparent 70%)'
                            : 'radial-gradient(circle, rgba(254,226,226,0.6) 0%, transparent 70%)',
                    }}
                />
            </div>

            {/* Mobile overlay */}
            <div
                className={`fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden transition-opacity duration-300 ${
                    sidebarOpen ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'
                }`}
                onClick={() => setSidebarOpen(false)}
                aria-hidden="true"
            />

            {/* =============================================
                SIDEBAR
               ============================================= */}
            <aside
                className={`
                    fixed inset-y-0 left-0 z-50 w-[264px] flex flex-col
                    border-r ${sidebarBg}
                    transform transition-transform duration-300 ease-out
                    lg:translate-x-0 lg:static lg:z-auto
                    ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
                `}
            >
                {/* Logo */}
                <div className={`h-16 flex items-center justify-between px-5 border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200/70'}`}>
                    <Link
                        to="/dashboard"
                        className="flex items-center gap-2 group"
                        aria-label="UltrAI home"
                    >
                        <UltrLogo className="w-9 h-9 group-hover:scale-105 transition-transform duration-300" />
                        <span className="text-xl font-extrabold tracking-tight flex items-center gap-[2px]">
                            <span className={isDark ? 'text-white' : 'text-slate-900'}>Ultr</span>
                            <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">AI</span>
                        </span>
                    </Link>
                    <button
                        onClick={() => setSidebarOpen(false)}
                        className={`lg:hidden p-2 rounded-lg transition-colors ${
                            isDark
                                ? 'text-gray-400 hover:text-white hover:bg-white/10'
                                : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                        }`}
                        aria-label="Close sidebar"
                    >
                        {Icons.close}
                    </button>
                </div>

                {/* Navigation */}
                <nav className="flex-1 px-3 py-5 space-y-1 overflow-y-auto scrollbar-thin">
                    <div className="px-3 mb-3 flex items-center justify-between">
                        <span className={`text-[10px] font-bold uppercase tracking-[0.18em] ${sectionLabel}`}>Menu Utama</span>
                    </div>

                    <div className={mounted ? 'stagger' : ''}>
                        {navigation.map((item) => {
                            const active = isActive(item.href);
                            return (
                                <Link
                                    key={item.href}
                                    to={item.href}
                                    onClick={() => setSidebarOpen(false)}
                                    className={`
                                        group relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium
                                        transition-all duration-200 ease-out
                                        ${active
                                            ? (isDark
                                                ? 'bg-gradient-to-r from-red-500/20 via-red-500/10 to-transparent text-red-300 shadow-[inset_0_0_0_1px_rgba(239,68,68,0.22)]'
                                                : 'bg-gradient-to-r from-red-50 via-red-50/60 to-transparent text-red-600 shadow-[inset_0_0_0_1px_rgba(239,68,68,0.15)]'
                                            )
                                            : (isDark
                                                ? 'text-gray-400 hover:text-white hover:bg-white/[0.06]'
                                                : 'text-gray-600 hover:text-slate-900 hover:bg-gray-100/70'
                                            )
                                        }
                                    `}
                                >
                                    {/* Active indicator bar */}
                                    {active && (
                                        <span className="absolute left-0 top-1/2 -translate-y-1/2 h-6 w-[3px] rounded-r-full bg-gradient-to-b from-red-500 to-red-600 shadow-[0_0_12px_rgba(239,68,68,0.6)] animate-fade-in-left" />
                                    )}
                                    <span
                                        className={`
                                            transition-all duration-200
                                            ${active
                                                ? 'text-red-500 scale-110'
                                                : (isDark
                                                    ? 'text-gray-500 group-hover:text-gray-300 group-hover:scale-110'
                                                    : 'text-gray-400 group-hover:text-red-500 group-hover:scale-110'
                                                )
                                            }
                                        `}
                                    >
                                        {item.icon}
                                    </span>
                                    <span className="flex-1">{item.name}</span>
                                    {active && (
                                        <span className="relative flex w-1.5 h-1.5">
                                            <span className="absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-60 animate-ping" style={{ animationDuration: '1.8s' }} />
                                            <span className="relative inline-flex w-1.5 h-1.5 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.7)]" />
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>

                    {/* External links */}
                    <div className="pt-6 pb-2 px-3">
                        <span className={`text-[10px] font-bold uppercase tracking-[0.18em] ${sectionLabel}`}>Tautan</span>
                    </div>
                    {externalLinks.map((item) => (
                        item.internal ? (
                            <Link
                                key={item.name}
                                to={item.href}
                                className={`group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 ${
                                    isDark
                                        ? 'text-gray-500 hover:text-gray-200 hover:bg-white/[0.04]'
                                        : 'text-gray-500 hover:text-slate-900 hover:bg-gray-100/70'
                                }`}
                            >
                                <span className={`transition-transform duration-200 group-hover:scale-110 ${isDark ? 'text-gray-600 group-hover:text-gray-400' : 'text-gray-400 group-hover:text-red-500'}`}>{item.icon}</span>
                                <span>{item.name}</span>
                            </Link>
                        ) : (
                            <a
                                key={item.name}
                                href={item.href}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={`group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 ${
                                    isDark
                                        ? 'text-gray-500 hover:text-gray-200 hover:bg-white/[0.04]'
                                        : 'text-gray-500 hover:text-slate-900 hover:bg-gray-100/70'
                                }`}
                            >
                                <span className={`transition-transform duration-200 group-hover:scale-110 ${isDark ? 'text-gray-600 group-hover:text-gray-400' : 'text-gray-400 group-hover:text-red-500'}`}>{item.icon}</span>
                                <span className="flex-1">{item.name}</span>
                                <span className="opacity-0 group-hover:opacity-100 transition-opacity duration-200">{Icons.external}</span>
                            </a>
                        )
                    ))}
                </nav>

                {/* User card */}
                <div className={`p-3 border-t ${isDark ? 'border-white/[0.06]' : 'border-gray-200/70'}`}>
                    <Link
                        to="/profile"
                        className={`group flex items-center gap-3 p-2.5 rounded-xl transition-all duration-200 ${
                            isDark
                                ? 'bg-white/[0.03] hover:bg-white/[0.06]'
                                : 'bg-gray-50 hover:bg-red-50/60'
                        }`}
                    >
                        <div className="relative w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white text-sm font-bold shadow-[0_6px_16px_-4px_rgba(239,68,68,0.45)] group-hover:shadow-[0_10px_22px_-4px_rgba(239,68,68,0.55)] transition-all duration-200">
                            {user?.name?.[0]?.toUpperCase() || 'U'}
                            <span className="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-gray-900" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className={`text-sm font-semibold truncate ${isDark ? 'text-white' : 'text-slate-900'}`}>
                                {user?.name || 'User'}
                            </div>
                            <div className={`text-[11px] capitalize ${isDark ? 'text-gray-500' : 'text-gray-500'}`}>
                                {user?.role || 'member'}
                            </div>
                        </div>
                        <button
                            onClick={(e) => { e.preventDefault(); e.stopPropagation(); handleLogout(); }}
                            className={`p-2 rounded-lg transition-all duration-200 ${
                                isDark
                                    ? 'text-gray-500 hover:text-red-400 hover:bg-red-500/10'
                                    : 'text-gray-400 hover:text-red-500 hover:bg-red-100'
                            }`}
                            aria-label="Keluar"
                            title="Keluar"
                        >
                            {Icons.logout}
                        </button>
                    </Link>
                </div>
            </aside>

            {/* =============================================
                MAIN AREA
               ============================================= */}
            <div className="flex-1 flex flex-col min-w-0 relative">
                {/* Top bar */}
                <header
                    className={`h-16 flex items-center justify-between px-4 lg:px-6 border-b sticky top-0 z-30 ${topbarBg}`}
                >
                    <div className="flex items-center gap-3 min-w-0">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className={`lg:hidden p-2 rounded-xl transition-all duration-200 ${
                                isDark
                                    ? 'text-gray-400 hover:text-white hover:bg-white/10'
                                    : 'text-gray-500 hover:text-slate-900 hover:bg-gray-100'
                            }`}
                            aria-label="Open menu"
                        >
                            {Icons.menu}
                        </button>

                        {/* Breadcrumb */}
                        <nav className="hidden sm:flex items-center gap-2 text-sm min-w-0" aria-label="Breadcrumb">
                            <span className={`inline-flex items-center gap-1.5 font-medium ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                                UltrAI
                            </span>
                            <svg className={`w-3.5 h-3.5 ${isDark ? 'text-gray-700' : 'text-gray-300'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                            <span className={`font-semibold truncate ${isDark ? 'text-gray-200' : 'text-slate-900'}`}>
                                {currentPage}
                            </span>
                        </nav>
                    </div>

                    <div className="flex items-center gap-2 sm:gap-3">
                        {/* Online status pill */}
                        <div className={`hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${
                            isDark
                                ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/20'
                                : 'bg-emerald-50 text-emerald-700 border border-emerald-200/70'
                        }`}>
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping" style={{ animationDuration: '2s' }} />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                            </span>
                            <span>Online</span>
                        </div>

                        {/* Theme toggle */}
                        <button
                            onClick={toggleTheme}
                            className={`relative p-2 rounded-xl transition-all duration-200 ${
                                isDark
                                    ? 'text-gray-400 hover:text-white hover:bg-white/10'
                                    : 'text-gray-500 hover:text-red-500 hover:bg-red-50'
                            }`}
                            title={isDark ? 'Light mode' : 'Dark mode'}
                            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
                        >
                            <span className="block transition-transform duration-300" style={{ transform: isDark ? 'rotate(0deg)' : 'rotate(180deg)' }}>
                                {isDark ? Icons.sun : Icons.moon}
                            </span>
                        </button>

                        {/* Role badge */}
                        <span
                            className={`
                                px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase tracking-wider border
                                ${isAdmin
                                    ? (isDark
                                        ? 'bg-red-500/15 text-red-300 border-red-500/25'
                                        : 'bg-red-50 text-red-600 border-red-200')
                                    : (isDark
                                        ? 'bg-blue-500/15 text-blue-300 border-blue-500/25'
                                        : 'bg-blue-50 text-blue-600 border-blue-200')
                                }
                            `}
                        >
                            {user?.role || 'member'}
                        </span>
                    </div>
                </header>

                {/* Page content */}
                <main className="flex-1 overflow-y-auto scrollbar-thin">
                    <div key={location.pathname} className="animate-fade-in-up">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
