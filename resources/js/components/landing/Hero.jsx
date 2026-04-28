import React from 'react';
import { Link } from 'react-router-dom';

export default function Hero() {
    const scrollTo = (id) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section className="relative min-h-screen flex items-center overflow-hidden pt-16">
            {/* Background */}
            <div className="absolute inset-0 -z-10">
                <div className="absolute inset-0 hero-dots" />
                <div className="absolute top-0 right-0 w-[500px] h-[500px] bg-red-500/10 rounded-full blur-[100px] animate-blob" />
                <div className="absolute bottom-0 left-0 w-[400px] h-[400px] bg-rose-500/10 rounded-full blur-[80px] animate-blob-reverse" />
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-orange-500/5 rounded-full blur-[100px] animate-blob-delay" />
            </div>

            <div className="max-w-7xl mx-auto px-4 md:px-6 w-full py-16 md:py-0">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-12 lg:gap-16">
                    {/* Content */}
                    <div className="max-w-2xl text-center lg:text-left">
                        <div className="mb-6 inline-flex items-center gap-2.5 rounded-full border border-red-500/15 bg-red-500/[0.04] px-4 py-1.5 text-xs font-medium text-red-600 backdrop-blur-md">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping-dot" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-red-500" />
                            </span>
                            Platform AI untuk UMKM Indonesia
                        </div>

                        <h1 className="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-extrabold tracking-tight text-gray-900 leading-[1.05]">
                            Solusi Digital<br />
                            <span className="bg-gradient-to-r from-red-500 via-red-600 to-red-500 bg-clip-text text-transparent animate-gradient">Cerdas &amp; Cepat</span><br />
                            untuk Bisnis Anda.
                        </h1>

                        <p className="mt-6 text-lg md:text-xl text-gray-500 leading-relaxed max-w-xl mx-auto lg:mx-0">
                            Platform all-in-one dengan kecerdasan buatan. SMM Panel, PPOB, dan Produk Digital — semua terintegrasi dalam satu ekosistem.
                        </p>

                        <div className="mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3">
                            <button
                                onClick={() => scrollTo('services')}
                                className="group relative overflow-hidden inline-flex items-center justify-center h-12 px-8 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white font-medium text-sm hover:brightness-110 transition-all shadow-[0_0_20px_rgba(239,68,68,0.15)] cursor-pointer btn-shimmer"
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
                        <div className="relative w-[280px] h-[280px] flex items-center justify-center">
                            <div className="absolute inset-[-40px] bg-red-500/[0.08] rounded-full blur-[80px] animate-pulse-glow" />
                            <div className="absolute inset-[-50px] border border-dashed border-red-500/[0.06] rounded-full animate-spin-slow-reverse" />
                            <div className="absolute inset-[-20px] border border-red-500/10 rounded-full animate-spin-slow">
                                <span className="absolute -top-1 left-1/2 w-2 h-2 bg-red-500 rounded-full shadow-[0_0_12px_rgba(239,68,68,0.5)]" />
                            </div>
                            <div className="relative w-[180px] h-[180px] bg-gradient-to-br from-white to-red-50 rounded-[40px] flex items-center justify-center shadow-[0_20px_60px_rgba(239,68,68,0.1),0_0_0_1px_rgba(239,68,68,0.08)] animate-float">
                                <span className="text-5xl font-black tracking-tighter">
                                    <span className="text-gray-900">Ultr</span>
                                    <span className="bg-gradient-to-r from-red-500 to-red-600 bg-clip-text text-transparent">AI</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
