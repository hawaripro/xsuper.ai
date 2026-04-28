import React from 'react';
import { Link } from 'react-router-dom';

export default function Footer() {
    return (
        <footer className="border-t border-gray-100 bg-gray-50">
            <div className="max-w-7xl mx-auto px-4 md:px-6 py-16">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 lg:gap-12">
                    <div className="lg:col-span-1">
                        <Link to="/" className="inline-flex items-center gap-[3px] text-[1.5rem] font-extrabold tracking-tight mb-4">
                            <span className="text-gray-900">Ultr</span>
                            <span className="bg-gradient-to-r from-red-500 to-red-600 bg-clip-text text-transparent">AI</span>
                        </Link>
                        <p className="text-sm text-gray-500 leading-relaxed max-w-xs">
                            Platform AI-powered untuk UMKM Indonesia. Solusi digital cerdas untuk pertumbuhan bisnis Anda.
                        </p>
                    </div>

                    <div>
                        <h4 className="text-xs font-bold uppercase tracking-widest text-gray-900 mb-4">Layanan</h4>
                        <ul className="space-y-2.5">
                            {[['SMM Panel', 'https://smm.superpanelpedia.com'], ['PPOB', 'https://ppob.superpanelpedia.com'], ['Produk Digital', 'https://digital.superpanelpedia.com'], ['AI API', 'https://api.ultrai.id']].map(([label, href], i) => (
                                <li key={i}><a href={href} target="_blank" rel="noopener noreferrer" className="text-sm text-gray-500 hover:text-gray-900 transition-colors">{label}</a></li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <h4 className="text-xs font-bold uppercase tracking-widest text-gray-900 mb-4">Platform</h4>
                        <ul className="space-y-2.5">
                            {[['Dashboard', '/dashboard'], ['AI Chat', '/chat'], ['Dokumentasi', '#'], ['Status', '#']].map(([label, href], i) => (
                                <li key={i}>
                                    {href.startsWith('/') ? (
                                        <Link to={href} className="text-sm text-gray-500 hover:text-gray-900 transition-colors">{label}</Link>
                                    ) : (
                                        <a href={href} className="text-sm text-gray-500 hover:text-gray-900 transition-colors">{label}</a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <h4 className="text-xs font-bold uppercase tracking-widest text-gray-900 mb-4">Legal</h4>
                        <ul className="space-y-2.5">
                            {['Privacy Policy', 'Terms of Service', 'Refund Policy'].map((label, i) => (
                                <li key={i}><a href="#" className="text-sm text-gray-500 hover:text-gray-900 transition-colors">{label}</a></li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>

            <div className="max-w-7xl mx-auto px-4 md:px-6 py-5 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2">
                <p className="text-xs text-gray-400">&copy; 2026 UltrAI. All rights reserved.</p>
                <p className="text-xs text-gray-400">Indonesia</p>
            </div>
        </footer>
    );
}
