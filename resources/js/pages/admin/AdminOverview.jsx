import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import DashboardWorkspace, { WorkspaceInbox, WorkspaceLoading, WorkspaceMetric, WorkspaceModule, WorkspaceUsageChart } from '../../components/dashboard/DashboardWorkspace';
import DataTable from '../../components/dashboard/DataTable';
import StatusBadge from '../../components/dashboard/StatusBadge';
import { Button, InlineAlert, StatePanel, formatCount, formatUsdMicros } from '../../components/member/MemberUI';
import { apiRequest, formatCurrency, formatDateTime } from '../../lib/api';
import { useLocale } from '../../contexts/LocaleContext';
import Icons from '../../layouts/SidebarIcons';

const ENDPOINTS = { revenue: '/api/a/stats/revenue', usage: '/api/usage', catalog: '/api/admin/ai/catalog' };
const INITIAL_SOURCES = Object.fromEntries(Object.keys(ENDPOINTS).map(key => [key, { data: null, loading: true, error: '' }]));
const SOURCE_LABELS = { revenue: 'Data keuangan', usage: 'Data pemakaian', catalog: 'AI catalog' };
const GENERATOR_LABELS = { image: 'Image', video: 'Video', audio: 'Audio' };
const OPERATION_TOOLS = [
    { label: 'Orders & Billing', description: 'Tinjau order, deposit, dan langganan.', href: '/admin/operations', icon: 'orders', tone: 'amber' },
    { label: 'People & Access', description: 'Kelola pengguna, izin, dan perangkat.', href: '/admin/users', icon: 'users', tone: 'blue' },
    { label: 'AI Catalog', description: 'Kelola model, harga, dan provider.', href: '/admin/ai', icon: 'model', tone: 'violet' },
    { label: 'Content & Support', description: 'Tanggapi dukungan dan kelola konten.', href: '/admin/content', icon: 'help', tone: 'emerald' },
];

