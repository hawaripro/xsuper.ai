import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import PageHeader from '../../components/dashboard/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../../components/dashboard/AsyncState';
import StatCard from '../../components/dashboard/StatCard';
import StatusBadge from '../../components/dashboard/StatusBadge';
import DataTable from '../../components/dashboard/DataTable';
import DepositQueue from '../../components/deposit/DepositQueue';
import { apiRequest, formatCurrency, formatDateTime } from '../../lib/api';
import { useLocale } from '../../contexts/LocaleContext';

const endpoints = {
    users: '/api/a/u',
    orders: '/api/a/period?status=pending',
    expiry: '/api/a/stats/expiring?days=7',
    billing: '/api/pricing/settings',
};

const navItems = [
    { label: 'Users', to: '/admin/users' },
    { label: 'Orders', tab: 'orders' },
    { label: 'Deposit', tab: 'deposits' },
    { label: 'Periods', to: '/admin/periods' },
    { label: 'Expiry', tab: 'expiry' },
    { label: 'Billing', tab: 'billing' },
];

function resourceState() {
    return Object.fromEntries(Object.keys(endpoints).map(key => [key, { loading: true, data: null, error: '' }]));
}

function count(value) {
    return new Intl.NumberFormat('id-ID').format(Number(value || 0));
}

