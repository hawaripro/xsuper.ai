import React from 'react';

/* ============================================================
   Services — SMM Panel / PPOB / Produk Digital
   All three are "Coming Soon". Click scrolls back to top of
   the landing (hash: #top or scrollTo(0)) instead of navigating
   away.
   ============================================================ */

const services = [
    {
        key: 'smm',
        title: 'SMM Panel',
        desc: 'Social media marketing — followers, likes, views, engagement untuk semua platform populer.',
        accent: 'red',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="svc-smm-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#ef4444" />
                        <stop offset="100%" stopColor="#f97316" />
                    </linearGradient>
                </defs>
                <circle cx="32" cy="32" r="26" fill="url(#svc-smm-g)" opacity="0.12" />
                <circle cx="32" cy="32" r="26" fill="none" stroke="url(#svc-smm-g)" strokeWidth="2" />
                <circle cx="32" cy="22" r="6" fill="url(#svc-smm-g)" opacity="0.9" />
                <circle cx="18" cy="36" r="4" fill="url(#svc-smm-g)" opacity="0.75" />
                <circle cx="46" cy="36" r="4" fill="url(#svc-smm-g)" opacity="0.75" />
                <path d="M32 28 L18 32 M32 28 L46 32" stroke="url(#svc-smm-g)" strokeWidth="2" strokeLinecap="round" />
                <path d="M28 46 q4 4 8 0" stroke="url(#svc-smm-g)" strokeWidth="2" strokeLinecap="round" fill="none" />
            </svg>
        ),
    },
    {
        key: 'ppob',
        title: 'PPOB',
        desc: 'Payment Point Online Bank — pulsa, token listrik, BPJS, internet, dan ratusan layanan pembayaran lainnya.',
        accent: 'amber',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="svc-ppob-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#f59e0b" />
                        <stop offset="100%" stopColor="#f97316" />
                    </linearGradient>
                </defs>
                <rect x="8" y="16" width="48" height="32" rx="4" fill="url(#svc-ppob-g)" opacity="0.12" stroke="url(#svc-ppob-g)" strokeWidth="2" />
                <rect x="8" y="22" width="48" height="6" fill="url(#svc-ppob-g)" opacity="0.25" />
                <rect x="14" y="34" width="16" height="4" rx="1" fill="url(#svc-ppob-g)" opacity="0.7" />
                <rect x="14" y="40" width="10" height="3" rx="1" fill="url(#svc-ppob-g)" opacity="0.45" />
                <circle cx="46" cy="40" r="5" fill="url(#svc-ppob-g)" opacity="0.85" />
            </svg>
        ),
    },
    {
        key: 'digital',
        title: 'Produk Digital',
        desc: 'Marketplace produk digital — akun premium, software license, template, dan banyak lagi.',
        accent: 'violet',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="svc-dig-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#a855f7" />
                        <stop offset="100%" stopColor="#ec4899" />
                    </linearGradient>
                </defs>
                <path d="M32 8 L56 20 V44 L32 56 L8 44 V20 Z" fill="url(#svc-dig-g)" opacity="0.12" stroke="url(#svc-dig-g)" strokeWidth="2" strokeLinejoin="round" />
                <path d="M8 20 L32 32 L56 20" stroke="url(#svc-dig-g)" strokeWidth="2" strokeLinejoin="round" />
                <line x1="32" y1="32" x2="32" y2="56" stroke="url(#svc-dig-g)" strokeWidth="2" />
                <circle cx="32" cy="32" r="4" fill="url(#svc-dig-g)" />
            </svg>
        ),
    },
];

const ACCENT = {
    red: {
        glow: 'bg-red-400/15',
        ring: 'ring-red-500/20',
        pulse: 'bg-red-500',
    },
    amber: {
        glow: 'bg-amber-400/15',
        ring: 'ring-amber-500/20',
        pulse: 'bg-amber-500',
    },
    violet: {
        glow: 'bg-violet-400/15',
        ring: 'ring-violet-500/20',
        pulse: 'bg-violet-500',
    },
};

export default function Services() {
    const scrollToTop = (e) => {
        e.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <section id="services" className="relative py-20 md:py-24 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-gray-50/40 to-white pointer-events-none" />

            <div className="relative max-w-7xl mx-auto px-4 md:px-6">
                <div className="text-center mb-12 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-black uppercase tracking-[0.15em] border border-red-200/80 mb-5">
                        Layanan Lainnya
                    </span>
                    <h2 className="text-3xl md:text-5xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Ekosistem <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">Digital Lengkap</span>
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-2xl mx-auto">
                        Selain akses AI, kami juga siapin layanan digital lain untuk memenuhi kebutuhan kamu.
                        <span className="block mt-1 text-sm text-slate-500">Sabar ya, semua lagi on the way.</span>
                    </p>
                </div>

                <div className="grid md:grid-cols-3 gap-5 lg:gap-6">
                    {services.map((s, i) => {
                        const a = ACCENT[s.accent];
                        return (
                            <button
                                key={s.key}
                                onClick={scrollToTop}
                                type="button"
                                aria-label={`${s.title} — akan segera hadir`}
                                className="group relative block text-left w-full p-7 rounded-3xl bg-white border border-gray-200/80 shadow-[0_8px_24px_-8px_rgba(15,23,42,0.08)] hover:shadow-[0_24px_48px_-12px_rgba(15,23,42,0.18)] hover:-translate-y-1.5 transition-all duration-300 overflow-hidden cursor-pointer"
                                style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${80 + i * 80}ms` }}
                            >
                                {/* Accent glow */}
                                <div className={`absolute -top-20 -right-20 w-60 h-60 rounded-full blur-3xl ${a.glow}`} />
                                {/* Soft grayscale veil to signal "not active yet" */}
                                <div className="absolute inset-0 bg-gradient-to-br from-white/0 via-white/0 to-slate-100/40 pointer-events-none" aria-hidden="true" />

                                {/* Coming Soon ribbon (top-right) */}
                                <span
                                    className="absolute top-5 right-5 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-[0.12em] bg-gradient-to-r from-amber-400 to-orange-500 text-amber-950 shadow-[0_4px_14px_-2px_rgba(245,158,11,0.35)]"
                                >
                                    <span className={`relative flex w-1.5 h-1.5`}>
                                        <span className={`absolute inline-flex h-full w-full rounded-full ${a.pulse} opacity-70 animate-ping`} />
                                        <span className={`relative inline-flex w-1.5 h-1.5 rounded-full ${a.pulse}`} />
                                    </span>
                                    Coming Soon
                                </span>

                                <div className="relative">
                                    <div className={`w-20 h-20 mb-5 transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-3`}>
                                        <s.Icon className="w-full h-full" />
                                    </div>
                                    <h3 className="text-xl font-extrabold tracking-tight text-slate-900 mb-2">{s.title}</h3>
                                    <p className="text-sm text-slate-600 leading-relaxed mb-5">{s.desc}</p>

                                    <div className="flex items-center gap-1.5 text-xs font-bold text-slate-500 group-hover:text-red-500 transition-colors">
                                        <svg className="w-4 h-4 group-hover:-translate-x-0.5 transition-transform" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="19 12 5 12"/><polyline points="12 19 5 12 12 5"/></svg>
                                        Kembali ke UltrAI
                                    </div>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
