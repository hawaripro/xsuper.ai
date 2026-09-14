import { useCallback, useEffect, useMemo, useState } from 'react';
import PageHeader from '../../components/dashboard/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../../components/dashboard/AsyncState';
import StatCard from '../../components/dashboard/StatCard';
import StatusBadge from '../../components/dashboard/StatusBadge';
import DataTable from '../../components/dashboard/DataTable';
import { apiRequest, formatCurrency, formatDateTime } from '../../lib/api';

const queueStatuses = ['', 'pending', 'processing', 'completed', 'failed'];
const emptyResource = { data: null, loading: true, error: '' };

function count(value) {
    return new Intl.NumberFormat('id-ID').format(Number(value || 0));
}

function ConfirmDialog({ title, description, confirmLabel, onConfirm, onCancel, busy }) {
    return <div className="fixed inset-0 z-[90] grid place-items-center bg-slate-950/55 p-4" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget && !busy) onCancel(); }}><div role="dialog" aria-modal="true" aria-labelledby="ai-confirm-title" className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900"><h2 id="ai-confirm-title" className="text-base font-bold text-slate-900 dark:text-white">{title}</h2><p className="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">{description}</p><div className="mt-5 flex justify-end gap-2"><button type="button" className="ui-btn-secondary" onClick={onCancel} disabled={busy}>Cancel</button><button type="button" className="ui-btn-primary min-h-10 px-4 text-xs" onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div></div></div>;
}

