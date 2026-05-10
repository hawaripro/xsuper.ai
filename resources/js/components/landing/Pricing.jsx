import React from 'react';

/* ============================================================
   Pricing section — 6 plans, monthly-equivalent savings, VIP feel.
   ============================================================ */

const WA_NUMBER = '6287786866648';

const PLANS = [
    {
        key: 'daily',
        name: '1 Hari',
        price: 5000,
        perLabel: 'hari',
        badge: 'Coba Dulu',
        badgeTone: 'slate',
        description: 'Cocok buat testing atau tugas singkat sekali pakai.',
        perks: ['Akses 50+ AI Models', 'Unlimited usage 24 jam', 'Garansi refund'],
        highlight: false,
    },
    {
        key: 'weekly',
        name: '1 Minggu',
        price: 20000,
        perLabel: '7 hari',
        badge: 'Fleksibel',
        badgeTone: 'blue',
        description: 'Deadline tugas atau project 1 mingguan.',
        perks: ['Akses 50+ AI Models', 'Unlimited usage 7 hari', 'Support API key'],
        highlight: false,
    },
    {
        key: 'monthly',
        name: '1 Bulan',
        price: 55000,
        perLabel: 'bulan',
        badge: 'Paling Laris',
        badgeTone: 'red',
        description: 'Pilihan paling populer. Best value harian.',
        perks: ['Akses 50+ AI Models', 'Unlimited usage 30 hari', 'Support API key', 'Garansi refund penuh'],
        highlight: true,
        compareToOpenAI: { original: 300000, saving: 82 },
    },
    {
        key: 'quarterly',
        name: '3 Bulan',
        price: 135000,
        perLabel: '3 bulan',
        badge: 'Hemat 18%',
        badgeTone: 'emerald',
        description: 'Cocok untuk semester atau sprint proyek.',
        perks: ['Semua fitur 1 bulan', 'Harga per bulan Rp 45rb', 'Prioritas support'],
        highlight: false,
        saving: 18,
        perMonth: 45000,
    },
    {
        key: 'semi',
        name: '6 Bulan',
        price: 299000,
        perLabel: '6 bulan',
        badge: 'Hemat 24%',
        badgeTone: 'emerald',
        description: 'Untuk freelancer yang butuh konsistensi.',
        perks: ['Semua fitur 3 bulan', 'Harga per bulan Rp 49.8rb', 'Prioritas support'],
        highlight: false,
        saving: 24,
        perMonth: 49833,
    },
    {
        key: 'yearly',
        name: '12 Bulan',
        price: 499000,
        perLabel: 'tahun',
        badge: 'Hemat 38%',
        badgeTone: 'amber',
        description: 'Paling hemat. Cocok buat bisnis jangka panjang.',
        perks: ['Semua fitur 6 bulan', 'Harga per bulan Rp 41.5rb', 'Prioritas 24/7'],
        highlight: false,
        saving: 38,
        perMonth: 41583,
    },
];

const fmt = new Intl.NumberFormat('id-ID');

const BADGE_STYLES = {
    slate:   'bg-slate-100 text-slate-600 border-slate-200',
    blue:    'bg-blue-50 text-blue-600 border-blue-200',
    red:     'bg-gradient-to-r from-red-500 to-orange-500 text-white border-transparent shadow-[0_6px_18px_-4px_rgba(239,68,68,0.45)]',
    emerald: 'bg-emerald-50 text-emerald-600 border-emerald-200',
    amber:   'bg-amber-50 text-amber-700 border-amber-200',
};

