import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTheme } from '../contexts/ThemeContext';

const CATEGORIES = [
    { id: 'Coding', label: 'Coding', icon: '💻' },
    { id: 'UMKM', label: 'UMKM', icon: '🏪' },
    { id: 'Konten', label: 'Konten', icon: '✍️' },
    { id: 'Marketplace', label: 'Marketplace', icon: '🛒' },
    { id: 'Excel', label: 'Excel', icon: '📊' },
    { id: 'Desain', label: 'Desain', icon: '🎨' },
    { id: 'Prompt Gambar', label: 'Prompt Gambar', icon: '🖼️' },
    { id: 'Prompt Video', label: 'Prompt Video', icon: '🎬' },
    { id: 'Bisnis', label: 'Bisnis', icon: '💼' },
    { id: 'Belajar', label: 'Belajar', icon: '📚' },
];

export default function TemplatePrompt() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const navigate = useNavigate();
    const [templates, setTemplates] = useState([]);
    const [activeCategory, setActiveCategory] = useState('all');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await fetch('/api/templates', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    setTemplates(data.templates || []);
                }
            } catch {} finally { setLoading(false); }
        };
        load();
    }, []);

    const filtered = activeCategory === 'all' ? templates : templates.filter(t => t.category === activeCategory);

    const handleUseTemplate = (promptText) => {
        navigate('/chat', { state: { prefillPrompt: promptText } });
    };

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-6xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                    Template Prompt
                </h1>
                <p className={`mb-6 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    Pilih template siap pakai untuk memulai percakapan dengan AI.
                </p>

                {/* Category tabs */}
                <div className="flex flex-wrap gap-2 mb-6">
                    <button
                        onClick={() => setActiveCategory('all')}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                            activeCategory === 'all'
                                ? (isDark ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-red-50 text-red-600 border border-red-200')
                                : (isDark ? 'bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10' : 'bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200')
                        }`}
                    >
                        Semua
                    </button>
                    {CATEGORIES.map(cat => (
                        <button
                            key={cat.id}
                            onClick={() => setActiveCategory(cat.id)}
                            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                                activeCategory === cat.id
                                    ? (isDark ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-red-50 text-red-600 border border-red-200')
                                    : (isDark ? 'bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10' : 'bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200')
                            }`}
                        >
                            {cat.icon} {cat.label}
                        </button>
                    ))}
                </div>

                {/* Templates grid */}
                {loading ? (
                    <div className="flex items-center justify-center py-20">
                        <div className="w-8 h-8 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    </div>
                ) : filtered.length === 0 ? (
                    <div className={`rounded-2xl border p-12 text-center ${isDark ? 'bg-gray-900/50 border-white/10' : 'bg-white border-gray-200'}`}>
                        <p className={`text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                            {templates.length === 0 ? 'Belum ada template tersedia.' : 'Tidak ada template di kategori ini.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {filtered.map(template => (
                            <div
                                key={template.id}
                                className={`group relative rounded-2xl border p-5 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg ${
                                    isDark ? 'bg-gray-900/60 border-white/[0.06] hover:border-white/[0.14]' : 'bg-white border-gray-200 hover:border-gray-300'
                                }`}
                            >
                                <div className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider mb-3 ${
                                    isDark ? 'bg-white/5 text-gray-400' : 'bg-gray-100 text-gray-500'
                                }`}>
                                    {template.category}
                                </div>
                                <h3 className={`text-sm font-semibold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>
                                    {template.title}
                                </h3>
                                <p className={`text-xs mb-4 line-clamp-2 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                    {template.prompt_text}
                                </p>
                                <button
                                    onClick={() => handleUseTemplate(template.prompt_text)}
                                    className={`w-full px-4 py-2 rounded-xl text-xs font-semibold transition-all duration-200 ${
                                        isDark
                                            ? 'bg-red-500/10 text-red-300 border border-red-500/20 hover:bg-red-500/20'
                                            : 'bg-red-50 text-red-600 border border-red-200 hover:bg-red-100'
                                    }`}
                                >
                                    Pakai Template →
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
