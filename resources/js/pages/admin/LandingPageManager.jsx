import React, { useState } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

const SECTIONS = [
    { id: 'hero', name: 'Hero Banner', desc: 'Headline utama dan CTA', active: true },
    { id: 'features', name: 'Fitur Unggulan', desc: '6 fitur utama UltrAI', active: true },
    { id: 'models', name: 'Model AI', desc: 'Daftar model yang tersedia', active: true },
    { id: 'pricing', name: 'Pricing', desc: 'Paket harga langganan', active: true },
    { id: 'testimonials', name: 'Testimoni', desc: 'Review dari pengguna', active: true },
    { id: 'faq', name: 'FAQ', desc: 'Pertanyaan yang sering ditanya', active: true },
    { id: 'carousel', name: 'Carousel', desc: 'Slider gambar/promo', active: true },
    { id: 'cta', name: 'CTA Section', desc: 'Call to action bawah', active: true },
    { id: 'footer', name: 'Footer', desc: 'Link dan info kontak', active: true },
];

export default function LandingPageManager() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [sections, setSections] = useState(SECTIONS);

    const toggleSection = (id) => {
        setSections(prev => prev.map(s => s.id === id ? { ...s, active: !s.active } : s));
    };

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Landing Page Manager</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Kelola section dan konten landing page.</p>
            </div>

            <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                <div className="flex items-center justify-between mb-4">
                    <h3 className={`text-sm font-semibold ${isDark ? 'text-white' : 'text-slate-900'}`}>Sections ({sections.filter(s => s.active).length} aktif)</h3>
                    <a href="/" target="_blank" rel="noopener noreferrer" className={`text-xs font-medium ${isDark ? 'text-red-400 hover:text-red-300' : 'text-red-500 hover:text-red-600'}`}>Preview Landing →</a>
                </div>
                <div className="space-y-2">
                    {sections.map((section, i) => (
                        <div key={section.id} className={`flex items-center justify-between p-3 rounded-xl border transition-all ${
                            isDark ? 'border-white/5 hover:border-white/10' : 'border-gray-100 hover:border-gray-200'
                        }`}>
                            <div className="flex items-center gap-3">
                                <span className={`w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-bold ${isDark ? 'bg-white/5 text-gray-500' : 'bg-gray-100 text-gray-400'}`}>{i + 1}</span>
                                <div>
                                    <div className={`text-xs font-semibold ${isDark ? 'text-gray-200' : 'text-gray-800'}`}>{section.name}</div>
                                    <div className={`text-[10px] ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>{section.desc}</div>
                                </div>
                            </div>
                            <button onClick={() => toggleSection(section.id)}
                                className={`relative w-9 h-5 rounded-full transition-colors ${section.active ? 'bg-emerald-500' : (isDark ? 'bg-gray-700' : 'bg-gray-300')}`}>
                                <span className={`absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform ${section.active ? 'translate-x-4' : 'translate-x-0.5'}`} />
                            </button>
                        </div>
                    ))}
                </div>
            </div>

            <div className={`rounded-xl border p-4 ${isDark ? 'bg-amber-500/5 border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                <p className={`text-xs ${isDark ? 'text-amber-300' : 'text-amber-700'}`}>
                    ⚠️ Perubahan section saat ini hanya simulasi. Integrasi CMS untuk edit konten akan ditambahkan.
                </p>
            </div>
        </div>
    );
}
