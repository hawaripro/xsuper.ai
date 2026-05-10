import React from 'react';

/* ============================================================
   Audience section — "Tepat untuk kamu yang..."
   5 audience cards with animated SVG icons.
   ============================================================ */

const AUDIENCES = [
    {
        key: 'dev',
        title: 'Developer / Programmer',
        desc: 'Pair-programming dengan Claude & GPT-4o. Integrasi langsung ke VSCode, Cursor, OpenCode. Generate kode, debug, review PR tanpa limit.',
        accent: 'red',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="aud-dev-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#ef4444" />
                        <stop offset="100%" stopColor="#f97316" />
                    </linearGradient>
                </defs>
                <rect x="6" y="10" width="52" height="38" rx="5" fill="url(#aud-dev-g)" opacity="0.12" stroke="url(#aud-dev-g)" strokeWidth="2" />
                <rect x="6" y="10" width="52" height="10" rx="5" fill="url(#aud-dev-g)" opacity="0.25" />
                <circle cx="12" cy="15" r="1.4" fill="#ef4444" />
                <circle cx="16" cy="15" r="1.4" fill="#ef4444" opacity="0.6" />
                <circle cx="20" cy="15" r="1.4" fill="#ef4444" opacity="0.35" />
                {/* Code lines — animated shimmer */}
                <g>
                    <rect x="12" y="26" width="18" height="2.5" rx="1.2" fill="#ef4444" opacity="0.7">
                        <animate attributeName="width" values="10;24;10" dur="3s" repeatCount="indefinite" />
                    </rect>
                    <rect x="12" y="32" width="28" height="2.5" rx="1.2" fill="#f97316" opacity="0.65">
                        <animate attributeName="width" values="18;32;18" dur="3.4s" repeatCount="indefinite" />
                    </rect>
                    <rect x="12" y="38" width="14" height="2.5" rx="1.2" fill="#ef4444" opacity="0.5">
                        <animate attributeName="width" values="8;20;8" dur="2.8s" repeatCount="indefinite" />
                    </rect>
                </g>
                <rect x="20" y="50" width="24" height="5" rx="2" fill="#1e293b" opacity="0.85" />
                <rect x="14" y="55" width="36" height="3" rx="1.5" fill="#1e293b" opacity="0.6" />
            </svg>
        ),
    },
    {
        key: 'student',
        title: 'Mahasiswa & Pelajar',
        desc: 'Bantu ngerjain tugas, bikin esai, ringkas jurnal, belajar bahasa. Harga 5rb/hari — lebih murah dari jajanan kantin.',
        accent: 'blue',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="aud-stu-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#3b82f6" />
                        <stop offset="100%" stopColor="#6366f1" />
                    </linearGradient>
                </defs>
                {/* Book */}
                <path d="M10 16 L32 10 L54 16 V48 L32 54 L10 48 Z" fill="url(#aud-stu-g)" opacity="0.15" stroke="url(#aud-stu-g)" strokeWidth="2" strokeLinejoin="round" />
                <line x1="32" y1="10" x2="32" y2="54" stroke="url(#aud-stu-g)" strokeWidth="1.5" opacity="0.5" />
                {/* Graduation cap — floating */}
                <g>
                    <animateTransform attributeName="transform" type="translate" values="0,0; 0,-3; 0,0" dur="3s" repeatCount="indefinite" />
                    <path d="M32 20 L48 26 L32 32 L16 26 Z" fill="url(#aud-stu-g)" opacity="0.9" />
                    <rect x="44" y="26" width="1.6" height="10" fill="url(#aud-stu-g)" />
                    <circle cx="44.8" cy="37" r="2" fill="#f97316" />
                </g>
                {/* Lines = content */}
                <line x1="14" y1="38" x2="26" y2="36" stroke="#3b82f6" strokeWidth="1.6" strokeLinecap="round" opacity="0.5" />
                <line x1="14" y1="42" x2="28" y2="40" stroke="#3b82f6" strokeWidth="1.6" strokeLinecap="round" opacity="0.4" />
                <line x1="38" y1="40" x2="50" y2="38" stroke="#3b82f6" strokeWidth="1.6" strokeLinecap="round" opacity="0.5" />
                <line x1="38" y1="44" x2="48" y2="42" stroke="#3b82f6" strokeWidth="1.6" strokeLinecap="round" opacity="0.4" />
            </svg>
        ),
    },
    {
        key: 'creator',
        title: 'Content Creator',
        desc: 'Brainstorming ide, skrip video, caption IG, thumbnail concept. Multi-modal AI untuk hasil lebih kreatif dan original.',
        accent: 'violet',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="aud-cre-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#a855f7" />
                        <stop offset="100%" stopColor="#ec4899" />
                    </linearGradient>
                </defs>
                {/* Camera body */}
                <rect x="10" y="22" width="44" height="28" rx="4" fill="url(#aud-cre-g)" opacity="0.15" stroke="url(#aud-cre-g)" strokeWidth="2" />
                <rect x="22" y="16" width="20" height="8" rx="2" fill="url(#aud-cre-g)" opacity="0.25" stroke="url(#aud-cre-g)" strokeWidth="2" />
                {/* Lens — spinning accent */}
                <g>
                    <animateTransform attributeName="transform" type="rotate" from="0 32 36" to="360 32 36" dur="6s" repeatCount="indefinite" />
                    <circle cx="32" cy="36" r="10" fill="url(#aud-cre-g)" opacity="0.25" />
                    <circle cx="32" cy="36" r="10" fill="none" stroke="url(#aud-cre-g)" strokeWidth="2" strokeDasharray="4 3" />
                </g>
                <circle cx="32" cy="36" r="5" fill="url(#aud-cre-g)" opacity="0.85" />
                <circle cx="32" cy="36" r="2" fill="#ffffff" opacity="0.9" />
                {/* Flash sparks */}
                <circle cx="48" cy="26" r="1.5" fill="#f59e0b">
                    <animate attributeName="opacity" values="0.2;1;0.2" dur="1.5s" repeatCount="indefinite" />
                </circle>
                <circle cx="16" cy="30" r="1.2" fill="#ec4899">
                    <animate attributeName="opacity" values="1;0.2;1" dur="1.8s" repeatCount="indefinite" />
                </circle>
            </svg>
        ),
    },
    {
        key: 'biz',
        title: 'Freelancer & Bisnis',
        desc: 'Draft proposal, analisis kompetitor, copywriting. Tingkatkan produktivitas tanpa harus bayar langganan tool per bulan.',
        accent: 'amber',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="aud-biz-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#f59e0b" />
                        <stop offset="100%" stopColor="#f97316" />
                    </linearGradient>
                </defs>
                {/* Briefcase */}
                <rect x="10" y="20" width="44" height="32" rx="4" fill="url(#aud-biz-g)" opacity="0.15" stroke="url(#aud-biz-g)" strokeWidth="2" />
                <path d="M24 20 V16 a2 2 0 0 1 2-2 h12 a2 2 0 0 1 2 2 V20" stroke="url(#aud-biz-g)" strokeWidth="2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
                {/* Chart bars growing */}
                <g>
                    <rect x="18" y="42" width="5" height="6" rx="1" fill="url(#aud-biz-g)" opacity="0.85">
                        <animate attributeName="height" values="4;10;4" dur="2.2s" repeatCount="indefinite" />
                        <animate attributeName="y" values="44;38;44" dur="2.2s" repeatCount="indefinite" />
                    </rect>
                    <rect x="28" y="38" width="5" height="10" rx="1" fill="url(#aud-biz-g)" opacity="0.9">
                        <animate attributeName="height" values="8;14;8" dur="2.5s" repeatCount="indefinite" />
                        <animate attributeName="y" values="40;34;40" dur="2.5s" repeatCount="indefinite" />
                    </rect>
                    <rect x="38" y="34" width="5" height="14" rx="1" fill="url(#aud-biz-g)">
                        <animate attributeName="height" values="12;18;12" dur="2.3s" repeatCount="indefinite" />
                        <animate attributeName="y" values="36;30;36" dur="2.3s" repeatCount="indefinite" />
                    </rect>
                </g>
                {/* Arrow up */}
                <path d="M46 28 L50 24 L54 28 M50 24 V34" stroke="#16a34a" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" fill="none">
                    <animate attributeName="opacity" values="0.5;1;0.5" dur="2s" repeatCount="indefinite" />
                </path>
            </svg>
        ),
    },
    {
        key: 'researcher',
        title: 'Researcher & AI Enthusiast',
        desc: 'Bandingkan output antar model (GPT vs Claude vs Gemini). Eksperimen prompting, analisis paper, data exploration.',
        accent: 'emerald',
        Icon: ({ className }) => (
            <svg className={className} viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="aud-res-g" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#10b981" />
                        <stop offset="100%" stopColor="#14b8a6" />
                    </linearGradient>
                </defs>
                {/* Flask */}
                <path d="M26 12 V24 L16 46 a3 3 0 0 0 2.7 4.3 h26.6 a3 3 0 0 0 2.7 -4.3 L38 24 V12" stroke="url(#aud-res-g)" strokeWidth="2" fill="url(#aud-res-g)" fillOpacity="0.15" strokeLinecap="round" strokeLinejoin="round" />
                <line x1="22" y1="12" x2="42" y2="12" stroke="url(#aud-res-g)" strokeWidth="2.5" strokeLinecap="round" />
                {/* Liquid */}
                <path d="M18.8 43 L45.2 43 L43 48 a1 1 0 0 1 -1 0.7 h-20 a1 1 0 0 1 -1 -0.7 Z" fill="url(#aud-res-g)" opacity="0.7" />
                {/* Bubbles */}
                <circle cx="28" cy="40" r="1.4" fill="#fff" opacity="0.8">
                    <animate attributeName="cy" values="45;32;45" dur="2.8s" repeatCount="indefinite" />
                    <animate attributeName="opacity" values="0;0.8;0" dur="2.8s" repeatCount="indefinite" />
                </circle>
                <circle cx="34" cy="42" r="1" fill="#fff" opacity="0.8">
                    <animate attributeName="cy" values="46;34;46" dur="3.4s" repeatCount="indefinite" />
                    <animate attributeName="opacity" values="0;0.8;0" dur="3.4s" repeatCount="indefinite" />
                </circle>
                <circle cx="38" cy="41" r="1.2" fill="#fff" opacity="0.8">
                    <animate attributeName="cy" values="44;30;44" dur="3s" repeatCount="indefinite" />
                    <animate attributeName="opacity" values="0;0.8;0" dur="3s" repeatCount="indefinite" />
                </circle>
                {/* Sparkle */}
                <path d="M50 16 L51.5 19.5 L55 21 L51.5 22.5 L50 26 L48.5 22.5 L45 21 L48.5 19.5 Z" fill="#f59e0b">
                    <animateTransform attributeName="transform" type="scale" values="1;1.3;1" dur="2s" repeatCount="indefinite" additive="sum" />
                </path>
            </svg>
        ),
    },
];

