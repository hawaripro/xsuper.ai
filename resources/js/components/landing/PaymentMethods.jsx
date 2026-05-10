import React from 'react';
import { QRISLogo, BCALogo, MandiriLogo, BRILogo, GopayLogo, ShopeePayLogo, LogoChip } from './BrandIcons';

/* ============================================================
   PaymentMethods — accepted payment methods strip.
   ============================================================ */

const METHODS = [
    { key: 'qris',      name: 'QRIS',      Logo: QRISLogo },
    { key: 'bca',       name: 'BCA',       Logo: BCALogo },
    { key: 'mandiri',   name: 'Mandiri',   Logo: MandiriLogo },
    { key: 'bri',       name: 'BRI',       Logo: BRILogo },
    { key: 'gopay',     name: 'Gopay',     Logo: GopayLogo },
    { key: 'shopeepay', name: 'ShopeePay', Logo: ShopeePayLogo },
];

export default function PaymentMethods() {
    return (
        <section id="payment" className="relative py-16 md:py-20 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-gray-50/60 to-white pointer-events-none" />

            <div className="relative max-w-6xl mx-auto px-4 md:px-6">
                <div className="text-center mb-10 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-emerald-50 text-emerald-600 text-xs font-black uppercase tracking-[0.15em] border border-emerald-200/80 mb-5">
                        <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                        Pembayaran Mudah
                    </span>
                    <h2 className="text-2xl md:text-4xl font-black tracking-[-0.02em] text-slate-900 leading-[1.1]">
                        Bayar pakai <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">metode favorit</span> kamu
                    </h2>
                    <p className="mt-3 text-sm md:text-base text-slate-600 max-w-xl mx-auto">
                        Semua metode pembayaran Indonesia. Aktivasi instan begitu pembayaran kamu kami terima.
                    </p>
                </div>

                <div className="relative p-6 md:p-8 rounded-3xl bg-white border border-gray-200/80 shadow-[0_16px_48px_-16px_rgba(15,23,42,0.12)] animate-fade-in-up overflow-hidden" style={{ animationDelay: '100ms' }}>
                    {/* Decorative beam */}
                    <div
                        className="absolute inset-x-0 top-0 h-[2px] bg-gradient-to-r from-transparent via-red-500 to-transparent"
                        style={{ backgroundSize: '200% 100%', animation: 'gradient-shift 4s linear infinite' }}
                        aria-hidden="true"
                    />

                    <div className="grid grid-cols-3 md:grid-cols-6 gap-3 md:gap-4">
                        {METHODS.map(({ key, name, Logo }, i) => (
                            <div
                                key={key}
                                className="group flex flex-col items-center gap-2"
                                style={{ animation: 'pop-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both', animationDelay: `${120 + i * 60}ms` }}
                            >
                                <div
                                    className="relative w-full aspect-[2/1] rounded-xl bg-gray-50/80 border border-gray-200 flex items-center justify-center p-2.5 transition-all duration-300 group-hover:border-red-200 group-hover:bg-white group-hover:shadow-[0_6px_18px_-4px_rgba(239,68,68,0.15)] group-hover:-translate-y-0.5"
                                >
                                    <Logo className="max-w-full max-h-full" />
                                </div>
                                <span className="text-[11px] font-semibold text-slate-600 group-hover:text-slate-900 transition-colors">{name}</span>
                            </div>
                        ))}
                    </div>

                    <div className="mt-6 pt-6 border-t border-gray-200/70 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs text-slate-500">
                        <span className="inline-flex items-center gap-1.5">
                            <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            Aktivasi instan
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            Transaksi aman (SSL)
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            Konfirmasi otomatis
                        </span>
                    </div>
                </div>
            </div>
        </section>
    );
}
