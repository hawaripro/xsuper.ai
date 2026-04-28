import React from 'react';
import { Link } from 'react-router-dom';

export default function Cta() {
    return (
        <section className="py-24 md:py-32 text-center">
            <div className="max-w-7xl mx-auto px-4 md:px-6">
                <h2 className="text-3xl md:text-4xl lg:text-5xl font-extrabold text-gray-900 tracking-tight mb-4">
                    Siap Mengembangkan Bisnis Anda?
                </h2>
                <p className="text-lg text-gray-500 max-w-lg mx-auto mb-10">
                    Bergabung dengan ribuan UMKM Indonesia yang sudah menggunakan UltrAI untuk pertumbuhan bisnis mereka.
                </p>
                <div className="flex flex-wrap justify-center gap-3">
                    <Link
                        to="/login"
                        className="relative overflow-hidden inline-flex items-center gap-2 h-13 px-8 rounded-2xl text-[15px] font-semibold text-white bg-gradient-to-r from-red-500 to-red-600 shadow-lg shadow-red-500/25 hover:shadow-red-500/40 hover:-translate-y-0.5 transition-all btn-shimmer"
                    >
                        Mulai Sekarang
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </Link>
                    <Link
                        to="/dashboard"
                        className="inline-flex items-center gap-2 h-13 px-8 rounded-2xl text-[15px] font-semibold text-gray-700 border-[1.5px] border-gray-200 hover:border-gray-300 hover:bg-gray-50 hover:-translate-y-0.5 transition-all"
                    >
                        Lihat Dashboard
                    </Link>
                </div>
            </div>
        </section>
    );
}
