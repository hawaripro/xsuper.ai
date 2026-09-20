import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useLocale } from '../contexts/LocaleContext';
import { useTheme } from '../contexts/ThemeContext';
import { apiRequest } from '../lib/api';
import { Button, StatePanel, controlClass } from '../components/member/MemberUI';

const PAGE_SIZE = 24;

/* Every catalogue category gets a stable colour + icon so the grid can be
   scanned by eye. Gradients are decorative only — the label always names the
   category, so colour is never the sole carrier of meaning. */
const CATEGORY_META = {
    'Coding': { grad: 'from-sky-500 to-blue-600', icon: 'M8 9l-4 3 4 3m8-6 4 3-4 3M14 4l-4 16' },
    'UMKM': { grad: 'from-amber-500 to-orange-600', icon: 'M3 9l1-5h16l1 5M4 9v11h16V9M9 20v-6h6v6' },
    'Konten': { grad: 'from-fuchsia-500 to-pink-600', icon: 'M15 10l4.5-2.5v9L15 14m-11 3h11V7H4z' },
    'Marketplace': { grad: 'from-emerald-500 to-teal-600', icon: 'M6 6h15l-1.5 9h-12zM6 6L5 3H2m6 18a1 1 0 100-2 1 1 0 000 2zm9 0a1 1 0 100-2 1 1 0 000 2z' },
    'Excel': { grad: 'from-green-600 to-emerald-700', icon: 'M4 4h16v16H4zM4 9h16M4 14h16M9 4v16' },
    'Desain': { grad: 'from-violet-500 to-purple-600', icon: 'M12 19l7-7-4-4-7 7v4h4zM15 8l1.5-1.5a2.1 2.1 0 013 3L18 11' },
    'Prompt Gambar': { grad: 'from-rose-500 to-red-600', icon: 'M4 5h16v14H4zM4 15l4-4 3 3 4-5 5 6' },
    'Prompt Video': { grad: 'from-orange-500 to-red-500', icon: 'M4 6h12v12H4zM16 10l5-3v10l-5-3' },
    'Bisnis': { grad: 'from-cyan-600 to-sky-700', icon: 'M4 20V10m6 10V4m6 16v-7m4 7H2' },
    'Belajar': { grad: 'from-indigo-500 to-blue-700', icon: 'M12 4L2 9l10 5 10-5-10-5zM6 11.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5' },
};

function CategoryBadge({ category, size = 'h-9 w-9' }) {
    const meta = CATEGORY_META[category] || { grad: 'from-slate-500 to-slate-600', icon: 'M4 6h16M4 12h16M4 18h16' };
    return (
        <span aria-hidden="true" className={`inline-flex ${size} shrink-0 items-center justify-center rounded-xl bg-gradient-to-br ${meta.grad} text-white shadow-md transition-transform duration-200 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:transform-none`}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" className="h-4.5 w-4.5 h-[18px] w-[18px]"><path d={meta.icon} /></svg>
        </span>
    );
}

function CopyIcon({ done }) {
    return done
        ? <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-3.5 w-3.5"><path d="M20 6 9 17l-5-5" /></svg>
        : <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" className="h-3.5 w-3.5"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>;
}


function SearchIcon() {
    return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" className="h-5 w-5"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4.5 4.5" /></svg>;
}

function ArrowIcon({ back = false }) {
    return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4"><path d={back ? 'M19 12H5m6-6-6 6 6 6' : 'M5 12h14m-6-6 6 6-6 6'} /></svg>;
}

function loadErrorCopy(error) {
    if (error?.status === 401) return 'Sesi Anda telah berakhir. Masuk kembali untuk melanjutkan.';
    if (error?.status === 403) return 'Akun Anda tidak memiliki akses ke template ini.';
    if (error?.status === 422) return 'Pencarian tidak valid. Gunakan maksimal 200 karakter dan coba lagi.';
    if (error?.status === 429) return 'Terlalu banyak pencarian. Tunggu sebentar, lalu coba lagi.';
    return 'Template tidak dapat dimuat. Periksa koneksi Anda dan coba lagi.';
}

