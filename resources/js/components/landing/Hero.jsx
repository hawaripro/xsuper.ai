import React from 'react';
import { Link } from 'react-router-dom';

// Floating dot particles — rendered with inline styles for reliability
function FloatingDots() {
    const dots = [
        { top: '12%', left: '8%', dur: 3, del: 0, size: 6 },
        { top: '22%', left: '82%', dur: 4, del: 0.5, size: 5 },
        { top: '55%', left: '4%', dur: 3.5, del: 1, size: 7 },
        { top: '65%', left: '88%', dur: 4.5, del: 1.5, size: 4 },
        { top: '38%', left: '18%', dur: 5, del: 2, size: 5 },
        { top: '75%', left: '72%', dur: 3.8, del: 0.8, size: 6 },
        { top: '8%', left: '55%', dur: 4.2, del: 1.2, size: 4 },
        { top: '48%', left: '92%', dur: 3.2, del: 0.3, size: 5 },
        { top: '30%', left: '42%', dur: 5.5, del: 2.5, size: 3 },
        { top: '82%', left: '28%', dur: 4.8, del: 1.8, size: 5 },
        { top: '18%', left: '68%', dur: 3.6, del: 0.6, size: 6 },
        { top: '50%', left: '12%', dur: 4.4, del: 1.4, size: 4 },
    ];

    return (
        <>
            {dots.map((d, i) => (
                <span
                    key={i}
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
            ))}
        </>
    );
}