function PlanCard({ plan, index }) {
    const wa = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(`Halo UltrAI, saya ingin order paket ${plan.name} (Rp ${fmt.format(plan.price)}).`)}`;

    return (
        <div
            className={`
                group relative p-6 lg:p-7 rounded-3xl transition-all duration-300
                border overflow-hidden
                ${plan.highlight
                    ? 'bg-gradient-to-br from-white via-red-50/40 to-orange-50/40 border-red-300 shadow-[0_32px_80px_-16px_rgba(239,68,68,0.35),0_12px_32px_-8px_rgba(239,68,68,0.15)] md:scale-105 z-10'
                    : 'bg-white/95 border-gray-200/80 shadow-[0_8px_24px_-8px_rgba(15,23,42,0.08)] hover:shadow-[0_20px_48px_-12px_rgba(15,23,42,0.18)] hover:-translate-y-1.5 hover:border-red-200'
                }
            `}
            style={{
                animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both',
                animationDelay: `${60 + index * 60}ms`,
            }}
        >
            {/* Highlight aura */}
            {plan.highlight && (
                <>
                    <div className="absolute -top-16 -right-16 w-48 h-48 rounded-full bg-red-500/15 blur-3xl pointer-events-none" />
                    <div className="absolute -bottom-20 -left-16 w-56 h-56 rounded-full bg-orange-400/15 blur-3xl pointer-events-none" />
                    <div
                        className="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-transparent via-red-500 to-transparent"
                        style={{ backgroundSize: '200% 100%', animation: 'gradient-shift 3s linear infinite' }}
                        aria-hidden="true"
                    />
                </>
            )}

            {/* Badge */}
            <div className="relative flex items-center justify-between mb-5">
                <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-[0.1em] border ${BADGE_STYLES[plan.badgeTone]}`}>
                    {plan.highlight && <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 14.39 8.26 21 9.27 16 14.14 17.18 21.02 12 17.77 6.82 21.02 8 14.14 3 9.27 9.61 8.26 12 2Z"/></svg>}
                    {plan.badge}
                </span>
                {plan.saving && !plan.highlight && (
                    <span className="text-[10px] font-bold text-emerald-600 tabular-nums bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">
                        Save {plan.saving}%
                    </span>
                )}
            </div>

            {/* Name */}
            <div className="relative">
                <h3 className={`text-xl font-extrabold tracking-tight ${plan.highlight ? 'text-red-600' : 'text-slate-900'}`}>
                    {plan.name}
                </h3>
                <p className="mt-1 text-sm text-slate-500 line-clamp-2 min-h-[40px]">{plan.description}</p>
            </div>

            {/* Price */}
            <div className="relative my-5 pb-5 border-b border-gray-200/80">
                {plan.compareToOpenAI && (
                    <div className="mb-2 flex items-center gap-2">
                        <span className="relative inline-flex items-baseline">
                            <span className="text-base font-bold text-slate-400 line-through decoration-red-500 decoration-2">Rp {fmt.format(plan.compareToOpenAI.original)}</span>
                        </span>
                        <span className="px-1.5 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider bg-gradient-to-r from-red-500 to-orange-500 text-white">
                            −{plan.compareToOpenAI.saving}%
                        </span>
                    </div>
                )}
                <div className="flex items-baseline gap-1">
                    <span className="text-sm font-bold text-slate-500">Rp</span>
                    <span className={`text-4xl lg:text-5xl font-black tracking-[-0.02em] tabular-nums ${
                        plan.highlight
                            ? 'bg-gradient-to-br from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent'
                            : 'text-slate-900'
                    }`}>
                        {fmt.format(plan.price)}
                    </span>
                </div>
                <div className="mt-1 text-xs text-slate-500">
                    / {plan.perLabel}
                    {plan.perMonth && (
                        <span className="ml-1 text-emerald-600 font-semibold">
                            · ≈ Rp {fmt.format(plan.perMonth)} /bulan
                        </span>
                    )}
                </div>
            </div>

            {/* Perks */}
            <ul className="relative space-y-2.5 mb-6">
                {plan.perks.map((perk, i) => (
                    <li key={i} className="flex items-start gap-2.5 text-sm">
                        <span className={`flex-shrink-0 mt-0.5 w-5 h-5 rounded-full flex items-center justify-center ${
                            plan.highlight ? 'bg-red-500 text-white' : 'bg-emerald-500 text-white'
                        }`}>
                            <svg className="w-3 h-3" fill="none" stroke="currentColor" strokeWidth="3" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        <span className="text-slate-700">{perk}</span>
                    </li>
                ))}
            </ul>

            {/* CTA */}
            <a
                href={wa}
                target="_blank"
                rel="noopener noreferrer"
                className={`relative block text-center w-full py-3 rounded-xl text-sm font-bold transition-all duration-200 ${
                    plan.highlight
                        ? 'bg-gradient-to-r from-red-500 to-red-600 text-white shadow-[0_12px_28px_-6px_rgba(239,68,68,0.45)] hover:shadow-[0_18px_40px_-8px_rgba(239,68,68,0.6)] hover:brightness-105 hover:-translate-y-0.5'
                        : 'bg-slate-900 text-white hover:bg-slate-800 hover:-translate-y-0.5 hover:shadow-[0_12px_28px_-6px_rgba(15,23,42,0.25)]'
                }`}
            >
                {plan.highlight ? 'Pilih Paket Populer' : 'Order via WhatsApp'}
            </a>
        </div>
    );
}

export default function Pricing() {
    return (
        <section id="pricing" className="relative py-16 md:py-20 overflow-hidden">
            {/* Background */}
            <div className="absolute inset-0 bg-gradient-to-b from-white via-red-50/30 to-white pointer-events-none" />
            <div className="absolute inset-0 hero-dots opacity-30 pointer-events-none" />
            <div className="absolute top-10 left-1/2 -translate-x-1/2 w-[800px] h-[400px] rounded-full bg-red-400/10 blur-[120px] pointer-events-none" />

            <div className="relative max-w-7xl mx-auto px-4 md:px-6">
                {/* Header */}
                <div className="text-center mb-10 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-black uppercase tracking-[0.15em] border border-red-200/80 mb-5">
                        <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 14.39 8.26 21 9.27 16 14.14 17.18 21.02 12 17.77 6.82 21.02 8 14.14 3 9.27 9.61 8.26 12 2Z"/></svg>
                        Harga Spesial Indonesia
                    </span>
                    <h2 className="text-2xl md:text-3xl lg:text-4xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Lebih murah dari{' '}
                        <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">
                            secangkir kopi
                        </span>
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-2xl mx-auto">
                        ChatGPT Plus <span className="font-bold text-slate-500 line-through decoration-red-500 decoration-2">Rp 300.000</span>
                        <span className="mx-2">·</span>
                        UltrAI <span className="font-black text-red-500">Rp 55.000</span> dengan <span className="font-bold">50+ model AI</span>.
                        Semua paket Unlimited Usage &amp; Garansi Refund.
                    </p>
                </div>

                {/* Plans — horizontal snap-scroll, user swipes/drags */}
                <div className="relative -mx-4 md:-mx-6 mb-10">
                    <div className="flex items-stretch gap-4 lg:gap-5 px-4 md:px-6 py-4 overflow-x-auto snap-x snap-mandatory scrollbar-thin scroll-smooth">
                        {PLANS.map((plan, i) => (
                            <div key={plan.key} className="snap-start shrink-0 w-[280px] sm:w-[300px] md:w-[320px]">
                                <PlanCard plan={plan} index={i} />
                            </div>
                        ))}
                    </div>
                </div>

                {/* Bottom note */}
                <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-slate-500 animate-fade-in-up">
                    <span className="inline-flex items-center gap-1.5">
                        <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                        Aktivasi instan
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                        Tanpa komitmen
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <svg className="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                        Pembayaran lokal
                    </span>
                </div>
            </div>
        </section>
    );
}
