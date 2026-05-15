import React from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function AnalyticsFunnel() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-4xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    Analytics & Funnel
                </h1>
                <p className={`mb-8 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Analisis funnel konversi dan perilaku pengguna.
                </p>
                <div className={`rounded-2xl border p-12 text-center ${
                    isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'
                }`}>
                    <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-red-500/20 to-orange-500/20 flex items-center justify-center">
                        <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z" />
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
