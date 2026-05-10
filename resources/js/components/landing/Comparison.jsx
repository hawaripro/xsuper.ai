import React from 'react';
import UltrLogo from '../UltrLogo';
import { ChatGPTLogo } from './BrandIcons';

/* ============================================================
   Comparison table — UltrAI vs ChatGPT Plus.
   Desktop: 3-column table. Mobile: stacked feature cards.
   ============================================================ */

const ROWS = [
    { feature: 'Harga / bulan',       ultrai: 'Rp 55.000',        chatgpt: 'Rp 300.000',        kind: 'price' },
    { feature: 'Jumlah model AI',     ultrai: '50+ model',        chatgpt: '1 model (GPT-4o)',  kind: 'text'  },
    { feature: 'Unlimited usage',     ultrai: true,               chatgpt: false,               kind: 'bool'  },
    { feature: 'Akun pribadi',        ultrai: true,               chatgpt: true,                kind: 'bool'  },
    { feature: 'Claude Sonnet',       ultrai: true,               chatgpt: false,               kind: 'bool'  },
    { feature: 'Gemini Pro',          ultrai: true,               chatgpt: false,               kind: 'bool'  },
    { feature: 'DeepSeek / Qwen / GLM', ultrai: true,             chatgpt: false,               kind: 'bool'  },
    { feature: 'Support API Key',     ultrai: 'VSCode, Cursor, OpenCode', chatgpt: 'Hanya OpenAI', kind: 'text' },
    { feature: 'Pembayaran lokal',    ultrai: 'QRIS, BCA, Gopay, dll',    chatgpt: 'Kartu kredit internasional', kind: 'text' },
    { feature: 'Refund / Garansi',    ultrai: 'Ya — refund/replace',      chatgpt: 'Tidak',              kind: 'text-bool' },
    { feature: 'Support bahasa ID',   ultrai: true,               chatgpt: false,               kind: 'bool'  },
];

const CheckIcon = ({ className = 'w-5 h-5' }) => (
    <svg className={className} fill="none" stroke="currentColor" strokeWidth="3" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
);
const XIcon = ({ className = 'w-5 h-5' }) => (
    <svg className={className} fill="none" stroke="currentColor" strokeWidth="3" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
);

function CellValue({ value, isUltrai }) {
    if (value === true) {
        return (
            <span className={`inline-flex items-center justify-center w-7 h-7 rounded-full ${isUltrai ? 'bg-emerald-500 text-white shadow-[0_4px_12px_-2px_rgba(16,185,129,0.45)]' : 'bg-emerald-50 text-emerald-500 border border-emerald-200'}`}>
                <CheckIcon className="w-4 h-4" />
            </span>
        );
    }
    if (value === false) {
        return (
            <span className="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-100 text-slate-400 border border-slate-200">
                <XIcon className="w-4 h-4" />
            </span>
        );
    }
    return (
        <span className={`text-sm font-semibold ${isUltrai ? 'text-slate-900' : 'text-slate-600'}`}>
            {value}
        </span>
    );
}

