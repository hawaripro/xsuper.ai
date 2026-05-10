import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import UltrLogo from '../UltrLogo';

const WA_NUMBER = '6287786866648';
const WA_REGISTER_URL = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent('Halo UltrAI, saya ingin mendaftar akun UltrAI.')}`;

const NAV_ITEMS = [
    ['pricing', 'Harga'],
    ['why-ultrai', 'Keunggulan'],
    ['for-you', 'Untuk Kamu'],
    ['services', 'Layanan'],
    ['faq', 'FAQ'],
];

export default function Header() {
    const { user } = useAuth();
    const [scrolled, setScrolled] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        const handleScroll = () => setScrolled(window.scrollY > 20);
        handleScroll();
        window.addEventListener('scroll', handleScroll, { passive: true });
        return () => window.removeEventListener('scroll', handleScroll);
    }, []);

    useEffect(() => {
        document.body.style.overflow = mobileOpen ? 'hidden' : '';
        return () => { document.body.style.overflow = ''; };
    }, [mobileOpen]);

    const scrollTo = (id) => {
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setMobileOpen(false);
    };

    return (
        <header
            className={`fixed top-0 left-0 right-0 z-50 transition-all duration-500 ${
                scrolled
                    ? 'bg-white/85 shadow-[0_2px_24px_rgba(15,23,42,0.08)] border-b border-gray-200/70'
                    : 'bg-white/50 border-b border-transparent'
            } backdrop-blur-2xl`}
        >
            <div className="max-w-7xl mx-auto px-4 md:px-6 h-16 flex items-center justify-between">
                {/* Logo */}
                <Link to="/" className="flex items-center gap-2 group" aria-label="UltrAI home">
                    <UltrLogo className="w-9 h-9 group-hover:scale-105 group-hover:-rotate-3 transition-transform duration-300" />
                    <span className="text-[1.4rem] font-black tracking-tight flex items-center gap-[1px]">
                        <span className="text-slate-900">Ultr</span>
                        <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">AI</span>
                    </span>
                </Link>

                {/* Desktop nav */}
                <nav className="hidden lg:flex items-center gap-1">
                    {NAV_ITEMS.map(([id, label]) => (
                        <button
                            key={id}
                            onClick={() => scrollTo(id)}
                            className="px-3.5 py-2 rounded-lg text-sm font-medium text-slate-600 hover:text-red-500 hover:bg-red-50/70 transition-colors"
                        >
                            {label}
                        </button>
                    ))}
                </nav>

                {/* Desktop actions */}
                <div className="hidden md:flex items-center gap-2">
                    {user ? (
                        <Link
                            to="/dashboard"
                            className="ui-btn-primary text-sm px-5 py-2.5"
                        >
                            Dashboard
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                        </Link>
                    ) : (
                        <>
                            <a
                                href={WA_REGISTER_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200/80 hover:bg-emerald-100 hover:border-emerald-300 hover:-translate-y-0.5 transition-all duration-200"
                                aria-label="Register via WhatsApp"
                            >
                                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413Z"/>
                                </svg>
                                Register
                            </a>
                            <Link
                                to="/login"
                                className="ui-btn-primary text-sm px-5 py-2.5"
                            >
                                Masuk
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                            </Link>
                        </>
                    )}
                </div>

                {/* Mobile toggle */}
                <button
                    onClick={() => setMobileOpen(!mobileOpen)}
                    className="md:hidden p-2 rounded-lg text-slate-700 hover:text-red-500 hover:bg-red-50 transition-colors"
                    aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                    aria-expanded={mobileOpen}
                >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" viewBox="0 0 24 24">
                        {mobileOpen
                            ? <><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></>
                            : <><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></>
                        }
                    </svg>
                </button>
            </div>

            {/* Mobile menu */}
            <div
                className={`md:hidden border-t border-gray-200/70 bg-white/95 backdrop-blur-2xl overflow-hidden transition-all duration-300 ease-out ${
                    mobileOpen ? 'max-h-[80vh] opacity-100' : 'max-h-0 opacity-0'
                }`}
            >
                <div className="px-4 py-4 space-y-1">
                    {NAV_ITEMS.map(([id, label]) => (
                        <button
                            key={id}
                            onClick={() => scrollTo(id)}
                            className="block w-full text-left px-4 py-3 rounded-xl text-sm font-semibold text-slate-700 hover:text-red-500 hover:bg-red-50 transition-colors"
                        >
                            {label}
                        </button>
                    ))}
                    <div className="pt-3 border-t border-gray-200/70 space-y-2">
                        {user ? (
                            <Link to="/dashboard" className="ui-btn-primary w-full justify-center" onClick={() => setMobileOpen(false)}>
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <a
                                    href={WA_REGISTER_URL}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl text-sm font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 transition-colors"
                                >
                                    <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413Z"/></svg>
                                    Register via WhatsApp
                                </a>
                                <Link to="/login" className="ui-btn-primary w-full justify-center" onClick={() => setMobileOpen(false)}>
                                    Masuk
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </header>
    );
}