export default function TemplatePrompt() {
    const navigate = useNavigate();
    const { t, locale, localizedPath } = useLocale();
    const [searchParams, setSearchParams] = useSearchParams();
    const requestedTemplate = searchParams.get('template') || '';
    const selectedTemplateId = /^[1-9]\d{0,18}$/.test(requestedTemplate) ? requestedTemplate : null;
    const { theme } = useTheme();
    const isDark = theme === 'dark';
    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState({ q: '', category: '', page: 1 });
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [refresh, setRefresh] = useState(0);
    const [expandedId, setExpandedId] = useState(null);
    const [copiedId, setCopiedId] = useState(null);
    const number = useMemo(() => new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'id-ID'), [locale]);
    const awaitingSearch = !selectedTemplateId && search.trim() !== filters.q;
    const busy = loading || awaitingSearch;

    useEffect(() => {
        if (!selectedTemplateId) return;
        setSearch('');
        setFilters(current => current.q || current.category || current.page !== 1 ? { q: '', category: '', page: 1 } : current);
    }, [selectedTemplateId]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            const q = search.trim();
            setFilters(current => current.q === q ? current : { ...current, q, page: 1 });
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        const controller = new AbortController();
        const params = new URLSearchParams({ page: String(selectedTemplateId ? 1 : filters.page), per_page: String(PAGE_SIZE) });
        if (selectedTemplateId) params.set('template', selectedTemplateId);
        else {
            if (filters.q) params.set('q', filters.q);
            if (filters.category) params.set('category', filters.category);
        }
        setLoading(true);
        setError(null);
        setExpandedId(null);

        const load = async () => {
            try {
                const result = await apiRequest(`/api/templates?${params}`, { signal: controller.signal });
                if (!Array.isArray(result?.templates) || result.templates.length > PAGE_SIZE || !Array.isArray(result?.categories) || !result?.counts || !result?.pagination) {
                    throw new Error('Invalid template response');
                }
                if (!controller.signal.aborted) {
                    setData(result);
                    setExpandedId(result.templates.find(template => String(template.id) === selectedTemplateId)?.id ?? null);
                }
            } catch (failure) {
                if (!controller.signal.aborted) setError(failure);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        };
        load();
        return () => controller.abort();
    }, [filters, refresh, selectedTemplateId]);

    useEffect(() => {
        if (busy || error || !selectedTemplateId || !data?.templates?.some(template => String(template.id) === selectedTemplateId)) return;
        const heading = document.getElementById(`template-title-${selectedTemplateId}`);
        heading?.focus({ preventScroll: true });
        heading?.scrollIntoView({ block: 'nearest' });
    }, [busy, error, selectedTemplateId, data]);

    const clearTemplateSelection = () => {
        if (!selectedTemplateId) return;
        setSearchParams(current => {
            const next = new URLSearchParams(current);
            next.delete('template');
            return next;
        }, { replace: true });
    };

    const clearFilters = () => {
        clearTemplateSelection();
        setSearch('');
        setFilters({ q: '', category: '', page: 1 });
    };
    const chooseCategory = category => {
        clearTemplateSelection();
        setFilters(current => ({ ...current, category, page: 1 }));
    };
    const useTemplate = prompt => navigate(localizedPath('/chat'), { state: { prefillPrompt: prompt } });
    const categories = data?.categories || [];
    const templates = data?.templates || [];
    const pagination = data?.pagination;
    const allCount = Object.values(data?.counts || {}).reduce((total, count) => total + Number(count), 0);
    const first = pagination?.total ? (pagination.current_page - 1) * pagination.per_page + 1 : 0;
    const last = pagination ? Math.min(pagination.total, pagination.current_page * pagination.per_page) : 0;
    const resultsCopy = pagination && templates.length > 0
        ? t('Hasil :from–:to dari :total').replace(':from', number.format(first)).replace(':to', number.format(last)).replace(':total', number.format(pagination.total))
        : t('Tidak ada hasil.');
    const categoryClass = selected => `inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition-all focus-visible:outline-2 focus-visible:outline-red-500 motion-reduce:transition-none ${selected
        ? (isDark ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-red-50 text-red-600 border border-red-200')
        : (isDark ? 'bg-white/5 text-gray-400 border border-white/10 hover:bg-white/10' : 'bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200')}`;

    return (
        <div className="p-6 lg:p-8" style={{ fontSize: '90%' }}>
            <div className="max-w-6xl mx-auto">
                <h1 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>{t('Template Prompt')}</h1>
                <p className={`mb-6 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{t('Pilih template siap pakai untuk memulai percakapan dengan AI.')}</p>

            <section aria-label={t('Cari dan filter template')} className="space-y-4 mb-6">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div className="w-full sm:max-w-xl">
                        <label htmlFor="template-search" className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{t('Cari template')}</label>
                        <div className="relative">
                            <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-500 dark:text-slate-400"><SearchIcon /></span>
                            <input
                                id="template-search"
                                type="search"
                                maxLength={200}
                                value={search}
                                onChange={event => { clearTemplateSelection(); setSearch(event.target.value); }}
                                placeholder={t('Contoh: invoice, foto produk, rencana belajar')}
                                aria-describedby="template-search-help"
                                className={`${controlClass} pl-10 pr-3 caret-red-600`}
                            />
                        </div>
                    </div>
                    {(search || filters.category || selectedTemplateId) && <Button variant="ghost" className="min-h-11 self-start sm:self-auto" onClick={clearFilters}>{t('Reset pencarian')}</Button>}
                </div>
                <p id="template-search-help" className="text-sm leading-6 text-slate-600 dark:text-slate-400">{t('Cari berdasarkan judul atau isi prompt. Pilih kategori untuk mempersempit hasil.')}</p>
                <div className="flex flex-wrap gap-2" aria-label={t('Kategori template')}>
                    <button type="button" aria-pressed={!filters.category} className={categoryClass(!filters.category)} onClick={() => chooseCategory('')}>
                        {t('Semua')}
                        {data && <span className="tabular-nums opacity-80">{number.format(allCount)}</span>}
                    </button>
                    {categories.map(category => {
                        const meta = CATEGORY_META[category];
                        return (
                            <button key={category} type="button" aria-pressed={filters.category === category} className={categoryClass(filters.category === category)} onClick={() => chooseCategory(category)}>
                                {meta && <span aria-hidden="true" className={`h-2 w-2 rounded-full bg-gradient-to-br ${meta.grad}`} />}
                                {t(category)}
                                <span className="tabular-nums opacity-80">{number.format(data.counts[category] || 0)}</span>
                            </button>
                        );
                    })}
                </div>
            </section>

            <div className="flex flex-col gap-1 border-t border-slate-200 pt-4 text-sm text-slate-600 dark:border-white/10 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                <p role="status" aria-live="polite" aria-atomic="true">{busy ? t('Memuat template…') : error ? t('Pencarian belum dapat ditampilkan.') : resultsCopy}</p>
                <p>{t('Prompt library menggunakan Bahasa Indonesia.')}</p>
            </div>

            <section aria-label={t('Hasil template')} aria-busy={busy}>
                {busy ? (
                    <StatePanel type="loading" title={t('Memuat template…')} description={t('Mengambil hasil yang sesuai dengan pencarian Anda.')} />
                ) : error ? (
                    <StatePanel
                        type="error"
                        title={t('Gagal memuat template')}
                        description={t(loadErrorCopy(error))}
                        action={error.status === 401
                            ? <Button className="min-h-11" onClick={() => navigate(localizedPath('/login'))}>{t('Masuk kembali')}</Button>
                            : <Button variant="secondary" className="min-h-11" onClick={() => setRefresh(value => value + 1)}>{t('Coba lagi')}</Button>}
                    />
                ) : templates.length === 0 ? (
                    <StatePanel
                        title={selectedTemplateId ? t('Template tidak tersedia.') : filters.q || filters.category ? t('Tidak ada template yang cocok.') : t('Belum ada template tersedia.')}
                        description={selectedTemplateId ? t('Template ini tidak tersedia lagi. Pilih template lain untuk melanjutkan.') : filters.q || filters.category ? t('Coba kata kunci lain atau tampilkan semua kategori.') : t('Template yang diterbitkan akan muncul di sini.')}
                        action={(filters.q || filters.category || selectedTemplateId) && <Button variant="secondary" className="min-h-11" onClick={clearFilters}>{t('Reset pencarian')}</Button>}
                    />
                ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {templates.map(template => {
                            const expanded = expandedId === template.id;
                            return (
                                <div key={template.id} style={{ animationDelay: `${Math.min(templates.indexOf(template), 11) * 35}ms` }} className={`group relative min-w-0 rounded-2xl border p-5 animate-fade-in-up transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg motion-reduce:animate-none motion-reduce:transform-none motion-reduce:transition-none ${isDark ? 'bg-gray-900/60 border-white/[0.06] hover:border-white/[0.14]' : 'bg-white border-gray-200 hover:border-gray-300'}`}>
                                    <div className="mb-3 flex items-center gap-2.5">
                                        <CategoryBadge category={template.category} />
                                        <div className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider ${isDark ? 'bg-white/5 text-gray-400' : 'bg-gray-100 text-gray-500'}`}>{t(template.category)}</div>
                                    </div>
                                    <h3 id={`template-title-${template.id}`} tabIndex={-1} className={`text-sm font-semibold mb-2 break-words focus-visible:outline-2 focus-visible:outline-red-500 ${isDark ? 'text-white' : 'text-slate-900'}`}>{template.title}</h3>
                                    <p className={`text-xs mb-4 line-clamp-2 break-words ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{template.prompt_text}</p>
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => useTemplate(template.prompt_text)} className={`flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all duration-200 active:scale-[0.98] focus-visible:outline-2 focus-visible:outline-red-500 motion-reduce:transition-none ${isDark ? 'bg-red-500/10 text-red-300 border border-red-500/20 hover:bg-red-500/20' : 'bg-red-50 text-red-600 border border-red-200 hover:bg-red-100'}`}>{t('Pakai Template')}<ArrowIcon /></button>
                                        <button type="button" aria-label={t('Salin prompt')} onClick={() => { navigator.clipboard?.writeText(template.prompt_text); setCopiedId(template.id); setTimeout(() => setCopiedId(current => current === template.id ? null : current), 1600); }} className={`inline-flex items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold transition-all duration-200 active:scale-[0.98] motion-reduce:transition-none ${copiedId === template.id ? 'border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' : isDark ? 'border-white/10 bg-white/5 text-gray-300 hover:bg-white/10' : 'border-gray-200 bg-gray-50 text-gray-600 hover:bg-gray-100'}`}><CopyIcon done={copiedId === template.id} />{copiedId === template.id ? t('Tersalin') : t('Salin')}</button>
                                        <button type="button" className={`mt-2 w-full py-1 text-xs hover:underline ${isDark ? 'text-gray-400' : 'text-gray-500'}`} aria-expanded={expanded} aria-controls={`template-prompt-${template.id}`} onClick={() => setExpandedId(expanded ? null : template.id)}>{expanded ? t('Tutup prompt') : t('Lihat prompt')}</button>
                                    </div>
                                    {expanded && (
                                        <div id={`template-prompt-${template.id}`} role="region" aria-labelledby={`template-title-${template.id}`} className="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">
                                            <p className="mb-3 text-sm leading-6 text-slate-600 dark:text-slate-400">{t('Ganti bagian dalam tanda kurung siku di Chat. Prompt tidak langsung dikirim.')}</p>
                                            <pre tabIndex={0} className="max-h-80 overflow-y-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 font-sans text-sm leading-6 text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50 dark:bg-slate-950/70 dark:text-slate-200">{template.prompt_text}</pre>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </section>

            {!busy && !error && pagination && pagination.last_page > 1 && (
                <nav aria-label={t('Navigasi halaman template')} className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
                    <Button variant="secondary" className="min-h-11" disabled={pagination.current_page <= 1} onClick={() => setFilters(current => ({ ...current, page: current.page - 1 }))}><ArrowIcon back />{t('Sebelumnya')}</Button>
                    <p className="text-sm tabular-nums text-slate-600 dark:text-slate-300">{t('Halaman :page dari :pages').replace(':page', number.format(pagination.current_page)).replace(':pages', number.format(pagination.last_page))}</p>
                    <Button variant="secondary" className="min-h-11" disabled={pagination.current_page >= pagination.last_page} onClick={() => setFilters(current => ({ ...current, page: current.page + 1 }))}>{t('Berikutnya')}<ArrowIcon /></Button>
                </nav>
            )}
            </div>
        </div>
    );
}
