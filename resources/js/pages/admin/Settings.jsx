import React, { useState } from 'react';
import { useTheme } from '../../contexts/ThemeContext';

export default function Settings() {
    const { theme } = useTheme();
    const isDark = theme === 'dark';

    const [settings, setSettings] = useState({
        site_name: 'UltrAI',
        session_lifetime: 10080,
        max_devices: 2,
        auto_approve_orders: false,
        maintenance_mode: false,
        referral_bonus_days: 3,
        default_duration_days: 7,
    });

    const handleChange = (key, value) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    return (
        <div className="p-6 lg:p-8 space-y-6" style={{ fontSize: '90%' }}>
            <div>
                <h1 className={`text-2xl font-bold mb-1 ${isDark ? 'text-white' : 'text-slate-900'}`}>Settings</h1>
                <p className={`text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Konfigurasi sistem dan pengaturan aplikasi.</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* General */}
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>General</h3>
                    <div className="space-y-4">
                        <div>
                            <label className={`text-xs font-medium block mb-1.5 ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Nama Situs</label>
                            <input type="text" value={settings.site_name} onChange={e => handleChange('site_name', e.target.value)}
                                className={`w-full px-3 py-2 rounded-lg text-sm border ${isDark ? 'bg-white/5 border-white/10 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'}`} />
                        </div>
                        <div>
                            <label className={`text-xs font-medium block mb-1.5 ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Session Lifetime (menit)</label>
                            <input type="number" value={settings.session_lifetime} onChange={e => handleChange('session_lifetime', parseInt(e.target.value))}
                                className={`w-full px-3 py-2 rounded-lg text-sm border ${isDark ? 'bg-white/5 border-white/10 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'}`} />
                        </div>
                        <div>
                            <label className={`text-xs font-medium block mb-1.5 ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Max Devices per User</label>
                            <input type="number" value={settings.max_devices} onChange={e => handleChange('max_devices', parseInt(e.target.value))}
                                className={`w-full px-3 py-2 rounded-lg text-sm border ${isDark ? 'bg-white/5 border-white/10 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'}`} />
                        </div>
                    </div>
                </div>

                {/* Membership */}
                <div className={`rounded-2xl border p-5 ${isDark ? 'bg-gray-900/60 border-white/[0.06]' : 'bg-white border-gray-200'}`}>
                    <h3 className={`text-sm font-semibold mb-4 ${isDark ? 'text-white' : 'text-slate-900'}`}>Membership</h3>
                    <div className="space-y-4">
                        <div>
                            <label className={`text-xs font-medium block mb-1.5 ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Default Durasi (hari)</label>
                            <input type="number" value={settings.default_duration_days} onChange={e => handleChange('default_duration_days', parseInt(e.target.value))}
                                className={`w-full px-3 py-2 rounded-lg text-sm border ${isDark ? 'bg-white/5 border-white/10 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'}`} />
                        </div>
                        <div>
                            <label className={`text-xs font-medium block mb-1.5 ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Referral Bonus (hari)</label>
                            <input type="number" value={settings.referral_bonus_days} onChange={e => handleChange('referral_bonus_days', parseInt(e.target.value))}
                                className={`w-full px-3 py-2 rounded-lg text-sm border ${isDark ? 'bg-white/5 border-white/10 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'}`} />
                        </div>
                        <div className="flex items-center justify-between py-2">
                            <span className={`text-xs font-medium ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Auto-approve Orders</span>
                            <button onClick={() => handleChange('auto_approve_orders', !settings.auto_approve_orders)}
                                className={`relative w-9 h-5 rounded-full transition-colors ${settings.auto_approve_orders ? 'bg-emerald-500' : (isDark ? 'bg-gray-700' : 'bg-gray-300')}`}>
                                <span className={`absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform ${settings.auto_approve_orders ? 'translate-x-4' : 'translate-x-0.5'}`} />
                            </button>
                        </div>
                        <div className="flex items-center justify-between py-2">
                            <span className={`text-xs font-medium ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>Maintenance Mode</span>
                            <button onClick={() => handleChange('maintenance_mode', !settings.maintenance_mode)}
                                className={`relative w-9 h-5 rounded-full transition-colors ${settings.maintenance_mode ? 'bg-red-500' : (isDark ? 'bg-gray-700' : 'bg-gray-300')}`}>
                                <span className={`absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform ${settings.maintenance_mode ? 'translate-x-4' : 'translate-x-0.5'}`} />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex items-center gap-3">
                <button className="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 shadow-lg shadow-red-500/25 transition-all">
                    Simpan Settings
                </button>
                <span className={`text-xs ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>Perubahan belum tersimpan</span>
            </div>

            <div className={`rounded-xl border p-4 ${isDark ? 'bg-amber-500/5 border-amber-500/20' : 'bg-amber-50 border-amber-200'}`}>
                <p className={`text-xs ${isDark ? 'text-amber-300' : 'text-amber-700'}`}>
                    ⚠️ Settings saat ini dalam mode simulasi. Backend persistence akan ditambahkan.
                </p>
            </div>
        </div>
    );
}
