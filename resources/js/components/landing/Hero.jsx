import React from 'react';
import UltrLogo from '../UltrLogo';
import {
    ClaudeLogo, ChatGPTLogo, GeminiLogo, DeepSeekLogo, QwenLogo, GLMLogo,
} from './BrandIcons';

/* ============================================================
   Hero — clean, centered, mobile-optimized.
   - No orbit animation (removed for performance)
   - UltrAI logo with gentle tilt/sway animation
   - All content centered
   - Minimal floating dots (5 only)
   ============================================================ */

const WA_NUMBER = '6287786866648';
const WA_REGISTER_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent('Halo UltrAI, saya ingin mendaftar akun UltrAI.')}`;

export default function Hero() {
    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section className="relative min-h-dvh flex items-center justify-center overflow-hidden">
            {/* Background — simplified for mobile perf */}
            <div className="absolute inset-0 hero-dots opacity-40 pointer-events-none" />
            <div
                className="absolute top-[-80px] left-1/2 -translate-x-1/2 w-[500px] h-[500px] rounded-full bg-red-500/15 blur-[120px] pointer-events-none"
                aria-hidden="true"
            />

            {/* Content — all centered */}
            <div className="relative max-w-3xl mx-auto px-5 md:px-6 text-center">
                {/* Eyebrow */}
                <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/80 border border-red-200/80 backdrop-blur-sm text-red-600 text-[11px] font-bold uppercase tracking-[0.15em] mb-12 animate-fade-in-down shadow-[0_4px_16px_-4px_rgba(239,68,68,0.15)]">
                    <span className="relative flex w-2 h-2">
                        <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping" />
                        <span className="relative inline-flex w-2 h-2 rounded-full bg-red-500" />
                    </span>
                    Akses Premium AI
                </div>

                {/* Heading */}
                <h1 className="text-3xl md:text-4xl lg:text-5xl font-black tracking-[-0.02em] leading-[1.1] text-slate-900 animate-fade-in-up">
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

                {/* Subtitle */}
                <p className="mt-10 text-sm md:text-base lg:text-lg text-slate-600 leading-relaxed max-w-xl mx-auto animate-fade-in-up" style={{ animationDelay: '80ms' }}>
                    50+ model AI (GPT-4o, Claude, Gemini, DeepSeek, Qwen, GLM) dalam satu akun.
                    <span className="font-semibold text-slate-900"> Unlimited usage.</span> Support API key untuk VSCode, Cursor, OpenCode.
                </p>

                {/* CTA */}
                <div className="mt-12 flex flex-wrap justify-center gap-3 animate-fade-in-up" style={{ animationDelay: '140ms' }}>
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

                {/* Trust badges */}
                <div className="mt-12 flex flex-wrap justify-center items-center gap-x-4 gap-y-2 text-[11px] text-slate-500 animate-fade-in-up" style={{ animationDelay: '200ms' }}>
                    {['Garansi Refund', 'Akun Pribadi', 'Unlimited Usage', '50+ AI Models'].map((t) => (
                        <span key={t} className="inline-flex items-center gap-1.5">
                            <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            {t}
                        </span>
                    ))}
                </div>

                {/* 6 AI brand logos — floating/swaying gently */}
                <div className="mt-14 flex items-center justify-center gap-3 md:gap-5 animate-fade-in-up" style={{ animationDelay: '280ms' }}>
                    {[
                        { Logo: ClaudeLogo,   name: 'Claude',   delay: '0s' },
                        { Logo: ChatGPTLogo,  name: 'ChatGPT',  delay: '0.4s' },
                        { Logo: GeminiLogo,   name: 'Gemini',   delay: '0.8s' },
                        { Logo: DeepSeekLogo, name: 'DeepSeek', delay: '1.2s' },
                        { Logo: QwenLogo,     name: 'Qwen',     delay: '1.6s' },
                        { Logo: GLMLogo,      name: 'GLM',      delay: '2.0s' },
                    ].map(({ Logo, name, delay }) => (
                        <div
                            key={name}
                            className="animate-float"
                            style={{ animationDelay: delay, animationDuration: '4s' }}
                            title={name}
                        >
                            <span className="inline-flex items-center justify-center w-12 h-12 md:w-14 md:h-14 rounded-2xl bg-white border border-gray-200/80 shadow-[0_8px_20px_-4px_rgba(15,23,42,0.1)] p-2.5">
                                <Logo className="w-full h-full" />
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
