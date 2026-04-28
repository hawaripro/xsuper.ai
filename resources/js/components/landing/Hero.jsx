import React from 'react';
import { Link } from 'react-router-dom';

// Floating dot particles
function FloatingDots() {
    const dots = [
        { top: '15%', left: '10%', duration: '3s', delay: '0s', size: 6, opacity: 0.3 },
        { top: '25%', left: '85%', duration: '4s', delay: '0.5s', size: 5, opacity: 0.25 },
        { top: '60%', left: '5%', duration: '3.5s', delay: '1s', size: 7, opacity: 0.35 },
        { top: '70%', left: '90%', duration: '4.5s', delay: '1.5s', size: 4, opacity: 0.2 },
        { top: '40%', left: '20%', duration: '5s', delay: '2s', size: 5, opacity: 0.25 },
        { top: '80%', left: '75%', duration: '3.8s', delay: '0.8s', size: 6, opacity: 0.3 },
        { top: '10%', left: '60%', duration: '4.2s', delay: '1.2s', size: 4, opacity: 0.2 },
        { top: '50%', left: '95%', duration: '3.2s', delay: '0.3s', size: 5, opacity: 0.28 },
        { top: '35%', left: '45%', duration: '5.5s', delay: '2.5s', size: 3, opacity: 0.15 },
        { top: '85%', left: '30%', duration: '4.8s', delay: '1.8s', size: 5, opacity: 0.22 },
        { top: '20%', left: '70%', duration: '3.6s', delay: '0.6s', size: 6, opacity: 0.32 },
        { top: '55%', left: '15%', duration: '4.4s', delay: '1.4s', size: 4, opacity: 0.18 },
    ];

    return (
        <>
            {dots.map((dot, i) => (
                <span
                    key={i}
                    className="hero-dot-particle"
                    style={{
                        top: dot.top,
                        left: dot.left,
                        width: dot.size,
                        height: dot.size,
                        '--duration': dot.duration,
                        '--delay': dot.delay,
                        opacity: dot.opacity,
                    }}
                />
            ))}
        </>
    );
}

export default function Hero() {
    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section className="relative min-h-screen flex items-center overflow-hidden pt-16">
            {/* Background */}
            <div className="absolute inset-0 -z-10">
                {/* Dot grid pattern */}
                <div className="absolute inset-0 hero-dots" />

                {/* Animated floating dot particles */}
                <FloatingDots />

                {/* Red glow blobs — more visible */}
                <div className="absolute -top-20 -right-20 w-[600px] h-[600px] bg-red-500/20 rounded-full blur-[120px] animate-blob-glow" />
                <div className="absolute -bottom-32 -left-20 w-[500px] h-[500px] bg-rose-500/15 rounded-full blur-[100px] animate-blob-glow-reverse" />
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[700px] bg-orange-400/8 rounded-full blur-[120px] animate-blob-glow-delay" />

                {/* Extra subtle red accent line */}
                <div className="absolute top-0 left-0 right-0 h-[2px] bg-gradient-to-r from-transparent via-red-500/30 to-transparent" />
            </div>

            <div className="max-w-7xl mx-auto px-4 md:px-6 w-full py-16 md:py-0">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-12 lg:gap-16">
                    {/* Content */}
                    <div className="max-w-2xl text-center lg:text-left">
                        {/* Badge */}
                        <div className="mb-6 inline-flex items-center gap-2.5 rounded-full border border-red-500/20 bg-red-50 px-4 py-1.5 text-xs font-semibold text-red-600 backdrop-blur-md shadow-sm">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping-dot" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500 shadow-[0_0_6px_rgba(239,68,68,0.6)]" />
                            </span>
                            Platform AI untuk UMKM Indonesia
                        </div>

                        {/* Heading */}
                        <h1 className="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-extrabold tracking-tight text-gray-900 leading-[1.05]">
                            Solusi Digital<br />
                            <span className="bg-gradient-to-r from-red-500 via-orange-500 to-red-600 bg-clip-text text-transparent animate-gradient">
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
                                className="group relative overflow-hidden inline-flex items-center justify-center h-12 px-8 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white font-medium text-sm hover:brightness-110 transition-all shadow-[0_4px_24px_rgba(239,68,68,0.3)] hover:shadow-[0_8px_32px_rgba(239,68,68,0.4)] cursor-pointer btn-shimmer"
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

                    {/* Logo Visual — Enhanced */}
                    <div className="hidden lg:flex items-center justify-center">
                        <div className="relative w-[320px] h-[320px] flex items-center justify-center">
                            {/* Outer glow */}
                            <div className="absolute inset-[-60px] bg-red-500/[0.12] rounded-full blur-[100px] animate-pulse-glow" />

                            {/* Orbiting ring outer (dashed) */}
                            <div className="absolute inset-[-50px] border border-dashed border-red-500/[0.1] rounded-full animate-spin-slow-reverse" />

                            {/* Orbiting ring inner with dot */}
                            <div className="absolute inset-[-20px] border border-red-500/15 rounded-full animate-spin-slow">
                                <span className="absolute -top-1.5 left-1/2 w-3 h-3 bg-red-500 rounded-full shadow-[0_0_16px_rgba(239,68,68,0.6)]" />
                            </div>

                            {/* Second orbiting dot */}
                            <div className="absolute inset-[-35px] rounded-full animate-spin-slow-reverse" style={{ animationDuration: '40s' }}>
                                <span className="absolute top-1/2 -right-1 w-2 h-2 bg-orange-400 rounded-full shadow-[0_0_12px_rgba(251,146,60,0.5)]" />
                            </div>

                            {/* Main logo card */}
                            <div className="relative w-[200px] h-[200px] bg-gradient-to-br from-white via-white to-red-50 rounded-[44px] flex items-center justify-center shadow-[0_20px_80px_rgba(239,68,68,0.15),0_0_0_1px_rgba(239,68,68,0.1)] animate-float">
                                {/* Inner glow */}
                                <div className="absolute inset-0 rounded-[44px] bg-gradient-to-br from-red-500/[0.03] to-transparent" />
                                <span className="relative text-5xl font-black tracking-tighter">
                                    <span className="text-gray-900">Ultr</span>
                                    <span className="bg-gradient-to-r from-red-500 to-red-600 bg-clip-text text-transparent">AI</span>
                                </span>
                            </div>

                            {/* Floating mini dots around logo */}
                            <span className="absolute top-4 right-8 w-2 h-2 bg-red-400 rounded-full hero-dot-particle" style={{ '--duration': '2.5s', '--delay': '0s', opacity: 0.5 }} />
                            <span className="absolute bottom-8 left-4 w-1.5 h-1.5 bg-orange-400 rounded-full hero-dot-particle" style={{ '--duration': '3s', '--delay': '0.8s', opacity: 0.4 }} />
                            <span className="absolute top-1/2 right-0 w-2 h-2 bg-red-300 rounded-full hero-dot-particle" style={{ '--duration': '3.5s', '--delay': '1.5s', opacity: 0.35 }} />
                            <span className="absolute bottom-4 right-12 w-1.5 h-1.5 bg-rose-400 rounded-full hero-dot-particle" style={{ '--duration': '2.8s', '--delay': '0.4s', opacity: 0.45 }} />
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
