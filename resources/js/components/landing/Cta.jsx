import React from 'react';

const WA_NUMBER = '6287786866648';
const WA_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent('Halo UltrAI, saya ingin mendaftar akun UltrAI.')}`;

export default function Cta() {
    const scrollToPricing = () => document.getElementById('pricing')?.scrollIntoView({ behavior: 'smooth' });

    return (
        <section className="relative py-16 md:py-20 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white to-gray-50 pointer-events-none" />

            <div className="relative max-w-5xl mx-auto px-4 md:px-6">
                <div className="relative p-10 md:p-14 lg:p-16 rounded-[2rem] bg-gradient-to-br from-slate-900 via-slate-900 to-slate-800 text-white text-center overflow-hidden shadow-[0_32px_80px_-16px_rgba(15,23,42,0.35)] animate-fade-in-up">
                    {/* Aurora glows */}
                    <div className="absolute -top-32 -right-32 w-96 h-96 rounded-full bg-red-500/30 blur-[120px] animate-aurora pointer-events-none" />
                    <div className="absolute -bottom-32 -left-32 w-96 h-96 rounded-full bg-orange-500/25 blur-[120px] animate-aurora pointer-events-none" style={{ animationDelay: '3s' }} />
                    <div className="absolute inset-0 hero-dots opacity-20 pointer-events-none" />

                    <div className="relative">
                        <span className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/10 border border-white/15 text-white text-xs font-bold uppercase tracking-[0.15em] mb-6">
                            <span className="relative flex w-2 h-2">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping" />
                                <span className="relative inline-flex w-2 h-2 rounded-full bg-emerald-400" />
                            </span>
                            Aktivasi instan &middot; Garansi refund
                        </span>

                        <h2 className="text-2xl md:text-3xl lg:text-4xl font-black tracking-[-0.02em] leading-[1.05] mb-4">
                            Siap upgrade cara kamu pakai AI?
                        </h2>
                        <p className="text-base md:text-lg text-slate-300 max-w-2xl mx-auto mb-8">
                            Mulai cuma dari <span className="font-bold text-white">Rp 5.000</span>. Akses 50+ model AI unlimited, support API key, refund kalau ada kendala.
                        </p>

                        <div className="flex flex-wrap justify-center gap-3">
                            <button
                                onClick={scrollToPricing}
                                className="inline-flex items-center gap-2 px-8 py-3.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white font-bold text-base shadow-[0_16px_40px_-8px_rgba(239,68,68,0.6)] hover:shadow-[0_24px_56px_-12px_rgba(239,68,68,0.75)] hover:-translate-y-0.5 hover:brightness-110 active:translate-y-0 active:scale-[0.98] transition-all duration-200"
                            >
                                Lihat Harga
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </button>
                            <a
                                href={WA_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 px-8 py-3.5 rounded-xl bg-white/10 border border-white/20 backdrop-blur-sm text-white font-bold text-base hover:bg-white/15 hover:-translate-y-0.5 transition-all duration-200"
                            >
                                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413Z"/></svg>
                                Register via WhatsApp
                            </a>
                        </div>

                        <div className="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-slate-400">
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                Tanpa kartu kredit
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                Aktivasi &lt; 5 menit
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <svg className="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                Support 24/7
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
