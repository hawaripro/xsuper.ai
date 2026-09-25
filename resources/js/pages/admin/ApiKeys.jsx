import { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import { useTheme } from '../../contexts/ThemeContext';
import { apiRequest, formatDateTime } from '../../lib/api';

import { ANTHROPIC_BASE, OPENAI_BASE } from '../../lib/apiAccess';

/* Connection card — one per wire protocol, with a copyable base URL + snippet. */
function ConnectionCard({ dark, tone, title, subtitle, badge, baseUrl, snippet, onCopy, copied }) {
    const tones = {
        emerald: 'from-emerald-500 to-teal-500',
        orange: 'from-amber-500 to-orange-500',
    };
    return (
        <div className={`relative overflow-hidden rounded-2xl border p-5 transition-all hover:-translate-y-0.5 ${dark ? 'bg-white/[0.02] border-white/[0.08] hover:border-white/[0.14]' : 'bg-white border-gray-200/80 hover:shadow-[0_16px_40px_-16px_rgba(15,23,42,0.2)]'} animate-fade-in-up`}>
            <div className={`absolute -right-8 -top-8 h-24 w-24 rounded-full bg-gradient-to-br ${tones[tone]} opacity-10 blur-2xl`} aria-hidden="true" />
            <div className="flex items-center gap-3">
                <span className={`flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br ${tones[tone]} text-white shadow-md`}>
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><polyline points="4 17 10 11 4 5" /><line x1="12" y1="19" x2="20" y2="19" /></svg>
                </span>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <h3 className={`text-sm font-bold ${dark ? 'text-white' : 'text-slate-900'}`}>{title}</h3>
                        <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${tone === 'emerald' ? 'bg-emerald-500/15 text-emerald-500' : 'bg-amber-500/15 text-amber-500'}`}>{badge}</span>
                    </div>
                    <p className={`text-xs ${dark ? 'text-gray-500' : 'text-gray-500'}`}>{subtitle}</p>
                </div>
            </div>
            <div className={`mt-3 flex items-center gap-2 rounded-lg p-2 ${dark ? 'bg-black/30' : 'bg-gray-100'}`}>
                <span className={`shrink-0 text-[10px] font-bold uppercase ${dark ? 'text-gray-500' : 'text-gray-400'}`}>Base URL</span>
                <code className={`flex-1 truncate font-mono text-xs ${dark ? 'text-gray-200' : 'text-slate-700'}`}>{baseUrl}</code>
                <button type="button" onClick={() => onCopy(baseUrl)} className={`shrink-0 rounded-md px-2 py-1 text-[10px] font-bold transition-all ${copied === baseUrl ? 'bg-emerald-500/20 text-emerald-500' : dark ? 'bg-white/[0.06] text-gray-300 hover:bg-white/[0.12]' : 'bg-gray-200 text-gray-600 hover:bg-gray-300'}`}>{copied === baseUrl ? '✓' : 'Copy'}</button>
            </div>
            <pre className={`mt-2 overflow-x-auto rounded-lg p-3 font-mono text-[11px] leading-relaxed ${dark ? 'bg-black/40 text-gray-400' : 'bg-slate-900/95 text-gray-300'}`}>{snippet}</pre>
        </div>
    );
}

export default function ApiKeys() {
    const { t } = useLocale();
    const { theme } = useTheme();
    const dark = theme === 'dark';

    const [keys, setKeys] = useState([]);
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [form, setForm] = useState({ user_id: '', name: '', rate_limit: 60 });
    const [busy, setBusy] = useState('');
    const [freshKey, setFreshKey] = useState('');
    const [confirm, setConfirm] = useState(null); // { id, action: 'delete'|'regen' }
    const [copied, setCopied] = useState('');
    const [search, setSearch] = useState('');

    const copy = (text) => {
        navigator.clipboard?.writeText(text);
        setCopied(text);
        setTimeout(() => setCopied((current) => (current === text ? '' : current)), 1800);
    };

    const load = useCallback(async () => {
        setError('');
        try {
            const [keyData, userData] = await Promise.all([
                apiRequest('/api/k/list'),
                apiRequest('/api/a/u').catch(() => ({ users: [] })),
            ]);
            setKeys(keyData.keys || []);
            setUsers((userData.users || userData || []).map((u) => ({ id: u.id, name: u.name, email: u.email })));
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const createKey = async (event) => {
        event.preventDefault();
        if (!form.user_id) { setError(t('Pilih pengguna untuk key ini terlebih dahulu.')); return; }
        setBusy('create');
        setError('');
        try {
            const result = await apiRequest('/api/k/create', {
                method: 'POST',
                body: { user_id: Number(form.user_id), name: form.name || 'API Key', rate_limit: Number(form.rate_limit) || 60 },
            });
            setFreshKey(result.key || '');
            setForm({ user_id: '', name: '', rate_limit: 60 });
            await load();
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    const toggle = async (id) => {
        setBusy(`toggle-${id}`);
        try { await apiRequest(`/api/k/toggle/${id}`, { method: 'POST' }); await load(); }
        catch (requestError) { setError(requestError.message); }
        finally { setBusy(''); }
    };

    const runConfirm = async () => {
        if (!confirm) return;
        setBusy('confirm');
        try {
            if (confirm.action === 'delete') {
                await apiRequest(`/api/k/${confirm.id}`, { method: 'DELETE' });
            } else {
                const result = await apiRequest(`/api/k/regen/${confirm.id}`, { method: 'POST' });
                setFreshKey(result.key || '');
            }
            setConfirm(null);
            await load();
        } catch (requestError) { setError(requestError.message); }
        finally { setBusy(''); }
    };

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return keys;
        return keys.filter((k) => [k.name, k.user_name, k.masked_key].some((v) => (v || '').toLowerCase().includes(q)));
    }, [keys, search]);

    const stats = useMemo(() => ({
        total: keys.length,
        active: keys.filter((k) => k.is_active).length,
        requests: keys.reduce((sum, k) => sum + (k.total_requests || 0), 0),
    }), [keys]);

    const card = dark ? 'bg-white/[0.02] border-white/[0.08]' : 'bg-white border-gray-200/80';
    const head = dark ? 'text-white' : 'text-slate-900';
    const muted = dark ? 'text-gray-500' : 'text-gray-500';
    const field = `w-full rounded-xl px-3 py-2.5 text-sm ${dark ? 'bg-white/[0.04] border border-white/[0.08] text-white placeholder:text-gray-600' : 'bg-white border border-gray-200 text-slate-900 placeholder:text-gray-400'} focus:outline-none focus:border-red-500/50 focus:ring-4 focus:ring-red-500/10`;

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-6xl mx-auto">
            {/* Header */}
            <header className="animate-fade-in-up">
                <div className="flex items-center gap-3">
                    <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/25">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" /></svg>
                    </span>
                    <div>
                        <h1 className={`text-xl font-bold ${head}`}>{t('API Keys')}</h1>
                        <p className={`text-sm ${muted}`}>{t('Kelola akses API OpenAI & Anthropic-compatible. Hanya admin.')}</p>
                    </div>
                </div>
            </header>

            {/* Connection guides */}
            <div className="grid gap-4 md:grid-cols-2">
                <ConnectionCard
                    dark={dark} tone="emerald" title="OpenAI Compatible" subtitle={t('Untuk SDK OpenAI, OpenCode, dsb.')} badge="/v1"
                    baseUrl={OPENAI_BASE} onCopy={copy} copied={copied}
                    snippet={`from openai import OpenAI\nclient = OpenAI(\n  base_url="${OPENAI_BASE}",\n  api_key="xsuper-xxxx",\n)`}
                />
                <ConnectionCard
                    dark={dark} tone="orange" title="Anthropic Compatible" subtitle={t('Untuk SDK Anthropic (Messages API).')} badge="/v1/messages"
                    baseUrl={ANTHROPIC_BASE} onCopy={copy} copied={copied}
                    snippet={`from anthropic import Anthropic\nclient = Anthropic(\n  base_url="${ANTHROPIC_BASE}",\n  api_key="xsuper-xxxx",\n)`}
                />
            </div>

            {/* Stats */}
            <div className="grid grid-cols-3 gap-3">
                {[
                    { label: t('Total Key'), value: stats.total, tone: 'text-blue-500' },
                    { label: t('Aktif'), value: stats.active, tone: 'text-emerald-500' },
                    { label: t('Total Request'), value: stats.requests.toLocaleString('id-ID'), tone: 'text-violet-500' },
                ].map((s) => (
                    <div key={s.label} className={`rounded-2xl border p-4 ${card} animate-fade-in-up`}>
                        <div className={`text-2xl font-bold ${s.tone}`}>{s.value}</div>
                        <div className={`text-xs ${muted}`}>{s.label}</div>
                    </div>
                ))}
            </div>

            {error && <div role="alert" className="rounded-xl border border-red-500/25 bg-red-500/5 p-3 text-sm text-red-600 dark:text-red-300">{error}</div>}

            {freshKey && (
                <div className={`rounded-2xl border-2 p-4 ${dark ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-emerald-200 bg-emerald-50'} animate-scale-in`}>
                    <p className={`text-xs font-bold ${dark ? 'text-emerald-300' : 'text-emerald-700'}`}>{t('Key baru dibuat — salin sekarang.')}</p>
                    <div className="mt-2 flex items-center gap-2">
                        <code className={`flex-1 truncate rounded-lg px-3 py-2 font-mono text-xs ${dark ? 'bg-black/40 text-emerald-200' : 'bg-white text-emerald-800'}`}>{freshKey}</code>
                        <button type="button" onClick={() => copy(freshKey)} className="ui-btn-primary min-h-9 px-4">{copied === freshKey ? t('Tersalin') : t('Salin')}</button>
                        <button type="button" onClick={() => setFreshKey('')} className="ui-btn-ghost min-h-9" aria-label={t('Tutup')}>✕</button>
                    </div>
                </div>
            )}

            {/* Create */}
            <form onSubmit={createKey} className={`rounded-2xl border p-5 ${card} animate-fade-in-up`}>
                <h2 className={`text-sm font-bold ${head}`}>{t('Buat API Key')}</h2>
                <div className="mt-3 grid gap-3 sm:grid-cols-[2fr_2fr_1fr_auto] sm:items-end">
                    <div>
                        <label htmlFor="ak-user" className={`mb-1.5 block text-xs font-bold uppercase tracking-wide ${muted}`}>{t('Pengguna')}</label>
                        <select id="ak-user" value={form.user_id} onChange={(e) => setForm((f) => ({ ...f, user_id: e.target.value }))} className={field} required>
                            <option value="">{t('Pilih pengguna…')}</option>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name} — {u.email}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="ak-name" className={`mb-1.5 block text-xs font-bold uppercase tracking-wide ${muted}`}>{t('Nama')}</label>
                        <input id="ak-name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="API Key" className={field} maxLength={255} />
                    </div>
                    <div>
                        <label htmlFor="ak-rate" className={`mb-1.5 block text-xs font-bold uppercase tracking-wide ${muted}`}>{t('Limit/min')}</label>
                        <input id="ak-rate" type="number" min={1} max={1000} value={form.rate_limit} onChange={(e) => setForm((f) => ({ ...f, rate_limit: e.target.value }))} className={field} />
                    </div>
                    <button type="submit" disabled={busy === 'create'} className="ui-btn-primary min-h-11 px-5">{busy === 'create' ? t('Membuat…') : t('+ Buat Key')}</button>
                </div>
            </form>

            {/* Keys list */}
            <div className={`rounded-2xl border ${card} animate-fade-in-up`}>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4 dark:border-white/[0.06]">
                    <h2 className={`text-sm font-bold ${head}`}>{t('Semua API Key')}</h2>
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Cari nama / pengguna / key…')} className={`${field} max-w-xs`} />
                </div>
                {loading ? (
                    <div className={`p-8 text-center text-sm ${muted}`}>{t('Memuat…')}</div>
                ) : filtered.length === 0 ? (
                    <div className={`p-10 text-center ${muted}`}>
                        <svg className="mx-auto mb-2 h-10 w-10 opacity-40" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" /></svg>
                        <p className="text-sm">{t('Belum ada API key')}</p>
                    </div>
                ) : (
                    <div className="divide-y dark:divide-white/[0.06]">
                        {filtered.map((k) => (
                            <div key={k.id} className="flex flex-wrap items-center gap-3 p-4 transition-colors hover:bg-black/[0.02] dark:hover:bg-white/[0.02]">
                                <span className={`h-2 w-2 shrink-0 rounded-full ${k.is_active ? 'bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.5)]' : 'bg-gray-400'}`} />
                                <div className="min-w-40 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className={`text-sm font-semibold ${head}`}>{k.name}</span>
                                        <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase ${k.is_active ? 'bg-emerald-500/15 text-emerald-500' : 'bg-gray-500/15 text-gray-500'}`}>{k.is_active ? t('Aktif') : t('Nonaktif')}</span>
                                    </div>
                                    <div className={`text-xs ${muted}`}>{k.user_name}</div>
                                </div>
                                <div className={`flex items-center gap-2 rounded-lg px-2 py-1 ${dark ? 'bg-black/30' : 'bg-gray-100'}`}>
                                    <code className={`max-w-[220px] truncate font-mono text-xs ${dark ? 'text-gray-300' : 'text-gray-600'}`}>{k.masked_key}</code>
                                </div>
                                <div className={`hidden text-center text-xs sm:block ${muted}`}>
                                    <div className={`font-bold ${head}`}>{(k.total_requests || 0).toLocaleString('id-ID')}</div>
                                    <div className="text-[10px]">{t('Requests')}</div>
                                </div>
                                <div className={`hidden text-center text-xs md:block ${muted}`}>
                                    <div className={`font-bold ${head}`}>{k.rate_limit}/m</div>
                                    <div className="text-[10px]">{t('Rate Limit')}</div>
                                </div>
                                <div className={`hidden text-center text-xs lg:block ${muted}`}>
                                    <div className={`font-bold ${head}`}>{k.last_used_at ? formatDateTime(k.last_used_at) : '—'}</div>
                                    <div className="text-[10px]">{t('Last Used')}</div>
                                </div>
                                <div className="flex items-center gap-1.5">
                                    <button type="button" onClick={() => toggle(k.id)} disabled={busy === `toggle-${k.id}`} className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-all ${k.is_active ? (dark ? 'bg-amber-500/10 text-amber-400 hover:bg-amber-500/20' : 'bg-amber-50 text-amber-600 hover:bg-amber-100') : (dark ? 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100')}`}>{k.is_active ? t('Nonaktifkan') : t('Aktifkan')}</button>
                                    <button type="button" onClick={() => setConfirm({ id: k.id, action: 'regen' })} className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-all ${dark ? 'bg-blue-500/10 text-blue-400 hover:bg-blue-500/20' : 'bg-blue-50 text-blue-600 hover:bg-blue-100'}`}>{t('Regenerate')}</button>
                                    <button type="button" onClick={() => setConfirm({ id: k.id, action: 'delete' })} className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-all ${dark ? 'bg-red-500/10 text-red-400 hover:bg-red-500/20' : 'bg-red-50 text-red-600 hover:bg-red-100'}`}>{t('Hapus')}</button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Confirm dialog */}
            {confirm && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 animate-fade-in" role="dialog" aria-modal="true">
                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={() => setConfirm(null)} />
                    <div className={`relative w-full max-w-sm rounded-2xl border p-6 text-center shadow-2xl animate-scale-in ${dark ? 'bg-gray-900/95 border-white/[0.08]' : 'bg-white border-gray-200/80'}`}>
                        <h3 className={`text-lg font-bold ${head}`}>{confirm.action === 'delete' ? t('Hapus API Key?') : t('Regenerate API Key?')}</h3>
                        <p className={`mt-2 text-sm ${muted}`}>{confirm.action === 'delete' ? t('Key ini dihapus permanen dan tidak bisa dipakai lagi.') : t('Key lama diganti key baru. Key lama langsung tidak berlaku.')}</p>
                        <div className="mt-5 flex gap-3">
                            <button type="button" onClick={() => setConfirm(null)} className="ui-btn-ghost flex-1 justify-center">{t('Batal')}</button>
                            <button type="button" onClick={runConfirm} disabled={busy === 'confirm'} className={`flex-1 justify-center rounded-xl px-4 py-3 text-sm font-bold text-white transition-all hover:-translate-y-0.5 ${confirm.action === 'delete' ? 'bg-gradient-to-r from-red-500 to-red-600' : 'bg-gradient-to-r from-amber-500 to-orange-500'}`}>{confirm.action === 'delete' ? t('Hapus') : t('Regenerate')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
