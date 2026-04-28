import React from 'react';

const features = [
    {
        icon: <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>,
        title: 'AI-Powered',
        desc: 'Didukung 183+ model AI termasuk GPT, Claude, Gemini, dan DeepSeek untuk otomatisasi dan analisis bisnis cerdas.',
    },
    {
        icon: <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>,
        title: 'Ultra Fast',
        desc: 'Infrastruktur server Indonesia dengan response time minimal. Transaksi diproses dalam hitungan milidetik.',
    },
    {
        icon: <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>,
        title: 'Keamanan Tinggi',
        desc: 'Enkripsi SSL/TLS, firewall berlapis, dan Docker container isolation untuk keamanan data maksimal.',
    },
    {
        icon: <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24" strokeLinecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>,
        title: 'Dashboard Realtime',
        desc: 'Monitor semua transaksi, analytics, dan performa bisnis Anda secara realtime dari satu dashboard.',
    },
];

export default function Features() {
    return (
        <section id="features" className="py-24 md:py-32 bg-gradient-to-b from-gray-50 to-white">
            <div className="max-w-7xl mx-auto px-4 md:px-6">
                <div className="text-center mb-16">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-semibold uppercase tracking-wider mb-5">
                        Keunggulan
                    </span>
                    <h2 className="text-3xl md:text-4xl lg:text-5xl font-extrabold text-gray-900 tracking-tight mb-4">
                        Mengapa Memilih UltrAI?
                    </h2>
                    <p className="text-lg text-gray-500 max-w-xl mx-auto">
                        Dibangun dengan teknologi terkini untuk memberikan pengalaman terbaik.
                    </p>
                </div>

                <div className="grid md:grid-cols-2 gap-5">
                    {features.map((f, i) => (
                        <div key={i} className="group p-8 md:p-9 rounded-2xl border border-gray-100 bg-white hover:border-gray-200 hover:shadow-[0_8px_30px_rgba(0,0,0,0.04)] transition-all">
                            <div className="w-11 h-11 rounded-xl bg-red-50 flex items-center justify-center text-red-500 mb-5 group-hover:scale-110 transition-transform">
                                {f.icon}
                            </div>
                            <h3 className="text-lg font-bold text-gray-900 mb-2">{f.title}</h3>
                            <p className="text-[15px] text-gray-500 leading-relaxed">{f.desc}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