export default function Operations() {
    const { t, localizedPath } = useLocale();
    const [tab, setTab] = useState('orders');
    const [resources, setResources] = useState(resourceState);
    const [confirmation, setConfirmation] = useState(null);
    const [mutation, setMutation] = useState({ id: null, error: '', success: '' });
    const [depositRefresh, setDepositRefresh] = useState(0);
    const [depositPendingCount, setDepositPendingCount] = useState(null);

    const load = useCallback(async (key, signal) => {
        setResources(current => ({ ...current, [key]: { ...current[key], loading: true, error: '' } }));
        try {
            const data = await apiRequest(endpoints[key], { signal });
            setResources(current => ({ ...current, [key]: { loading: false, data, error: '' } }));
        } catch (error) {
            if (error?.name === 'AbortError') return;
            setResources(current => ({ ...current, [key]: { ...current[key], loading: false, error: error.message || 'Request failed.' } }));
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        Object.keys(endpoints).forEach(key => load(key, controller.signal));
        return () => controller.abort();
    }, [load]);

    useEffect(() => {
        const controller = new AbortController();
        apiRequest('/api/admin/deposits?status=pending&per_page=1', { signal: controller.signal })
            .then(data => setDepositPendingCount(Number(data?.pending_count || 0)))
            .catch(error => { if (error?.name !== 'AbortError') setDepositPendingCount(null); });
        return () => controller.abort();
    }, [depositRefresh]);

    const pendingOrders = resources.orders.data?.orders || [];
    const expiring = resources.expiry.data?.expiring || [];
    const expired = resources.expiry.data?.expired || [];
    const users = resources.users.data?.users || [];
    const billing = resources.billing.data;
    const activePackages = useMemo(() => Object.values(billing?.duration_packages || {}).filter(item => item.is_active).length, [billing]);
    const activeRates = useMemo(() => (billing?.usage_rates || []).filter(item => item.is_active).length, [billing]);

    const runOrderAction = async () => {
        if (!confirmation) return;
        const current = confirmation;
        setConfirmation(null);
        setMutation({ id: current.order.id, error: '', success: '' });
        try {
            const result = await apiRequest(`/api/a/period/${current.action}/${current.order.id}`, { method: 'POST' });
            setMutation({ id: null, error: '', success: result?.message || `Order ${current.action === 'approve' ? 'approved' : 'rejected'}.` });
            await load('orders');
        } catch (error) {
            setMutation({ id: null, error: error.message || 'Order action failed.', success: '' });
        }
    };

    const currentResource = resources[tab] || { loading: false, data: null, error: '' };

    return (
        <div className="ui-page space-y-5">
            <PageHeader
                eyebrow={t("Operational queues")}
                title={t("Orders & billing operations")}
                description={t("Review live queues, route into mature account workflows, and act on pending orders without duplicate dashboard charts.")}
                actions={<button type="button" className="ui-btn-secondary" onClick={() => { Object.keys(endpoints).forEach(key => load(key)); setDepositRefresh(value => value + 1); }} disabled={Object.values(resources).some(item => item.loading)}>{t("Refresh queues")}</button>}
            />

            <nav aria-label={t("Operations areas")} className="flex flex-wrap gap-2">
                {navItems.map(item => item.to ? (
                    <Link key={item.label} to={item.to} className="ui-btn-secondary">{item.label} ↗</Link>
                ) : (
                    <button key={item.label} type="button" onClick={() => setTab(item.tab)} className={tab === item.tab ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'} aria-pressed={tab === item.tab}>{t(item.label)}</button>
                ))}
            </nav>

            <section aria-labelledby="queue-summary-title" className="space-y-3">
                <h2 id="queue-summary-title" className="ui-section-title">{t("Action queue summary")}</h2>
                <div className="ui-stat-grid">
                    <StatCard label={t("Accounts")} value={resources.users.loading && !resources.users.data ? '…' : resources.users.error && !resources.users.data ? 'Unavailable' : count(users.length)} detail="Open People & Access for account actions" />
                    <StatCard label={t("Pending orders")} value={resources.orders.loading && !resources.orders.data ? '…' : resources.orders.error && !resources.orders.data ? 'Unavailable' : count(resources.orders.data?.pending_count)} detail="Approve or reject below" tone={resources.orders.data?.pending_count ? 'warn' : 'neutral'} />
                    <StatCard label={t("Expiring in 7 days")} value={resources.expiry.loading && !resources.expiry.data ? '…' : resources.expiry.error && !resources.expiry.data ? 'Unavailable' : count(resources.expiry.data?.expiring_count)} detail={`${count(resources.expiry.data?.expired_count)} already expired`} tone={resources.expiry.data?.expiring_count ? 'warn' : 'neutral'} />
                    <StatCard label={t("Pending deposits")} value={depositPendingCount === null ? t('Unavailable') : count(depositPendingCount)} detail={t("Approve or reject in Deposit")} tone={depositPendingCount ? 'warn' : 'neutral'} />
                    <StatCard label={t("Active billing rules")} value={resources.billing.loading && !billing ? '…' : resources.billing.error && !billing ? 'Unavailable' : count(activePackages + activeRates)} detail={`${activePackages} packages · ${activeRates} usage rates`} />
                </div>
            </section>

            {(mutation.error || mutation.success) && (
                <div className={`rounded-xl border p-3 text-xs ${mutation.error ? 'border-red-500/25 bg-red-500/5 text-red-700 dark:text-red-300' : 'border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300'}`} role={mutation.error ? 'alert' : 'status'}>
                    {mutation.error || mutation.success}
                </div>
            )}

            {tab === 'deposits' ? <DepositQueue refreshKey={depositRefresh} onQueueChanged={() => setDepositRefresh(value => value + 1)} /> : (
            <section className="ui-card" aria-live="polite">
                <div className="ui-card-header">
                    <div>
                        <h2 className="ui-section-title">{tab === 'orders' ? 'Pending order review' : tab === 'expiry' ? 'Membership expiry' : 'Billing configuration'}</h2>
                        <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{tab === 'orders' ? 'Actions change the live order and membership state.' : tab === 'expiry' ? 'Accounts approaching or past their expiry timestamp.' : 'Read-only operational summary; edit through Pricing Settings.'}</p>
                    </div>
                    {tab === 'orders' && <Link to={localizedPath("/admin/overview")} className="ui-btn-secondary">{t("Full period queue")}</Link>}
                    {tab === 'billing' && <Link to={localizedPath("/admin/settings")} className="ui-btn-secondary">{t("Edit pricing")}</Link>}
                </div>

                {currentResource.loading && !currentResource.data ? <div className="p-4"><LoadingState label={`Loading ${tab}…`} /></div> : currentResource.error && !currentResource.data ? <div className="p-4"><ErrorState message={currentResource.error} onRetry={() => load(tab)} /></div> : tab === 'orders' ? (
                    <DataTable
                        rows={pendingOrders}
                        emptyTitle="No pending orders"
                        emptyDescription="There are no membership orders waiting for review."
                        columns={[
                            { key: 'member', label: 'Member', render: row => <div><strong className="block text-slate-900 dark:text-white">{row.user_name}</strong><span className="text-[11px] text-slate-500">{row.user_email}</span></div> },
                            { key: 'package', label: 'Package', render: row => <div><span className="block">{row.package}</span><span className="text-[11px] text-slate-500">{row.days} days</span></div> },
                            { key: 'price', label: 'Amount', render: row => formatCurrency(row.price, 'IDR') },
                            { key: 'created', label: 'Submitted', render: row => formatDateTime(row.created_at) },
                            { key: 'status', label: 'Status', render: row => <StatusBadge status={row.status} /> },
                            { key: 'actions', label: 'Actions', render: row => <div className="flex flex-wrap gap-2"><button type="button" className="ui-btn-secondary" disabled={mutation.id === row.id} onClick={() => setConfirmation({ action: 'approve', order: row })}>{t("Approve")}</button><button type="button" className="ui-btn-secondary text-red-600 dark:text-red-400" disabled={mutation.id === row.id} onClick={() => setConfirmation({ action: 'reject', order: row })}>{t("Reject")}</button></div> },
                        ]}
                    />
                ) : tab === 'expiry' ? (
                    <div className="grid gap-5 p-4 lg:grid-cols-2">
                        <div>
                            <h3 className="mb-3 text-xs font-semibold text-slate-900 dark:text-white">Expiring soon ({expiring.length})</h3>
                            {expiring.length ? <div className="space-y-2">{expiring.map(user => <article key={user.id} className="rounded-xl border border-slate-200 p-3 dark:border-white/10"><div className="flex items-start justify-between gap-3"><div><strong className="block text-xs text-slate-900 dark:text-white">{user.name}</strong><span className="text-[11px] text-slate-500">{user.email}</span></div><StatusBadge status="pending" /></div><p className="mt-2 text-[11px] text-slate-500">{user.days_remaining} days · {formatDateTime(user.expires_at)}</p></article>)}</div> : <EmptyState title={t("No upcoming expiries")} description={t("No member expires in the next seven days.")} />}
                        </div>
                        <div>
                            <h3 className="mb-3 text-xs font-semibold text-slate-900 dark:text-white">Recently expired ({expired.length})</h3>
                            {expired.length ? <div className="space-y-2">{expired.map(user => <article key={user.id} className="rounded-xl border border-slate-200 p-3 dark:border-white/10"><div className="flex items-start justify-between gap-3"><div><strong className="block text-xs text-slate-900 dark:text-white">{user.name}</strong><span className="text-[11px] text-slate-500">{user.email}</span></div><StatusBadge status="expired" /></div><p className="mt-2 text-[11px] text-slate-500">Expired {user.days_expired} days ago · {formatDateTime(user.expires_at)}</p></article>)}</div> : <EmptyState title={t("No expired accounts")} description={t("No expired members were returned by the current queue.")} />}
                        </div>
                    </div>
                ) : (
                    <div className="grid gap-5 p-4 lg:grid-cols-2">
                        <div>
                            <h3 className="mb-3 text-xs font-semibold text-slate-900 dark:text-white">{t("Duration packages")}</h3>
                            {Object.keys(billing?.duration_packages || {}).length ? <div className="space-y-2">{Object.entries(billing.duration_packages).map(([key, item]) => <article key={key} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 dark:border-white/10"><div><strong className="block text-xs text-slate-900 dark:text-white">{item.label || key}</strong><span className="text-[11px] text-slate-500">{formatCurrency(item.price_idr, 'IDR')} · {item.days} days</span></div><StatusBadge status={item.is_active ? 'active' : 'offline'} /></article>)}</div> : <EmptyState title={t("No duration packages")} description={t("Configure at least one duration package in Pricing Settings.")} />}
                        </div>
                        <div>
                            <h3 className="mb-3 text-xs font-semibold text-slate-900 dark:text-white">{t("Usage rates")}</h3>
                            {billing?.usage_rates?.length ? <div className="space-y-2">{billing.usage_rates.map(rate => <article key={rate.id} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 dark:border-white/10"><div><strong className="block text-xs text-slate-900 dark:text-white">{rate.label}</strong><span className="text-[11px] text-slate-500">{rate.service} · {rate.model || 'default'} · {rate.meter}</span></div><StatusBadge status={rate.is_active ? 'active' : 'offline'} /></article>)}</div> : <EmptyState title={t("No usage rates")} description={t("Add a rate before usage billing can be activated.")} />}
                        </div>
                    </div>
                )}
            </section>
            )}

            {confirmation && (
                <div className="fixed inset-0 z-[80] grid place-items-center bg-slate-950/55 p-4" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) setConfirmation(null); }}>
                    <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="order-confirm-title">
                        <h2 id="order-confirm-title" className="text-base font-bold text-slate-900 dark:text-white">{confirmation.action === 'approve' ? 'Approve order?' : 'Reject order?'}</h2>
                        <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">{confirmation.action === 'approve' ? `This adds ${confirmation.order.days} days to ${confirmation.order.user_name}'s membership.` : `This rejects ${confirmation.order.user_name}'s pending ${confirmation.order.package} order.`}</p>
                        <div className="mt-5 flex justify-end gap-2"><button type="button" className="ui-btn-secondary" onClick={() => setConfirmation(null)}>{t("Cancel")}</button><button type="button" className="ui-btn-primary min-h-10 px-4 text-xs" onClick={runOrderAction}>Confirm {confirmation.action}</button></div>
                    </div>
                </div>
            )}
        </div>
    );
}
