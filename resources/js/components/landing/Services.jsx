import React from 'react';

const services = [
    {
        title: 'SMM Panel',
        desc: 'Tingkatkan engagement media sosial Anda. Followers, likes, views, dan semua layanan social media marketing dalam satu dashboard.',
        link: 'https://smm.superpanelpedia.com',
        linkText: 'Kunjungi SMM Panel',
        icon: (
            <svg className="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"><path d="M17 2h4v4M15 9l7-7M7 22H3v-4M9 15l-7 7M22 17v4h-4M15 15l7 7M2 7V3h4M9 9 2 2"/></svg>
        ),
        gradient: 'from-red-500 to-rose-500',
    },
    {
        title: 'PPOB',
        desc: 'Payment Point Online Bank lengkap. Pulsa, token listrik, BPJS, internet, dan ratusan produk pembayaran lainnya.',
        link: 'https://ppob.superpanelpedia.com',
        linkText: 'Kunjungi PPOB',
        icon: (
            <svg className="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        ),
        gradient: 'from-amber-500 to-orange-500',
    },
    {
        title: 'Produk Digital',
        desc: 'Marketplace produk digital terlengkap. Akun premium, software license, template, dan berbagai produk digital berkualitas.',
        link: 'https://digital.superpanelpedia.com',
        linkText: 'Kunjungi Produk Digital',
        icon: (
            <svg className="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        ),
        gradient: 'from-violet-500 to-purple-500',
    },
];

export default function Services() {
    return (
        <section id="services" className="py-24 md:py-32">
            <div className="max-w-7xl mx-auto px-4 md:px-6">
                <div className="text-center mb-16">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-semibold uppercase tracking-wider mb-5">
                        Layanan Kami
                    </span>
                    <h2 className="text-3xl md:text-4xl lg:text-5xl font-extrabold text-gray-900 tracking-tight mb-4">
                        Semua yang Bisnis Anda Butuhkan
                    </h2>
                    <p className="text-lg text-gray-500 max-w-xl mx-auto">
                        Tiga layanan utama yang dirancang khusus untuk mendukung pertumbuhan UMKM Indonesia.
                    </p>
                </div>

                <div className="grid md:grid-cols-3 gap-6">
                    {services.map((s, i) => (
                        <a key={i} href={s.link} target="_blank" rel="noopener noreferrer"
                            className="group relative p-8 md:p-10 rounded-2xl border border-gray-100 bg-white hover:-translate-y-1 hover:border-red-500/15 hover:shadow-[0_20px_40px_rgba(0,0,0,0.06)] transition-all duration-300 overflow-hidden">
                            <div className={`absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r ${s.gradient} opacity-0 group-hover:opacity-100 transition-opacity`} />
                            <div className={`w-14 h-14 rounded-2xl bg-gradient-to-br ${s.gradient} flex items-center justify-center text-white mb-6 shadow-lg`}>
                                {s.icon}
                            </div>
                            <h3 className="text-xl font-bold text-gray-900 mb-3">{s.title}</h3>
                            <p className="text-[15px] text-gray-500 leading-relaxed mb-6">{s.desc}</p>
                            <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-red-500 group-hover:gap-2.5 transition-all">
                                {s.linkText}
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </span>
                        </a>
                    ))}
                </div>
            </div>
        </section>
    );
}
