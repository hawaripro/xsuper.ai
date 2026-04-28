import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

export default function Header() {
    const { user } = useAuth();
    const [scrolled, setScrolled] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        const handleScroll = () => setScrolled(window.scrollY > 20);
        window.addEventListener('scroll', handleScroll);
        return () => window.removeEventListener('scroll', handleScroll);
    }, []);

    const scrollTo = (id) => {
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
        setMobileOpen(false);
    };

    return (
        <header className={`fixed top-0 left-0 right-0 z-50 transition-all duration-500 ${
            scrolled
                ? 'bg-white/80 shadow-[0_1px_20px_rgba(0,0,0,0.06)]'
                : 'bg-white/50'
        } backdrop-blur-2xl border-b border-gray-200/50`}>
            <div className="max-w-7xl mx-auto px-4 md:px-6 h-16 flex items-center justify-between">
                <Link to="/" className="flex items-center gap-[3px] text-[1.5rem] font-bold tracking-tight">
                    <span className="text-gray-900">Ultr</span>
                    <span className="bg-gradient-to-r from-red-500 to-red-600 bg-clip-text text-transparent">AI</span>
                </Link>

                <nav className="hidden md:flex items-center gap-1">
                    {[['services', 'Layanan'], ['features', 'Fitur'], ['ai', 'AI Proxy']].map(([id, label]) => (
                        <button key={id} onClick={() => scrollTo(id)} className="px-4 py-2 rounded-lg text-sm font-medium text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition-all">
                            {label}
                        </button>
                    ))}
                </nav>

                <div className="hidden md:flex items-center gap-2">
                    {user ? (
                        <Link to="/dashboard" className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-red-500 to-red-600 shadow-lg shadow-red-500/25 hover:shadow-red-500/40 hover:-translate-y-0.5 transition-all">
                            Dashboard
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </Link>
                    ) : (
                        <Link to="/login" className="relative overflow-hidden inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-red-500 to-red-600 shadow-lg shadow-red-500/25 hover:shadow-red-500/40 hover:-translate-y-0.5 transition-all btn-shimmer">
                            Mulai Sekarang
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </Link>
                    )}
                </div>

                <button onClick={() => setMobileOpen(!mobileOpen)} className="md:hidden p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" viewBox="0 0 24 24">
                        {mobileOpen ? <><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></> : <><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></>}
                    </svg>
                </button>
            </div>

            {mobileOpen && (
                <div className="md:hidden bg-white border-t border-gray-100 px-4 py-3 space-y-1">
                    {[['services', 'Layanan'], ['features', 'Fitur'], ['ai', 'AI Proxy']].map(([id, label]) => (
                        <button key={id} onClick={() => scrollTo(id)} className="block w-full text-left px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50">
                            {label}
                        </button>
                    ))}
                    <div className="pt-2 border-t border-gray-100">
                        {user ? (
                            <Link to="/dashboard" className="block text-center px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-red-500 to-red-600">
                                Dashboard
                            </Link>
                        ) : (
                            <Link to="/login" className="block text-center px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-red-500 to-red-600">
                                Masuk
                            </Link>
                        )}
                    </div>
                </div>
            )}
        </header>
    );
}
