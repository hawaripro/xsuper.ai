import React from 'react';
import { Link } from 'react-router-dom';

const WA_NUMBER = '6287786866648';
const WA_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent('Halo UltrAI, saya ingin mendaftar akun UltrAI.')}`;

export default function Footer() {
    return (
        <footer className="relative border-t border-gray-200/80 bg-gradient-to-b from-gray-50 to-gray-100/60">
            <div className="max-w-7xl mx-auto px-4 md:px-6 py-14">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 lg:gap-12">
                    {/* Brand */}
                    <div className="lg:col-span-1">
                        <Link to="/" className="flex items-center gap-2 group mb-4" aria-label="UltrAI home">
                            <span className="relative inline-flex items-center justify-center w-9 h-9 rounded-xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-[0_8px_20px_-4px_rgba(239,68,68,0.45)] group-hover:scale-105 transition-transform">
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M12 2 L4 7 v10 l8 5 l8 -5 V7 Z" />
                                    <path d="M12 22 V12" />
                                    <path d="M4 7 l8 5 l8 -5" />
                                </svg>
                                <span className="absolute inset-0 rounded-xl ring-1 ring-white/30 pointer-events-none" />
                            </span>
                            <span className="text-[1.4rem] font-black tracking-tight flex items-center gap-[1px]">
                                <span className="text-slate-900">Ultr</span>
                                <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent">AI</span>
                            </span>
                        </Link>
                        <p className="text-sm text-slate-500 leading-relaxed max-w-xs">
                            Akses 50+ model AI premium dengan harga UMKM. Unlimited usage, akun pribadi, garansi refund.
                        </p>
                        <a
                            href={WA_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 hover:border-emerald-300 transition-colors"
                        >
                            <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413Z"/></svg>
                            087786866648
                        </a>
                    </div>

                    {/* Product */}
                    <div>
                        <h4 className="text-xs font-black uppercase tracking-[0.15em] text-slate-900 mb-4">Produk</h4>
                        <ul className="space-y-2.5">
                            {[
                                ['Harga', '#pricing'],
                                ['Keunggulan', '#why-ultrai'],
                                ['Untuk Kamu', '#for-you'],
                                ['FAQ', '#faq'],
                            ].map(([label, href]) => (
                                <li key={label}>
                                    <a href={href} className="text-sm text-slate-500 hover:text-red-500 transition-colors">{label}</a>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Services — Coming Soon */}
                    <div>
                        <h4 className="text-xs font-black uppercase tracking-[0.15em] text-slate-900 mb-4">Layanan</h4>
                        <ul className="space-y-2.5">
                            {['SMM Panel', 'PPOB', 'Produk Digital'].map((label) => (
                                <li key={label}>
                                    <span className="inline-flex items-center gap-2 text-sm text-slate-500">
                                        {label}
                                        <span className="px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200">Soon</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Kontak */}
                    <div>
                        <h4 className="text-xs font-black uppercase tracking-[0.15em] text-slate-900 mb-4">Kontak &amp; Legal</h4>
                        <ul className="space-y-2.5">
                            <li>
                                <a href={WA_URL} target="_blank" rel="noopener noreferrer" className="text-sm text-slate-500 hover:text-red-500 transition-colors">WhatsApp Admin</a>
                            </li>
                            <li>
                                <Link to="/login" className="text-sm text-slate-500 hover:text-red-500 transition-colors">Dashboard</Link>
                            </li>
                            {['Privacy Policy', 'Terms of Service', 'Refund Policy'].map((label) => (
                                <li key={label}>
                                    <a href="#" className="text-sm text-slate-500 hover:text-red-500 transition-colors">{label}</a>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>

            <div className="max-w-7xl mx-auto px-4 md:px-6 py-5 border-t border-gray-200/70 flex flex-col sm:flex-row items-center justify-between gap-2">
                <p className="text-xs text-slate-500">&copy; {new Date().getFullYear()} UltrAI. All rights reserved.</p>
                <p className="text-xs text-slate-500">Made with care in Indonesia</p>
            </div>
        </footer>
    );
}
