import React from 'react';
import Carousel from './Carousel';

/* ============================================================
   Testimonials — social proof cards with avatar + rating.
   ============================================================ */

const TESTIMONIALS = [
    {
        name: 'Ardi Saputra',
        role: 'Fullstack Developer',
        avatar: 'A',
        grad: 'from-red-500 to-orange-500',
        rating: 5,
        quote: 'Ganti dari ChatGPT Plus ke UltrAI bulan lalu. Hemat 250rb/bulan dan dapet Claude + Gemini sekalian. Gilaak sih value-nya.',
    },
    {
        name: 'Nadia Putri',
        role: 'Content Creator',
        avatar: 'N',
        grad: 'from-violet-500 to-pink-500',
        rating: 5,
        quote: 'Awalnya ragu karena murah banget. Tapi ternyata akun beneran pribadi, model lengkap, support refund. Udah langganan 3 bulan, nggak ada drama.',
    },
    {
        name: 'Rizky Pratama',
        role: 'Mahasiswa Informatika',
        avatar: 'R',
        grad: 'from-blue-500 to-indigo-500',
        rating: 5,
        quote: 'Buat ngerjain tugas kuliah, akses Claude Sonnet 5rb/hari cukup bget. Buat skripsi aku upgrade ke 1 bulan — Claude bantuin coding dan ChatGPT bantuin nulis.',
    },
    {
        name: 'Maria Lestari',
        role: 'Freelance Copywriter',
        avatar: 'M',
        grad: 'from-emerald-500 to-teal-500',
        rating: 5,
        quote: 'Draft proposal klien, riset kompetitor, A/B test copy — semua jauh lebih cepat. ROI-nya kerasa banget, bayar 55rb tapi dapet kerjaan 3 juta-an.',
    },
    {
        name: 'Dimas Kurniawan',
        role: 'AI Researcher',
        avatar: 'D',
        grad: 'from-amber-500 to-red-500',
        rating: 5,
        quote: 'Bisa bandingin output Claude vs GPT vs Gemini dari satu akun sangat membantu research prompt engineering. Plus support OpenCode via API key — mantap.',
    },
    {
        name: 'Siti Rahma',
        role: 'Founder UMKM',
        avatar: 'S',
        grad: 'from-pink-500 to-rose-500',
        rating: 5,
        quote: 'Buat bikin caption produk, balas customer, analisis review. AI sekarang udah kayak asisten kecil yg 24/7 standby. Worth banget buat usaha kecil.',
    },
];

function StarRating({ value = 5 }) {
    return (
        <div className="inline-flex items-center gap-0.5" aria-label={`Rating ${value} dari 5 bintang`}>
            {Array.from({ length: 5 }).map((_, i) => (
                <svg key={i} className={`w-4 h-4 ${i < value ? 'text-amber-400' : 'text-slate-200'}`} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 2 14.39 8.26 21 9.27 16 14.14 17.18 21.02 12 17.77 6.82 21.02 8 14.14 3 9.27 9.61 8.26 12 2Z"/>
                </svg>
            ))}
        </div>
    );
}

function TestimonialCard({ t, ...rest }) {
    return (
        <figure
            {...rest}
            className="group shrink-0 w-[300px] md:w-[360px] relative p-6 lg:p-7 rounded-3xl bg-white border border-gray-200/80 shadow-[0_8px_24px_-8px_rgba(15,23,42,0.08)] hover:shadow-[0_24px_56px_-16px_rgba(15,23,42,0.18)] hover:-translate-y-1 hover:border-red-200 transition-all duration-300 overflow-hidden"
        >
            <span className="absolute top-5 right-5 text-7xl font-black text-red-500/10 leading-none select-none pointer-events-none">
                &ldquo;
            </span>

            <div className="relative mb-4">
                <StarRating value={t.rating} />
            </div>

            <blockquote className="relative text-sm md:text-[15px] text-slate-700 leading-relaxed mb-6">
                &ldquo;{t.quote}&rdquo;
            </blockquote>

            <figcaption className="relative flex items-center gap-3">
                <div className={`relative w-11 h-11 rounded-xl bg-gradient-to-br ${t.grad} text-white font-black flex items-center justify-center shadow-md ring-4 ring-white group-hover:scale-110 transition-transform duration-200`}>
                    {t.avatar}
                    <span className="absolute inset-0 rounded-xl ring-1 ring-white/30 pointer-events-none" />
                </div>
                <div>
                    <div className="text-sm font-bold text-slate-900">{t.name}</div>
                    <div className="text-xs text-slate-500">{t.role}</div>
                </div>
            </figcaption>
        </figure>
    );
}

export default function Testimonials() {
    return (
        <section id="testimonials" className="relative py-16 md:py-20 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-red-50/20 to-white" />
            <div className="absolute top-0 right-0 w-[500px] h-[500px] rounded-full bg-orange-400/10 blur-[120px] pointer-events-none" />
            <div className="absolute bottom-0 left-0 w-[400px] h-[400px] rounded-full bg-red-400/10 blur-[120px] pointer-events-none" />

            <div className="relative max-w-7xl mx-auto px-4 md:px-6">
                <div className="text-center mb-10 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-amber-50 text-amber-700 text-xs font-black uppercase tracking-[0.15em] border border-amber-200/80 mb-5">
                        <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 14.39 8.26 21 9.27 16 14.14 17.18 21.02 12 17.77 6.82 21.02 8 14.14 3 9.27 9.61 8.26 12 2Z"/></svg>
                        Dipercaya 1000+ pengguna
                    </span>
                    <h2 className="text-2xl md:text-3xl lg:text-4xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Apa kata mereka yang sudah <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">pakai UltrAI</span>
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-2xl mx-auto">
                        Developer, mahasiswa, content creator, freelancer — ratusan pengguna udah ngerasain UltrAI.
                    </p>
                </div>

                {/* Testimonials — carousel, 1 per 1 */}
                <div className="max-w-[400px] mx-auto">
                    <Carousel autoPlay interval={5000}>
                        {TESTIMONIALS.map((t, i) => (
                            <TestimonialCard key={i} t={t} />
                        ))}
                    </Carousel>
                </div>

                {/* Footer stats */}
                <div className="mt-12 grid grid-cols-3 gap-4 max-w-3xl mx-auto">
                    {[
                        { value: '1.000+',  label: 'Pengguna Aktif' },
                        { value: '50+',     label: 'Model AI'       },
                        { value: '4.9/5',   label: 'Rating'         },
                    ].map((s, i) => (
                        <div
                            key={s.label}
                            className="text-center p-5 rounded-2xl bg-white/80 backdrop-blur border border-gray-200/80 shadow-[0_4px_16px_-4px_rgba(15,23,42,0.08)]"
                            style={{ animation: 'fade-in-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${400 + i * 80}ms` }}
                        >
                            <div className="text-2xl md:text-3xl font-black tracking-tight bg-gradient-to-br from-red-500 to-orange-500 bg-clip-text text-transparent">
                                {s.value}
                            </div>
                            <div className="mt-1 text-xs font-semibold text-slate-500">{s.label}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