const ACCENT_RING = {
    red:     'hover:border-red-300     hover:shadow-[0_20px_48px_-12px_rgba(239,68,68,0.25)]',
    blue:    'hover:border-blue-300    hover:shadow-[0_20px_48px_-12px_rgba(59,130,246,0.25)]',
    violet:  'hover:border-violet-300  hover:shadow-[0_20px_48px_-12px_rgba(139,92,246,0.25)]',
    amber:   'hover:border-amber-300   hover:shadow-[0_20px_48px_-12px_rgba(245,158,11,0.25)]',
    emerald: 'hover:border-emerald-300 hover:shadow-[0_20px_48px_-12px_rgba(16,185,129,0.25)]',
};

export default function Audience() {
    return (
        <section id="for-you" className="relative py-20 md:py-24 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-gray-50/60 to-white" />
            <div className="absolute top-0 left-1/4 w-96 h-96 rounded-full bg-red-400/10 blur-[120px] pointer-events-none" />

            <div className="relative max-w-7xl mx-auto px-4 md:px-6">
                <div className="text-center mb-12 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-black uppercase tracking-[0.15em] border border-red-200/80 mb-5">
                        Target Pengguna
                    </span>
                    <h2 className="text-3xl md:text-5xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Tepat untuk <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">kamu yang</span>...
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-2xl mx-auto">
                        Siapapun kamu, UltrAI punya harga &amp; fitur yang pas. Pilih yang sesuai sama aktivitas kamu.
                    </p>
                </div>

                <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 lg:gap-6">
                    {AUDIENCES.map((a, i) => (
                        <div
                            key={a.key}
                            className={`group relative p-6 lg:p-7 rounded-3xl bg-white border border-gray-200/80 shadow-[0_8px_24px_-8px_rgba(15,23,42,0.08)] transition-all duration-300 hover:-translate-y-1.5 ${ACCENT_RING[a.accent]}`}
                            style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${60 + i * 80}ms` }}
                        >
                            <div className="mb-5">
                                <div className="w-20 h-20 transition-transform duration-300 group-hover:scale-110">
                                    <a.Icon className="w-full h-full" />
                                </div>
                            </div>
                            <h3 className="text-lg font-extrabold tracking-tight text-slate-900 mb-2 group-hover:text-red-500 transition-colors">
                                {a.title}
                            </h3>
                            <p className="text-sm text-slate-600 leading-relaxed">
                                {a.desc}
                            </p>
                            <div className="mt-5 inline-flex items-center gap-1 text-xs font-bold text-red-500 opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all duration-300">
                                Cocok untukmu
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
