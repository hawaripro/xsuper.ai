import React, { useState } from 'react';

/* ============================================================
   FAQ — collapsible accordion with smooth animation.
   ============================================================ */

const FAQS = [
    {
        q: 'Apa itu UltrAI dan bedanya sama ChatGPT Plus?',
        a: 'UltrAI adalah layanan akses AI premium dengan 50+ model (GPT-4o, Claude Sonnet, Gemini Pro, DeepSeek, Qwen, GLM, dll) dalam satu akun pribadi. Dibanding ChatGPT Plus yang Rp 300rb/bulan untuk 1 model, UltrAI cuma Rp 55rb/bulan untuk akses 50+ model dengan unlimited usage.',
    },
    {
        q: 'Apakah UltrAI legal dan aman dipakai?',
        a: 'Ya, UltrAI legal dan aman. Setiap user dapat akun pribadi (bukan sharing), data percakapan kamu nggak bercampur sama user lain. Semua transaksi dilindungi SSL/TLS encryption, dan kami punya kebijakan privacy yang jelas.',
    },
    {
        q: 'Bagaimana kalau akun saya habis masa aktifnya tiba-tiba atau kena limit?',
        a: 'Tenang, ada garansi refund/replace. Kalau akun habis sebelum waktunya, kena limit dari provider AI, atau token error, kamu bisa langsung hubungin kami via WhatsApp untuk dapetin akun replace atau refund pro-rata.',
    },
    {
        q: 'Bisa dipakai untuk VSCode, Cursor, atau OpenCode?',
        a: 'Bisa! UltrAI menyediakan API key yang compatible dengan format OpenAI API. Kamu bisa generate API key dari dashboard dan pakai langsung di VSCode (via Continue / Copilot extension), Cursor, OpenCode, atau tool developer lainnya yang support OpenAI-compatible endpoint.',
    },
    {
        q: 'Pembayaran pakai apa aja?',
        a: 'Semua metode pembayaran Indonesia: QRIS, transfer bank (BCA, Mandiri, BRI), e-wallet (Gopay, ShopeePay), dan lainnya. Aktivasi otomatis dalam hitungan menit setelah pembayaran kami terima.',
    },
    {
        q: 'Ada batas penggunaan atau hidden cost?',
        a: 'Nggak ada. Unlimited beneran selama masa aktif paket. Nggak ada hitungan token, nggak ada rate limit harian yang bikin nyebelin. Bayar sekali, pakai sepuasnya sampai masa aktif habis.',
    },
    {
        q: 'Kalau saya nggak puas, bisa refund?',
        a: 'Untuk paket baru yang belum dipakai, kamu bisa request refund penuh dalam 24 jam pertama. Untuk masalah teknis (akun error, kena limit, dll) kami replace atau refund pro-rata. Fair dan transparan.',
    },
    {
        q: 'Gimana cara mulai langganan?',
        a: 'Gampang banget. Klik tombol Register via WhatsApp di header, pilih paket yang kamu mau (1 hari / 1 minggu / 1 bulan / dst), transfer sesuai metode pembayaran, dan akun kamu aktif dalam hitungan menit. Nggak perlu kartu kredit, nggak perlu subscription berulang.',
    },
];

function FaqItem({ item, isOpen, onToggle, index }) {
    return (
        <div
            className="rounded-2xl bg-white border border-gray-200/80 shadow-[0_2px_8px_-2px_rgba(15,23,42,0.05)] overflow-hidden transition-all duration-300 hover:border-red-200"
            style={{ animation: 'fade-in-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${60 + index * 50}ms` }}
        >
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={isOpen}
                className="group flex items-center justify-between w-full px-5 md:px-6 py-4 md:py-5 text-left"
            >
                <span className="pr-4 flex items-center gap-3 flex-1">
                    <span className={`flex-shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-lg text-[11px] font-black transition-all duration-300 ${
                        isOpen
                            ? 'bg-gradient-to-br from-red-500 to-red-600 text-white shadow-[0_4px_10px_-2px_rgba(239,68,68,0.45)]'
                            : 'bg-red-50 text-red-500 group-hover:bg-red-100'
                    }`}>
                        {String(index + 1).padStart(2, '0')}
                    </span>
                    <span className={`text-sm md:text-base font-bold transition-colors ${isOpen ? 'text-red-600' : 'text-slate-900 group-hover:text-red-500'}`}>
                        {item.q}
                    </span>
                </span>
                <span
                    className={`flex-shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg transition-all duration-300 ${
                        isOpen
                            ? 'bg-red-500 text-white rotate-180 shadow-[0_4px_10px_-2px_rgba(239,68,68,0.45)]'
                            : 'bg-gray-100 text-slate-500 group-hover:bg-red-50 group-hover:text-red-500'
                    }`}
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </span>
            </button>
            <div
                className="grid transition-all duration-300 ease-out"
                style={{ gridTemplateRows: isOpen ? '1fr' : '0fr' }}
            >
                <div className="overflow-hidden">
                    <div className="px-5 md:px-6 pb-5 md:pb-6 text-sm md:text-[15px] text-slate-600 leading-relaxed pl-[60px]">
                        {item.a}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function FAQ() {
    const [openIdx, setOpenIdx] = useState(0);

    const toggle = (i) => setOpenIdx(openIdx === i ? -1 : i);

    return (
        <section id="faq" className="relative py-20 md:py-24 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-gray-50/60 to-white pointer-events-none" />

            <div className="relative max-w-4xl mx-auto px-4 md:px-6">
                <div className="text-center mb-12 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-black uppercase tracking-[0.15em] border border-red-200/80 mb-5">
                        FAQ
                    </span>
                    <h2 className="text-3xl md:text-5xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Pertanyaan yang <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">sering ditanya</span>
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-xl mx-auto">
                        Masih bingung? Tenang, kami udah rangkum jawaban pertanyaan paling sering muncul.
                    </p>
                </div>

                <div className="space-y-3 md:space-y-4">
                    {FAQS.map((item, i) => (
                        <FaqItem
                            key={i}
                            item={item}
                            index={i}
                            isOpen={openIdx === i}
                            onToggle={() => toggle(i)}
                        />
                    ))}
                </div>

                <div className="mt-10 p-6 md:p-8 rounded-3xl bg-gradient-to-br from-slate-900 to-slate-800 text-white text-center animate-fade-in-up relative overflow-hidden">
                    <div className="absolute -top-20 -right-20 w-60 h-60 rounded-full bg-red-500/25 blur-3xl pointer-events-none" />
                    <div className="absolute -bottom-20 -left-20 w-60 h-60 rounded-full bg-orange-500/20 blur-3xl pointer-events-none" />
                    <div className="relative">
                        <h3 className="text-xl md:text-2xl font-extrabold mb-2">Masih ada pertanyaan lain?</h3>
                        <p className="text-slate-300 mb-5 max-w-md mx-auto">
                            Chat langsung via WhatsApp, admin kami siap bantu 24/7.
                        </p>
                        <a
                            href="https://wa.me/6287786866648"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-white text-slate-900 font-bold text-sm hover:bg-red-50 hover:text-red-600 hover:-translate-y-0.5 transition-all duration-200 shadow-[0_10px_28px_-6px_rgba(255,255,255,0.3)]"
                        >
                            <svg className="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413Z"/></svg>
                            Chat Admin
                        </a>
                    </div>
                </div>
            </div>
        </section>
    );
}
