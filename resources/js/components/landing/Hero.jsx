import React from 'react';
import { Link } from 'react-router-dom';
import UltrLogo from '../UltrLogo';

/* ============================================================
   Hero — premium, VIP-grade landing hero.
   - Aurora + starburst backdrop
   - Gradient headline with shimmer
   - Above-the-fold price teaser (ChatGPT 300rb dicoret vs UltrAI 55rb)
   - Trusted-by row with subtle badges
   ============================================================ */

function FloatingDots() {
    // Positions intentionally chosen to frame hero content without touching text.
    const dots = [
        { top: '12%', left: '6%',  dur: 3.2, del: 0.0, size: 6 },
        { top: '22%', left: '84%', dur: 4.0, del: 0.4, size: 5 },
        { top: '58%', left: '4%',  dur: 3.6, del: 0.9, size: 7 },
        { top: '70%', left: '88%', dur: 4.4, del: 1.3, size: 4 },
        { top: '38%', left: '16%', dur: 5.0, del: 1.8, size: 5 },
        { top: '78%', left: '74%', dur: 3.8, del: 0.7, size: 6 },
        { top: '8%',  left: '55%', dur: 4.2, del: 1.1, size: 4 },
        { top: '48%', left: '92%', dur: 3.2, del: 0.2, size: 5 },
        { top: '32%', left: '44%', dur: 5.4, del: 2.2, size: 3 },
        { top: '84%', left: '28%', dur: 4.6, del: 1.6, size: 5 },
    ];
    return dots.map((d, i) => (
        <span
            key={i}
            aria-hidden="true"
            style={{
                position: 'absolute',
                top: d.top,
                left: d.left,
                width: d.size,
                height: d.size,
                borderRadius: '50%',
                background: '#ef4444',
                opacity: 0.35,
                animation: `dot-float ${d.dur}s ease-in-out ${d.del}s infinite`,
                zIndex: 1,
            }}
        />
    ));
}

