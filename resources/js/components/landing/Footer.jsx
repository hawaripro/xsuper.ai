import React from 'react';
import { Link } from 'react-router-dom';
import UltrLogo from '../UltrLogo';

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
                            <UltrLogo className="w-9 h-9 group-hover:scale-105 transition-transform" />
                            <span className="text-[1.4rem] font-black tracking-tight flex items-center gap-[1px]">
                                <span className="text-slate-900">Ultr</span>
                                <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent">AI</span>
                            </span>
                        </Link>
                        <p className="text-sm text-slate-500 leading-relaxed max-w-xs">
                            Akses 50+ model AI premium dengan harga UMKM. Unlimited usage, akun pribadi, garansi refund.
                        </p>
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