export default function AdminOverview() {
    const { t, localizedPath } = useLocale();
    const [sources, setSources] = useState(INITIAL_SOURCES);
    const [usageMonth, setUsageMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [usagePeriod, setUsagePeriod] = useState('daily');
    const requests = useRef({});
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Jakarta';

    const loadSource = useCallback(async (key, value) => {
        requests.current[key]?.abort();
        const controller = new AbortController();
        requests.current[key] = controller;
        setSources(current => ({ ...current, [key]: { ...current[key], loading: true, error: '' } }));
        const query = key === 'revenue' ? `?month=${encodeURIComponent(value)}` : key === 'usage' ? `?period=${encodeURIComponent(value)}&tz=${encodeURIComponent(timezone)}` : '';
        try {
            const data = await apiRequest(`${ENDPOINTS[key]}${query}`, { signal: controller.signal });
            if (!controller.signal.aborted) setSources(current => ({ ...current, [key]: { data, loading: false, error: '' } }));
        } catch (error) {
            if (!controller.signal.aborted) setSources(current => ({ ...current, [key]: { ...current[key], loading: false, error: error.message || 'Data tidak dapat dimuat.' } }));
        }
    }, [timezone]);

    useEffect(() => {
        const currentRequests = requests.current;
        loadSource('catalog');
        return () => Object.values(currentRequests).forEach(controller => controller.abort());
    }, [loadSource]);
    useEffect(() => { loadSource('revenue', usageMonth); }, [loadSource, usageMonth]);
    useEffect(() => { loadSource('usage', usagePeriod); }, [loadSource, usagePeriod]);

    function loadAll() {
        loadSource('revenue', usageMonth);
        loadSource('usage', usagePeriod);
        loadSource('catalog');
    }
    function retrySource(key) { loadSource(key, key === 'revenue' ? usageMonth : usagePeriod); }

    const revenue = sources.revenue.data;
    const earnings = revenue?.usage_earnings?.month === usageMonth ? revenue.usage_earnings : null;
    const usage = sources.usage.data;
    const providers = sources.catalog.data?.providers || [];
    const timeline = (usage?.timeline || []).map(row => ({ ...row, period: row.label }));
    const providerAlerts = providers.filter(provider => !provider.is_enabled || !['online', 'healthy', 'active', 'ok'].includes(String(provider.status).toLowerCase()));
    const failedSources = Object.entries(sources).filter(([, source]) => source.error);
    const loadingAny = Object.values(sources).some(source => source.loading);
    const unavailable = t('Belum tersedia');
    const financePending = sources.revenue.loading && !revenue;
    const earningsPending = sources.revenue.loading && !earnings;

    return (
        <DashboardWorkspace title={t('Operasional XSuper.ai')} description={t('Pantau keuangan, pemakaian, dan pekerjaan operasional dalam satu ruang.')} actions={<><button type="button" className="dw-button" onClick={loadAll} disabled={loadingAny}>{Icons.refresh}<span>{t('Refresh data')}</span></button><Link className="dw-button dw-button-primary" to={localizedPath('/admin/token-usage')}>{Icons.token}{t('Tinjau pemakaian')}</Link></>}>
            {failedSources.map(([key, source]) => <InlineAlert key={key} tone="warning" action={<Button variant="ghost" onClick={() => retrySource(key)} disabled={source.loading}>{t('Retry source')}</Button>}><strong>{t(SOURCE_LABELS[key])}: </strong>{t(source.error)}{source.data && <> {t('Data terakhir tetap ditampilkan.')}</>}</InlineAlert>)}

            <section className="dw-finance" aria-labelledby="overview-metrics">
                <header className="dw-section-head"><div><h2 id="overview-metrics">{t('Business pulse')}</h2><p>{t('Bulan settlement hanya mengubah pendapatan PAYG dan token generator, bukan pendapatan langganan.')}</p></div><label className="dw-month-label"><span>{t('Settlement month')}</span><input type="month" name="usage_month" value={usageMonth} onChange={event => { if (event.target.value) setUsageMonth(event.target.value); }} aria-controls="overview-financial-metrics usage-earnings-data" /></label></header>
                <dl id="overview-financial-metrics" className="dw-metrics" aria-busy={sources.revenue.loading}>
                    <WorkspaceMetric label={t('Subscription revenue this month')} value={revenue ? formatCurrency(revenue.revenue_this_month, 'IDR') : unavailable} loading={financePending} icon={Icons.revenue} tone="emerald" detail={revenue ? `${formatCount(revenue.approved_this_month)} ${t('approved subscription orders')} · IDR` : t('Approved subscription payments · IDR')} />
                    <WorkspaceMetric label={t('PAYG earnings · selected month')} value={earnings ? formatUsdMicros(earnings.payg.month_cost_microusd) : unavailable} loading={earningsPending} icon={Icons.api} tone="cyan" detail={t('Biaya API dan chat terselesaikan · USD')} />
                    <WorkspaceMetric label={t('Generator tokens · selected month')} value={earnings ? formatCount(earnings.generators.month_tokens) : unavailable} loading={earningsPending} icon={Icons.token} tone="fuchsia" detail={t('Settled image, video, and audio tokens')} />
                    <WorkspaceMetric label={t('Pending orders')} value={revenue ? formatCount(revenue.pending_orders) : unavailable} loading={financePending} icon={Icons.orders} tone="amber" detail={<Link to={localizedPath('/admin/operations')}>{t('Open operations')}</Link>} />
                </dl>
                <p className="dw-note">{Icons.info}{t('Biaya API dan chat serta token generator dipisahkan dari deposit dan pendapatan langganan.')}</p>
            </section>

            <div className="dw-board">
                <div className="dw-stack">
                    <WorkspaceUsageChart rows={timeline} loading={sources.usage.loading} error={sources.usage.error ? t(sources.usage.error) : null} onRetry={() => loadSource('usage', usagePeriod)} description={`${t({ hourly: 'Permintaan seluruh akun selama 24 jam terakhir.', '7d': 'Permintaan seluruh akun selama 7 hari terakhir.', monthly: 'Permintaan seluruh akun selama 365 hari terakhir.' }[usagePeriod] || 'Permintaan seluruh akun selama 30 hari terakhir.')} ${timezone}`} reportPath="/admin/token-usage" controls={<div className="dw-periods" role="group" aria-label={t('Periode pemakaian')}><button type="button" aria-pressed={usagePeriod === 'hourly'} onClick={() => setUsagePeriod('hourly')}>{t('1 hari')}</button><button type="button" aria-pressed={usagePeriod === '7d'} onClick={() => setUsagePeriod('7d')}>{t('7 hari')}</button><button type="button" aria-pressed={usagePeriod === 'daily'} onClick={() => setUsagePeriod('daily')}>{t('30 hari')}</button><button type="button" aria-pressed={usagePeriod === 'monthly'} onClick={() => setUsagePeriod('monthly')}>{t('1 tahun')}</button></div>} />
                    <WorkspaceModule title={t('Recent approved orders')} icon={Icons.orders} tone="amber">
                        {sources.revenue.loading && !revenue ? <WorkspaceLoading label={t('Loading orders…')} /> : !revenue ? <StatePanel type="error" title={t('Orders are unavailable')} description={sources.revenue.error} action={<Button variant="secondary" onClick={() => loadSource('revenue', usageMonth)}>{t('Coba lagi')}</Button>} /> : <DataTable rows={revenue.recent_orders || []} emptyTitle={t('No approved orders')} emptyDescription={t('Approved orders will appear here after they are processed.')} columns={[
                            { key: 'member', label: t('Member'), render: row => <div><strong className="block text-slate-900 dark:text-white">{row.user_name}</strong><span className="text-[11px] text-slate-500 dark:text-slate-400 break-all">{row.user_email}</span></div> },
                            { key: 'package', label: t('Package'), render: row => row.package || '—' },
                            { key: 'amount', label: t('Amount (IDR)'), render: row => formatCurrency(row.price, 'IDR') },
                            { key: 'approved', label: t('Approved'), render: row => formatDateTime(row.approved_at) },
                        ]} />}
                        <footer className="dw-module-footer"><span>{t('Latest approved subscription payments.')}</span><Link className="dw-text-link" to={localizedPath('/admin/operations')}>{t('Open operations')}{Icons.arrow}</Link></footer>
                    </WorkspaceModule>
                    <WorkspaceInbox />
                </div>
                <div className="dw-stack dw-side-stack">
                    <WorkspaceModule title={t('Alat operasional')} icon={Icons.dashboard} tone="violet" className="dw-tools">{OPERATION_TOOLS.map(item => <Link className="dw-tool" to={localizedPath(item.href)} key={item.href}><span className="dw-icon" data-tone={item.tone} aria-hidden="true">{Icons[item.icon]}</span><span><strong>{t(item.label)}</strong><small>{t(item.description)}</small></span>{Icons.arrow}</Link>)}</WorkspaceModule>
                    <WorkspaceModule title={t('Akun & aktivitas')} icon={Icons.users} tone="blue">
                        {sources.revenue.loading && !revenue ? <WorkspaceLoading label={t('Memuat status akun')} /> : <dl className="dw-account">{[
                            [t('Active members'), revenue ? formatCount(revenue.active_users) : unavailable],
                            [t('Total members'), revenue ? formatCount(revenue.total_users) : unavailable],
                            [t('New members this month'), revenue ? formatCount(revenue.new_users_this_month) : unavailable],
                            [t('Requests recorded · all time'), usage ? formatCount(usage.total_requests) : unavailable],
                            [t('Usage tokens · all time'), usage ? formatCount(usage.total_tokens) : unavailable],
                        ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>}
                        <footer className="dw-module-footer"><span>{t('Izin dan masa aktif dikelola per akun.')}</span><Link className="dw-text-link" to={localizedPath('/admin/users')}>{t('People & Access')}{Icons.arrow}</Link></footer>
                    </WorkspaceModule>
                    <WorkspaceModule title={t('Service alerts')} icon={Icons.provider} tone="amber" count={sources.catalog.data ? providerAlerts.length : undefined}>
                        {sources.catalog.loading && !sources.catalog.data ? <WorkspaceLoading label={t('Checking services…')} /> : sources.catalog.error && !sources.catalog.data ? <StatePanel type="error" title={t('Catalog data is unavailable')} description={sources.catalog.error} action={<Button variant="secondary" onClick={() => loadSource('catalog')}>{t('Coba lagi')}</Button>} /> : !providers.length ? <div className="dw-empty"><h3>{t('No provider health records')}</h3><p>{t('Sync the AI catalog to establish provider health records.')}</p></div> : !providerAlerts.length ? <div className="dw-empty"><h3>{t('No active service alerts')}</h3><p>{t('Tidak ada peringatan pada status provider terakhir yang tersimpan. Ini bukan pemeriksaan realtime.')}</p></div> : <div className="dw-alerts">{providerAlerts.map(provider => <article key={provider.id} className="dw-alert"><div><strong>{provider.name || provider.slug}</strong><StatusBadge status={provider.is_enabled ? provider.status || 'unknown' : 'offline'} /></div><p>{provider.is_enabled ? t('Status terakhir yang dilaporkan provider.') : t('Provider dinonaktifkan untuk katalog global.')}</p><p>{t('Last checked')}: {formatDateTime(provider.last_checked_at)}</p></article>)}</div>}
                        <footer className="dw-module-footer"><span>{t('Status tersimpan, bukan asumsi frontend.')}</span><Link className="dw-text-link" to={localizedPath('/admin/ai')}>{t('AI catalog')}{Icons.arrow}</Link></footer>
                    </WorkspaceModule>
                </div>
            </div>

            <WorkspaceModule title={t('Usage earnings')} icon={Icons.revenue} tone="emerald">
                <div id="usage-earnings-data" className="dw-finance" aria-busy={sources.revenue.loading}>
                    <p className="dw-note">{t('Settlement month')}: <strong>{usageMonth}</strong>. {t('Token consumption, not cash revenue.')}</p>
                    {sources.revenue.loading ? <WorkspaceLoading label={t('Loading settled usage earnings…')} /> : !earnings || sources.revenue.error ? <StatePanel type="error" title={t('Usage earnings are unavailable. Retry the revenue source.')} description={sources.revenue.error} action={<Button variant="secondary" onClick={() => loadSource('revenue', usageMonth)}>{t('Coba lagi')}</Button>} /> : <>
                        <dl className="dw-metrics">
                            <WorkspaceMetric label={t('PAYG earnings · all time')} value={formatUsdMicros(earnings.payg.total_cost_microusd)} detail={t('Wallet deposits are not usage earnings.')} icon={Icons.api} tone="cyan" />
                            <WorkspaceMetric label={t('Generator tokens · all time')} value={formatCount(earnings.generators.total_tokens)} detail={t('Token consumption, not cash revenue.')} icon={Icons.token} tone="fuchsia" />
                            <WorkspaceMetric label={t('Subscription revenue · all time')} value={formatCurrency(revenue.total_revenue, 'IDR')} detail={t('Approved subscription payments · IDR')} icon={Icons.revenue} tone="emerald" />
                            <WorkspaceMetric label={t('Subscription revenue last month')} value={formatCurrency(revenue.revenue_last_month, 'IDR')} detail={t('Approved subscription payments · IDR')} icon={Icons.period} tone="amber" />
                        </dl>
                        <div className="dw-finance-grid">
                            <section className="dw-finance-detail" aria-labelledby="payg-model-earnings-title"><h3 id="payg-model-earnings-title">{t('Pendapatan API dan chat per model')}</h3><DataTable rows={earnings.payg.by_model} rowKey={row => `${row.service}:${row.model}`} emptyTitle={t('No settled PAYG earnings')} emptyDescription={t('Permintaan API dan chat berbayar yang terselesaikan akan tampil di sini untuk bulan yang dipilih.')} columns={[
                                { key: 'service', label: t('Layanan'), render: row => t(row.service === 'chat' ? 'Chat web' : 'API') },
                                { key: 'model', label: t('Model'), render: row => <span className="break-all">{row.model}</span> },
                                { key: 'requests', label: t('Settled requests'), render: row => formatCount(row.requests) },
                                { key: 'earnings', label: t('Earnings (USD)'), render: row => formatUsdMicros(row.cost_microusd) },
                            ]} /></section>
                            <section className="dw-finance-detail" aria-labelledby="generator-model-earnings-title"><h3 id="generator-model-earnings-title">{t('Generator token consumption by model')}</h3><DataTable rows={earnings.generators.by_model} rowKey={row => `${row.service}:${row.model}`} emptyTitle={t('No settled generator usage')} emptyDescription={t('Reserved, released, and historical free requests are not counted.')} columns={[
                                { key: 'service', label: t('Layanan'), render: row => t(GENERATOR_LABELS[row.service] || row.service) },
                                { key: 'model', label: t('Model'), render: row => <span className="break-all">{row.model}</span> },
                                { key: 'generations', label: t('Generations'), render: row => formatCount(row.generations) },
                                { key: 'tokens', label: t('Consumed tokens'), render: row => formatCount(row.tokens) },
                            ]} /></section>
                        </div>
                    </>}
                </div>
            </WorkspaceModule>
            <WorkspaceModule title={t('Provider health')} icon={Icons.provider} tone="emerald" count={sources.catalog.data ? providers.length : undefined} open={false}>
                <p className="dw-note mb-4">{t('Non-secret metadata returned by the catalog service.')}</p>
                {sources.catalog.loading && !sources.catalog.data ? <WorkspaceLoading label={t('Loading provider health…')} /> : sources.catalog.error && !sources.catalog.data ? <StatePanel type="error" title={t('Catalog data is unavailable')} description={sources.catalog.error} action={<Button variant="secondary" onClick={() => loadSource('catalog')}>{t('Coba lagi')}</Button>} /> : <DataTable rows={providers} emptyTitle={t('No providers discovered')} emptyDescription={t('Run a catalog sync from AI Catalog to discover providers and models.')} columns={[
                    { key: 'name', label: t('Provider'), render: row => <div><strong className="text-slate-900 dark:text-white">{row.name || row.slug}</strong><span className="block text-[11px] text-slate-500 dark:text-slate-400">{row.slug}</span></div> },
                    { key: 'status', label: t('Status'), render: row => <StatusBadge status={row.is_enabled ? row.status || 'unknown' : 'offline'} /> },
                    { key: 'checked', label: t('Last checked'), render: row => formatDateTime(row.last_checked_at) },
                ]} />}
            </WorkspaceModule>
            <p className="dw-note dw-note-footer">{Icons.density}{t('Klik judul modul untuk merapikan ruang kerja. Ringkasan angka dan grafik tetap terlihat.')}</p>
        </DashboardWorkspace>
    );
}