export default function Comparison() {
    return (
        <section id="compare" className="relative py-20 md:py-24 overflow-hidden">
            <div className="absolute inset-0 bg-gradient-to-b from-white via-gray-50/60 to-white pointer-events-none" />

            <div className="relative max-w-5xl mx-auto px-4 md:px-6">
                <div className="text-center mb-12 animate-fade-in-up">
                    <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-red-50 text-red-600 text-xs font-black uppercase tracking-[0.15em] border border-red-200/80 mb-5">
                        Perbandingan
                    </span>
                    <h2 className="text-3xl md:text-5xl font-black tracking-[-0.02em] text-slate-900 leading-[1.05]">
                        Kenapa UltrAI <span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">5× lebih hemat</span>
                    </h2>
                    <p className="mt-4 text-base md:text-lg text-slate-600 max-w-2xl mx-auto">
                        Bandingkan fitur lengkapnya. UltrAI kasih akses ke lebih banyak model, unlimited usage, dan support API key untuk developer.
                    </p>
                </div>

                {/* Desktop table */}
                <div className="hidden md:block">
                    <div className="relative overflow-hidden rounded-3xl border border-gray-200/80 bg-white/95 shadow-[0_24px_60px_-16px_rgba(15,23,42,0.18)] animate-fade-in-up" style={{ animationDelay: '120ms' }}>
                        <table className="w-full">
                            <thead>
                                <tr>
                                    <th className="text-left px-6 py-5 text-xs font-bold uppercase tracking-[0.12em] text-slate-500 bg-gray-50/80 border-b border-gray-200">
                                        Fitur
                                    </th>
                                    <th className="relative text-center px-6 py-5 bg-gradient-to-br from-red-500 via-red-500 to-orange-500 text-white border-b border-red-600 overflow-hidden">
                                        <div className="absolute inset-0 bg-gradient-to-b from-white/10 to-transparent" />
                                        <div className="relative">
                                            <div className="inline-flex items-center gap-2">
                                                <UltrLogo className="w-7 h-7 !shadow-none !ring-0" />
                                                <span className="text-base font-black tracking-tight">UltrAI</span>
                                            </div>
                                            <div className="mt-1 text-[11px] font-bold uppercase tracking-wider opacity-90">Rp 55rb / bulan</div>
                                        </div>
                                        <span className="absolute -top-0.5 left-1/2 -translate-x-1/2 px-2.5 py-0.5 rounded-b-md bg-amber-400 text-amber-950 text-[9px] font-black uppercase tracking-wider shadow-md">Populer</span>
                                    </th>
                                    <th className="text-center px-6 py-5 text-slate-600 border-b border-gray-200 bg-gray-50/80">
                                        <div className="inline-flex items-center gap-2">
                                            <span className="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center p-1">
                                                <ChatGPTLogo className="w-full h-full" />
                                            </span>
                                            <span className="text-base font-bold text-slate-700">ChatGPT Plus</span>
                                        </div>
                                        <div className="mt-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400 line-through">Rp 300rb / bulan</div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {ROWS.map((row, i) => (
                                    <tr
                                        key={i}
                                        className={`${i % 2 === 0 ? 'bg-white' : 'bg-gray-50/40'} hover:bg-red-50/40 transition-colors`}
                                        style={{ animation: 'fade-in-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${150 + i * 40}ms` }}
                                    >
                                        <td className="px-6 py-4 text-sm font-semibold text-slate-800 border-b border-gray-100 last:border-0">
                                            {row.feature}
                                        </td>
                                        <td className="px-6 py-4 text-center border-b border-gray-100 last:border-0">
                                            <CellValue value={row.ultrai} isUltrai />
                                        </td>
                                        <td className="px-6 py-4 text-center border-b border-gray-100 last:border-0">
                                            <CellValue value={row.chatgpt === 'Tidak' ? false : row.chatgpt} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Mobile stacked */}
                <div className="md:hidden space-y-3">
                    {ROWS.map((row, i) => (
                        <div
                            key={i}
                            className="p-4 rounded-2xl bg-white border border-gray-200/80 shadow-[0_2px_8px_-2px_rgba(15,23,42,0.05)] animate-fade-in-up"
                            style={{ animationDelay: `${i * 40}ms` }}
                        >
                            <div className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3">{row.feature}</div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="p-3 rounded-xl bg-gradient-to-br from-red-50 to-orange-50 border border-red-200">
                                    <div className="text-[10px] font-black uppercase tracking-wider text-red-600 mb-1">UltrAI</div>
                                    <CellValue value={row.ultrai} isUltrai />
                                </div>
                                <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                                    <div className="text-[10px] font-black uppercase tracking-wider text-slate-500 mb-1">ChatGPT Plus</div>
                                    <CellValue value={row.chatgpt === 'Tidak' ? false : row.chatgpt} />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
