import React from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function FeedbackTestimoni() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-4xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    Feedback & Testimoni
                </h1>
                <p className={`mb-8 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Kelola feedback dan testimoni pengguna.
                </p>
                <div className={`rounded-2xl border p-12 text-center ${
                    isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'
                }`}>
                    <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-red-500/20 to-orange-500/20 flex items-center justify-center">
                        <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
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
