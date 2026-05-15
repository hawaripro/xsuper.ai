import React from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function ExpiringUsers() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-4xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    Expiring Users
                </h1>
                <p className={`mb-8 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Daftar pengguna yang masa aktifnya akan segera berakhir.
                </p>
                <div className={`rounded-2xl border p-12 text-center ${
                    isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'
                }`}>
                    <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-red-500/20 to-orange-500/20 flex items-center justify-center">
                        <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
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
