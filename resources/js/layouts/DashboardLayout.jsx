import React, { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

// Icons as inline SVGs for zero dependencies
const Icons = {
    dashboard: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
    ),
    chat: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
        </svg>
    ),
    users: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
    ),
    profile: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
        </svg>
    ),
    logout: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><polyline points="16 17 21 12 16 7" /><line x1="21" y1="12" x2="9" y2="12" />
        </svg>
    ),
    menu: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round">
            <line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" />
        </svg>
    ),
    close: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round">
            <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
        </svg>
    ),
    external: (
        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><polyline points="15 3 21 3 21 9" /><line x1="10" y1="14" x2="21" y2="3" />
        </svg>
    ),
    home: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" />
        </svg>
    ),
    api: (
        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="16 18 22 12 16 6" /><polyline points="8 6 2 12 8 18" />
        </svg>
    ),
};

export default function DashboardLayout({ children }) {
    const { user, logout } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const location = useLocation();
    const navigate = useNavigate();
    const [sidebarOpen, setSidebarOpen] = useState(false);

    const isDark = theme === 'dark';
    const isAdmin = user?.role === 'admin';

    const perms = user?.permissions || {};
    const hasChat = isAdmin || (perms.chat !== false);
    const hasVideo = isAdmin || (perms.video_generator === true);
    const hasDashboard = isAdmin || (perms.ai_dashboard === true);

    const navigation = [
        { name: 'Dashboard', href: '/dashboard', icon: Icons.dashboard },
        ...(hasChat ? [{ name: 'Chat AI', href: '/chat', icon: Icons.chat }] : []),
        ...(hasVideo ? [{ name: 'Video Generator', href: '/video', icon: <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="2" y="2" width="20" height="20" rx="2"/><polygon points="10 8 16 12 10 16 10 8"/></svg> }] : []),
        ...(isAdmin ? [
            { name: 'Kelola Users', href: '/admin', icon: Icons.users },
            { name: 'Token Usage', href: '/usage', icon: <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg> },
        ] : []),
        { name: 'Profil', href: '/profile', icon: Icons.profile },
    ];

    const externalLinks = [
        { name: 'Landing Page', href: '/', icon: Icons.home, internal: true },
        ...(isAdmin ? [
            { name: 'AI Dashboard Official', href: 'https://app.ultrai.id', icon: Icons.external },
            { name: 'AI API', href: 'https://api.ultrai.id', icon: Icons.api },
            { name: 'AI Dashboard', href: 'https://dash.ultrai.id', icon: Icons.external },
        ] : []),
    ];

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    const isActive = (href) => location.pathname === href;

    return (
        <div className={`min-h-screen flex ${isDark ? 'bg-gray-950' : 'bg-gray-50'}`} style={{ fontSize: '90%' }}>
            {/* Mobile overlay */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* ===== SIDEBAR ===== */}
            <aside className={`
                fixed inset-y-0 left-0 z-50 w-[260px] flex flex-col
                ${isDark ? 'bg-gray-900/80 border-white/[0.06]' : 'bg-white border-gray-200'} backdrop-blur-2xl border-r
                transform transition-transform duration-300 ease-out
                lg:translate-x-0 lg:static lg:z-auto
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
            `}>
                {/* Logo */}
                <div className={`h-16 flex items-center justify-between px-5 border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                    <Link to="/dashboard" className="flex items-center gap-[3px] text-xl font-extrabold tracking-tight">
                        <span className={isDark ? 'text-white' : 'text-gray-900'}>Ultr</span>
                        <span className="bg-gradient-to-r from-red-500 to-red-400 bg-clip-text text-transparent">AI</span>
                    </Link>
                    <button
                        onClick={() => setSidebarOpen(false)}
                        className={`lg:hidden p-1.5 rounded-lg transition-colors ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                    >
                        {Icons.close}
                    </button>
                </div>

                {/* Navigation */}
                <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                    <div className="px-3 mb-3">
                        <span className={`text-[10px] font-bold uppercase tracking-[0.15em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Menu</span>
                    </div>
                    {navigation.map((item) => (
                        <Link
                            key={item.href}
                            to={item.href}
                            onClick={() => setSidebarOpen(false)}
                            className={`
                                group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200
                                ${isActive(item.href)
                                    ? 'bg-gradient-to-r from-red-500/15 to-red-500/5 text-red-400 shadow-[inset_0_0_0_1px_rgba(239,68,68,0.15)]'
                                    : isDark
                                        ? 'text-gray-400 hover:text-white hover:bg-white/[0.06]'
                                        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                                }
                            `}
                        >
                            <span className={`transition-colors ${isActive(item.href) ? 'text-red-400' : isDark ? 'text-gray-500 group-hover:text-gray-300' : 'text-gray-400 group-hover:text-gray-600'}`}>
                                {item.icon}
                            </span>
                            {item.name}
                            {isActive(item.href) && (
                                <span className="ml-auto w-1.5 h-1.5 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.6)]" />
                            )}
                        </Link>
                    ))}

                    {/* External Links */}
                    <div className="pt-6 pb-2 px-3">
                        <span className={`text-[10px] font-bold uppercase tracking-[0.15em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Links</span>
                    </div>
                    {externalLinks.map((item) => (
                        item.internal ? (
                            <Link
                                key={item.name}
                                to={item.href}
                                className={`group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all ${isDark ? 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                            >
                                <span className={isDark ? 'text-gray-600 group-hover:text-gray-400' : 'text-gray-400 group-hover:text-gray-500'}>{item.icon}</span>
                                {item.name}
                            </Link>
                        ) : (
                            <a
                                key={item.name}
                                href={item.href}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={`group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all ${isDark ? 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                            >
                                <span className={isDark ? 'text-gray-600 group-hover:text-gray-400' : 'text-gray-400 group-hover:text-gray-500'}>{item.icon}</span>
                                {item.name}
                                <span className="ml-auto opacity-0 group-hover:opacity-100 transition-opacity">{Icons.external}</span>
                            </a>
                        )
                    ))}
                </nav>

                {/* User section */}
                <div className={`p-3 border-t ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                    <div className={`flex items-center gap-3 px-3 py-3 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white text-sm font-bold shadow-lg shadow-red-500/20">
                            {user?.name?.[0]?.toUpperCase() || 'U'}
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className={`text-sm font-semibold truncate ${isDark ? 'text-white' : 'text-gray-900'}`}>{user?.name || 'User'}</div>
                            <div className={`text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{user?.role || 'member'}</div>
                        </div>
                        <button
                            onClick={handleLogout}
                            className={`p-1.5 rounded-lg transition-all ${isDark ? 'text-gray-500 hover:text-red-400 hover:bg-red-500/10' : 'text-gray-400 hover:text-red-500 hover:bg-red-50'}`}
                            title="Logout"
                        >
                            {Icons.logout}
                        </button>
                    </div>
                </div>
            </aside>

            {/* ===== MAIN CONTENT ===== */}
            <div className="flex-1 flex flex-col min-w-0">
                {/* Top Bar */}
                <header className={`h-16 flex items-center justify-between px-4 lg:px-6 border-b backdrop-blur-xl sticky top-0 z-30 ${isDark ? 'border-white/[0.06] bg-gray-950/80' : 'border-gray-200/60 bg-white/80'}`}>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className={`lg:hidden p-2 rounded-xl transition-colors ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                        >
                            {Icons.menu}
                        </button>
                        <div className="hidden sm:flex items-center gap-2 text-sm">
                            <span className={isDark ? 'text-gray-600' : 'text-gray-400'}>UltrAI</span>
                            <span className={isDark ? 'text-gray-700' : 'text-gray-300'}>/</span>
                            <span className={`font-medium ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                                {navigation.find(n => isActive(n.href))?.name || 'Dashboard'}
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Theme toggle */}
                        <button onClick={toggleTheme} className={`p-2 rounded-xl transition-all ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`} title={isDark ? 'Light Mode' : 'Dark Mode'}>
                            {isDark ? (
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                            ) : (
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                            )}
                        </button>

                        {/* Status indicator */}
                        <div className="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping" style={{ animationDuration: '2s' }} />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                            </span>
                            <span className="text-xs font-medium text-emerald-400">Online</span>
                        </div>

                        {/* Role badge */}
                        <span className={`
                            px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase tracking-wider
                            ${isAdmin
                                ? 'bg-red-500/15 text-red-400 border border-red-500/20'
                                : 'bg-blue-500/15 text-blue-400 border border-blue-500/20'
                            }
                        `}>
                            {user?.role || 'member'}
                        </span>
                    </div>
                </header>

                {/* Page Content */}
                <main className="flex-1 overflow-y-auto">
                    {children}
                </main>
            </div>
        </div>
    );
}
