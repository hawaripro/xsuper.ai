import React from 'react';
import {
    ClaudeLogo, ChatGPTLogo, GeminiLogo, DeepSeekLogo, QwenLogo, GLMLogo,
    VSCodeLogo, CursorLogo, OpenCodeLogo,
    LogoChip,
} from './BrandIcons';

/* ============================================================
   Why UltrAI — 4 premium feature cards.
   1) Unlimited usage + Akun pribadi (combined hero card)
   2) 50+ Model AI with official logos
   3) Support API Key for dev tools
   4) Garansi refund
   ============================================================ */

export default function Features() {
    return (
        <section id="why-ultrai" className="relative py-20 md:py-28 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-gray-50/60 to-white" />
            <div className="absolute top-0 right-0 w-[500px] h-[500px] rounded-full bg-red-400/10 blur-[120px] pointer-events-none" />

            <div className="relative max-w-7xl mx-auto px-4 md:px-6">
                {/* Header */}
                <div className="text-center mb-14 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-black uppercase tracking-[0.15em] border border-red-200/80 mb-5">
                        Keunggulan
                    </span>
                    <h2 className="text-3xl md:text-5xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Kenapa memilih <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">UltrAI</span>?
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-2xl mx-auto">
                        Empat keunggulan utama yang bikin UltrAI jadi pilihan terbaik untuk akses AI premium di Indonesia.
                    </p>
                </div>

                {/* Grid */}
                <div className="grid md:grid-cols-2 gap-5 lg:gap-6">
                    {/* ===== Card 1: Unlimited + Private ===== */}
                    <div
                        className="md:col-span-2 relative overflow-hidden rounded-3xl border border-gray-200/80 bg-white shadow-[0_12px_40px_-12px_rgba(15,23,42,0.12)] hover:shadow-[0_24px_60px_-16px_rgba(239,68,68,0.25)] transition-all duration-300 group"
                        style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both' }}
                    >
                        <div className="absolute -top-20 -right-20 w-80 h-80 rounded-full bg-gradient-to-br from-red-400/20 to-orange-400/15 blur-3xl" />
                        <div className="absolute -bottom-20 -left-20 w-80 h-80 rounded-full bg-gradient-to-br from-orange-400/15 to-red-300/10 blur-3xl" />

                        <div className="relative grid md:grid-cols-2 gap-0">
                            {/* Unlimited side */}
                            <div className="p-7 lg:p-9 border-b md:border-b-0 md:border-r border-gray-200/70">
                                <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-[0_10px_24px_-6px_rgba(239,68,68,0.45)] ring-4 ring-red-500/20 mb-5 group-hover:scale-110 group-hover:rotate-3 transition-all">
                                    <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M18.178 8c5.096 0 5.096 8 0 8-5.095 0-7.133-8-12.739-8-4.585 0-4.585 8 0 8 5.606 0 7.644-8 12.74-8z"/>
                                    </svg>
                                </div>
                                <h3 className="text-2xl font-extrabold tracking-tight text-slate-900 mb-2">Unlimited Usage</h3>
                                <p className="text-slate-600 leading-relaxed">
                                    Pakai sepuasnya selama masa aktif. <span className="font-semibold text-slate-900">Tanpa limit token, tanpa rate limit harian.</span> Chat sebanyak apapun, generate kode berjam-jam — nggak ada hitungan.
                                </p>
                            </div>

                            {/* Private side */}
                            <div className="p-7 lg:p-9">
                                <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 text-white shadow-[0_10px_24px_-6px_rgba(16,185,129,0.45)] ring-4 ring-emerald-500/20 mb-5 group-hover:scale-110 group-hover:rotate-3 transition-all">
                                    <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                        <path d="M9 12l2 2 4-4"/>
                                    </svg>
                                </div>
                                <h3 className="text-2xl font-extrabold tracking-tight text-slate-900 mb-2">Akun Pribadi</h3>
                                <p className="text-slate-600 leading-relaxed">
                                    Akun khusus untuk kamu — <span className="font-semibold text-slate-900">bukan sharing, bukan pakai bareng.</span> Privasi chat terjamin, history aman, nggak bakal kena suspend karena aktivitas user lain.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* ===== Card 2: 50+ Models ===== */}
                    <div
                        className="relative overflow-hidden p-7 lg:p-8 rounded-3xl border border-gray-200/80 bg-white shadow-[0_12px_40px_-12px_rgba(15,23,42,0.12)] hover:shadow-[0_20px_48px_-12px_rgba(59,130,246,0.25)] hover:-translate-y-1 transition-all duration-300 group"
                        style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: '80ms' }}
                    >
                        <div className="absolute -top-16 -right-16 w-64 h-64 rounded-full bg-blue-400/10 blur-3xl" />

                        <div className="relative">
                            <div className="inline-flex items-center gap-3 mb-5">
                                <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-500 text-white shadow-[0_10px_24px_-6px_rgba(59,130,246,0.45)] ring-4 ring-blue-500/20 flex items-center justify-center group-hover:scale-110 group-hover:rotate-3 transition-all">
                                    <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                        <rect x="4" y="4" width="16" height="16" rx="2"/>
                                        <rect x="9" y="9" width="6" height="6"/>
                                        <line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/>
                                        <line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/>
                                        <line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/>
                                        <line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>
                                    </svg>
                                </div>
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-blue-50 border border-blue-200 text-blue-700 text-[10px] font-black uppercase tracking-wider">
                                    50+ Model
                                </span>
                            </div>
                            <h3 className="text-xl font-extrabold tracking-tight text-slate-900 mb-2">Semua Model AI Top</h3>
                            <p className="text-slate-600 leading-relaxed mb-5">
                                Akses model premium dari berbagai provider. Pilih yang paling cocok untuk task kamu.
                            </p>

                            {/* Logo grid */}
                            <div className="grid grid-cols-3 gap-2.5">
                                {[
                                    { name: 'Claude',    Logo: ClaudeLogo   },
                                    { name: 'ChatGPT',   Logo: ChatGPTLogo  },
                                    { name: 'Gemini',    Logo: GeminiLogo   },
                                    { name: 'DeepSeek',  Logo: DeepSeekLogo },
                                    { name: 'Qwen',      Logo: QwenLogo     },
                                    { name: 'GLM',       Logo: GLMLogo      },
                                ].map(({ name, Logo }, i) => (
                                    <div
                                        key={name}
                                        className="group/item flex flex-col items-center gap-1.5 p-2.5 rounded-xl bg-gradient-to-br from-gray-50 to-white border border-gray-200 hover:border-red-300 hover:shadow-[0_6px_16px_-4px_rgba(239,68,68,0.15)] hover:-translate-y-0.5 transition-all duration-200"
                                        style={{ animation: 'pop-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both', animationDelay: `${120 + i * 60}ms` }}
                                    >
                                        <LogoChip className="w-10 h-10 group-hover/item:scale-110 transition-transform duration-200">
                                            <Logo className="w-full h-full" />
                                        </LogoChip>
                                        <span className="text-[11px] font-bold text-slate-700">{name}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* ===== Card 3: API Key support ===== */}
                    <div
                        className="relative overflow-hidden p-7 lg:p-8 rounded-3xl border border-gray-200/80 bg-white shadow-[0_12px_40px_-12px_rgba(15,23,42,0.12)] hover:shadow-[0_20px_48px_-12px_rgba(139,92,246,0.25)] hover:-translate-y-1 transition-all duration-300 group"
                        style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: '140ms' }}
                    >
                        <div className="absolute -top-16 -right-16 w-64 h-64 rounded-full bg-violet-400/10 blur-3xl" />

                        <div className="relative">
                            <div className="inline-flex items-center gap-3 mb-5">
                                <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-500 text-white shadow-[0_10px_24px_-6px_rgba(139,92,246,0.45)] ring-4 ring-violet-500/20 flex items-center justify-center group-hover:scale-110 group-hover:rotate-3 transition-all">
                                    <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                        <polyline points="16 18 22 12 16 6"/>
                                        <polyline points="8 6 2 12 8 18"/>
                                    </svg>
                                </div>
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-violet-50 border border-violet-200 text-violet-700 text-[10px] font-black uppercase tracking-wider">
                                    Dev Tools
                                </span>
                            </div>
                            <h3 className="text-xl font-extrabold tracking-tight text-slate-900 mb-2">Support API Key</h3>
                            <p className="text-slate-600 leading-relaxed mb-5">
                                Integrasi langsung ke IDE kamu. Generate API key dari dashboard, pakai di tool favorit.
                            </p>

                            <div className="grid grid-cols-3 gap-2.5">
                                {[
                                    { name: 'VSCode',   Logo: VSCodeLogo   },
                                    { name: 'Cursor',   Logo: CursorLogo   },
                                    { name: 'OpenCode', Logo: OpenCodeLogo },
                                ].map(({ name, Logo }, i) => (
                                    <div
                                        key={name}
                                        className="group/item flex flex-col items-center gap-1.5 p-2.5 rounded-xl bg-gradient-to-br from-gray-50 to-white border border-gray-200 hover:border-violet-300 hover:shadow-[0_6px_16px_-4px_rgba(139,92,246,0.15)] hover:-translate-y-0.5 transition-all duration-200"
                                        style={{ animation: 'pop-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both', animationDelay: `${200 + i * 60}ms` }}
                                    >
                                        <LogoChip className="w-10 h-10 group-hover/item:scale-110 transition-transform duration-200">
                                            <Logo className="w-full h-full" />
                                        </LogoChip>
                                        <span className="text-[11px] font-bold text-slate-700">{name}</span>
                                    </div>
                                ))}
                            </div>
                            <p className="mt-4 text-xs text-slate-500">
                                &amp; tool lainnya yang support OpenAI-compatible API.
                            </p>
                        </div>
                    </div>

                    {/* ===== Card 4: Refund ===== */}
                    <div
                        className="md:col-span-2 relative overflow-hidden p-7 lg:p-9 rounded-3xl border border-emerald-200/80 bg-gradient-to-br from-white via-emerald-50/30 to-white shadow-[0_12px_40px_-12px_rgba(15,23,42,0.12)] hover:shadow-[0_20px_48px_-12px_rgba(16,185,129,0.25)] hover:-translate-y-1 transition-all duration-300 group"
                        style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: '220ms' }}
                    >
                        <div className="absolute -top-20 -right-20 w-80 h-80 rounded-full bg-emerald-400/15 blur-3xl" />

                        <div className="relative flex flex-col md:flex-row md:items-center gap-6">
                            <div className="flex-shrink-0">
                                <div className="relative w-20 h-20 rounded-3xl bg-gradient-to-br from-emerald-500 to-teal-500 text-white shadow-[0_14px_32px_-6px_rgba(16,185,129,0.5)] ring-8 ring-emerald-500/15 flex items-center justify-center group-hover:scale-105 group-hover:rotate-3 transition-all duration-300">
                                    <svg className="w-10 h-10" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                        <path d="M9 12l2 2 4-4"/>
                                    </svg>
                                </div>
                            </div>
                            <div className="flex-1">
                                <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-md bg-emerald-500/15 text-emerald-700 text-[10px] font-black uppercase tracking-wider mb-3">
                                    100% Safe
                                </div>
                                <h3 className="text-2xl font-extrabold tracking-tight text-slate-900 mb-2">Garansi Refund / Replace</h3>
                                <p className="text-slate-600 leading-relaxed mb-4 max-w-2xl">
                                    Kalau ada kendala — <span className="font-semibold text-slate-900">akun habis di tengah jalan, kena limit, atau token error</span> — langsung kami refund atau replace akun baru. Tanpa drama.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {[
                                        'Akun habis → replace gratis',
                                        'Kena limit → refund pro-rata',
                                        'Token error → replace instan',
                                    ].map((t, i) => (
                                        <span
                                            key={i}
                                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-emerald-200 text-emerald-700 shadow-[0_2px_6px_-2px_rgba(16,185,129,0.15)]"
                                            style={{ animation: 'fade-in-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${320 + i * 80}ms` }}
                                        >
                                            <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                            {t}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
