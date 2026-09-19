import { useCallback, useEffect, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import { useTheme } from '../../contexts/ThemeContext';
import { apiRequest } from '../../lib/api';

/* Colored toggle switch used for the two policy flags. */
function Toggle({ checked, onChange, disabled, tone = 'red' }) {
    const tones = { red: 'from-red-500 to-red-600', amber: 'from-amber-500 to-orange-500' };
    return (
        <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
            className={`relative h-6 w-11 shrink-0 rounded-full transition-all ${checked ? `bg-gradient-to-r ${tones[tone]}` : 'bg-gray-300 dark:bg-white/[0.12]'} disabled:opacity-50`}>
            <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${checked ? 'left-[22px]' : 'left-0.5'}`} />
        </button>
    );
}

export default function Security() {
    const { t } = useLocale();
    const { theme } = useTheme();
    const dark = theme === 'dark';

    const [currentIp, setCurrentIp] = useState('');
    const [entries, setEntries] = useState([]);
    const [enforceIp, setEnforceIp] = useState(false);
    const [require2fa, setRequire2fa] = useState(false);
    const [newIp, setNewIp] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState({ error: '', success: '' });

    const load = useCallback(async () => {
        setStatus({ error: '', success: '' });
        try {
            const data = await apiRequest('/api/security/settings');
            setCurrentIp(data.current_ip || '');
            setEntries(data.settings.admin_ip_allowlist || []);
            setEnforceIp(!!data.settings.enforce_admin_ip);
            setRequire2fa(!!data.settings.require_admin_2fa);
        } catch (error) {
            setStatus({ error: error.message, success: '' });
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const addEntry = (value) => {
        const ip = (value || '').trim();
        if (ip && !entries.includes(ip)) setEntries((list) => [...list, ip]);
        setNewIp('');
    };

    const save = async () => {
        setBusy(true);
        setStatus({ error: '', success: '' });
        try {
            const data = await apiRequest('/api/security/settings', {
                method: 'PUT',
                body: { enforce_admin_ip: enforceIp, require_admin_2fa: require2fa, admin_ip_allowlist: entries },
            });
            setEntries(data.settings.admin_ip_allowlist || []);
            setStatus({ error: '', success: t('Pengaturan keamanan disimpan.') });
        } catch (error) {
            setStatus({ error: error.message, success: '' });
        } finally {
            setBusy(false);
        }
    };

    const card = dark ? 'bg-white/[0.02] border-white/[0.08]' : 'bg-white border-gray-200/80';
    const head = dark ? 'text-white' : 'text-slate-900';
    const muted = dark ? 'text-gray-500' : 'text-gray-500';
    const field = `w-full rounded-xl px-3 py-2.5 text-sm ${dark ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder:text-gray-600' : 'bg-white border border-gray-200 text-slate-900 placeholder:text-gray-400'} focus:outline-none focus:border-red-500/50 focus:ring-4 focus:ring-red-500/10`;

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-4xl mx-auto">
            <header className="animate-fade-in-up">
                <div className="flex items-center gap-3">
                    <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 text-white shadow-lg shadow-red-500/25">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>
                    </span>
                    <div>
                        <h1 className={`text-xl font-bold ${head}`}>{t('Keamanan')}</h1>
                        <p className={`text-sm ${muted}`}>{t('Kontrol akses admin: IP whitelist dan kebijakan 2FA.')}</p>
                    </div>
                </div>
            </header>

            {status.error && <div role="alert" className="rounded-xl border border-red-500/25 bg-red-500/5 p-3 text-sm text-red-600 dark:text-red-300">{status.error}</div>}
            {status.success && <div role="status" className="rounded-xl border border-emerald-500/25 bg-emerald-500/5 p-3 text-sm text-emerald-600 dark:text-emerald-300">{status.success}</div>}

            {loading ? (
                <div className={`rounded-2xl border p-8 text-center text-sm ${card} ${muted}`}>{t('Memuat…')}</div>
            ) : (
                <>
                    {/* IP allowlist */}
                    <section className={`rounded-2xl border p-5 ${card} animate-fade-in-up`}>
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-500 text-white shadow-md">
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" /><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20" /></svg>
                                </span>
                                <div>
                                    <h2 className={`text-sm font-bold ${head}`}>{t('IP Whitelist Admin')}</h2>
                                    <p className={`text-xs ${muted}`}>{t('Batasi akses admin hanya dari IP/CIDR tepercaya.')}</p>
                                </div>
                            </div>
                            <Toggle checked={enforceIp} onChange={setEnforceIp} disabled={busy} />
                        </div>

                        <div className={`mt-4 flex flex-wrap items-center gap-2 rounded-xl p-3 text-xs ${dark ? 'bg-white/[0.03]' : 'bg-slate-50'}`}>
                            <span className={muted}>{t('IP Anda saat ini')}:</span>
                            <code className={`font-mono font-bold ${head}`}>{currentIp}</code>
                            <button type="button" onClick={() => addEntry(currentIp)} className="ml-auto rounded-md bg-blue-500/10 px-2 py-1 text-[11px] font-bold text-blue-500 hover:bg-blue-500/20">+ {t('Tambahkan IP saya')}</button>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            {entries.length === 0 && <span className={`text-xs ${muted}`}>{t('Belum ada IP di daftar.')}</span>}
                            {entries.map((ip) => (
                                <span key={ip} className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-mono text-xs ${dark ? 'bg-white/[0.06] text-gray-200' : 'bg-gray-100 text-slate-700'}`}>
                                    {ip}
                                    <button type="button" onClick={() => setEntries((list) => list.filter((x) => x !== ip))} className="text-red-500 hover:text-red-600" aria-label={t('Hapus')}>✕</button>
                                </span>
                            ))}
                        </div>

                        <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); addEntry(newIp); }}>
                            <input value={newIp} onChange={(e) => setNewIp(e.target.value)} placeholder={t('mis. 203.0.113.4 atau 10.0.0.0/8')} className={field} />
                            <button type="submit" className="ui-btn-ghost min-h-11 shrink-0">+ {t('Tambah')}</button>
                        </form>
                        <p className={`mt-2 text-[11px] ${muted}`}>{t('Saat aktif, akses admin dari IP di luar daftar akan ditolak (403). Pastikan IP Anda ada di daftar agar tidak terkunci.')}</p>
                    </section>

                    {/* Admin 2FA policy */}
                    <section className={`rounded-2xl border p-5 ${card} animate-fade-in-up`}>
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 text-white shadow-md">
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                                </span>
                                <div>
                                    <h2 className={`text-sm font-bold ${head}`}>{t('Wajib 2FA untuk admin')}</h2>
                                    <p className={`text-xs ${muted}`}>{t('Admin harus mengaktifkan 2FA sebelum mengakses panel admin.')}</p>
                                </div>
                            </div>
                            <Toggle checked={require2fa} onChange={setRequire2fa} disabled={busy} tone="amber" />
                        </div>
                        <p className={`mt-3 text-[11px] ${muted}`}>{t('Admin tanpa 2FA masih bisa membuka Profil untuk mengaktifkannya, lalu akses admin terbuka kembali.')}</p>
                    </section>

                    <div className="flex justify-end">
                        <button type="button" onClick={save} disabled={busy} className="ui-btn-primary min-h-11 px-6">{busy ? t('Menyimpan…') : t('Simpan pengaturan')}</button>
                    </div>
                </>
            )}
        </div>
    );
}