const WA_NUMBER = '6287786866648';
const WA_REGISTER_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent('Halo UltrAI, saya ingin mendaftar akun UltrAI.')}`;

export default function Hero() {
    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section
            className="relative flex items-center pt-28 pb-16 md:pt-32 md:pb-24"
            style={{ overflow: 'clip' }}
        >
            {/* ===== Background layer ===== */}
            <div className="absolute inset-0 hero-dots pointer-events-none" style={{ zIndex: 0 }} />

            {/* Red aurora blobs */}
            <div
                className="absolute animate-blob-glow pointer-events-none"
                style={{
                    top: '-120px',
                    right: '-80px',
                    width: 640,
                    height: 640,
                    background: 'rgba(239, 68, 68, 0.18)',
                    borderRadius: '50%',
                    filter: 'blur(130px)',
                    zIndex: 0,
                }}
            />
            <div
                className="absolute animate-blob-glow-reverse pointer-events-none"
                style={{
                    bottom: '-120px',
                    left: '-80px',
                    width: 540,
                    height: 540,
                    background: 'rgba(251, 146, 60, 0.15)',
                    borderRadius: '50%',
                    filter: 'blur(130px)',
                    zIndex: 0,
                }}
            />

            <FloatingDots />

            {/* ===== Content ===== */}
            <div className="relative max-w-7xl mx-auto px-4 md:px-6 w-full" style={{ zIndex: 2 }}>
                <div className="grid lg:grid-cols-[1.15fr,1fr] items-center gap-12 lg:gap-16">
                    {/* Left — headline + CTA */}
                    <div className="text-center lg:text-left">
                        {/* Eyebrow pill */}
                        <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/70 border border-red-200/80 backdrop-blur-sm text-red-600 text-[11px] font-bold uppercase tracking-[0.15em] mb-6 animate-fade-in-down shadow-[0_4px_20px_-6px_rgba(239,68,68,0.18)]">
                            <span className="relative flex w-2 h-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping" />
                                <span className="relative inline-flex w-2 h-2 rounded-full bg-red-500" />
                            </span>
                            Akses Premium AI · Mulai dari Rp 5 ribu
                        </div>

                        {/* Main heading */}
                        <h1 className="text-4xl md:text-5xl lg:text-6xl xl:text-7xl font-black tracking-[-0.02em] leading-[1.05] text-slate-900 animate-fade-in-up">
                            Akses AI Premium
                            <br />
                            <span className="relative inline-block">
                                <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">
                                    Harga UMKM.
                                </span>
                                <svg
                                    className="absolute -bottom-3 left-0 w-full"
                                    viewBox="0 0 300 12"
                                    preserveAspectRatio="none"
                                    aria-hidden="true"
                                    style={{ height: 10 }}
                                >
                                    <path
                                        d="M2 8 Q 80 2, 150 6 T 298 4"
                                        fill="none"
                                        stroke="url(#underline-g)"
                                        strokeWidth="3"
                                        strokeLinecap="round"
                                    />
                                    <defs>
                                        <linearGradient id="underline-g" x1="0" y1="0" x2="1" y2="0">
                                            <stop offset="0%" stopColor="#ef4444" />
                                            <stop offset="100%" stopColor="#f97316" />
                                        </linearGradient>
                                    </defs>
                                </svg>
                            </span>
                        </h1>

                        <p className="mt-6 text-lg md:text-xl text-slate-600 leading-relaxed max-w-xl mx-auto lg:mx-0 animate-fade-in-up" style={{ animationDelay: '80ms' }}>
                            50+ model AI (GPT-4o, Claude, Gemini, DeepSeek, Qwen, GLM) dalam satu akun. <span className="font-semibold text-slate-900">Unlimited usage.</span> Support API key untuk VSCode, Cursor, OpenCode.
                        </p>

                        {/* CTA row */}
                        <div className="mt-8 flex flex-wrap justify-center lg:justify-start gap-3 animate-fade-in-up" style={{ animationDelay: '140ms' }}>
                            <button
                                onClick={() => scrollTo('pricing')}
                                className="ui-btn-primary px-7 py-3.5 text-base"
                            >
                                Lihat Harga
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                            </button>
                            <a
                                href={WA_REGISTER_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 px-7 py-3.5 rounded-xl text-base font-semibold text-emerald-700 bg-white border border-emerald-200/80 hover:bg-emerald-50 hover:border-emerald-300 hover:-translate-y-0.5 shadow-[0_4px_14px_-4px_rgba(16,185,129,0.25)] transition-all duration-200"
                            >
                                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413Z"/></svg>
                                Register via WhatsApp
                            </a>
                        </div>

                        {/* Trust row */}
                        <div className="mt-8 flex flex-wrap justify-center lg:justify-start items-center gap-x-5 gap-y-2 text-xs text-slate-500 animate-fade-in-up" style={{ animationDelay: '200ms' }}>
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                Garansi Refund
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                Akun Pribadi
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                Unlimited Usage
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                50+ AI Models
                            </span>
                        </div>
                    </div>

                    {/* Right — Price comparison card (above fold) */}
                    <div
                        className="relative animate-fade-in-right"
                        style={{ animationDelay: '120ms' }}
                    >
                        {/* Ambient glow behind card */}
                        <div className="absolute -inset-4 bg-gradient-to-br from-red-400/30 via-orange-300/20 to-red-300/30 rounded-3xl blur-3xl" aria-hidden="true" />

                        <div className="relative p-6 md:p-7 rounded-3xl bg-white/90 backdrop-blur-xl border border-gray-200/80 shadow-[0_32px_80px_-16px_rgba(15,23,42,0.2),0_12px_32px_-8px_rgba(239,68,68,0.12)]">
                            {/* VIP tag */}
                            <div className="flex items-center justify-between mb-5">
                                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-gradient-to-r from-amber-400 to-amber-500 text-amber-950 text-[10px] font-black uppercase tracking-[0.15em] shadow-[0_4px_14px_-2px_rgba(245,158,11,0.35)]">
                                    <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 14.39 8.26 21 9.27 16 14.14 17.18 21.02 12 17.77 6.82 21.02 8 14.14 3 9.27 9.61 8.26 12 2Z"/></svg>
                                    Hemat 82%
                                </span>
                                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Bandingkan</span>
                            </div>

                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Harga per bulan</p>

                            {/* ChatGPT Plus — strikethrough */}
                            <div className="flex items-baseline justify-between pb-4 border-b border-dashed border-gray-200">
                                <div className="flex items-center gap-3">
                                    <div className="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                                        <svg viewBox="0 0 24 24" className="w-5 h-5" aria-hidden="true"><path fill="#9ca3af" d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729z"/></svg>
                                    </div>
                                    <div>
                                        <div className="text-sm font-semibold text-slate-500">ChatGPT Plus</div>
                                        <div className="text-[11px] text-slate-400">OpenAI · 1 model</div>
                                    </div>
                                </div>
                                <div className="text-right">
                                    <div className="relative inline-block">
                                        <span className="text-2xl font-black text-slate-400 line-through decoration-red-500 decoration-[3px]">Rp 300.000</span>
                                    </div>
                                    <div className="text-[11px] text-slate-400 mt-0.5">/ bulan</div>
                                </div>
                            </div>

                            {/* UltrAI — highlighted */}
                            <div className="flex items-baseline justify-between pt-4">
                                <div className="flex items-center gap-3">
                                    <UltrLogo className="w-10 h-10" />
                                    <div>
                                        <div className="text-sm font-extrabold text-slate-900 flex items-center gap-1.5">
                                            UltrAI
                                            <span className="px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider bg-gradient-to-r from-amber-400 to-orange-500 text-white">VIP</span>
                                        </div>
                                        <div className="text-[11px] text-slate-500">50+ model · Unlimited</div>
                                    </div>
                                </div>
                                <div className="text-right">
                                    <div className="flex items-baseline gap-1">
                                        <span className="text-xs font-bold text-slate-400">Rp</span>
                                        <span className="text-4xl font-black tracking-tight bg-gradient-to-br from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient tabular-nums">55.000</span>
                                    </div>
                                    <div className="text-[11px] text-slate-500 mt-0.5">/ bulan · paling laris</div>
                                </div>
                            </div>

                            {/* Price marquee */}
                            <div className="mt-5 grid grid-cols-3 gap-2">
                                {[
                                    { label: '1 Hari',   price: '5rb'  },
                                    { label: '1 Minggu', price: '20rb' },
                                    { label: '1 Bulan',  price: '55rb', highlight: true },
                                ].map((t) => (
                                    <div
                                        key={t.label}
                                        className={`px-2 py-2 rounded-xl text-center border ${
                                            t.highlight
                                                ? 'bg-gradient-to-br from-red-50 to-orange-50 border-red-200 shadow-[0_6px_16px_-4px_rgba(239,68,68,0.2)]'
                                                : 'bg-gray-50/80 border-gray-200'
                                        }`}
                                    >
                                        <div className={`text-[10px] font-semibold uppercase tracking-wider ${t.highlight ? 'text-red-500' : 'text-slate-500'}`}>{t.label}</div>
                                        <div className={`text-sm font-black tabular-nums mt-0.5 ${t.highlight ? 'text-red-600' : 'text-slate-900'}`}>Rp {t.price}</div>
                                    </div>
                                ))}
                            </div>

                            <button
                                onClick={() => scrollTo('pricing')}
                                className="mt-5 w-full py-3 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold shadow-[0_10px_28px_-6px_rgba(239,68,68,0.45)] hover:shadow-[0_16px_40px_-8px_rgba(239,68,68,0.6)] hover:brightness-105 hover:-translate-y-0.5 active:translate-y-0 active:scale-[0.98] transition-all duration-200 flex items-center justify-center gap-2"
                            >
                                Lihat semua paket
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                        </div>

                        {/* Floating badges around card */}
                        <span className="hidden md:inline-flex absolute -top-4 -left-4 animate-bounce-subtle items-center gap-1 px-3 py-1.5 rounded-full bg-white border border-gray-200 shadow-lg text-[10px] font-bold text-slate-700">
                            <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" /> 50+ Model
                        </span>
                        <span className="hidden md:inline-flex absolute -bottom-4 -right-4 animate-bounce-subtle items-center gap-1 px-3 py-1.5 rounded-full bg-white border border-gray-200 shadow-lg text-[10px] font-bold text-slate-700" style={{ animationDelay: '1s' }}>
                            <svg className="w-3 h-3 text-amber-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 14.39 8.26 21 9.27 16 14.14 17.18 21.02 12 17.77 6.82 21.02 8 14.14 3 9.27 9.61 8.26 12 2Z"/></svg>
                            Garansi Refund
                        </span>
                    </div>
                </div>
            </div>
        </section>
    );
}
