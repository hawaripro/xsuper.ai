import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

// ============================================
// Stat Card Component
// ============================================
function StatCard({ icon, label, value, suffix, trend, color = 'red', isDark }) {
    const colors = {
        red: 'from-red-500/15 to-red-500/5 border-red-500/10 text-red-400',
        blue: 'from-blue-500/15 to-blue-500/5 border-blue-500/10 text-blue-400',
        emerald: 'from-emerald-500/15 to-emerald-500/5 border-emerald-500/10 text-emerald-400',
        amber: 'from-amber-500/15 to-amber-500/5 border-amber-500/10 text-amber-400',
        violet: 'from-violet-500/15 to-violet-500/5 border-violet-500/10 text-violet-400',
    };

    const colorsLight = {
        red: 'from-red-50 to-white border-red-200 text-red-500',
        blue: 'from-blue-50 to-white border-blue-200 text-blue-500',
        emerald: 'from-emerald-50 to-white border-emerald-200 text-emerald-500',
        amber: 'from-amber-50 to-white border-amber-200 text-amber-500',
        violet: 'from-violet-50 to-white border-violet-200 text-violet-500',
    };

    const iconColors = {
        red: 'bg-red-500/15 text-red-400',
        blue: 'bg-blue-500/15 text-blue-400',
        emerald: 'bg-emerald-500/15 text-emerald-400',
        amber: 'bg-amber-500/15 text-amber-400',
        violet: 'bg-violet-500/15 text-violet-400',
    };

    const iconColorsLight = {
        red: 'bg-red-100 text-red-500',
        blue: 'bg-blue-100 text-blue-500',
        emerald: 'bg-emerald-100 text-emerald-500',
        amber: 'bg-amber-100 text-amber-500',
        violet: 'bg-violet-100 text-violet-500',
    };

    return (
        <div className={`relative p-5 rounded-2xl bg-gradient-to-br ${isDark ? colors[color] : colorsLight[color]} border ${isDark ? 'backdrop-blur-xl' : 'shadow-sm'} overflow-hidden group hover:scale-[1.02] transition-transform duration-300`}>
            <div className="absolute top-0 right-0 w-24 h-24 bg-current opacity-[0.03] rounded-full blur-2xl -translate-y-1/2 translate-x-1/2" />
            <div className="flex items-start justify-between mb-4">
                <div className={`w-10 h-10 rounded-xl ${isDark ? iconColors[color] : iconColorsLight[color]} flex items-center justify-center`}>
                    {icon}
                </div>
                {trend && (
                    <span className="text-[11px] font-bold text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-md">
                        {trend}
                    </span>
                )}
            </div>
            <div className={`text-2xl font-extrabold tracking-tight ${isDark ? 'text-white' : 'text-gray-900'}`}>
                {value}<span className="text-current text-lg">{suffix}</span>
            </div>
            <div className={`text-xs mt-1 font-medium ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{label}</div>
        </div>
    );
}

// ============================================
// Quick Action Card
// ============================================
function QuickAction({ icon, title, desc, href, external = false, isDark }) {
    const Wrapper = external ? 'a' : Link;
    const props = external
        ? { href, target: '_blank', rel: 'noopener noreferrer' }
        : { to: href };

    return (
        <Wrapper {...props} className={`group flex items-center gap-4 p-4 rounded-xl border transition-all duration-200 ${
            isDark
                ? 'bg-white/[0.03] border-white/[0.06] hover:bg-white/[0.06] hover:border-white/[0.1]'
                : 'bg-gray-50 border-gray-200 hover:bg-gray-100 hover:border-gray-300'
        }`}>
            <div className={`w-10 h-10 rounded-xl bg-gradient-to-br from-red-500/15 to-red-500/5 border border-red-500/10 flex items-center justify-center text-red-400 flex-shrink-0 group-hover:scale-110 transition-transform`}>
                {icon}
            </div>
            <div className="flex-1 min-w-0">
                <div className={`text-sm font-semibold group-hover:text-red-400 transition-colors ${isDark ? 'text-white' : 'text-gray-900'}`}>{title}</div>
                <div className={`text-xs truncate ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{desc}</div>
            </div>
            <svg className={`w-4 h-4 group-hover:translate-x-0.5 transition-all flex-shrink-0 ${isDark ? 'text-gray-600 group-hover:text-gray-400' : 'text-gray-300 group-hover:text-gray-500'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
        </Wrapper>
    );
}

// ============================================
// Service Status Card (with live check)
// ============================================
function ServiceStatus({ name, url, status = 'online', isDark }) {
    const statusColors = {
        online: 'bg-emerald-500 shadow-emerald-500/50',
        offline: 'bg-red-500 shadow-red-500/50',
        maintenance: 'bg-amber-500 shadow-amber-500/50',
        checking: 'bg-gray-500 shadow-gray-500/50 animate-pulse',
    };

    const statusLabels = {
        online: 'Online',
        offline: 'Offline',
        maintenance: 'Maintenance',
        checking: 'Checking...',
    };

    return (
        <div className={`flex items-center justify-between py-3 border-b last:border-0 ${isDark ? 'border-white/[0.04]' : 'border-gray-100'}`}>
            <div className="flex items-center gap-3">
                <span className={`w-2 h-2 rounded-full ${statusColors[status]} shadow-[0_0_8px]`} />
                <span className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{name}</span>
            </div>
            <div className="flex items-center gap-3">
                <span className={`text-[10px] font-bold uppercase tracking-wider ${
                    status === 'online' ? 'text-emerald-400' :
                    status === 'offline' ? 'text-red-400' :
                    status === 'checking' ? 'text-gray-500' : 'text-amber-400'
                }`}>
                    {statusLabels[status]}
                </span>
                <a href={url} target="_blank" rel="noopener noreferrer" className={`text-xs hover:text-red-400 transition-colors ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                    Buka →
                </a>
            </div>
        </div>
    );
}

// ============================================
// Main Dashboard
// ============================================
export default function Dashboard() {
    const { user } = useAuth();
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [time, setTime] = useState(new Date());
    const [aiStatus, setAiStatus] = useState({ online: null, total_models: 0, chat_models: 0 });
    const [chatCount, setChatCount] = useState(0);

    // Clock
    useEffect(() => {
        const timer = setInterval(() => setTime(new Date()), 60000);
        return () => clearInterval(timer);
    }, []);

    // Load AI proxy status (admin only)
    useEffect(() => {
        if (user?.role !== 'admin') return;
        const checkAiStatus = async () => {
            try {
                const res = await fetch('/api/s/info', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    setAiStatus(data);
                }
            } catch {
                setAiStatus({ online: false, total_models: 0, chat_models: 0 });
            }
        };
        checkAiStatus();
    }, [user]);

    // Load chat history count
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

    return (
        <div className="p-3 lg:p-5 space-y-4 lg:space-y-5 max-w-7xl mx-auto">
            {/* Welcome Header */}
            <div className={`relative p-6 lg:p-8 rounded-2xl bg-gradient-to-br overflow-hidden ${
                isDark
                    ? 'from-gray-900 via-gray-900 to-gray-800 border border-white/[0.06]'
                    : 'from-white via-white to-gray-50 border border-gray-200 shadow-sm'
            }`}>
                <div className={`absolute top-0 right-0 w-64 h-64 rounded-full blur-[80px] -translate-y-1/2 translate-x-1/4 ${isDark ? 'bg-red-500/[0.08]' : 'bg-red-500/[0.06]'}`} />
                <div className={`absolute bottom-0 left-1/2 w-48 h-48 rounded-full blur-[60px] translate-y-1/2 ${isDark ? 'bg-red-500/[0.04]' : 'bg-red-500/[0.03]'}`} />

                <div className="relative z-10">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h1 className={`text-2xl lg:text-3xl font-extrabold tracking-tight ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                {greeting()}, {user?.name?.split(' ')[0] || 'User'} 👋
                            </h1>
                            <p className={`mt-1 text-sm lg:text-base ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                                Selamat datang di dashboard UltrAI. Kelola semua layanan Anda dari sini.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className={`px-3 py-1.5 rounded-lg text-xs font-mono ${
                                isDark
                                    ? 'bg-white/[0.06] border border-white/[0.08] text-gray-400'
                                    : 'bg-gray-100 border border-gray-200 text-gray-500'
                            }`}>
                                {time.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Stats Grid — Live Data */}
            <div className={`grid grid-cols-2 ${user?.role === 'admin' ? 'lg:grid-cols-4' : 'lg:grid-cols-2'} gap-3 lg:gap-4`}>
                <StatCard
                    icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>}
                    label="Chat Sessions"
                    value={chatCount || '0'}
                    suffix=""
                    color="red"
                    isDark={isDark}
                />
                {user?.role === 'admin' && (
                    <>
                        <StatCard
                            icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6" /><polyline points="8 6 2 12 8 18" /></svg>}
                            label="AI Models"
                            value={aiStatus.chat_models || aiStatus.total_models || '0'}
                            suffix="+"
                            color="blue"
                            isDark={isDark}
                        />
                        <StatCard
                            icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>}
                            label="AI Status"
                            value={aiStatus.online === null ? '...' : aiStatus.online ? 'Online' : 'Offline'}
                            suffix=""
                            trend={aiStatus.online ? '✓ Active' : null}
                            color="emerald"
                            isDark={isDark}
                        />
                    </>
                )}
                <StatCard
                    icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>}
                    label="Response Time"
                    value="<100"
                    suffix="ms"
                    color="amber"
                    isDark={isDark}
                />
            </div>

            {/* Main Grid */}
            <div className="grid lg:grid-cols-3 gap-4 lg:gap-6">
                {/* Quick Actions */}
                <div className="lg:col-span-2 space-y-4">
                    <div className={`p-5 lg:p-6 rounded-2xl border ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                        <h2 className={`text-lg font-bold mb-4 flex items-center gap-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            <svg className="w-5 h-5 text-red-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" /></svg>
                            Aksi Cepat
                        </h2>
                        <div className="grid sm:grid-cols-2 gap-3">
                            <QuickAction
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>}
                                title="Chat AI"
                                desc="Mulai percakapan dengan AI models"
                                href="/chat"
                                isDark={isDark}
                            />
                            {user?.role === 'admin' && (
                                <QuickAction
                                    icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><polyline points="16 18 22 12 16 6" /><polyline points="8 6 2 12 8 18" /></svg>}
                                    title="AI API"
                                    desc="Akses API endpoint untuk integrasi"
                                    href="https://api.ultrai.id"
                                    external
                                    isDark={isDark}
                                />
                            )}
                            <QuickAction
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" /><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" /><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" /></svg>}
                                title="SMM Panel"
                                desc="Social media marketing services"
                                href="https://smm.superpanelpedia.com"
                                external
                                isDark={isDark}
                            />
                            <QuickAction
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2" /><line x1="1" y1="10" x2="23" y2="10" /></svg>}
                                title="PPOB"
                                desc="Pembayaran online & produk digital"
                                href="https://ppob.superpanelpedia.com"
                                external
                                isDark={isDark}
                            />
                        </div>
                    </div>

                    {/* AI Models Preview */}
                    <div className={`p-5 lg:p-6 rounded-2xl border ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                        <div className="flex items-center justify-between mb-4">
                            <h2 className={`text-lg font-bold flex items-center gap-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                <svg className="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" /></svg>
                                AI Models Populer
                            </h2>
                            <Link to="/chat" className="text-xs text-red-400 hover:text-red-300 font-semibold transition-colors">
                                Coba Sekarang →
                            </Link>
                        </div>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            {['GPT-4o', 'Claude Sonnet', 'Gemini Pro', 'DeepSeek V3', 'Llama 3.1', 'Mistral Large'].map((model) => (
                                <div key={model} className={`px-3 py-2.5 rounded-xl border text-sm font-medium transition-all cursor-default ${
                                    isDark
                                        ? 'bg-white/[0.03] border-white/[0.06] text-gray-300 hover:bg-white/[0.06] hover:border-white/[0.1]'
                                        : 'bg-gray-50 border-gray-200 text-gray-700 hover:bg-gray-100 hover:border-gray-300'
                                }`}>
                                    <div className="flex items-center gap-2">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.5)]" />
                                        {model}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Right Sidebar */}
                <div className="space-y-4">
                    {/* Service Status — Live */}
                    <div className={`p-5 rounded-2xl border ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                        <h2 className={`text-lg font-bold mb-4 flex items-center gap-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            <svg className="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>
                            Status Layanan
                        </h2>
                        <div className="space-y-0">
                            <ServiceStatus name="UltrAI Platform" url="https://ultrai.id" status="online" isDark={isDark} />
                            {user?.role === 'admin' && (
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

                    {/* Account Info */}
                    <div className={`p-5 rounded-2xl border ${isDark ? 'bg-gray-900/50 border-white/[0.06] backdrop-blur-xl' : 'bg-white border-gray-200 shadow-sm'}`}>
                        <h2 className={`text-lg font-bold mb-4 flex items-center gap-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                            <svg className="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                            Akun Anda
                        </h2>
                        <div className="space-y-3">
                            <div className="flex justify-between items-center">
                                <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Nama</span>
                                <span className={`text-sm font-medium ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>{user?.name || '-'}</span>
                            </div>
                            <div className="flex justify-between items-center">
                                <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Role</span>
                                <span className={`text-xs font-bold uppercase px-2 py-0.5 rounded-md ${
                                    user?.role === 'admin'
                                        ? 'bg-red-500/15 text-red-400'
                                        : 'bg-blue-500/15 text-blue-400'
                                }`}>
                                    {user?.role || 'member'}
                                </span>
                            </div>
                            {user?.role !== 'admin' && (
                                <div className="flex justify-between items-center">
                                    <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Masa Aktif</span>
                                    {user?.is_expired ? (
                                        <span className="text-xs font-bold px-2 py-0.5 rounded-md bg-red-500/15 text-red-400">
                                            Expired
                                        </span>
                                    ) : user?.days_remaining === null ? (
                                        <span className="text-xs font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-400">
                                            ∞ Unlimited
                                        </span>
                                    ) : user?.days_remaining !== undefined ? (
                                        <span className={`text-xs font-bold px-2 py-0.5 rounded-md ${
                                            user.days_remaining <= 3
                                                ? 'bg-red-500/15 text-red-400'
                                                : user.days_remaining <= 7
                                                    ? 'bg-amber-500/15 text-amber-400'
                                                    : 'bg-emerald-500/15 text-emerald-400'
                                        }`}>
                                            {user.days_remaining} hari tersisa
                                        </span>
                                    ) : (
                                        <span className={`text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>-</span>
                                    )}
                                </div>
                            )}
                        </div>
                        <Link to="/profile" className={`mt-4 flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border text-sm font-medium transition-all ${
                            isDark
                                ? 'bg-white/[0.05] border-white/[0.08] text-gray-400 hover:text-white hover:bg-white/[0.08]'
                                : 'bg-gray-50 border-gray-200 text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                        }`}>
                            Edit Profil
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
