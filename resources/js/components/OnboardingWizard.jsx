import React, { useState } from 'react';
import { useTheme } from '../contexts/ThemeContext';

const MODES = [
    { id: 'coding_assistant', label: 'Coding Assistant', desc: 'Debug, logic, API, database', icon: '💻' },
    { id: 'project_builder', label: 'Project Builder', desc: 'Struktur project, flow aplikasi, dokumentasi', icon: '🏗️' },
    { id: 'content_creator', label: 'Content Creator', desc: 'Ide konten, caption, hook, script', icon: '✍️' },
    { id: 'umkm_assistant', label: 'UMKM Assistant', desc: 'Promo, katalog, balasan customer', icon: '🏪' },
    { id: 'marketplace_helper', label: 'Marketplace Helper', desc: 'Judul produk, deskripsi, optimasi listing', icon: '🛒' },
    { id: 'excel_office_helper', label: 'Excel & Office Helper', desc: 'Rumus, surat, laporan, proposal', icon: '📊' },
    { id: 'prompt_visual_generator', label: 'Prompt Visual Generator', desc: 'Prompt gambar/video untuk AI visual', icon: '🎨' },
];

export default function OnboardingWizard({ onComplete }) {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [selected, setSelected] = useState(null);
    const [loading, setLoading] = useState(false);

    const handleSubmit = async () => {
        if (!selected) return;
        setLoading(true);
        try {
            const res = await fetch('/api/onboarding/mode', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '') },
                credentials: 'same-origin',
                body: JSON.stringify({ mode: selected }),
            });
            if (res.ok) {
                onComplete(selected);
            }
        } catch (e) {
            console.error('Onboarding save failed:', e);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" />
            <div className={`relative w-full max-w-2xl rounded-3xl border p-8 shadow-2xl ${
                isDark ? 'bg-gray-900 border-white/10' : 'bg-white border-gray-200'
            }`}>
                <div className="text-center mb-8">
                    <h2 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                        Selamat datang di XSuper.ai! 👋
                    </h2>
                    <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                        Kamu mau pakai XSuper.ai untuk apa? Pilih mode yang paling sesuai.
                    </p>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-8">
                    {MODES.map((mode) => (
                        <button
                            key={mode.id}
                            onClick={() => setSelected(mode.id)}
                            className={`group relative flex items-start gap-3 p-4 rounded-2xl border text-left transition-all duration-200 ${
                                selected === mode.id
                                    ? (isDark
                                        ? 'border-red-500/50 bg-red-500/10 shadow-[0_0_20px_rgba(239,68,68,0.15)]'
                                        : 'border-red-300 bg-red-50 shadow-[0_0_20px_rgba(239,68,68,0.1)]')
                                    : (isDark
                                        ? 'border-white/10 bg-white/[0.03] hover:border-white/20 hover:bg-white/[0.06]'
                                        : 'border-gray-200 bg-gray-50/50 hover:border-gray-300 hover:bg-gray-100/70')
                            }`}
                        >
                            {selected === mode.id && (
                                <span className="absolute top-3 right-3 w-5 h-5 rounded-full bg-red-500 flex items-center justify-center">
                                    <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" strokeWidth="3" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </span>
                            )}
                            <span className="text-2xl flex-shrink-0 mt-0.5">{mode.icon}</span>
                            <div>
                                <div className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-slate-900'}`}>{mode.label}</div>
                                <div className={`text-xs mt-0.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{mode.desc}</div>
                            </div>
                        </button>
                    ))}
                </div>

                <div className="flex items-center justify-between">
                    <button
                        onClick={() => onComplete(null)}
                        className={`text-sm font-medium px-4 py-2 rounded-xl transition-colors ${
                            isDark ? 'text-gray-500 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'
                        }`}
                    >
                        Lewati
                    </button>
                    <button
                        onClick={handleSubmit}
                        disabled={!selected || loading}
                        className={`px-6 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 ${
                            selected
                                ? 'bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 shadow-lg shadow-red-500/25'
                                : 'bg-gray-400 cursor-not-allowed opacity-50'
                        }`}
                    >
                        {loading ? 'Menyimpan...' : 'Mulai Pakai XSuper.ai'}
                    </button>
                </div>
            </div>
        </div>
    );
}
