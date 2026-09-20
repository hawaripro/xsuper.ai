import { useCallback, useEffect, useMemo, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import PageHeader from '../../components/dashboard/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../../components/dashboard/AsyncState';
import StatCard from '../../components/dashboard/StatCard';
import DataTable from '../../components/dashboard/DataTable';
import { apiRequest, formatCurrency, formatDateTime } from '../../lib/api';

const periods = [7, 30, 90, 365];
const initialResource = { data: null, loading: true, error: '' };
const initialFilters = { action: '', actor_id: '', subject_type: '', subject_id: '', from: '', to: '', per_page: '50' };

function integer(value) {
    return new Intl.NumberFormat('id-ID').format(Number(value || 0));
}

function csvCell(value) {
    let text = value == null ? '' : String(value);
    if (/^[=+@-]/.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
}

function FunnelStep({ label, value, baseline, accent }) {
    const { t } = useLocale();
    const width = baseline > 0 ? Math.min(100, Math.max(value > 0 ? 3 : 0, (Number(value || 0) / baseline) * 100)) : 0;
    const rate = baseline > 0 ? Math.round((Number(value || 0) / baseline) * 100) : 0;
    return <article><div className="mb-1.5 flex items-end justify-between gap-3"><div><strong className="block text-xs text-slate-900 dark:text-white">{label}</strong><span className="text-[10px] text-slate-500">{rate}% {t('of registered members')}</span></div><span className="text-sm font-bold tabular-nums text-slate-900 dark:text-white">{integer(value)}</span></div><div className="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/[.06]"><div className={`h-full rounded-full ${accent}`} style={{ width: `${width}%` }} /></div></article>;
}

export default function SystemActivity() {
    const { t } = useLocale();
    const [section, setSection] = useState('analytics');
    const [days, setDays] = useState(30);
    const [funnel, setFunnel] = useState(initialResource);
    const [audit, setAudit] = useState(initialResource);
    const [actors, setActors] = useState({ data: [], loading: true, error: '' });
    const [filters, setFilters] = useState(initialFilters);
    const [appliedFilters, setAppliedFilters] = useState(initialFilters);
    const [page, setPage] = useState(1);
    const [validationError, setValidationError] = useState('');

    const loadFunnel = useCallback(async signal => {
        setFunnel(current => ({ ...current, loading: true, error: '' }));
        try {
            const data = await apiRequest(`/api/admin/analytics/funnel?days=${days}`, { signal });
            setFunnel({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setFunnel(current => ({ ...current, loading: false, error: error.message || 'Analytics funnel could not be loaded.' }));
        }
    }, [days]);

    const loadAudit = useCallback(async signal => {
        setAudit(current => ({ ...current, loading: true, error: '' }));
        const query = new URLSearchParams({ page: String(page), per_page: appliedFilters.per_page });
        Object.entries(appliedFilters).forEach(([key, value]) => {
            if (key !== 'per_page' && value) query.set(key, value);
        });
        try {
            const data = await apiRequest(`/api/admin/audit?${query}`, { signal });
            setAudit({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setAudit(current => ({ ...current, loading: false, error: error.message || 'Audit events could not be loaded.' }));
        }
    }, [appliedFilters, page]);

    const loadActors = useCallback(async signal => {
        setActors(current => ({ ...current, loading: true, error: '' }));
        try {
            const data = await apiRequest('/api/a/u', { signal });
            setActors({ data: data.users || [], loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setActors({ data: [], loading: false, error: error.message || 'Actor list could not be loaded.' });
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        loadFunnel(controller.signal);
        return () => controller.abort();
    }, [loadFunnel]);

    useEffect(() => {
        const controller = new AbortController();
        loadAudit(controller.signal);
        return () => controller.abort();
    }, [loadAudit]);

    useEffect(() => {
        const controller = new AbortController();
        loadActors(controller.signal);
        return () => controller.abort();
    }, [loadActors]);

    const applyFilters = event => {
        event.preventDefault();
        if (filters.from && filters.to && filters.from > filters.to) {
            setValidationError(t('Start date must be on or before end date.'));
            return;
        }
        if (filters.subject_id && !/^\d+$/.test(filters.subject_id)) {
            setValidationError(t('Subject ID must be a positive numeric ID.'));
            return;
        }
        setValidationError('');
        setPage(1);
        setAppliedFilters(filters);
    };

    const resetFilters = () => {
        setValidationError('');
        setFilters(initialFilters);
        setPage(1);
        setAppliedFilters(initialFilters);
    };

    const exportCsv = () => {
        const rows = audit.data?.data || [];
        if (!rows.length) return;
        const header = ['timestamp', 'action', 'actor_id', 'actor_name', 'actor_email', 'subject_type', 'subject_id', 'ip_address', 'metadata'];
        const body = rows.map(row => [
            row.created_at,
            row.action,
            row.actor_id,
            row.actor?.name,
            row.actor?.email,
            row.subject_type,
            row.subject_id,
            row.ip_address,
            JSON.stringify(row.metadata || {}),
        ]);
        const csv = `\uFEFF${[header, ...body].map(record => record.map(csvCell).join(',')).join('\r\n')}`;
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `xsuper-audit-page-${audit.data.current_page || page}-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    };

    const counts = funnel.data?.counts || {};
    const steps = funnel.data?.funnel || {};
    const baseline = Number(steps.registered_users || 0);
    const events = useMemo(() => Object.entries(funnel.data?.event_counts || {}).sort((a, b) => Number(b[1]) - Number(a[1])), [funnel.data]);
    const auditRows = audit.data?.data || [];
    const hasFilters = Object.entries(appliedFilters).some(([key, value]) => key !== 'per_page' && value);

    return (
        <div className="ui-page space-y-5">
            <PageHeader
                eyebrow={t("Operational intelligence")}
                title={t("System activity")}
                description={t("Server-recorded conversion signals and authorized audit events. Export contains only the currently loaded, filtered page.")}
                actions={<button type="button" className="ui-btn-secondary" onClick={() => { loadFunnel(); loadAudit(); }} disabled={funnel.loading || audit.loading}>{t("Refresh")}</button>}
            />

            <div className="flex flex-wrap gap-2" role="tablist" aria-label={t("System activity sections")}><button type="button" role="tab" aria-selected={section === 'analytics'} onClick={() => setSection('analytics')} className={section === 'analytics' ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'}>{t("Analytics funnel")}</button><button type="button" role="tab" aria-selected={section === 'audit'} onClick={() => setSection('audit')} className={section === 'audit' ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'}>{t("Audit log")}</button></div>

            {section === 'analytics' && (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="ui-section-title">{t("Measured funnel")}</h2><div className="flex flex-wrap gap-2" aria-label={t("Analytics period")}>{periods.map(period => <button key={period} type="button" className={days === period ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'} onClick={() => setDays(period)}>{period === 365 ? t('1 year') : `${period} ${t('days')}`}</button>)}</div></div>
                    {funnel.loading && !funnel.data ? <LoadingState label={t("Loading analytics funnel…")} /> : funnel.error && !funnel.data ? <ErrorState message={funnel.error} onRetry={() => loadFunnel()} /> : <>
                        <div className="ui-stat-grid"><StatCard label={t("Tracked events")} value={integer(counts.events)} detail={`${integer(counts.unique_event_users)} ${t('identified event users')}`} /><StatCard label={t("New members")} value={integer(counts.new_users)} detail={`${integer(counts.total_users)} ${t('total members')}`} /><StatCard label={t("Approved orders")} value={integer(counts.approved_orders)} detail={`${integer(counts.orders)} ${t('orders created')}`} tone="good" /><StatCard label={t("Revenue")} value={formatCurrency(counts.revenue_idr, 'IDR')} detail={`${t('Selected period')}: ${funnel.data?.period?.days || days} ${t('days')}`} /></div>
                        <div className="grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(300px,.8fr)]">
                            <section className="ui-card" aria-labelledby="funnel-title"><div className="ui-card-header"><div><h3 id="funnel-title" className="ui-section-title">{t("Conversion stages")}</h3><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{t("Counts are returned by the authorized analytics aggregate.")}</p></div></div><div className="ui-card-body space-y-5">{baseline > 0 ? <><FunnelStep label={t("Registered members")} value={steps.registered_users} baseline={baseline} accent="bg-slate-500" /><FunnelStep label={t("Engaged members")} value={steps.engaged_users} baseline={baseline} accent="bg-blue-500" /><FunnelStep label={t("Orders created")} value={steps.orders_created} baseline={baseline} accent="bg-amber-500" /><FunnelStep label={t("Orders approved")} value={steps.orders_approved} baseline={baseline} accent="bg-emerald-500" /></> : <EmptyState title={t("No registered members in funnel")} description={t("The selected period returned no registered-member baseline.")} />}</div></section>
                            <section className="ui-card" aria-labelledby="events-title"><div className="ui-card-header"><div><h3 id="events-title" className="ui-section-title">{t("Event ledger")}</h3><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{t("Allowlisted event totals for the period.")}</p></div></div><div className="ui-card-body">{events.length ? <div className="space-y-2">{events.map(([name, value]) => <div key={name} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 dark:border-white/10"><span className="font-mono text-[11px] text-slate-700 dark:text-slate-300">{name}</span><strong className="tabular-nums text-xs text-slate-900 dark:text-white">{integer(value)}</strong></div>)}</div> : <EmptyState title={t("No tracked events")} description={t("No allowlisted analytics events were recorded in this period.")} />}</div></section>
                        </div>
                    </>}
                </>
            )}

            {section === 'audit' && (
                <section className="ui-card" aria-labelledby="audit-title">
                    <div className="ui-card-header"><div><h2 id="audit-title" className="ui-section-title">{t("Authorized audit log")}</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{t("Filter server-side before reviewing or exporting current page data.")}</p></div><button type="button" className="ui-btn-secondary" onClick={exportCsv} disabled={!auditRows.length || audit.loading}>{t("Export current page CSV")}</button></div>
                    <form className="grid gap-3 border-b border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-4 dark:border-white/10" onSubmit={applyFilters} noValidate>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t("Action contains")}</span><input className="ui-input min-h-10" value={filters.action} onChange={event => setFilters(current => ({ ...current, action: event.target.value }))} placeholder="feedback.moderated" /></label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t("Actor")}</span><select className="ui-input min-h-10" value={filters.actor_id} onChange={event => setFilters(current => ({ ...current, actor_id: event.target.value }))} disabled={actors.loading}><option value="">{t("All actors")}</option>{actors.data.map(actor => <option key={actor.id} value={actor.id}>{actor.name} · {actor.email}</option>)}</select>{actors.error && <button type="button" className="mt-1 text-[11px] font-normal text-red-600 underline" onClick={() => loadActors()}>{t('Actor list failed — retry')}</button>}</label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t("Subject type")}</span><input className="ui-input min-h-10" value={filters.subject_type} onChange={event => setFilters(current => ({ ...current, subject_type: event.target.value }))} placeholder="App\\Models\\Feedback" /></label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t("Subject ID")}</span><input className="ui-input min-h-10" inputMode="numeric" value={filters.subject_id} onChange={event => setFilters(current => ({ ...current, subject_id: event.target.value }))} /></label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t("From")}</span><input className="ui-input min-h-10" type="date" value={filters.from} onChange={event => setFilters(current => ({ ...current, from: event.target.value }))} /></label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t('To')}</span><input className="ui-input min-h-10" type="date" value={filters.to} onChange={event => setFilters(current => ({ ...current, to: event.target.value }))} /></label>
                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{t("Rows per page")}</span><select className="ui-input min-h-10" value={filters.per_page} onChange={event => setFilters(current => ({ ...current, per_page: event.target.value }))}><option value="20">20</option><option value="50">50</option><option value="100">100</option></select></label>
                        <div className="flex items-end gap-2"><button type="submit" className="ui-btn-primary min-h-10 px-4 text-xs">{t("Apply filters")}</button><button type="button" className="ui-btn-secondary" onClick={resetFilters} disabled={!hasFilters}>{t("Clear")}</button></div>
                        {validationError && <p className="text-xs text-red-600 dark:text-red-400 md:col-span-2 xl:col-span-4" role="alert">{validationError}</p>}
                    </form>
                    {audit.loading && !audit.data ? <div className="p-4"><LoadingState label={t("Loading audit events…")} /></div> : audit.error && !audit.data ? <div className="p-4"><ErrorState message={audit.error} onRetry={() => loadAudit()} /></div> : <>
                        <DataTable rows={auditRows} emptyTitle={t('No matching audit events')} emptyDescription={hasFilters ? t('Broaden or clear filters to inspect other authorized events.') : t('Operational actions will appear after they are recorded.')} columns={[
                            { key: 'time', label: t('Time'), render: row => formatDateTime(row.created_at) },
                            { key: 'actor', label: t('Actor'), render: row => <div><strong className="block text-slate-900 dark:text-white">{row.actor?.name || t('System')}</strong><span className="text-[11px] text-slate-500">{row.actor?.email || `${t('Actor')} ${row.actor_id || '—'}`}</span></div> },
                            { key: 'action', label: t('Action'), render: row => <span className="font-mono text-[11px]">{row.action}</span> },
                            { key: 'subject', label: t('Subject'), render: row => <div><span className="block max-w-xs truncate" title={row.subject_type}>{row.subject_type?.split('\\').pop() || '—'}</span><span className="text-[11px] text-slate-500">ID {row.subject_id || '—'}</span></div> },
                            { key: 'metadata', label: t('Metadata'), render: row => <details className="max-w-sm"><summary className="cursor-pointer text-[11px] font-semibold text-red-600 dark:text-red-400">{t("Inspect")}</summary><pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-100 p-2 text-[10px] dark:bg-white/[.05]">{JSON.stringify(row.metadata || {}, null, 2)}</pre></details> },
                        ]} />
                        {Number(audit.data?.last_page || 1) > 1 && <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-3 dark:border-white/10"><span className="text-[11px] text-slate-500">{integer(audit.data.total)} authorized events · page {audit.data.current_page} of {audit.data.last_page}</span><div className="flex gap-2"><button type="button" className="ui-btn-secondary" disabled={page <= 1 || audit.loading} onClick={() => setPage(current => current - 1)}>{t("Previous")}</button><button type="button" className="ui-btn-secondary" disabled={page >= audit.data.last_page || audit.loading} onClick={() => setPage(current => current + 1)}>{t("Next")}</button></div></div>}
                    </>}
                </section>
            )}
        </div>
    );
}