export default function AICatalog() {
    const [catalog, setCatalog] = useState(emptyResource);
    const [queue, setQueue] = useState(emptyResource);
    const [section, setSection] = useState('catalog');
    const [queueType, setQueueType] = useState('images');
    const [queueStatus, setQueueStatus] = useState('');
    const [search, setSearch] = useState('');
    const [providerFilter, setProviderFilter] = useState('');
    const [confirmation, setConfirmation] = useState(null);
    const [modelEditor, setModelEditor] = useState(null);
    const [mutation, setMutation] = useState({ busy: false, error: '', success: '', fields: {} });

    const loadCatalog = useCallback(async signal => {
        setCatalog(current => ({ ...current, loading: true, error: '' }));
        try {
            const data = await apiRequest('/api/admin/ai/catalog', { signal });
            setCatalog({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setCatalog(current => ({ ...current, loading: false, error: error.message || 'AI catalog could not be loaded.' }));
        }
    }, []);

    const loadQueue = useCallback(async signal => {
        setQueue(current => ({ ...current, loading: true, error: '' }));
        try {
            const query = new URLSearchParams({ limit: '100' });
            if (queueStatus) query.set('status', queueStatus);
            const data = await apiRequest(`/api/admin/media/queue?${query}`, { signal });
            setQueue({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setQueue(current => ({ ...current, loading: false, error: error.message || 'Global media queue could not be loaded.' }));
        }
    }, [queueStatus]);

    useEffect(() => {
        const controller = new AbortController();
        loadCatalog(controller.signal);
        return () => controller.abort();
    }, [loadCatalog]);

    useEffect(() => {
        const controller = new AbortController();
        loadQueue(controller.signal);
        return () => controller.abort();
    }, [loadQueue]);

    const providers = catalog.data?.providers || [];
    const models = catalog.data?.models || [];
    const visibleModels = useMemo(() => {
        const needle = search.trim().toLowerCase();
        return models.filter(model => {
            const providerSlug = model.provider?.slug || model.provider?.name || '';
            const providerId = String(model.provider?.id ?? model.provider_id ?? '');
            const matchesProvider = !providerFilter || providerId === providerFilter;
            const matchesSearch = !needle || [model.model_id, model.display_name, model.category, model.tier, providerSlug].some(value => String(value || '').toLowerCase().includes(needle));
            return matchesProvider && matchesSearch;
        });
    }, [models, providerFilter, search]);
    const enabledModels = models.filter(model => model.is_enabled).length;
    const unhealthyProviders = providers.filter(provider => !provider.is_enabled || !['online', 'healthy', 'active'].includes(String(provider.status).toLowerCase())).length;
    const images = queue.data?.images || [];
    const videos = queue.data?.videos || [];
    const activeJobs = [...images, ...videos].filter(job => ['pending', 'processing'].includes(job.status)).length;
    const failedJobs = [...images, ...videos].filter(job => job.status === 'failed').length;

    const syncCatalog = async () => {
        setMutation({ busy: true, error: '', success: '', fields: {} });
        try {
            const result = await apiRequest('/api/admin/ai/catalog/sync', { method: 'POST' });
            setConfirmation(null);
            setMutation({ busy: false, error: '', fields: {}, success: `${result.message || 'Catalog synchronized.'} ${count(result.synced_models)} models reported.` });
            await loadCatalog();
        } catch (error) {
            setConfirmation(null);
            setMutation({ busy: false, error: error.message || 'Catalog sync failed.', success: '', fields: error.details?.errors || {} });
        }
    };

    const updateModel = async (model, patch, success) => {
        setMutation({ busy: true, error: '', success: '', fields: {} });
        try {
            await apiRequest(`/api/admin/ai/models/${model.id}`, { method: 'PATCH', body: patch });
            setConfirmation(null);
            setModelEditor(null);
            setMutation({ busy: false, error: '', fields: {}, success });
            await loadCatalog();
        } catch (error) {
            setConfirmation(null);
            setMutation({ busy: false, error: error.message || 'Model metadata could not be updated.', success: '', fields: error.details?.errors || {} });
        }
    };

    const saveModelName = event => {
        event.preventDefault();
        const displayName = modelEditor.display_name.trim();
        if (!displayName) {
            setMutation(current => ({ ...current, fields: { display_name: 'Display name is required.' }, error: '', success: '' }));
            return;
        }
        if (displayName.length > 160) {
            setMutation(current => ({ ...current, fields: { display_name: 'Use at most 160 characters.' }, error: '', success: '' }));
            return;
        }
        updateModel(modelEditor, { display_name: displayName }, 'Model display name updated.');
    };

    const renderCost = job => job.cost_microusd == null ? '—' : formatCurrency(Number(job.cost_microusd) / 1_000_000, 'USD');
    const queueRows = queueType === 'images' ? images : videos;

    return (
        <div className="ui-page space-y-5">
            <PageHeader
                eyebrow="AI operations"
                title="AI catalog & media queue"
                description="Synchronize upstream model metadata, control availability, inspect provider health, and monitor global image/video work without exposing credentials."
                actions={<button type="button" className="ui-btn-primary min-h-10 px-4 text-xs" onClick={() => setConfirmation({ type: 'sync' })} disabled={mutation.busy}>Sync catalog</button>}
            />

            {(mutation.error || mutation.success) && <div className={`rounded-xl border p-3 text-xs ${mutation.error ? 'border-red-500/25 bg-red-500/5 text-red-700 dark:text-red-300' : 'border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300'}`} role={mutation.error ? 'alert' : 'status'}>{mutation.error || mutation.success}</div>}

            <section aria-labelledby="ai-summary-title" className="space-y-3"><h2 id="ai-summary-title" className="ui-section-title">Operational summary</h2><div className="ui-stat-grid"><StatCard label="Providers" value={catalog.loading && !catalog.data ? '…' : catalog.error && !catalog.data ? 'Unavailable' : count(providers.length)} detail={`${unhealthyProviders} need attention`} tone={unhealthyProviders ? 'warn' : 'good'} /><StatCard label="Enabled models" value={catalog.loading && !catalog.data ? '…' : catalog.error && !catalog.data ? 'Unavailable' : count(enabledModels)} detail={`${count(models.length)} synchronized models`} /><StatCard label="Active media jobs" value={queue.loading && !queue.data ? '…' : queue.error && !queue.data ? 'Unavailable' : count(activeJobs)} detail="Pending and processing globally" tone={activeJobs ? 'warn' : 'neutral'} /><StatCard label="Failed media jobs" value={queue.loading && !queue.data ? '…' : queue.error && !queue.data ? 'Unavailable' : count(failedJobs)} detail="Current status filter response" tone={failedJobs ? 'bad' : 'good'} /></div></section>

            <div className="flex flex-wrap gap-2" role="tablist" aria-label="AI administration sections"><button type="button" role="tab" aria-selected={section === 'catalog'} className={section === 'catalog' ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'} onClick={() => setSection('catalog')}>Catalog & health</button><button type="button" role="tab" aria-selected={section === 'queue'} className={section === 'queue' ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'} onClick={() => setSection('queue')}>Global media queue</button></div>

            {section === 'catalog' && (
                <>
                    <section className="ui-card" aria-labelledby="provider-title"><div className="ui-card-header"><div><h2 id="provider-title" className="ui-section-title">Provider health</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Health and capabilities only; credentials are never returned or editable.</p></div><button type="button" className="ui-btn-secondary" onClick={() => loadCatalog()} disabled={catalog.loading}>Refresh</button></div>
                        {catalog.loading && !catalog.data ? <div className="p-4"><LoadingState label="Loading provider health…" /></div> : catalog.error && !catalog.data ? <div className="p-4"><ErrorState message={catalog.error} onRetry={() => loadCatalog()} /></div> : <DataTable rows={providers} emptyTitle="No provider profiles" emptyDescription="Run catalog sync to discover the configured upstream provider." columns={[
                            { key: 'provider', label: 'Provider', render: row => <div><strong className="block text-slate-900 dark:text-white">{row.name || row.slug}</strong><span className="text-[11px] text-slate-500">{row.slug}</span></div> },
                            { key: 'status', label: 'Health', render: row => <StatusBadge status={row.is_enabled ? row.status : 'offline'} /> },
                            { key: 'capabilities', label: 'Capabilities', render: row => <span className="block max-w-lg whitespace-normal text-[11px]">{Array.isArray(row.capabilities) ? row.capabilities.join(', ') : Object.keys(row.capabilities || {}).join(', ') || '—'}</span> },
                            { key: 'checked', label: 'Last check', render: row => formatDateTime(row.last_checked_at) },
                        ]} />}
                    </section>

                    <section className="ui-card" aria-labelledby="models-title"><div className="ui-card-header"><div><h2 id="models-title" className="ui-section-title">Model metadata</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Enable availability and maintain customer-facing labels.</p></div></div><div className="flex flex-wrap gap-2 border-b border-slate-200 p-3 dark:border-white/10"><input className="ui-input min-h-10 min-w-52 flex-1" type="search" placeholder="Search model, category, tier…" value={search} onChange={event => setSearch(event.target.value)} /><select className="ui-input min-h-10 w-auto" value={providerFilter} onChange={event => setProviderFilter(event.target.value)}><option value="">All providers</option>{providers.map(provider => <option key={provider.id} value={String(provider.id)}>{provider.name || provider.slug}</option>)}</select></div>
                        {catalog.loading && !catalog.data ? <div className="p-4"><LoadingState label="Loading models…" /></div> : catalog.error && !catalog.data ? <div className="p-4"><ErrorState message={catalog.error} onRetry={() => loadCatalog()} /></div> : <DataTable rows={visibleModels} emptyTitle="No matching models" emptyDescription={models.length ? 'Change the search or provider filter.' : 'Run a catalog sync to discover upstream models.'} columns={[
                            { key: 'model', label: 'Model', render: row => <div><strong className="block text-slate-900 dark:text-white">{row.display_name || row.model_id}</strong><span className="font-mono text-[10px] text-slate-500">{row.model_id}</span></div> },
                            { key: 'provider', label: 'Provider', render: row => row.provider?.name || row.provider?.slug || '—' },
                            { key: 'classification', label: 'Class', render: row => <div><span className="block">{row.category || 'Unclassified'}</span><span className="text-[11px] text-slate-500">{row.tier || 'No tier'}</span></div> },
                            { key: 'rate', label: 'Rate', render: row => row.rate ? <div><span className="block">{formatCurrency(row.rate.price_idr, 'IDR')}</span><span className="text-[11px] text-slate-500">{formatCurrency(row.rate.price_usd, 'USD')} / {row.rate.unit}</span></div> : <span className="text-amber-600 dark:text-amber-400">Not configured</span> },
                            { key: 'state', label: 'Availability', render: row => <StatusBadge status={row.is_enabled ? 'active' : 'offline'} /> },
                            { key: 'actions', label: 'Actions', render: row => <div className="flex flex-wrap gap-2"><button type="button" className="ui-btn-secondary" onClick={() => { setMutation({ busy: false, error: '', success: '', fields: {} }); setModelEditor({ ...row, display_name: row.display_name || '' }); }}>Edit label</button><button type="button" className="ui-btn-secondary" onClick={() => setConfirmation({ type: 'toggle', model: row })}>{row.is_enabled ? 'Disable' : 'Enable'}</button></div> },
                        ]} />}
                    </section>
                </>
            )}

            {section === 'queue' && (
                <section className="ui-card" aria-labelledby="queue-title"><div className="ui-card-header"><div><h2 id="queue-title" className="ui-section-title">Global media queue</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Authorized cross-account job status, billing, outputs, and operational failures.</p></div><button type="button" className="ui-btn-secondary" onClick={() => loadQueue()} disabled={queue.loading}>Refresh</button></div><div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-white/10"><button type="button" className={queueType === 'images' ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'} onClick={() => setQueueType('images')}>Images ({images.length})</button><button type="button" className={queueType === 'videos' ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'} onClick={() => setQueueType('videos')}>Videos ({videos.length})</button><select className="ui-input ml-auto min-h-10 w-auto" value={queueStatus} onChange={event => setQueueStatus(event.target.value)}>{queueStatuses.map(status => <option key={status || 'all'} value={status}>{status ? status.replaceAll('_', ' ') : 'All statuses'}</option>)}</select></div>
                    {queue.loading && !queue.data ? <div className="p-4"><LoadingState label="Loading global media jobs…" /></div> : queue.error && !queue.data ? <div className="p-4"><ErrorState message={queue.error} onRetry={() => loadQueue()} /></div> : <DataTable rows={queueRows} rowKey="job_id" emptyTitle={`No ${queueType} jobs`} emptyDescription={queueStatus ? `No ${queueStatus} jobs match the current queue filter.` : `No global ${queueType} jobs have been recorded.`} columns={[
                        { key: 'job', label: 'Job', render: row => <div><strong className="block font-mono text-[11px] text-slate-900 dark:text-white">{row.job_id}</strong><span className="text-[11px] text-slate-500">{row.model}</span></div> },
                        { key: 'user', label: 'Member', render: row => <div><span className="block">{row.user?.name || 'Unknown'}</span><span className="text-[11px] text-slate-500">{row.user?.email}</span></div> },
                        { key: 'prompt', label: 'Prompt', render: row => <span className="block max-w-sm whitespace-normal" title={row.prompt}>{row.prompt || '—'}</span> },
                        { key: 'spec', label: 'Spec', render: row => queueType === 'images' ? `${row.size || '—'} · ${row.n || 1} image(s)` : `${row.aspect_ratio || '—'} · ${row.duration || '—'}` },
                        { key: 'status', label: 'Status', render: row => <div><StatusBadge status={row.status} />{row.error && <p className="mt-1 max-w-xs whitespace-normal text-[10px] text-red-600 dark:text-red-400">{row.error}</p>}</div> },
                        { key: 'billing', label: 'Billing', render: row => <div><span className="block">{renderCost(row)}</span><StatusBadge status={row.billing_status || 'unknown'} /></div> },
                        { key: 'created', label: 'Created', render: row => formatDateTime(row.created_at) },
                    ]} />}
                </section>
            )}

            {modelEditor && <div className="fixed inset-0 z-[80] grid place-items-center bg-slate-950/55 p-4"><form className="w-full max-w-md space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900" onSubmit={saveModelName}><div><h2 className="text-base font-bold text-slate-900 dark:text-white">Edit model label</h2><p className="mt-1 font-mono text-[11px] text-slate-500">{modelEditor.model_id}</p></div><label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">Display name</span><input className="ui-input min-h-10" maxLength={160} value={modelEditor.display_name} onChange={event => setModelEditor(current => ({ ...current, display_name: event.target.value }))} aria-invalid={Boolean(mutation.fields.display_name)} />{mutation.fields.display_name && <span className="mt-1 block text-[11px] font-normal text-red-600 dark:text-red-400">{mutation.fields.display_name}</span>}</label><div className="flex justify-end gap-2"><button type="button" className="ui-btn-secondary" onClick={() => setModelEditor(null)} disabled={mutation.busy}>Cancel</button><button className="ui-btn-primary min-h-10 px-4 text-xs" disabled={mutation.busy}>{mutation.busy ? 'Saving…' : 'Save label'}</button></div></form></div>}

            {confirmation?.type === 'sync' && <ConfirmDialog title="Synchronize upstream catalog?" description="This calls the configured upstream model endpoint and updates provider/model metadata. Existing enablement metadata is retained by the server contract; no credential is displayed." confirmLabel="Start sync" onConfirm={syncCatalog} onCancel={() => setConfirmation(null)} busy={mutation.busy} />}
            {confirmation?.type === 'toggle' && <ConfirmDialog title={`${confirmation.model.is_enabled ? 'Disable' : 'Enable'} model?`} description={`${confirmation.model.display_name || confirmation.model.model_id} will become ${confirmation.model.is_enabled ? 'unavailable' : 'available'} in the global model catalog.`} confirmLabel={confirmation.model.is_enabled ? 'Disable model' : 'Enable model'} onConfirm={() => updateModel(confirmation.model, { is_enabled: !confirmation.model.is_enabled }, `Model ${confirmation.model.is_enabled ? 'disabled' : 'enabled'}.`)} onCancel={() => setConfirmation(null)} busy={mutation.busy} />}
        </div>
    );
}
