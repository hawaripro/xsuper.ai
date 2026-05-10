import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

/* ============================================================
   Shared icons
   ============================================================ */
const svg = (path) => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
        {path}
    </svg>
);

const Icon = {
    chat:  svg(<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />),
    code:  svg(<><polyline points="16 18 22 12 16 6" /><polyline points="8 6 2 12 8 18" /></>),
    zap:   svg(<path d="M22 12h-4l-3 9L9 3l-3 9H2" />),
    clock: svg(<><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></>),
    spark: svg(<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />),
    cpu:   svg(<><rect x="4" y="4" width="16" height="16" rx="2" /><rect x="9" y="9" width="6" height="6" /><line x1="9" y1="1" x2="9" y2="4" /><line x1="15" y1="1" x2="15" y2="4" /><line x1="9" y1="20" x2="9" y2="23" /><line x1="15" y1="20" x2="15" y2="23" /><line x1="20" y1="9" x2="23" y2="9" /><line x1="20" y1="14" x2="23" y2="14" /><line x1="1" y1="9" x2="4" y2="9" /><line x1="1" y1="14" x2="4" y2="14" /></>),
    ig:    svg(<><rect x="2" y="2" width="20" height="20" rx="5" /><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" /><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" /></>),
    card:  svg(<><rect x="1" y="4" width="22" height="16" rx="2" /><line x1="1" y1="10" x2="23" y2="10" /></>),
    video: svg(<><rect x="2" y="2" width="20" height="20" rx="3" /><polygon points="10 8 16 12 10 16 10 8" /></>),
    arrow: svg(<><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></>),
    user:  svg(<><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></>),
    globe: svg(<><circle cx="12" cy="12" r="10" /><line x1="2" y1="12" x2="22" y2="12" /><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></>),
    shield:svg(<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />),
    mail:  svg(<><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" /></>),
    tag:   svg(<><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" /><line x1="7" y1="7" x2="7.01" y2="7" /></>),
};

/* ============================================================
   Animated counter — smooth number roll-up
   ============================================================ */
function AnimatedNumber({ value, duration = 900 }) {
    const [display, setDisplay] = useState(0);
    const numeric = typeof value === 'number' ? value : parseFloat(String(value).replace(/[^\d.-]/g, ''));
    const target = Number.isFinite(numeric) ? numeric : 0;
    const hasNumber = Number.isFinite(numeric);

    useEffect(() => {
        if (!hasNumber) { setDisplay(0); return; }
        let raf;
        const start = performance.now();
        const from = 0;
        const tick = (now) => {
            const t = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - t, 3);
            setDisplay(Math.round(from + (target - from) * eased));
            if (t < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [target, duration, hasNumber]);

    if (!hasNumber) return <>{value}</>;
    return <>{display.toLocaleString('id-ID')}</>;
}

/* ============================================================
   Stat card with animated counter and color accent
   ============================================================ */
const STAT_COLORS = {
    red: {
        icon:   'from-red-500 to-red-600',
        ring:   'ring-red-500/30',
        chipBg: 'bg-red-50 text-red-600 border-red-200',
        chipBgD:'bg-red-500/12 text-red-300 border-red-500/20',
        glow:   'bg-red-500/10',
    },
    blue: {
        icon:   'from-blue-500 to-indigo-500',
        ring:   'ring-blue-500/30',
        chipBg: 'bg-blue-50 text-blue-600 border-blue-200',
        chipBgD:'bg-blue-500/12 text-blue-300 border-blue-500/20',
        glow:   'bg-blue-500/10',
    },
    emerald: {
        icon:   'from-emerald-500 to-teal-500',
        ring:   'ring-emerald-500/30',
        chipBg: 'bg-emerald-50 text-emerald-600 border-emerald-200',
        chipBgD:'bg-emerald-500/12 text-emerald-300 border-emerald-500/20',
        glow:   'bg-emerald-500/10',
    },
    amber: {
        icon:   'from-amber-500 to-orange-500',
        ring:   'ring-amber-500/30',
        chipBg: 'bg-amber-50 text-amber-600 border-amber-200',
        chipBgD:'bg-amber-500/12 text-amber-300 border-amber-500/20',
        glow:   'bg-amber-500/10',
    },
    violet: {
        icon:   'from-violet-500 to-purple-500',
        ring:   'ring-violet-500/30',
        chipBg: 'bg-violet-50 text-violet-600 border-violet-200',
        chipBgD:'bg-violet-500/12 text-violet-300 border-violet-500/20',
        glow:   'bg-violet-500/10',
    },
};

function StatCard({ icon, label, value, suffix, trend, color = 'red', isDark, animated = true }) {
    const c = STAT_COLORS[color] || STAT_COLORS.red;

    return (
        <div
            className={`
                group relative overflow-hidden p-5 rounded-2xl border
                transition-all duration-300 ease-out
                hover:-translate-y-1 hover:shadow-lg
                ${isDark
                    ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl hover:border-white/[0.14] hover:bg-gray-900/80'
                    : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)] hover:border-gray-300 hover:shadow-[0_12px_28px_-8px_rgba(15,23,42,0.12)]'
                }
            `}
        >
            {/* Accent glow */}
            <div className={`absolute -top-16 -right-16 w-40 h-40 rounded-full blur-3xl ${c.glow} opacity-60 group-hover:opacity-100 group-hover:scale-110 transition-all duration-500`} />

            <div className="relative flex items-start justify-between mb-4">
                <div className={`w-11 h-11 rounded-xl bg-gradient-to-br ${c.icon} text-white flex items-center justify-center shadow-lg shadow-black/10 ring-4 ${c.ring} group-hover:scale-110 group-hover:rotate-3 transition-all duration-300`}>
                    {icon}
                </div>
                {trend && (
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-bold border ${
                        isDark ? 'bg-emerald-500/12 text-emerald-300 border-emerald-500/25' : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    }`}>
                        <svg className="w-3 h-3" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
                        {trend}
                    </span>
                )}
            </div>

            <div className="relative">
                <div className={`text-3xl font-extrabold tracking-tight tabular-nums ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    {animated ? <AnimatedNumber value={value} /> : value}
                    {suffix && <span className="text-lg opacity-70 ml-0.5">{suffix}</span>}
                </div>
                <div className={`text-[13px] mt-1 font-medium ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{label}</div>
            </div>
        </div>
    );
}

/* ============================================================
   Quick action card — external or internal link
   ============================================================ */
function QuickAction({ icon, title, desc, href, external = false, isDark, accent = 'red' }) {
    const Wrapper = external ? 'a' : Link;
    const props = external
        ? { href, target: '_blank', rel: 'noopener noreferrer' }
        : { to: href };
    const c = STAT_COLORS[accent] || STAT_COLORS.red;

    return (
        <Wrapper
            {...props}
            className={`
                group relative overflow-hidden flex items-center gap-4 p-4 rounded-xl border
                transition-all duration-250 ease-out
                ${isDark
                    ? 'bg-white/[0.03] border-white/[0.06] hover:bg-white/[0.07] hover:border-white/[0.14]'
                    : 'bg-white border-gray-200 hover:border-gray-300 hover:shadow-md hover:-translate-y-0.5'
                }
            `}
        >
            <div className={`relative w-11 h-11 rounded-xl bg-gradient-to-br ${c.icon} text-white flex items-center justify-center flex-shrink-0 shadow-md ring-4 ${c.ring} group-hover:scale-110 group-hover:rotate-3 transition-all duration-300`}>
                {icon}
            </div>
            <div className="flex-1 min-w-0">
                <div className={`text-sm font-semibold group-hover:text-red-500 transition-colors ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    {title}
                    {external && (
                        <svg className="inline w-3 h-3 ml-1 opacity-50" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><polyline points="15 3 21 3 21 9" /><line x1="10" y1="14" x2="21" y2="3" /></svg>
                    )}
                </div>
                <div className={`text-xs truncate mt-0.5 ${isDark ? 'text-gray-500' : 'text-gray-500'}`}>{desc}</div>
            </div>
            <svg
                className={`w-4 h-4 flex-shrink-0 transition-all duration-300 group-hover:translate-x-1 group-hover:text-red-500 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}
                fill="none" stroke="currentColor" strokeWidth="2.2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"
            >
                <path d="M5 12h14" />
                <path d="m12 5 7 7-7 7" />
            </svg>
        </Wrapper>
    );
}

/* ============================================================
   Service status row
   ============================================================ */
function ServiceStatus({ name, url, status = 'online', isDark }) {
    const statusConfig = {
        online:      { dot: 'bg-emerald-500', glow: 'shadow-emerald-500/60', label: 'Online',      color: isDark ? 'text-emerald-300' : 'text-emerald-600' },
        offline:     { dot: 'bg-red-500',     glow: 'shadow-red-500/60',     label: 'Offline',     color: isDark ? 'text-red-300'     : 'text-red-600' },
        maintenance: { dot: 'bg-amber-500',   glow: 'shadow-amber-500/60',   label: 'Maintenance', color: isDark ? 'text-amber-300'   : 'text-amber-600' },
        checking:    { dot: 'bg-gray-400',    glow: 'shadow-gray-400/50',    label: 'Checking…',   color: isDark ? 'text-gray-400'    : 'text-gray-500' },
    };
    const s = statusConfig[status] || statusConfig.online;

    return (
        <div className={`flex items-center justify-between py-3 border-b last:border-0 transition-colors ${isDark ? 'border-white/[0.05]' : 'border-gray-100'}`}>
            <div className="flex items-center gap-3 min-w-0">
                <span className="relative flex h-2 w-2">
                    {status === 'online' && (
                        <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-70 animate-ping" style={{ animationDuration: '2.2s' }} />
                    )}
                    <span className={`relative inline-flex w-2 h-2 rounded-full ${s.dot} shadow-[0_0_10px] ${s.glow}`} />
                </span>
                <span className={`text-sm font-medium truncate ${isDark ? 'text-gray-200' : 'text-slate-700'}`}>{name}</span>
            </div>
            <div className="flex items-center gap-3 flex-shrink-0">
                <span className={`text-[10px] font-bold uppercase tracking-wider ${s.color}`}>
                    {s.label}
                </span>
                <a
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`text-xs font-medium transition-colors ${isDark ? 'text-gray-500 hover:text-red-300' : 'text-gray-400 hover:text-red-500'}`}
                >
                    Buka →
                </a>
            </div>
        </div>
    );
}

/* ============================================================
   Dashboard page
   ============================================================ */
export default function Dashboard() {
    const { user } = useAuth();
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [time, setTime] = useState(new Date());
    const [aiStatus, setAiStatus] = useState({ online: null, total_models: 0, chat_models: 0 });
    const [chatCount, setChatCount] = useState(0);

    useEffect(() => {
        const timer = setInterval(() => setTime(new Date()), 60000);
        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        if (user?.role !== 'admin') return;
        const checkAiStatus = async () => {
            try {
                const res = await fetch('/api/s/info', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) setAiStatus(await res.json());
            } catch {
                setAiStatus({ online: false, total_models: 0, chat_models: 0 });
            }
        };
        checkAiStatus();
    }, [user]);

    useEffect(() => {
        const loadChatCount = async () => {
            try {
                const res = await fetch('/api/c/h', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    setChatCount(data.conversations?.length || 0);
                }
            } catch {}
        };
        loadChatCount();
    }, []);

    const greeting = () => {
        const h = time.getHours();
        if (h < 12) return 'Selamat Pagi';
        if (h < 15) return 'Selamat Siang';
        if (h < 18) return 'Selamat Sore';
        return 'Selamat Malam';
    };

    const weekday = new Intl.DateTimeFormat('id-ID', { weekday: 'long' }).format(time);
    const datePart = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(time);
    const clock = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(time);

    const isAdmin = user?.role === 'admin';

    /* ---------- Theme classes ---------- */
    const card = isDark
        ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl'
        : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]';

    const cardHead = isDark ? 'text-white' : 'text-slate-900';
    const muted    = isDark ? 'text-gray-400' : 'text-gray-500';

    return (
        <div className="p-4 lg:p-6 space-y-5 lg:space-y-6 max-w-7xl mx-auto">
            {/* ============================================
                Hero — Welcome banner
               ============================================ */}
            <section
                className={`
                    relative overflow-hidden rounded-3xl border
                    ${isDark
                        ? 'bg-gradient-to-br from-gray-900 via-gray-900 to-slate-800 border-white/[0.07]'
                        : 'bg-gradient-to-br from-white via-red-50/30 to-white border-gray-200/80'
                    }
                `}
                style={{ animation: 'fade-in-up 0.7s cubic-bezier(0.16, 1, 0.3, 1)' }}
            >
                {/* Dot pattern */}
                <div className="absolute inset-0 hero-dots opacity-40" />
                {/* Aurora orbs */}
                <div className="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-red-500/20 blur-[100px] animate-aurora" />
                <div className="absolute -bottom-32 left-1/3 w-96 h-96 rounded-full bg-orange-400/15 blur-[120px] animate-aurora" style={{ animationDelay: '3s' }} />

                <div className="relative p-6 lg:p-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-6">
                    <div className="flex-1 min-w-0">
                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-300 text-xs font-semibold mb-3 animate-fade-in-down">
                            <span className="relative flex w-2 h-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping" />
                                <span className="relative inline-flex w-2 h-2 rounded-full bg-red-500" />
                            </span>
                            <span>Selamat datang kembali</span>
                        </div>
                        <h1 className={`text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight leading-[1.1] ${cardHead}`}>
                            {greeting()},{' '}
                            <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">
                                {user?.name?.split(' ')[0] || 'User'}
                            </span>
                        </h1>
                        <p className={`mt-2 text-sm sm:text-base max-w-xl ${muted}`}>
                            Kelola semua layanan <span className="font-semibold text-red-500">UltrAI</span> dari sini. Lihat statistik, jalankan Chat AI, dan pantau status layanan secara realtime.
                        </p>

                        <div className="mt-5 flex flex-wrap items-center gap-2">
                            <Link to="/chat" className="ui-btn-primary">
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
                                Mulai Chat AI
                            </Link>
                            <Link to="/profile" className="ui-btn-ghost">
                                {Icon.user}
                                Profil
                            </Link>
                        </div>
                    </div>

                    {/* Clock card */}
                    <div
                        className={`
                            relative flex-shrink-0 px-5 py-4 rounded-2xl border backdrop-blur-xl
                            ${isDark ? 'bg-white/5 border-white/10' : 'bg-white/80 border-gray-200'}
                        `}
                    >
                        <div className={`text-[11px] font-bold uppercase tracking-wider ${muted}`}>{weekday}</div>
                        <div className={`text-4xl font-extrabold tabular-nums tracking-tight my-0.5 bg-gradient-to-br from-red-500 to-red-600 bg-clip-text text-transparent`}>
                            {clock}
                        </div>
                        <div className={`text-xs ${muted}`}>{datePart}</div>
                    </div>
                </div>
            </section>

            {/* ============================================
                Stats grid
               ============================================ */}
            <section className={`grid grid-cols-2 ${isAdmin ? 'lg:grid-cols-4' : 'lg:grid-cols-2'} gap-3 lg:gap-4 stagger`}>
                <StatCard
                    icon={Icon.chat}
                    label="Chat Sessions"
                    value={chatCount}
                    color="red"
                    isDark={isDark}
                />
                {isAdmin && (
                    <>
                        <StatCard
                            icon={Icon.code}
                            label="AI Models"
                            value={aiStatus.chat_models || aiStatus.total_models || 0}
                            suffix="+"
                            color="blue"
                            isDark={isDark}
                        />
                        <StatCard
                            icon={Icon.zap}
                            label="AI Status"
                            value={aiStatus.online === null ? '…' : aiStatus.online ? 'Online' : 'Offline'}
                            trend={aiStatus.online ? 'Aktif' : null}
                            color="emerald"
                            isDark={isDark}
                            animated={false}
                        />
                    </>
                )}
                <StatCard
                    icon={Icon.clock}
                    label="Response Time"
                    value="<100"
                    suffix="ms"
                    color="amber"
                    isDark={isDark}
                    animated={false}
                />
            </section>

            {/* ============================================
                Main grid
               ============================================ */}
            <section className="grid lg:grid-cols-3 gap-5 lg:gap-6">
                {/* Column 1-2 */}
                <div className="lg:col-span-2 space-y-5 lg:space-y-6">
                    {/* Quick actions */}
                    <div className={`relative overflow-hidden p-5 lg:p-6 rounded-2xl border ${card} animate-fade-in-up`}>
                        <div className="flex items-center justify-between mb-5">
                            <h2 className={`text-base lg:text-lg font-bold flex items-center gap-2 ${cardHead}`}>
                                <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center shadow-md">
                                    <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" /></svg>
                                </span>
                                Aksi Cepat
                            </h2>
                        </div>
                        <div className="grid sm:grid-cols-2 gap-3 stagger">
                            <QuickAction
                                icon={Icon.chat}
                                title="Chat AI"
                                desc="Mulai percakapan dengan AI models"
                                href="/chat"
                                isDark={isDark}
                                accent="red"
                            />
                            {isAdmin && (
                                <QuickAction
                                    icon={Icon.code}
                                    title="AI API"
                                    desc="Akses endpoint untuk integrasi"
                                    href="https://api.ultrai.id"
                                    external
                                    isDark={isDark}
                                    accent="blue"
                                />
                            )}
                            <QuickAction
                                icon={Icon.ig}
                                title="SMM Panel"
                                desc="Social media marketing services"
                                href="https://smm.superpanelpedia.com"
                                external
                                isDark={isDark}
                                accent="violet"
                            />
                            <QuickAction
                                icon={Icon.card}
                                title="PPOB"
                                desc="Pembayaran online & produk digital"
                                href="https://ppob.superpanelpedia.com"
                                external
                                isDark={isDark}
                                accent="emerald"
                            />
                        </div>
                    </div>

                    {/* AI Models preview */}
                    <div className={`relative overflow-hidden p-5 lg:p-6 rounded-2xl border ${card} animate-fade-in-up`}>
                        <div className="flex items-center justify-between mb-5">
                            <h2 className={`text-base lg:text-lg font-bold flex items-center gap-2 ${cardHead}`}>
                                <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center shadow-md">
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="3" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" /></svg>
                                </span>
                                Model AI Populer
                            </h2>
                            <Link
                                to="/chat"
                                className={`group inline-flex items-center gap-1 text-xs font-semibold transition-colors ${
                                    isDark ? 'text-red-400 hover:text-red-300' : 'text-red-500 hover:text-red-600'
                                }`}
                            >
                                Coba Sekarang
                                <svg className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6" /></svg>
                            </Link>
                        </div>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 stagger">
                            {['GPT-4o', 'Claude Sonnet', 'Gemini Pro', 'DeepSeek V3', 'Llama 3.1', 'Mistral Large'].map((model) => (
                                <div
                                    key={model}
                                    className={`
                                        group px-3 py-2.5 rounded-xl border text-sm font-medium cursor-default
                                        transition-all duration-200 hover:-translate-y-0.5
                                        ${isDark
                                            ? 'bg-white/[0.03] border-white/[0.06] text-gray-200 hover:bg-white/[0.07] hover:border-red-400/30'
                                            : 'bg-gray-50/70 border-gray-200 text-slate-800 hover:bg-white hover:border-red-300 hover:shadow-sm'
                                        }
                                    `}
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="relative flex w-2 h-2">
                                            <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-70 animate-ping" style={{ animationDuration: '2.5s' }} />
                                            <span className="relative inline-flex w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.6)]" />
                                        </span>
                                        <span className="group-hover:text-red-500 transition-colors">{model}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Column 3 */}
                <div className="space-y-5 lg:space-y-6">
                    {/* Service status */}
                    <div className={`p-5 rounded-2xl border ${card} animate-fade-in-up`}>
                        <h2 className={`text-base lg:text-lg font-bold mb-4 flex items-center gap-2 ${cardHead}`}>
                            <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 text-white flex items-center justify-center shadow-md">
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>
                            </span>
                            Status Layanan
                        </h2>
                        <div>
                            <ServiceStatus name="UltrAI Platform" url="https://ultrai.id" status="online" isDark={isDark} />
                            {isAdmin && (
                                <>
                                    <ServiceStatus
                                        name="AI API"
                                        url="https://api.ultrai.id"
                                        status={aiStatus.online === null ? 'checking' : aiStatus.online ? 'online' : 'offline'}
                                        isDark={isDark}
                                    />
                                    <ServiceStatus name="AI Dashboard" url="https://dash.ultrai.id" status="online" isDark={isDark} />
                                </>
                            )}
                            <ServiceStatus name="SMM Panel" url="https://smm.superpanelpedia.com" status="online" isDark={isDark} />
                            <ServiceStatus name="PPOB" url="https://ppob.superpanelpedia.com" status="online" isDark={isDark} />
                        </div>
                    </div>

                    {/* Account card */}
                    <div className={`p-5 rounded-2xl border ${card} animate-fade-in-up`}>
                        <h2 className={`text-base lg:text-lg font-bold mb-4 flex items-center gap-2 ${cardHead}`}>
                            <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-violet-500 to-purple-500 text-white flex items-center justify-center shadow-md">
                                {React.cloneElement(Icon.user, { className: 'w-3.5 h-3.5' })}
                            </span>
                            Akun Anda
                        </h2>
                        <div className="space-y-3">
                            <div className={`flex items-center justify-between p-3 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50/80'}`}>
                                <span className={`text-xs font-medium ${muted}`}>Nama</span>
                                <span className={`text-sm font-semibold truncate ml-3 ${cardHead}`}>{user?.name || '-'}</span>
                            </div>
                            <div className={`flex items-center justify-between p-3 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50/80'}`}>
                                <span className={`text-xs font-medium ${muted}`}>Role</span>
                                <span className={`text-[11px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border ${
                                    isAdmin
                                        ? (isDark ? 'bg-red-500/15 text-red-300 border-red-500/25' : 'bg-red-50 text-red-600 border-red-200')
                                        : (isDark ? 'bg-blue-500/15 text-blue-300 border-blue-500/25' : 'bg-blue-50 text-blue-600 border-blue-200')
                                }`}>
                                    {user?.role || 'member'}
                                </span>
                            </div>
                            <Link
                                to="/profile"
                                className={`mt-2 w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200 ${
                                    isDark
                                        ? 'bg-red-500/15 text-red-300 border border-red-500/25 hover:bg-red-500/20'
                                        : 'bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 hover:border-red-300'
                                }`}
                            >
                                Kelola Profil
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6" /></svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    );
}