export default function Hero() {
    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section className="relative min-h-screen flex items-center pt-16" style={{ overflow: 'clip' }}>

            {/* ===== BACKGROUND LAYER ===== */}

            {/* Dot grid pattern */}
            <div className="absolute inset-0 hero-dots" style={{ zIndex: 0 }} />

            {/* Red glow blobs — large, visible, animated */}
            <div
                className="absolute animate-blob-glow"
                style={{
                    top: '-100px',
                    right: '-80px',
                    width: 600,
                    height: 600,
                    background: 'rgba(239, 68, 68, 0.18)',
                    borderRadius: '50%',
                    filter: 'blur(120px)',
                    zIndex: 0,
                }}
            />
            <div
                className="absolute animate-blob-glow-reverse"
                style={{
                    bottom: '-120px',
                    left: '-60px',
                    width: 500,
                    height: 500,
                    background: 'rgba(244, 63, 94, 0.14)',
                    borderRadius: '50%',
                    filter: 'blur(100px)',
                    zIndex: 0,
                }}
            />
            <div
                className="absolute animate-blob-glow-delay"
                style={{
                    top: '40%',
                    left: '35%',
                    width: 650,
                    height: 650,
                    background: 'rgba(251, 146, 60, 0.08)',
                    borderRadius: '50%',
                    filter: 'blur(120px)',
                    zIndex: 0,
                }}
            />

            {/* Floating dot particles */}
            <FloatingDots />

            {/* Top accent line */}
            <div className="absolute top-0 left-0 right-0 h-[2px] bg-gradient-to-r from-transparent via-red-500/30 to-transparent" style={{ zIndex: 1 }} />

            {/* ===== CONTENT ===== */}
            <div className="relative max-w-7xl mx-auto px-4 md:px-6 w-full py-16 md:py-0" style={{ zIndex: 2 }}>
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-12 lg:gap-16">
                    {/* Text Content */}
                    <div className="max-w-2xl text-center lg:text-left">
                        {/* Badge with ping dot */}
                        <div className="mb-6 inline-flex items-center gap-2.5 rounded-full border border-red-500/20 bg-red-50 px-4 py-1.5 text-xs font-semibold text-red-600 shadow-sm">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping-dot" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500" style={{ boxShadow: '0 0 8px rgba(239,68,68,0.6)' }} />
                            </span>
                            Platform AI untuk UMKM Indonesia
                        </div>

                        {/* Heading with animated gradient */}
                        <h1 className="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-extrabold tracking-tight text-gray-900 leading-[1.05]">
                            Solusi Digital<br />
                            <span
                                className="animate-gradient"
                                style={{
                                    backgroundImage: 'linear-gradient(90deg, #ef4444, #f97316, #ef4444, #dc2626, #ef4444)',
                                    backgroundSize: '300% 100%',
                                    WebkitBackgroundClip: 'text',
                                    WebkitTextFillColor: 'transparent',
                                    backgroundClip: 'text',
                                }}
                            >
                                Cerdas &amp; Cepat
                            </span><br />
                            untuk Bisnis Anda.
                        </h1>

                        {/* Description */}
                        <p className="mt-6 text-lg md:text-xl text-gray-500 leading-relaxed max-w-xl mx-auto lg:mx-0">
                            Platform all-in-one dengan kecerdasan buatan. SMM Panel, PPOB, dan Produk Digital — semua terintegrasi dalam satu ekosistem.
                        </p>

                        {/* CTA Buttons */}
                        <div className="mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3">
                            <button
                                onClick={() => scrollTo('services')}
                                className="group relative overflow-hidden inline-flex items-center justify-center h-12 px-8 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white font-medium text-sm hover:brightness-110 transition-all cursor-pointer btn-shimmer"
                                style={{ boxShadow: '0 4px 24px rgba(239,68,68,0.3)' }}
                            >
                                Jelajahi Layanan
                                <svg className="ml-2 w-4 h-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </button>
                            <Link
                                to="/login"
                                className="inline-flex items-center justify-center h-12 px-8 rounded-xl border border-gray-200/60 text-gray-900 font-medium text-sm hover:bg-gray-50/60 hover:border-gray-300 transition-all cursor-pointer backdrop-blur-sm"
                            >
                                Buka Dashboard
                            </Link>
                        </div>
                    </div>

                    {/* Logo Visual */}
                    <div className="hidden lg:flex items-center justify-center">
                        <div className="relative w-[320px] h-[320px] flex items-center justify-center">
                            {/* Outer glow */}
                            <div
                                className="absolute animate-pulse-glow"
                                style={{
                                    inset: -60,
                                    background: 'rgba(239,68,68,0.12)',
                                    borderRadius: '50%',
                                    filter: 'blur(100px)',
                                }}
                            />

                            {/* Orbiting ring outer (dashed) */}
                            <div className="absolute animate-spin-slow-reverse" style={{ inset: -50, border: '1px dashed rgba(239,68,68,0.12)', borderRadius: '50%' }} />

                            {/* Orbiting ring inner with dot */}
                            <div className="absolute animate-spin-slow" style={{ inset: -20, border: '1px solid rgba(239,68,68,0.15)', borderRadius: '50%' }}>
                                <span style={{
                                    position: 'absolute',
                                    top: -6,
                                    left: '50%',
                                    width: 12,
                                    height: 12,
                                    background: '#ef4444',
                                    borderRadius: '50%',
                                    boxShadow: '0 0 16px rgba(239,68,68,0.6)',
                                }} />
                            </div>

                            {/* Second orbiting dot */}
                            <div className="absolute animate-spin-slow-reverse" style={{ inset: -35, borderRadius: '50%', animationDuration: '40s' }}>
                                <span style={{
                                    position: 'absolute',
                                    top: '50%',
                                    right: -4,
                                    width: 8,
                                    height: 8,
                                    background: '#fb923c',
                                    borderRadius: '50%',
                                    boxShadow: '0 0 12px rgba(251,146,60,0.5)',
                                }} />
                            </div>

                            {/* Main logo card */}
                            <div className="relative w-[200px] h-[200px] animate-float flex items-center justify-center" style={{
                                background: 'linear-gradient(135deg, #fff, #fff, #fef2f2)',
                                borderRadius: 44,
                                boxShadow: '0 20px 80px rgba(239,68,68,0.15), 0 0 0 1px rgba(239,68,68,0.1)',
                            }}>
                                <div className="absolute inset-0" style={{ borderRadius: 44, background: 'linear-gradient(135deg, rgba(239,68,68,0.03), transparent)' }} />
                                <span className="relative text-5xl font-black tracking-tighter">
                                    <span className="text-gray-900">Ultr</span>
                                    <span style={{
                                        backgroundImage: 'linear-gradient(to right, #ef4444, #dc2626)',
                                        WebkitBackgroundClip: 'text',
                                        WebkitTextFillColor: 'transparent',
                                    }}>AI</span>
                                </span>
                            </div>

                            {/* Mini floating dots around logo */}
                            {[
                                { top: 16, right: 32, size: 8, bg: '#ef4444', dur: 2.5, del: 0 },
                                { bottom: 32, left: 16, size: 6, bg: '#fb923c', dur: 3, del: 0.8 },
                                { top: '50%', right: 0, size: 8, bg: '#fca5a5', dur: 3.5, del: 1.5 },
                                { bottom: 16, right: 48, size: 6, bg: '#fb7185', dur: 2.8, del: 0.4 },
                            ].map((d, i) => (
                                <span key={i} style={{
                                    position: 'absolute',
                                    top: d.top,
                                    right: d.right,
                                    bottom: d.bottom,
                                    left: d.left,
                                    width: d.size,
                                    height: d.size,
                                    background: d.bg,
                                    borderRadius: '50%',
                                    opacity: 0.45,
                                    animation: `dot-float ${d.dur}s ease-in-out ${d.del}s infinite`,
                                }} />
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
