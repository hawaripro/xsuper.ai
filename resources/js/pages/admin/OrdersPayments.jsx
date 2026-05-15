import React from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function OrdersPayments() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-4xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    Orders & Payments
                </h1>
                <p className={`mb-8 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Kelola pesanan dan pembayaran pengguna.
                </p>
                <div className={`rounded-2xl border p-12 text-center ${
                    isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'
                }`}>
                    <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-red-500/20 to-orange-500/20 flex items-center justify-center">
                        <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
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
