import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useLocale } from '../contexts/LocaleContext';
import { apiRequest } from '../lib/api';
import { ANTHROPIC_BASE, OPENAI_BASE } from '../lib/apiAccess';
import { Button, InlineAlert, MemberPage, Metric, PageHeader, Panel, SectionHeader, Spinner, StatePanel, controlClass, errorMessage, formatLocalDate } from '../components/member/MemberUI';
import MediaApiGuide from '../components/api/MediaApiGuide';

function CopyBlock({ label, value, onCopy, copied }) {
    const { t } = useLocale();
    return <div className="min-w-0 space-y-2">
        <div className="flex items-center justify-between gap-3"><h3 className="font-semibold text-slate-900 dark:text-white">{label}</h3>
            <Button variant="secondary" onClick={() => onCopy(value, label)} aria-label={`${t('Salin')} ${label}`}>{copied === label ? t('Tersalin') : t('Salin')}</Button></div>
        <pre tabIndex={0} className="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs leading-6 text-slate-100 focus:outline-none focus:ring-2 focus:ring-red-500"><code>{value}</code></pre>
    </div>;
}

export default function ApiAccess() {
    const { t, locale, localizedPath } = useLocale();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [name, setName] = useState('');
    const [fresh, setFresh] = useState(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [copied, setCopied] = useState('');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState('');
    const load = useCallback(async () => {
        try {
            const [keys, catalog, wallet, tokens] = await Promise.all([
                apiRequest('/api/me/api-keys'), apiRequest('/api/me/api-models'), apiRequest('/api/pricing/wallet'), apiRequest('/api/t/balance'),
            ]);
            setData({ keys, models: catalog.data, exchange: catalog.wallet_idr_per_usd, wallet, tokens });
            setError(null);
        } catch (e) { setError(e); }
        finally { setLoading(false); }
    }, []);
    useEffect(() => { void load(); }, [load]);
    useEffect(() => {
        if (!copied) return undefined;
        const timeout = setTimeout(() => setCopied(''), 2000);
        return () => clearTimeout(timeout);
    }, [copied]);
    const copy = async (value, label) => {
        try { await navigator.clipboard.writeText(value); setCopied(label); }
        catch { setError(new Error(t('Tidak dapat menyalin. Pilih teks lalu salin secara manual.'))); }
    };
    const create = async (event) => {
        event.preventDefault();
        setBusy(true); setError(null);
        try {
            const result = await apiRequest('/api/me/api-keys', { method: 'POST', body: { name: name.trim() } });
            setFresh({ id: result.api_key.id, value: result.key }); setName('');
            await load();
        } catch (e) { setError(e); }
        finally { setBusy(false); }
    };
    const changeKey = async () => {
        setBusy(true); setError(null);
        try {
            await apiRequest(`/api/me/api-keys/${confirm.id}${confirm.action === 'revoke' ? '/revoke' : ''}`, { method: confirm.action === 'revoke' ? 'POST' : 'DELETE' });
            if (fresh?.id === confirm.id) setFresh(null);
            setConfirm(null); await load();
        } catch (e) { setError(e); }
        finally { setBusy(false); }
    };
    const money = (value, currency = 'USD') => new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'id-ID', { style: 'currency', currency, maximumFractionDigits: currency === 'USD' ? 6 : 2 }).format(value);
    const number = (value) => new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'id-ID').format(value);
    const models = data?.models;
    const filtered = useMemo(() => (models || []).filter(model => `${model.id} ${model.name}`.toLowerCase().includes(search.trim().toLowerCase())), [models, search]);
    const model = models?.find(item => item.id === selected)?.id || models?.[0]?.id || '';
    const cheap = useMemo(() => (models || []).reduce((best, item) => !best || item.pricing.input_usd_per_million + item.pricing.output_usd_per_million < best.pricing.input_usd_per_million + best.pricing.output_usd_per_million ? item : best, null)?.id || '', [models]);
    const activeCount = data?.keys.filter(key => key.is_active).length || 0;
    const linkClass = 'font-semibold text-red-600 underline decoration-red-300 underline-offset-4 hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 dark:text-red-400';
    const th = 'px-4 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-300';
    const td = 'px-4 py-3 align-top text-slate-800 dark:text-slate-200';
    const guides = model ? [
        ['Claude Code', `export ANTHROPIC_BASE_URL="${ANTHROPIC_BASE}"\nexport ANTHROPIC_AUTH_TOKEN="<API_KEY>"\nexport ANTHROPIC_MODEL="${model}"\nexport ANTHROPIC_SMALL_FAST_MODEL="${cheap}"\nclaude`],
        ['Cursor', `OpenAI API Key: <API_KEY>\nOverride OpenAI Base URL: ${OPENAI_BASE}\nModel: ${model}`],
        ['Cline / Roo / Kilo', `API Provider: OpenAI Compatible\nBase URL: ${OPENAI_BASE}\nAPI Key: <API_KEY>\nModel ID: ${model}`],
        ['curl · OpenAI', `curl ${OPENAI_BASE}/chat/completions \\\n  -H 'Authorization: Bearer <API_KEY>' \\\n  -H 'Content-Type: application/json' \\\n  -d '${JSON.stringify({ model, messages: [{ role: 'user', content: 'Hello' }], max_tokens: 256 })}'`],
        ['curl · Anthropic', `curl ${ANTHROPIC_BASE}/v1/messages \\\n  -H 'x-api-key: <API_KEY>' \\\n  -H 'anthropic-version: 2023-06-01' \\\n  -H 'Content-Type: application/json' \\\n  -d '${JSON.stringify({ model, max_tokens: 256, messages: [{ role: 'user', content: 'Hello' }] })}'`],
    ] : [];

    return <MemberPage>
        <PageHeader title={t('Akses API')} description={t('Hubungkan alat coding Anda, kelola kunci pribadi, dan pantau pemakaian.')} />
        {error && <InlineAlert tone="error" action={<Button variant="secondary" disabled={busy} onClick={load}>{t('Coba lagi')}</Button>}>{t(errorMessage(error))}</InlineAlert>}
        {loading ? <Spinner label={t('Memuat akses API')} /> : data && <>
            <div className="grid gap-3 sm:grid-cols-2">
                <Metric label={t('Saldo AI')} value={money(data.wallet.balance_microusd / 1_000_000)} detail={t('Untuk chat dan API model bahasa')} />
                <Metric label={t('Token media')} value={number(data.tokens.balance)} detail={t('Untuk pembuatan gambar, video, dan audio')} />
            </div>
            <div className="flex flex-wrap gap-x-6 gap-y-2"><Link className={linkClass} to={`${localizedPath('/deposit')}?tab=wallet`}>{t('Isi Saldo AI')}</Link><Link className={linkClass} to={`${localizedPath('/deposit')}?tab=tokens`}>{t('Beli token media')}</Link><Link className={linkClass} to={localizedPath('/token-usage')}>{t('Lihat riwayat pemakaian')}</Link></div>
            <Panel className="space-y-4 p-4 sm:p-5">
                <SectionHeader title={t('Kunci API saya')} description={t('Maksimal 10 kunci aktif. Kunci dapat dicabut kapan saja.')} />
                {fresh && <InlineAlert tone="warning"><div className="space-y-3">
                    <p className="font-semibold">{t('Simpan kunci ini sekarang. Kunci lengkap hanya ditampilkan sekali.')}</p>
                    <p>{t('Jangan bagikan kunci atau masukkan ke repositori publik.')}</p>
                    <code className="block break-all select-all rounded bg-white/60 p-3 font-mono dark:bg-black/20">{fresh.value}</code>
                    <div className="flex flex-wrap gap-2"><Button onClick={() => copy(fresh.value, 'fresh')}>{copied === 'fresh' ? t('Tersalin') : t('Salin kunci')}</Button><Button variant="secondary" onClick={() => { setFresh(null); setCopied(''); }}>{t('Sudah disimpan, tutup')}</Button></div>
                </div></InlineAlert>}
                <form onSubmit={create} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1"><label htmlFor="api-key-name" className="mb-1.5 block font-medium text-slate-800 dark:text-slate-200">{t('Nama kunci')}</label>
                        <input id="api-key-name" className={controlClass} value={name} onChange={event => setName(event.target.value)} maxLength={100} placeholder={t('Contoh: Claude Code laptop')} autoComplete="off" required disabled={busy || activeCount >= 10} /></div>
                    <Button type="submit" disabled={busy || activeCount >= 10 || !name.trim()}>{busy ? t('Memproses…') : t('Buat kunci API')}</Button>
                </form>
                {activeCount >= 10 && <InlineAlert tone="warning">{t('Batas kunci aktif tercapai. Cabut salah satu kunci untuk membuat yang baru.')}</InlineAlert>}
                {confirm && <InlineAlert tone="warning"><div className="space-y-2"><p className="font-semibold">{confirm.action === 'revoke' ? t('Cabut kunci ini? Aplikasi yang memakainya akan kehilangan akses.') : t('Hapus kunci ini? Tindakan ini tidak dapat dibatalkan.')}</p><p>{confirm.name}</p><div className="flex gap-2"><Button variant="danger" disabled={busy} onClick={changeKey}>{t('Konfirmasi')}</Button><Button variant="secondary" disabled={busy} onClick={() => setConfirm(null)}>{t('Batal')}</Button></div></div></InlineAlert>}
                {data.keys.length === 0 ? <StatePanel compact title={t('Belum ada kunci API')} description={t('Buat kunci pertama untuk menghubungkan alat coding Anda.')} /> : <div className="overflow-x-auto">
                    <table className="w-full text-xs tabular-nums"><caption className="sr-only">{t('Kunci API dan pemakaian 30 hari')}</caption><thead className="border-b border-slate-200 dark:border-white/10"><tr>
                        {[t('Kunci'), t('Status'), t('Permintaan / menit'), t('Dibuat / terakhir dipakai'), t('Pemakaian 30 hari'), t('Tindakan')].map(label => <th scope="col" className={th} key={label}>{label}</th>)}</tr></thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-white/10">{data.keys.map(key => <tr key={key.id}>
                            <td className={td}><span className="block max-w-52 break-words font-semibold">{key.name}</span><code>{key.key_prefix}...</code></td>
                            <td className={td}>{key.is_active ? t('Aktif') : t('Dicabut')}</td><td className={td}>{number(key.rate_limit)}</td>
                            <td className={`${td} whitespace-nowrap`}><time dateTime={key.created_at}>{formatLocalDate(key.created_at, { locale })}</time><span className="block text-slate-500 dark:text-slate-400">{key.last_used_at ? formatLocalDate(key.last_used_at, { locale }) : t('Belum dipakai')}</span></td>
                            <td className={`${td} whitespace-nowrap`}><span className="block">{number(key.usage_30d.requests)} {t('permintaan')} · {money(key.usage_30d.cost_usd)}</span><span className="text-slate-500 dark:text-slate-400">{t('Total permintaan')}: {number(key.total_requests)}</span></td>
                            <td className={td}><div className="flex gap-1">{key.is_active && <Button variant="secondary" disabled={busy} onClick={() => setConfirm({ id: key.id, name: key.name, action: 'revoke' })}>{t('Cabut')}</Button>}<Button variant="danger" disabled={busy} onClick={() => setConfirm({ id: key.id, name: key.name, action: 'delete' })}>{t('Hapus')}</Button></div></td>
                        </tr>)}</tbody>
                    </table>
                </div>}
            </Panel>
            <Panel className="space-y-5 p-4 sm:p-5">
                <SectionHeader title={t('Hubungkan alat coding')} description={t('Ganti <API_KEY> dengan kunci pribadi Anda. Tidak ada prompt sistem tambahan dari XSuper.')} />
                {!model ? <StatePanel compact title={t('Belum ada model yang tersedia')} description={t('Model berharga aktif akan muncul di sini setelah tersedia.')} /> : <>
                    <div className="max-w-xl"><label htmlFor="api-guide-model" className="mb-1.5 block font-medium text-slate-800 dark:text-slate-200">{t('Model untuk panduan')}</label><select id="api-guide-model" value={model} className={controlClass} onChange={event => setSelected(event.target.value)}>{models.map(item => <option key={item.id} value={item.id}>{item.name} · {item.id}</option>)}</select></div>
                    <p className="text-slate-600 dark:text-slate-300">{t('Claude Code memakai URL tanpa /v1. Cursor, Cline, Roo, dan Kilo memakai URL dengan /v1. Aktifkan kunci OpenAI sendiri dan tambahkan ID model di pengaturan Cursor.')}</p>
                    <div className="grid min-w-0 gap-6 lg:grid-cols-2">{guides.map(([label, value]) => <CopyBlock key={label} label={label} value={value} onCopy={copy} copied={copied} />)}</div>
                </>}
            </Panel>
            <Panel className="space-y-4 p-4 sm:p-5">
                <SectionHeader title={t('Model dan harga API')} description={t('Harga per 1 juta token. Token cache memakai tarif input jika tidak ada tarif cache khusus.')} />
                <label className="block"><span className="sr-only">{t('Cari model')}</span><input type="search" className={controlClass} value={search} onChange={event => setSearch(event.target.value)} placeholder={t('Cari model berdasarkan nama atau ID')} /></label>
                {filtered.length === 0 ? <StatePanel compact title={t('Tidak ada model yang cocok')} description={t('Coba kata kunci lain atau periksa kembali nanti.')} /> : <div className="overflow-x-auto"><table className="w-full text-xs tabular-nums"><caption className="sr-only">{t('Harga model per 1 juta token')}</caption><thead className="border-b border-slate-200 dark:border-white/10"><tr>{[t('Model'), t('Konteks'), t('Input'), t('Output'), t('Baca cache'), t('Tulis cache')].map(label => <th scope="col" className={th} key={label}>{label}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-white/10">
                    {filtered.map(item => <tr key={item.id}><td className={td}><span className="block font-semibold">{item.name}</span><code className="break-all">{item.id}</code></td><td className={td}>{item.context_length ? number(item.context_length) : t('Tidak tersedia')}</td>{['input', 'output', 'cache_read', 'cache_write'].map(meter => {
                        const value = item.pricing[`${meter}_usd_per_million`] ?? item.pricing.input_usd_per_million;
                        return <td className={`${td} whitespace-nowrap`} key={meter}><span className="block">{money(value)}</span><span className="text-slate-500 dark:text-slate-400">{money(value * data.exchange, 'IDR')}</span></td>;
                    })}</tr>)}
                </tbody></table></div>}
            </Panel>
            <MediaApiGuide baseUrl={ANTHROPIC_BASE} />
        </>}
    </MemberPage>;
}
