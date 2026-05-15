import React, { useState } from 'react';
import { useTheme } from '../contexts/ThemeContext';
import { useAuth } from '../contexts/AuthContext';

export default function Referral() {
    const { theme } = useTheme();
    const { user } = useAuth();
    const isDark = theme === 'dark';
    const [copied, setCopied] = useState(false);

    const referralCode = `ULTRAI-${user?.name?.replace(/\s+/g, '').substring(0, 6).toUpperCase() || 'USER'}`;
    const referralLink = `https://ultrai.id/?ref=${referralCode}`;

    const handleCopy = () => {
        navigator.clipboard.writeText(referralLink);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Referral</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Ajak teman dan dapatkan bonus durasi.</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Referral link */}
                <div className={`rounded-2xl border p-6 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Link Referral Kamu</h3>
                    <div className={`flex items-center gap-2 p-3 rounded-xl border ${isDark ? 'bg-white/[0.03] border-white/10' : 'bg-gray-50 border-gray-200'}`}>
                        <input type="text" readOnly value={referralLink} className={`flex-1 bg-transparent text-xs font-mono outline-none ${isDark ? 'text-gray-300' : 'text-gray-700'}`} />
                        <button onClick={handleCopy} className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${copied ? 'bg-emerald-500/20 text-emerald-500' : (isDark ? 'bg-white/10 text-white hover:bg-white/15' : 'bg-gray-900 text-white hover:bg-gray-800')}`}>
                            {copied ? '✓ Copied' : 'Copy'}
                        </button>
                    </div>
                    <p className={`text-xs mt-3 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                        Bagikan link ini ke teman. Ketika mereka mendaftar, kamu dan temanmu akan mendapat bonus durasi.
                    </p>
                </div>

                {/* How it works */}
                <div className={`rounded-2xl border p-6 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Cara Kerja</h3>
                    <div className="space-y-4">
                        {[
                            { step: '1', title: 'Bagikan Link', desc: 'Kirim link referral ke teman' },
                            { step: '2', title: 'Teman Mendaftar', desc: 'Teman daftar via link kamu' },
                            { step: '3', title: 'Dapat Bonus', desc: 'Kamu dan teman dapat +3 hari' },
                        ].map(item => (
                            <div key={item.step} className="flex items-start gap-3">
                                <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">{item.step}</div>
                                <div>
                                    <div className={`text-xs font-semibold ${isDark ? 'text-white' : 'text-slate-900'}`}>{item.title}</div>
                                    <div className={`text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{item.desc}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Stats placeholder */}
            <div className={`rounded-2xl border p-6 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Statistik Referral</h3>
                <div className="grid grid-cols-3 gap-4">
                    <div className={`p-4 rounded-xl text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-slate-900'}`}>0</div>
                        <div className={`text-xs mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Teman Diajak</div>
                    </div>
                    <div className={`p-4 rounded-xl text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className={`text-2xl font-bold ${isDark ? 'text-white' : 'text-slate-900'}`}>0</div>
                        <div className={`text-xs mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Yang Mendaftar</div>
                    </div>
                    <div className={`p-4 rounded-xl text-center ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                        <div className={`text-2xl font-bold text-emerald-500`}>0 hari</div>
                        <div className={`text-xs mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>Bonus Didapat</div>
                    </div>
                </div>
            </div>

            <div className={`rounded-xl border p-4 ${isDark ? 'bg-amber-500/5 border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                <p className={`text-xs ${isDark ? 'text-amber-300' : 'text-amber-700'}`}>
                    ⚠️ Program referral akan segera aktif. Statistik akan terupdate otomatis setelah sistem berjalan.
                </p>
            </div>
        </div>
    );
}
