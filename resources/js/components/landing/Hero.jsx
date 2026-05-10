import React from 'react';
import { Link } from 'react-router-dom';
import UltrLogo from '../UltrLogo';
import {
    ClaudeLogo, ChatGPTLogo, GeminiLogo, DeepSeekLogo, QwenLogo, GLMLogo,
} from './BrandIcons';

/* ============================================================
   Hero — premium, VIP-grade landing hero.
   - Aurora + dot pattern backdrop
   - Orbital UltrAI: center logo with pulsing rings + 6 AI brand
     logos orbiting on two rings (fast outer / slow inner)
   - Gradient headline with animated underline
   - Above-the-fold price teaser (ChatGPT 300rb crossed out vs
     UltrAI 55rb)
   ============================================================ */

function FloatingDots() {
    const dots = [
        { top: '15%', left: '8%',  dur: 3.5, del: 0.0, size: 5 },
        { top: '25%', left: '85%', dur: 4.2, del: 0.5, size: 4 },
        { top: '60%', left: '5%',  dur: 3.8, del: 1.0, size: 6 },
        { top: '72%', left: '90%', dur: 4.6, del: 1.5, size: 4 },
        { top: '42%', left: '18%', dur: 5.2, del: 2.0, size: 4 },
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

/* ============================================================
   Orbital constellation — UltrAI at center surrounded by 6
   AI brand logos on two rings.
   ============================================================ */
function OrbitalConstellation({ variant = 'inline' }) {
    const isBackdrop = variant === 'backdrop';

    const outerRadius = isBackdrop ? 220 : 140;
    const innerRadius = isBackdrop ? 150 : 96;
    const centerSize  = isBackdrop ? 168 : 116;
    const outerBadge  = isBackdrop ? 72  : 52;
    const innerBadge  = isBackdrop ? 58  : 42;

    const OUTER = [
        { Logo: ClaudeLogo,   name: 'Claude',   angle: 0   },
        { Logo: ChatGPTLogo,  name: 'ChatGPT',  angle: 120 },
        { Logo: GeminiLogo,   name: 'Gemini',   angle: 240 },
    ];
    const INNER = [
        { Logo: DeepSeekLogo, name: 'DeepSeek', angle: 60  },
        { Logo: QwenLogo,     name: 'Qwen',     angle: 180 },
        { Logo: GLMLogo,      name: 'GLM',      angle: 300 },
    ];

    return (
        <div
            className="relative mx-auto flex items-center justify-center will-change-transform"
            style={{ width: outerRadius * 2 + 48, height: outerRadius * 2 + 48, maxWidth: '100%', contain: 'layout style paint' }}
            aria-hidden="true"
        >
            {/* Glow behind everything */}
            <div className="absolute inset-0 rounded-full bg-gradient-to-br from-red-400/25 via-orange-300/15 to-red-400/25 blur-3xl animate-pulse-soft" />

            {/* Ring circles — decorative */}
            <div
                className="absolute rounded-full"
                style={{
                    width: outerRadius * 2,
                    height: outerRadius * 2,
                    border: '1px dashed rgba(239, 68, 68, 0.28)',
                }}
            />
            <div
                className="absolute rounded-full"
                style={{
                    width: innerRadius * 2,
                    height: innerRadius * 2,
                    border: '1px dashed rgba(251, 146, 60, 0.32)',
                }}
            />

            {/* Pulsing rings behind center */}
            <div
                className="absolute rounded-full bg-red-500/10 animate-ping"
                style={{ width: centerSize * 1.3, height: centerSize * 1.3, animationDuration: '3s' }}
            />
            <div
                className="absolute rounded-full bg-red-500/8 animate-ping"
                style={{ width: centerSize * 1.6, height: centerSize * 1.6, animationDuration: '4s', animationDelay: '0.8s' }}
            />

            {/* Center UltrAI */}
            <div className="relative z-20 animate-pulse-glow rounded-[28px]">
                <span
                    className="relative inline-flex items-center justify-center rounded-[28px] overflow-hidden bg-white shadow-[0_20px_48px_-8px_rgba(239,68,68,0.55),0_8px_20px_-4px_rgba(239,68,68,0.35)]"
                    style={{ width: centerSize, height: centerSize }}
                >
                    <img
                        src="/ultr-icons.png"
                        alt="UltrAI"
                        className="w-full h-full object-cover"
                        loading="eager"
                        decoding="async"
                        width={centerSize}
                        height={centerSize}
                    />
                    <span className="absolute inset-0 rounded-[28px] ring-2 ring-white/50 pointer-events-none" />
                    <span className="absolute inset-0 rounded-[28px] bg-gradient-to-br from-white/20 via-transparent to-transparent pointer-events-none" />
                </span>
            </div>

            {/* Outer ring — forward orbit */}
            {OUTER.map((brand, i) => (
                <div
                    key={brand.name}
                    className="absolute animate-orbit"
                    style={{
                        '--orbit-radius': `${outerRadius}px`,
                        '--orbit-start': `${brand.angle}deg`,
                        animationDuration: '26s',
                        animationDelay: `${i * -1}s`,
                    }}
                >
                    <OrbitBadge Logo={brand.Logo} name={brand.name} size={outerBadge} />
                </div>
            ))}

            {/* Inner ring — reverse orbit */}
            {INNER.map((brand, i) => (
                <div
                    key={brand.name}
                    className="absolute animate-orbit-reverse"
                    style={{
                        '--orbit-radius': `${innerRadius}px`,
                        '--orbit-start': `${brand.angle}deg`,
                        animationDuration: '20s',
                        animationDelay: `${i * -0.8}s`,
                    }}
                >
                    <OrbitBadge Logo={brand.Logo} name={brand.name} size={innerBadge} />
                </div>
            ))}
        </div>
    );
}

function OrbitBadge({ Logo, name, size }) {
    return (
        <span
            className="inline-flex items-center justify-center rounded-2xl bg-white shadow-[0_8px_20px_-4px_rgba(15,23,42,0.15)] ring-1 ring-gray-200 p-2 will-change-transform"
            style={{ width: size, height: size }}
            title={name}
        >
            <Logo className="w-full h-full" />
        </span>
    );
}

const WA_NUMBER = '6287786866648';
const WA_REGISTER_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent('Halo UltrAI, saya ingin mendaftar akun UltrAI.')}`;

export default function Hero() {
    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section
            className="relative flex items-center pt-28 pb-20 md:pt-32 md:pb-28"
            style={{ overflow: 'clip' }}
        >
            {/* ===== Background ===== */}
            <div className="absolute inset-0 hero-dots pointer-events-none" style={{ zIndex: 0 }} />
            <div
                className="absolute animate-blob-glow pointer-events-none"
                style={{
                    top: '-120px', right: '-80px', width: 640, height: 640,
                    background: 'rgba(239, 68, 68, 0.18)',
                    borderRadius: '50%', filter: 'blur(130px)', zIndex: 0,
                }}
            />
            <div
                className="absolute animate-blob-glow-reverse pointer-events-none"
                style={{
                    bottom: '-120px', left: '-80px', width: 540, height: 540,
                    background: 'rgba(251, 146, 60, 0.15)',
                    borderRadius: '50%', filter: 'blur(130px)', zIndex: 0,
                }}
            />
            <FloatingDots />

            {/* ===== Content ===== */}
            <div className="relative max-w-7xl mx-auto px-4 md:px-6 w-full" style={{ zIndex: 2 }}>
                <div className="grid lg:grid-cols-[1.1fr,1fr] items-center gap-12 lg:gap-16">
                    {/* Left — headline + CTA */}
                    <div className="text-center lg:text-left">
                        <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/70 border border-red-200/80 backdrop-blur-sm text-red-600 text-[11px] font-bold uppercase tracking-[0.15em] mb-6 animate-fade-in-down shadow-[0_4px_20px_-6px_rgba(239,68,68,0.18)]">
                            <span className="relative flex w-2 h-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping" />
                                <span className="relative inline-flex w-2 h-2 rounded-full bg-red-500" />
                            </span>
                            Akses Premium AI
                        </div>

                        <h1 className="text-3xl md:text-4xl lg:text-5xl xl:text-6xl font-black tracking-[-0.02em] leading-[1.08] text-slate-900 animate-fade-in-up">
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

                        {/* Mobile-only inline orbital */}
                        <div className="lg:hidden mt-10 animate-fade-in-up" style={{ animationDelay: '260ms' }}>
                            <OrbitalConstellation variant="inline" />
                        </div>
                    </div>

                    {/* Right column — orbital only */}
                    <div className="hidden lg:block relative animate-fade-in-right" style={{ animationDelay: '120ms' }}>
                        <OrbitalConstellation variant="inline" />
                    </div>
                </div>
            </div>
        </section>
    );
}
