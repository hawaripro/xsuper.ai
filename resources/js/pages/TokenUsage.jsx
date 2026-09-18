import React, { useState, useEffect, useCallback } from 'react';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import { AreaChart, Area, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';

const COLORS = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316', '#14b8a6', '#a855f7', '#6366f1', '#84cc16', '#e11d48', '#0ea5e9', '#d946ef'];

const PERIODS = [
    { key: 'hourly',  label: 'Per Jam',    desc: '24 jam terakhir' },
    { key: 'daily',   label: 'Per Hari',   desc: '30 hari terakhir' },
    { key: 'weekly',  label: 'Per Minggu', desc: '12 minggu terakhir' },
    { key: 'monthly', label: 'Per Bulan',  desc: '12 bulan terakhir' },
    { key: 'all',     label: 'Semua',      desc: 'Seluruh waktu' },
];

/* ============================================================
   Stat card with animated counter
   ============================================================ */
const STAT_VARIANTS = {
    red:     { grad: 'from-red-500 to-red-600',         ring: 'ring-red-500/25',     glow: 'bg-red-500/10' },
    blue:    { grad: 'from-blue-500 to-indigo-500',     ring: 'ring-blue-500/25',    glow: 'bg-blue-500/10' },
    emerald: { grad: 'from-emerald-500 to-teal-500',    ring: 'ring-emerald-500/25', glow: 'bg-emerald-500/10' },
    amber:   { grad: 'from-amber-500 to-orange-500',    ring: 'ring-amber-500/25',   glow: 'bg-amber-500/10' },
    violet:  { grad: 'from-violet-500 to-purple-500',   ring: 'ring-violet-500/25',  glow: 'bg-violet-500/10' },
};

function StatCard({ label, value, icon, color, isDark }) {
    const v = STAT_VARIANTS[color] || STAT_VARIANTS.red;
    return (
        <div
            className={`
                group relative overflow-hidden p-4 rounded-2xl border
                transition-all duration-300 ease-out hover:-translate-y-1 hover:shadow-lg
                ${isDark
                    ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl hover:border-white/[0.14]'
                    : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)] hover:border-gray-300'
                }
            `}
        >
            <div className={`absolute -top-12 -right-12 w-32 h-32 rounded-full blur-3xl ${v.glow} opacity-70 group-hover:opacity-100 group-hover:scale-110 transition-all duration-500`} />
            <div className="relative">
                <div className={`w-9 h-9 rounded-xl bg-gradient-to-br ${v.grad} text-white flex items-center justify-center shadow-md ring-4 ${v.ring} mb-3 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300`}>
                    {icon}
                </div>
                <div className={`text-xl font-extrabold tabular-nums tracking-tight ${isDark ? 'text-white' : 'text-slate-900'}`}>{value}</div>
                <div className={`text-[10px] mt-0.5 font-semibold uppercase tracking-wider ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{label}</div>
            </div>
        </div>
    );
}

/* ============================================================
   Custom tooltip with brand accent
   ============================================================ */
function CustomTooltip({ active, payload, label, isDark }) {
    if (!active || !payload?.length) return null;
    return (
        <div
            className={`overflow-hidden rounded-xl border shadow-xl animate-fade-in ${
                isDark ? 'bg-gray-900/95 border-white/10 backdrop-blur-xl' : 'bg-white/95 border-gray-200 backdrop-blur-xl'
            }`}
        >
            <div className="h-1 bg-gradient-to-r from-red-500 via-orange-500 to-red-500 animate-gradient" />
            <div className="p-3">
                <p className={`text-xs font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>{label}</p>
                {payload.map((p, i) => (
                    <div key={i} className="flex items-center gap-2 text-[11px] mb-0.5 last:mb-0">
                        <span className="w-2 h-2 rounded-full" style={{ background: p.color }} />
                        <span className={isDark ? 'text-gray-400' : 'text-gray-500'}>{p.name}:</span>
                        <span className={`font-bold tabular-nums ml-auto ${isDark ? 'text-white' : 'text-slate-900'}`}>{Number(p.value).toLocaleString()}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function TokenUsage() {
    const { t } = useLocale();
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState('daily');
    const [selectedUser, setSelectedUser] = useState(null);
    const [userStats, setUserStats] = useState(null);
    const [chartType, setChartType] = useState('area');

    const textClass = isDark ? 'text-white' : 'text-slate-900';
    const subClass  = isDark ? 'text-gray-400' : 'text-gray-500';
    const cardClass = isDark
        ? 'bg-gray-900/60 border-white/[0.06] backdrop-blur-xl'
        : 'bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]';
    const gridColor = isDark ? 'rgba(255,255,255,0.05)' : 'rgba(15,23,42,0.06)';
    const axisColor = isDark ? '#6b7280' : '#94a3b8';

    const userTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Jakarta';

    const loadStats = useCallback(async (isInitial = false) => {
        if (isInitial) setLoading(true);
        try {
            const res = await fetch(`/api/usage?period=${period}&tz=${encodeURIComponent(userTz)}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) setStats(await res.json());
        } catch {} finally {
            if (isInitial) setLoading(false);
        }
    }, [period, userTz]);

    useEffect(() => {
        loadStats(true);
        const interval = setInterval(() => loadStats(false), 2000);
        return () => clearInterval(interval);
    }, [loadStats]);

    const loadUserStats = async (userId) => {
        setSelectedUser(userId);
        try {
            const res = await fetch(`/api/usage?user_id=${userId}&period=${period}&tz=${encodeURIComponent(userTz)}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) setUserStats(await res.json());
        } catch {}
    };

    // ESC closes modal
    useEffect(() => {
        if (!selectedUser) return;
        const onKey = (e) => {
            if (e.key === 'Escape') { setSelectedUser(null); setUserStats(null); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [selectedUser]);

    const fillTimeline = (data, models) => {
        if (!data || data.length === 0) return { data: [], models: models || [] };
        if (period === 'hourly') {
            const map = {};
            data.forEach(d => { map[d.label] = d; });
            const filled = [];
            for (let h = 0; h < 24; h++) {
                const label = String(h).padStart(2, '0') + ':00';
                const existing = map[label] || {};
                const entry = { label };
                (models || []).forEach(m => { entry[m] = existing[m] || 0; });
                entry.tokens = existing.tokens || 0;
                entry.requests = existing.requests || 0;
                filled.push(entry);
            }
            return { data: filled, models: models || [] };
        }
        if (period === 'daily') {
            const map = {};
            data.forEach(d => { map[d.label] = d; });
            const filled = [];
            for (let i = 29; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                const label = String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                const existing = map[label] || {};
                const entry = { label };
                (models || []).forEach(m => { entry[m] = existing[m] || 0; });
                entry.tokens = existing.tokens || 0;
                entry.requests = existing.requests || 0;
                filled.push(entry);
            }
            return { data: filled, models: models || [] };
        }
        return { data, models: models || [] };
    };

    const rawModelTimeline = stats?.model_timeline || { data: [], models: [] };
    const modelTimeline = React.useMemo(
        () => fillTimeline(rawModelTimeline.data, rawModelTimeline.models),
        [rawModelTimeline.data, rawModelTimeline.models, period]
    );
    const filledTimeline = React.useMemo(
        () => fillTimeline(stats?.timeline || [], []).data,
        [stats?.timeline, period]
    );
    const maxModel = Math.max(...(stats?.by_model || []).map(x => Number(x.tokens)), 1);

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-7xl mx-auto">
            {/* ============================================
                Header
               ============================================ */}
            <section className="animate-fade-in-up">
                <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                    <div>
                        <h1 className={`text-2xl lg:text-3xl font-extrabold tracking-tight ${textClass}`}>{t("Token ")}<span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">{t("Usage")}</span>
                        </h1>
                        <p className={`text-sm mt-1 ${subClass}`}>{t("Pantau penggunaan token AI seluruh platform secara realtime.")}</p>
                    </div>
                    <button
                        onClick={() => loadStats(true)}
                        className="ui-btn-ghost"
                        aria-label={t("Refresh data")}
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>{t("Refresh")}</button>
                </div>
            </section>

            {/* ============================================
                Stats cards
               ============================================ */}
            <section className="grid grid-cols-2 lg:grid-cols-5 gap-3 stagger">
                <StatCard isDark={isDark} label={t("Total Tokens")} color="red"
                    value={(stats?.total_tokens || 0).toLocaleString('id-ID')}
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>}
                />
                <StatCard isDark={isDark} label={t("Credits")} color="blue"
                    value={(stats?.total_credits || 0).toFixed(2)}
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>}
                />
                <StatCard isDark={isDark} label={t("Requests")} color="emerald"
                    value={(stats?.total_requests || 0).toLocaleString('id-ID')}
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>}
                />
                <StatCard isDark={isDark} label={t("Users")} color="amber"
                    value={stats?.active_users || 0}
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>}
                />
                <StatCard isDark={isDark} label={t("Avg/Req")} color="violet"
                    value={stats?.total_requests ? Math.round((stats?.total_tokens || 0) / stats.total_requests).toLocaleString('id-ID') : '0'}
                    icon={<svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>}
                />
            </section>

            {/* ============================================
                Period + chart type selectors
               ============================================ */}
            <section className="flex flex-wrap items-center gap-2 animate-fade-in">
                <div
                    role="tablist"
                    aria-label={t("Period")}
                    className={`inline-flex rounded-xl p-1 border ${isDark ? 'bg-white/[0.03] border-white/[0.06]' : 'bg-gray-100/80 border-gray-200'}`}
                >
                    {PERIODS.map(p => {
                        const active = period === p.key;
                        return (
                            <button
                                key={p.key}
                                onClick={() => setPeriod(p.key)}
                                role="tab"
                                aria-selected={active}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 ${
                                    active
                                        ? 'bg-gradient-to-r from-red-500 to-red-600 text-white shadow-[0_4px_12px_-2px_rgba(239,68,68,0.35)]'
                                        : isDark ? 'text-gray-400 hover:text-white' : 'text-gray-600 hover:text-slate-900'
                                }`}
                            >
                                {t(p.label)}
                            </button>
                        );
                    })}
                </div>
                <div
                    role="tablist"
                    aria-label={t("Chart type")}
                    className={`inline-flex rounded-xl p-1 border ${isDark ? 'bg-white/[0.03] border-white/[0.06]' : 'bg-gray-100/80 border-gray-200'}`}
                >
                    {[['area', 'Area'], ['bar', 'Bar']].map(([k, l]) => {
                        const active = chartType === k;
                        return (
                            <button
                                key={k}
                                onClick={() => setChartType(k)}
                                role="tab"
                                aria-selected={active}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 ${
                                    active
                                        ? isDark ? 'bg-white/10 text-white shadow-sm' : 'bg-white text-slate-900 shadow-sm'
                                        : isDark ? 'text-gray-500 hover:text-gray-300' : 'text-gray-500 hover:text-slate-700'
                                }`}
                            >
                                {l}
                            </button>
                        );
                    })}
                </div>
                <span className={`text-xs ml-auto ${subClass}`}>{t(PERIODS.find(p => p.key === period)?.desc || "")}</span>
            </section>

            {loading ? (
                <div className="grid gap-4">
                    <div className="ui-skeleton h-[320px]" />
                    <div className="grid lg:grid-cols-2 gap-4">
                        <div className="ui-skeleton h-[200px]" />
                        <div className="ui-skeleton h-[200px]" />
                    </div>
                </div>
            ) : (
                <>
                    {/* =====================================
                        Main timeline chart
                       ===================================== */}
                    <section className={`p-5 rounded-2xl border ${cardClass} animate-fade-in-up`}>
                        <h3 className={`text-sm font-bold mb-4 flex items-center gap-2 ${textClass}`}>
                            <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center shadow-md">
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            </span>
                            Token Usage Timeline &mdash; Per Model
                        </h3>
                        <div style={{ width: '100%', height: 320 }}>
                            <ResponsiveContainer>
                                {chartType === 'area' ? (
                                    <AreaChart data={modelTimeline.data} margin={{ top: 5, right: 12, bottom: 5, left: 0 }}>
                                        <defs>
                                            {modelTimeline.models.map((m, i) => (
                                                <linearGradient key={m} id={`grad-${i}`} x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor={COLORS[i % COLORS.length]} stopOpacity={0.32} />
                                                    <stop offset="95%" stopColor={COLORS[i % COLORS.length]} stopOpacity={0} />
                                                </linearGradient>
                                            ))}
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                        <XAxis dataKey="label" tick={{ fontSize: 10, fill: axisColor }} axisLine={{ stroke: gridColor }} tickLine={false} />
                                        <YAxis tick={{ fontSize: 10, fill: axisColor }} tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}k` : v} axisLine={{ stroke: gridColor }} tickLine={false} />
                                        <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                        <Legend wrapperStyle={{ fontSize: 11, paddingTop: 8 }} iconType="circle" />
                                        {modelTimeline.models.map((m, i) => (
                                            <Area
                                                key={m}
                                                type="monotone"
                                                dataKey={m}
                                                stackId="1"
                                                stroke={COLORS[i % COLORS.length]}
                                                fill={`url(#grad-${i})`}
                                                strokeWidth={2}
                                                dot={false}
                                                activeDot={{ r: 4, strokeWidth: 2 }}
                                                animationDuration={900}
                                                animationBegin={i * 80}
                                            />
                                        ))}
                                    </AreaChart>
                                ) : (
                                    <BarChart data={modelTimeline.data} margin={{ top: 5, right: 12, bottom: 5, left: 0 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                        <XAxis dataKey="label" tick={{ fontSize: 10, fill: axisColor }} axisLine={{ stroke: gridColor }} tickLine={false} />
                                        <YAxis tick={{ fontSize: 10, fill: axisColor }} tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}k` : v} axisLine={{ stroke: gridColor }} tickLine={false} />
                                        <Tooltip content={<CustomTooltip isDark={isDark} />} cursor={{ fill: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(15,23,42,0.04)' }} />
                                        <Legend wrapperStyle={{ fontSize: 11, paddingTop: 8 }} iconType="circle" />
                                        {modelTimeline.models.map((m, i) => (
                                            <Bar
                                                key={m}
                                                dataKey={m}
                                                stackId="1"
                                                fill={COLORS[i % COLORS.length]}
                                                animationDuration={800}
                                                animationBegin={i * 50}
                                                radius={i === modelTimeline.models.length - 1 ? [6, 6, 0, 0] : [0, 0, 0, 0]}
                                            />
                                        ))}
                                    </BarChart>
                                )}
                            </ResponsiveContainer>
                        </div>
                    </section>

                    <div className="grid lg:grid-cols-2 gap-5">
                        {/* =====================================
                            Total timeline chart
                           ===================================== */}
                        <section className={`p-5 rounded-2xl border ${cardClass} animate-fade-in-up`}>
                            <h3 className={`text-sm font-bold mb-4 flex items-center gap-2 ${textClass}`}>
                                <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 text-white flex items-center justify-center shadow-md">
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                </span>{t("Total Tokens")}</h3>
                            <div style={{ width: '100%', height: 220 }}>
                                <ResponsiveContainer>
                                    <AreaChart data={filledTimeline} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                                        <defs>
                                            <linearGradient id="gradTotal" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#10b981" stopOpacity={0.35} />
                                                <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                        <XAxis dataKey="label" tick={{ fontSize: 10, fill: axisColor }} axisLine={{ stroke: gridColor }} tickLine={false} />
                                        <YAxis tick={{ fontSize: 10, fill: axisColor }} tickFormatter={v => v >= 1000 ? `${(v/1000).toFixed(0)}k` : v} axisLine={{ stroke: gridColor }} tickLine={false} />
                                        <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                        <Area type="monotone" dataKey="tokens" name="Tokens" stroke="#10b981" fill="url(#gradTotal)" strokeWidth={2.5} dot={false} activeDot={{ r: 5, strokeWidth: 2, fill: '#10b981' }} animationDuration={1000} />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </section>

                        {/* =====================================
                            Model breakdown
                           ===================================== */}
                        <section className={`p-5 rounded-2xl border ${cardClass} animate-fade-in-up`}>
                            <h3 className={`text-sm font-bold mb-4 flex items-center gap-2 ${textClass}`}>
                                <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center shadow-md">
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                                </span>{t("Model Breakdown")}</h3>
                            <div className="space-y-3 max-h-56 overflow-y-auto scrollbar-thin pr-1 stagger">
                                {(stats?.by_model || []).map((m, i) => {
                                    const pct = (Number(m.tokens) / maxModel * 100);
                                    return (
                                        <div key={i}>
                                            <div className="flex items-center justify-between mb-1">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="w-2 h-2 rounded-full flex-shrink-0 shadow-[0_0_6px]" style={{ background: COLORS[i % COLORS.length], boxShadow: `0 0 6px ${COLORS[i % COLORS.length]}66` }} />
                                                    <span className={`text-xs font-medium truncate ${isDark ? 'text-gray-200' : 'text-slate-700'}`}>{m.model}</span>
                                                </div>
                                                <span className={`text-[11px] font-bold tabular-nums ml-2 flex-shrink-0 ${textClass}`}>{Number(m.tokens).toLocaleString('id-ID')}</span>
                                            </div>
                                            <div className={`h-2 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.04]' : 'bg-gray-100'}`}>
                                                <div
                                                    className="h-full rounded-full"
                                                    style={{
                                                        transform: `scaleX(${pct/100})`,
                                                        transformOrigin: 'left',
                                                        transition: 'transform 0.8s cubic-bezier(0.16, 1, 0.3, 1)',
                                                        background: `linear-gradient(90deg, ${COLORS[i % COLORS.length]}, ${COLORS[i % COLORS.length]}cc)`,
                                                        width: '100%',
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                                {(stats?.by_model || []).length === 0 && (
                                    <p className={`text-xs text-center py-8 ${subClass}`}>{t("Belum ada data")}</p>
                                )}
                            </div>
                        </section>
                    </div>

                    {/* =====================================
                        Top users table
                       ===================================== */}
                    <section className={`p-5 rounded-2xl border ${cardClass} animate-fade-in-up`}>
                        <h3 className={`text-sm font-bold mb-4 flex items-center gap-2 ${textClass}`}>
                            <span className="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center shadow-md">
                                <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            </span>{t("Top Users")}</h3>
                        <div className="overflow-x-auto -mx-5 sm:mx-0 scrollbar-thin">
                            <table className="w-full min-w-[480px]">
                                <thead>
                                    <tr className={`border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                                        {['#', 'User', 'Tokens', 'Credits', 'Requests'].map((h, idx) => (
                                            <th key={h} className={`${idx <= 1 ? 'text-left' : 'text-right'} px-3 py-2.5 text-[10px] font-bold uppercase tracking-[0.1em] ${subClass}`}>{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="stagger">
                                    {(stats?.top_users || []).map((u, i) => {
                                        const rankClass = i === 0
                                            ? 'bg-gradient-to-br from-amber-400 to-amber-600 text-white shadow-[0_4px_12px_-2px_rgba(245,158,11,0.5)]'
                                            : i === 1
                                            ? 'bg-gradient-to-br from-slate-300 to-slate-500 text-white shadow-md'
                                            : i === 2
                                            ? 'bg-gradient-to-br from-amber-700 to-amber-900 text-white shadow-md'
                                            : (isDark ? 'bg-white/[0.05] text-gray-400' : 'bg-gray-100 text-gray-500');
                                        return (
                                            <tr
                                                key={i}
                                                onClick={() => loadUserStats(u.user_id)}
                                                className={`group border-b cursor-pointer transition-colors ${
                                                    isDark
                                                        ? 'border-white/[0.04] hover:bg-white/[0.03]'
                                                        : 'border-gray-100 hover:bg-red-50/40'
                                                }`}
                                            >
                                                <td className="px-3 py-2.5">
                                                    <span className={`inline-flex items-center justify-center w-6 h-6 rounded-md text-[10px] font-extrabold ${rankClass}`}>
                                                        {i < 3 ? ['🥇', '🥈', '🥉'][i] : i + 1}
                                                    </span>
                                                </td>
                                                <td className={`px-3 py-2.5 text-xs font-semibold ${textClass} group-hover:text-red-500 transition-colors`}>
                                                    {u.user?.name || '-'}
                                                </td>
                                                <td className={`px-3 py-2.5 text-xs text-right font-bold tabular-nums ${textClass}`}>
                                                    {Number(u.tokens).toLocaleString('id-ID')}
                                                </td>
                                                <td className={`px-3 py-2.5 text-xs text-right tabular-nums ${subClass}`}>
                                                    {Number(u.credits).toFixed(2)}
                                                </td>
                                                <td className={`px-3 py-2.5 text-xs text-right tabular-nums ${subClass}`}>
                                                    {u.requests}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {(stats?.top_users || []).length === 0 && (
                                        <tr>
                                            <td colSpan="5" className={`text-center py-10 text-sm ${subClass}`}>{t("Belum ada data")}</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            )}

            {/* ============================================
                User detail modal
               ============================================ */}
            {selectedUser && userStats && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 animate-fade-in"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="user-detail-title"
                >
                    <div
                        className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"
                        onClick={() => { setSelectedUser(null); setUserStats(null); }}
                    />
                    <div
                        className={`relative w-full max-w-2xl max-h-[85vh] overflow-y-auto rounded-2xl border p-6 scrollbar-thin animate-scale-in ${
                            isDark
                                ? 'bg-gray-900/95 border-white/[0.08] backdrop-blur-2xl shadow-2xl'
                                : 'bg-white border-gray-200 shadow-[0_32px_64px_-16px_rgba(15,23,42,0.25)]'
                        }`}
                    >
                        <div className="flex items-center justify-between mb-5">
                            <h3 id="user-detail-title" className={`text-lg font-bold ${textClass} flex items-center gap-2`}>
                                <span className="w-8 h-8 rounded-lg bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center shadow-md">
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                                </span>{t("Detail Usage")}</h3>
                            <button
                                onClick={() => { setSelectedUser(null); setUserStats(null); }}
                                className={`p-2 rounded-xl transition-colors ${
                                    isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-500 hover:text-red-500 hover:bg-red-50'
                                }`}
                                aria-label={t("Close")}
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>

                        <div className="grid grid-cols-3 gap-3 mb-5">
                            {[
                                { label: 'Tokens',   value: (userStats.total_tokens || 0).toLocaleString('id-ID'), grad: 'from-red-500 to-red-600' },
                                { label: 'Credits',  value: (userStats.total_credits || 0).toFixed(2),             grad: 'from-blue-500 to-indigo-500' },
                                { label: 'Requests', value: userStats.total_requests || 0,                         grad: 'from-emerald-500 to-teal-500' },
                            ].map((s, i) => (
                                <div
                                    key={i}
                                    className={`p-3 rounded-xl text-center border ${
                                        isDark ? 'bg-white/[0.03] border-white/[0.06]' : 'bg-gray-50/80 border-gray-200'
                                    }`}
                                >
                                    <div className={`text-sm font-extrabold tabular-nums bg-gradient-to-r ${s.grad} bg-clip-text text-transparent`}>{s.value}</div>
                                    <div className={`text-[10px] mt-0.5 font-semibold uppercase tracking-wider ${subClass}`}>{t(s.label)}</div>
                                </div>
                            ))}
                        </div>

                        <div className="mb-5" style={{ width: '100%', height: 220 }}>
                            <ResponsiveContainer>
                                <AreaChart data={userStats.timeline || []} margin={{ top: 5, right: 10, bottom: 5, left: 0 }}>
                                    <defs>
                                        <linearGradient id="gradUser" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#ef4444" stopOpacity={0.35} />
                                            <stop offset="95%" stopColor="#ef4444" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" stroke={gridColor} />
                                    <XAxis dataKey="label" tick={{ fontSize: 10, fill: axisColor }} axisLine={{ stroke: gridColor }} tickLine={false} />
                                    <YAxis tick={{ fontSize: 10, fill: axisColor }} axisLine={{ stroke: gridColor }} tickLine={false} />
                                    <Tooltip content={<CustomTooltip isDark={isDark} />} />
                                    <Area type="monotone" dataKey="tokens" name="Tokens" stroke="#ef4444" fill="url(#gradUser)" strokeWidth={2.5} dot={false} activeDot={{ r: 5, strokeWidth: 2, fill: '#ef4444' }} animationDuration={900} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>

                        <h4 className={`text-xs font-bold uppercase tracking-wider mb-3 ${subClass}`}>{t("Per Model")}</h4>
                        <div className="space-y-2.5">
                            {(userStats.by_model || []).map((m, i) => {
                                const maxU = Math.max(...(userStats.by_model || []).map(x => Number(x.tokens)), 1);
                                const pct = (Number(m.tokens) / maxU) * 100;
                                return (
                                    <div key={i}>
                                        <div className="flex justify-between mb-1">
                                            <div className="flex items-center gap-2 min-w-0">
                                                <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: COLORS[i % COLORS.length] }} />
                                                <span className={`text-xs truncate ${isDark ? 'text-gray-200' : 'text-slate-700'}`}>{m.model}</span>
                                            </div>
                                            <span className={`text-[11px] font-bold tabular-nums ml-2 ${textClass}`}>{Number(m.tokens).toLocaleString('id-ID')}</span>
                                        </div>
                                        <div className={`h-1.5 rounded-full overflow-hidden ${isDark ? 'bg-white/[0.05]' : 'bg-gray-100'}`}>
                                            <div
                                                className="h-full rounded-full"
                                                style={{
                                                    transform: `scaleX(${pct/100})`,
                                                    transformOrigin: 'left',
                                                    transition: 'transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)',
                                                    background: COLORS[i % COLORS.length],
                                                    width: '100%',
                                                }}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
