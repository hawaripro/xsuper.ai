import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import PageHeader from '../../components/dashboard/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../../components/dashboard/AsyncState';
import StatCard from '../../components/dashboard/StatCard';
import StatusBadge from '../../components/dashboard/StatusBadge';
import DataTable from '../../components/dashboard/DataTable';
import { apiRequest, formatCurrency, formatDateTime } from '../../lib/api';

const ENDPOINTS = {
    revenue: '/api/a/stats/revenue',
    usage: '/api/usage?period=daily',
    catalog: '/api/admin/ai/catalog',
};

const initialSources = Object.fromEntries(
    Object.keys(ENDPOINTS).map(key => [key, { data: null, loading: true, error: '' }]),
);

function integer(value) {
    return new Intl.NumberFormat('id-ID').format(Number(value || 0));
}

export default function AdminOverview() {
    const [sources, setSources] = useState(initialSources);

    const loadSource = useCallback(async (key, signal) => {
        setSources(current => ({
            ...current,
            [key]: { ...current[key], loading: true, error: '' },
        }));
        try {
            const data = await apiRequest(ENDPOINTS[key], { signal });
            setSources(current => ({ ...current, [key]: { data, loading: false, error: '' } }));
        } catch (error) {
            if (error?.name === 'AbortError') return;
            setSources(current => ({
                ...current,
                [key]: { ...current[key], loading: false, error: error.message || 'Data tidak dapat dimuat.' },
            }));
        }
    }, []);

    const loadAll = useCallback(signal => {
        Object.keys(ENDPOINTS).forEach(key => loadSource(key, signal));
    }, [loadSource]);

    useEffect(() => {
        const controller = new AbortController();
        loadAll(controller.signal);
        return () => controller.abort();
    }, [loadAll]);

    const revenue = sources.revenue.data;
    const usage = sources.usage.data;
    const providers = sources.catalog.data?.providers || [];
    const serviceAlerts = useMemo(() => {
        const alerts = [];
        Object.entries(sources).forEach(([key, source]) => {
            if (source.error) alerts.push({
                id: `request-${key}`,
                status: 'failed',
                title: `${key === 'revenue' ? 'Revenue' : key === 'usage' ? 'Usage' : 'AI catalog'} tidak tersedia`,
                detail: source.error,
                retry: () => loadSource(key),
            });
        });
        providers.forEach(provider => {
            if (!provider.is_enabled || !['online', 'healthy', 'active'].includes(String(provider.status).toLowerCase())) {
                alerts.push({
                    id: `provider-${provider.id}`,
                    status: provider.is_enabled ? (provider.status || 'degraded') : 'offline',
                    title: provider.name || provider.slug,
                    detail: provider.is_enabled
                        ? `Status provider: ${provider.status || 'unknown'}.`
                        : 'Provider dinonaktifkan untuk katalog global.',
                });
            }
        });
        return alerts;
    }, [loadSource, providers, sources]);

    const isInitialLoading = Object.values(sources).every(source => source.loading && !source.data);
    const allFailed = Object.values(sources).every(source => source.error && !source.data);

    return (
        <div className="ui-page space-y-5">
            <PageHeader
                eyebrow="Admin control center"
                title="Operational overview"
                description="Revenue, account activity, usage, and AI service health from live operational endpoints."
                actions={<button type="button" className="ui-btn-secondary" onClick={() => loadAll()} disabled={Object.values(sources).some(source => source.loading)}>Refresh data</button>}
            />

            {isInitialLoading && <LoadingState label="Loading operational overview…" />}
            {allFailed && <ErrorState message="No overview source could be reached." onRetry={() => loadAll()} />}

            {!isInitialLoading && !allFailed && (
                <>
                    <section aria-labelledby="overview-metrics" className="space-y-3">
                        <h2 id="overview-metrics" className="ui-section-title">Business pulse</h2>
                        <div className="ui-stat-grid">
                            <StatCard label="Revenue this month" value={revenue ? formatCurrency(revenue.revenue_this_month, 'IDR') : 'Unavailable'} detail={revenue ? `${integer(revenue.approved_this_month)} approved orders` : sources.revenue.error} tone="good" />
                            <StatCard label="Active members" value={revenue ? integer(revenue.active_users) : 'Unavailable'} detail={revenue ? `${integer(revenue.total_users)} total members` : sources.revenue.error} />
                            <StatCard label="Pending orders" value={revenue ? integer(revenue.pending_orders) : 'Unavailable'} detail={revenue ? 'Requires operations review' : sources.revenue.error} tone={revenue?.pending_orders ? 'warn' : 'neutral'} />
                            <StatCard label="Requests recorded" value={usage ? integer(usage.total_requests) : 'Unavailable'} detail={usage ? `${integer(usage.total_tokens)} tokens · ${Number(usage.total_credits || 0).toLocaleString('id-ID')} credits` : sources.usage.error} />
                        </div>
                    </section>

                    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,.75fr)]">
                        <section className="ui-card" aria-labelledby="recent-orders-title">
                            <div className="ui-card-header">
                                <div>
                                    <h2 id="recent-orders-title" className="ui-section-title">Recent approved orders</h2>
                                    <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Latest completed revenue activity.</p>
                                </div>
                                <Link className="ui-btn-secondary" to="/admin/operations">Open operations</Link>
                            </div>
                            {sources.revenue.loading && !revenue ? <div className="p-4"><LoadingState label="Loading orders…" /></div> : sources.revenue.error && !revenue ? <div className="p-4"><ErrorState message={sources.revenue.error} onRetry={() => loadSource('revenue')} /></div> : (
                                <DataTable
                                    rows={revenue?.recent_orders || []}
                                    emptyTitle="No approved orders"
                                    emptyDescription="Approved orders will appear here after they are processed."
                                    columns={[
                                        { key: 'member', label: 'Member', render: row => <div><strong className="block text-slate-900 dark:text-white">{row.user_name}</strong><span className="text-[11px] text-slate-500">{row.user_email}</span></div> },
                                        { key: 'package', label: 'Package', render: row => row.package || '—' },
                                        { key: 'amount', label: 'Amount', render: row => formatCurrency(row.price, 'IDR') },
                                        { key: 'approved', label: 'Approved', render: row => formatDateTime(row.approved_at) },
                                    ]}
                                />
                            )}
                        </section>

                        <section className="ui-card" aria-labelledby="alerts-title">
                            <div className="ui-card-header">
                                <div>
                                    <h2 id="alerts-title" className="ui-section-title">Service alerts</h2>
                                    <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Request failures and non-healthy providers.</p>
                                </div>
                                <Link className="ui-btn-secondary" to="/admin/ai">AI catalog</Link>
                            </div>
                            <div className="ui-card-body space-y-2">
                                {sources.catalog.loading && !sources.catalog.data && !sources.catalog.error ? <LoadingState label="Checking services…" /> : serviceAlerts.length === 0 ? (
                                    providers.length ? <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4"><strong className="text-xs text-emerald-700 dark:text-emerald-300">No active service alerts</strong><p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">All {providers.length} reported providers are enabled and healthy.</p></div> : <EmptyState title="No provider health records" description="Sync the AI catalog to establish provider health records." />
                                ) : serviceAlerts.map(alert => (
                                    <article key={alert.id} className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                        <div className="flex items-center justify-between gap-3"><strong className="text-xs text-slate-900 dark:text-white">{alert.title}</strong><StatusBadge status={alert.status} /></div>
                                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{alert.detail}</p>
                                        {alert.retry && <button type="button" className="mt-2 text-xs font-semibold text-red-600 hover:underline dark:text-red-400" onClick={alert.retry}>Retry source</button>}
                                    </article>
                                ))}
                            </div>
                        </section>
                    </div>

                    <section className="ui-card" aria-labelledby="provider-health-title">
                        <div className="ui-card-header">
                            <div><h2 id="provider-health-title" className="ui-section-title">Provider health</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Non-secret metadata returned by the catalog service.</p></div>
                        </div>
                        {sources.catalog.loading && !sources.catalog.data ? <div className="p-4"><LoadingState label="Loading provider health…" /></div> : sources.catalog.error && !sources.catalog.data ? <div className="p-4"><ErrorState message={sources.catalog.error} onRetry={() => loadSource('catalog')} /></div> : (
                            <DataTable
                                rows={providers}
                                emptyTitle="No providers discovered"
                                emptyDescription="Run a catalog sync from AI Catalog to discover providers and models."
                                columns={[
                                    { key: 'name', label: 'Provider', render: row => <div><strong className="text-slate-900 dark:text-white">{row.name || row.slug}</strong><span className="ml-2 text-[10px] text-slate-400">{row.slug}</span></div> },
                                    { key: 'status', label: 'Status', render: row => <StatusBadge status={row.is_enabled ? row.status : 'offline'} /> },
                                    { key: 'checked', label: 'Last checked', render: row => formatDateTime(row.last_checked_at) },
                                ]}
                            />
                        )}
                    </section>
                </>
            )}
        </div>
    );
}
