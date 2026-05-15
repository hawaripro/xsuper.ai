import React from 'react';
import { useTheme } from '../contexts/ThemeContext';

export default function PaketPerpanjangan() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-4xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    Paket & Perpanjangan
                </h1>
                <p className={`mb-8 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Kelola paket langganan dan perpanjang durasi akses Anda.
                </p>
                <div className={`rounded-2xl border p-12 text-center ${
                    isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'
                }`}>
                    <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-red-500/20 to-orange-500/20 flex items-center justify-center">
                        <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                    </div>
                    <h2 className={`text-lg font-semibold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>Coming Soon</h2>
                    <p className={`text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                        Fitur ini sedang dalam pengembangan dan akan segera tersedia.
                    </p>
                </div>
            </div>
        </div>
    );
}
