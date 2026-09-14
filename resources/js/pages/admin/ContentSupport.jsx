import { useCallback, useEffect, useMemo, useState } from 'react';
import PageHeader from '../../components/dashboard/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../../components/dashboard/AsyncState';
import StatusBadge from '../../components/dashboard/StatusBadge';
import DataTable from '../../components/dashboard/DataTable';
import { apiRequest, formatDateTime } from '../../lib/api';

const tabs = [
    ['broadcast', 'Broadcasts'],
    ['feedback', 'Feedback'],
    ['tickets', 'Support tickets'],
    ['cms', 'CMS'],
];
const feedbackStatuses = ['new', 'reviewed', 'resolved', 'rejected'];
const ticketStatuses = ['open', 'in_progress', 'waiting_on_member', 'resolved', 'closed'];
const contentKeys = ['home.hero', 'home.faq', 'system.announcement', 'help.articles'];
const blankResource = { data: null, loading: true, error: '' };

function Field({ label, error, children }) {
    return <label className="block text-xs font-semibold text-slate-700 dark:text-slate-200"><span className="mb-1.5 block">{label}</span>{children}{error && <span className="mt-1 block text-[11px] font-normal text-red-600 dark:text-red-400">{error}</span>}</label>;
}

function ConfirmDialog({ title, description, confirmLabel, onCancel, onConfirm, busy }) {
    return (
        <div className="fixed inset-0 z-[90] grid place-items-center bg-slate-950/55 p-4" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget && !busy) onCancel(); }}>
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="content-confirm-title">
                <h2 id="content-confirm-title" className="text-base font-bold text-slate-900 dark:text-white">{title}</h2>
                <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">{description}</p>
                <div className="mt-5 flex justify-end gap-2"><button type="button" className="ui-btn-secondary" onClick={onCancel} disabled={busy}>Cancel</button><button type="button" className="ui-btn-primary min-h-10 px-4 text-xs" onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div>
            </div>
        </div>
    );
}

export default function ContentSupport() {
    const [tab, setTab] = useState('broadcast');
    const [feedback, setFeedback] = useState(blankResource);
    const [feedbackFilter, setFeedbackFilter] = useState('new');
    const [feedbackPage, setFeedbackPage] = useState(1);
    const [feedbackEditor, setFeedbackEditor] = useState(null);
    const [tickets, setTickets] = useState(blankResource);
    const [ticketFilter, setTicketFilter] = useState('open');
    const [ticketPage, setTicketPage] = useState(1);
    const [ticketThread, setTicketThread] = useState({ data: null, loading: false, error: '' });
    const [ticketDraft, setTicketDraft] = useState({ status: '', assigned_to: '', reply: '' });
    const [admins, setAdmins] = useState({ ...blankResource, loading: false });
    const [content, setContent] = useState(blankResource);
    const [contentFilter, setContentFilter] = useState({ key: '', locale: '' });
    const [contentEditor, setContentEditor] = useState(null);
    const [broadcast, setBroadcast] = useState({ segment: 'all', title: '', body: '', action_url: '' });
    const [formState, setFormState] = useState({ busy: false, error: '', success: '', fields: {} });
    const [confirmation, setConfirmation] = useState(null);

    const resetFormState = () => setFormState({ busy: false, error: '', success: '', fields: {} });

    const loadFeedback = useCallback(async (signal) => {
        setFeedback(current => ({ ...current, loading: true, error: '' }));
        try {
            const query = new URLSearchParams({ status: feedbackFilter, page: feedbackPage, per_page: 20 });
            const data = await apiRequest(`/api/admin/feedback?${query}`, { signal });
            setFeedback({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setFeedback(current => ({ ...current, loading: false, error: error.message || 'Feedback could not be loaded.' }));
        }
    }, [feedbackFilter, feedbackPage]);

    const loadTickets = useCallback(async (signal) => {
        setTickets(current => ({ ...current, loading: true, error: '' }));
        try {
            const query = new URLSearchParams({ status: ticketFilter, page: ticketPage, per_page: 20 });
            const data = await apiRequest(`/api/admin/support/tickets?${query}`, { signal });
            setTickets({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setTickets(current => ({ ...current, loading: false, error: error.message || 'Tickets could not be loaded.' }));
        }
    }, [ticketFilter, ticketPage]);

    const loadAdmins = useCallback(async signal => {
        setAdmins(current => ({ ...current, loading: true, error: '' }));
        try {
            const data = await apiRequest('/api/a/u', { signal });
            setAdmins({ data: (data.users || []).filter(user => user.role === 'admin'), loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setAdmins(current => ({ ...current, loading: false, error: error.message || 'Admin list could not be loaded.' }));
        }
    }, []);

    const loadContent = useCallback(async (signal) => {
        setContent(current => ({ ...current, loading: true, error: '' }));
        try {
            const query = new URLSearchParams();
            if (contentFilter.key) query.set('key', contentFilter.key);
            if (contentFilter.locale) query.set('locale', contentFilter.locale);
            const data = await apiRequest(`/api/admin/content${query.size ? `?${query}` : ''}`, { signal });
            setContent({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError') setContent(current => ({ ...current, loading: false, error: error.message || 'CMS content could not be loaded.' }));
        }
    }, [contentFilter]);

    useEffect(() => {
        if (tab !== 'feedback') return undefined;
        const controller = new AbortController();
        loadFeedback(controller.signal);
        return () => controller.abort();
    }, [tab, loadFeedback]);

    useEffect(() => {
        if (tab !== 'tickets') return undefined;
        const controller = new AbortController();
        loadTickets(controller.signal);
        if (!admins.data && !admins.loading) loadAdmins(controller.signal);
        return () => controller.abort();
    }, [admins.data, admins.loading, loadAdmins, loadTickets, tab]);

    useEffect(() => {
        if (tab !== 'cms') return undefined;
        const controller = new AbortController();
        loadContent(controller.signal);
        return () => controller.abort();
    }, [tab, loadContent]);

    const openTicket = async ticket => {
        resetFormState();
        setTicketThread({ data: null, loading: true, error: '' });
        setTicketDraft({ status: ticket.status, assigned_to: ticket.assigned_to || '', reply: '' });
        try {
            const data = await apiRequest(`/api/admin/support/tickets/${ticket.id}`);
            setTicketThread({ data: data.data, loading: false, error: '' });
        } catch (error) {
            setTicketThread({ data: { id: ticket.id }, loading: false, error: error.message || 'Ticket thread could not be loaded.' });
        }
    };

    const validateBroadcast = () => {
        const fields = {};
        if (!broadcast.title.trim()) fields.title = 'Title is required.';
        else if (broadcast.title.length > 160) fields.title = 'Use at most 160 characters.';
        if (broadcast.body.trim().length < 2) fields.body = 'Message is required.';
        else if (broadcast.body.length > 10000) fields.body = 'Use at most 10,000 characters.';
        if (broadcast.action_url) {
            try {
                if (!broadcast.action_url.startsWith('/')) new URL(broadcast.action_url);
            } catch { fields.action_url = 'Use a full URL or an internal path beginning with /.'; }
        }
        setFormState(current => ({ ...current, fields, error: '', success: '' }));
        return Object.keys(fields).length === 0;
    };

    const sendBroadcast = async () => {
        setConfirmation(null);
        setFormState({ busy: true, error: '', success: '', fields: {} });
        try {
            const data = await apiRequest('/api/admin/engagement/broadcasts', { method: 'POST', body: { ...broadcast, action_url: broadcast.action_url || null } });
            setFormState({ busy: false, error: '', fields: {}, success: `Broadcast delivered to ${data.data.recipient_count} recipients. Reference: ${data.data.id}` });
            setBroadcast({ segment: broadcast.segment, title: '', body: '', action_url: '' });
        } catch (error) {
            setFormState({ busy: false, error: error.message || 'Broadcast could not be delivered.', success: '', fields: error.details?.errors || {} });
        }
    };

    const saveFeedback = async () => {
        const editor = feedbackEditor;
        setConfirmation(null);
        setFormState({ busy: true, error: '', success: '', fields: {} });
        try {
            await apiRequest(`/api/admin/feedback/${editor.id}`, { method: 'PATCH', body: { status: editor.status, is_testimonial: editor.is_testimonial, admin_note: editor.admin_note || null } });
            setFeedbackEditor(null);
            setFormState({ busy: false, error: '', fields: {}, success: 'Feedback moderation saved.' });
            await loadFeedback();
        } catch (error) {
            setFormState({ busy: false, error: error.message || 'Feedback could not be updated.', success: '', fields: error.details?.errors || {} });
        }
    };

    const updateTicket = async () => {
        setConfirmation(null);
        setFormState({ busy: true, error: '', success: '', fields: {} });
        try {
            const body = { status: ticketDraft.status, assigned_to: ticketDraft.assigned_to ? Number(ticketDraft.assigned_to) : null };
            const data = await apiRequest(`/api/admin/support/tickets/${ticketThread.data.id}`, { method: 'PATCH', body });
            setTicketThread(current => ({ ...current, data: { ...current.data, ...data.data } }));
            setFormState({ busy: false, error: '', fields: {}, success: 'Ticket assignment and status updated.' });
            await loadTickets();
        } catch (error) {
            setFormState({ busy: false, error: error.message || 'Ticket could not be updated.', success: '', fields: error.details?.errors || {} });
        }
    };

    const sendReply = async event => {
        event.preventDefault();
        if (ticketDraft.reply.trim().length < 2) {
            setFormState(current => ({ ...current, fields: { reply: 'Reply must contain at least two characters.' }, error: '', success: '' }));
            return;
        }
        setFormState({ busy: true, error: '', success: '', fields: {} });
        try {
            await apiRequest(`/api/admin/support/tickets/${ticketThread.data.id}/replies`, { method: 'POST', body: { body: ticketDraft.reply } });
            const refreshed = await apiRequest(`/api/admin/support/tickets/${ticketThread.data.id}`);
            setTicketThread({ data: refreshed.data, loading: false, error: '' });
            setTicketDraft(current => ({ ...current, reply: '' }));
            setFormState({ busy: false, error: '', fields: {}, success: 'Reply sent and recorded in the ticket.' });
            await loadTickets();
        } catch (error) {
            setFormState({ busy: false, error: error.message || 'Reply could not be sent.', success: '', fields: error.details?.errors || {} });
        }
    };

    const startContentEditor = block => {
        resetFormState();
        setContentEditor(block ? { ...block, draftText: JSON.stringify(block.draft ?? {}, null, 2) } : { id: null, key: 'home.hero', locale: 'id', draftText: '{\n  \n}' });
    };

    const parsedDraft = () => {
        try {
            const draft = JSON.parse(contentEditor.draftText);
            if (draft === null || typeof draft !== 'object') throw new Error('Draft must be a JSON object or array.');
            return { draft };
        } catch (error) {
            setFormState(current => ({ ...current, fields: { draft: error.message }, error: '', success: '' }));
            return null;
        }
    };

    const saveContent = async event => {
        event.preventDefault();
        const parsed = parsedDraft();
        if (!parsed) return;
        setFormState({ busy: true, error: '', success: '', fields: {} });
        try {
            const creating = !contentEditor.id;
            const path = creating ? '/api/admin/content' : `/api/admin/content/${contentEditor.id}`;
            const body = creating ? { key: contentEditor.key, locale: contentEditor.locale, draft: parsed.draft } : { draft: parsed.draft };
            await apiRequest(path, { method: creating ? 'POST' : 'PUT', body });
            setContentEditor(null);
            setFormState({ busy: false, error: '', fields: {}, success: creating ? 'Draft created.' : 'Draft saved.' });
            await loadContent();
        } catch (error) {
            setFormState({ busy: false, error: error.message || 'Draft could not be saved.', success: '', fields: error.details?.errors || {} });
        }
    };

    const publishContent = async () => {
        const block = confirmation.block;
        setFormState({ busy: true, error: '', success: '', fields: {} });
        try {
            await apiRequest(`/api/admin/content/${block.id}/publish`, { method: 'POST' });
            setConfirmation(null);
            setFormState({ busy: false, error: '', fields: {}, success: `${block.key} (${block.locale}) published.` });
            await loadContent();
        } catch (error) {
            setConfirmation(null);
            setFormState({ busy: false, error: error.message || 'Content could not be published.', success: '', fields: error.details?.errors || {} });
        }
    };

    const contentRows = content.data?.data || [];
    const ticketMessages = ticketThread.data?.messages || [];
    const feedbackRows = feedback.data?.data || [];
    const ticketRows = tickets.data?.data || [];
    const availableAdmins = Array.isArray(admins.data) ? admins.data : [];
    const hasAnyFeedbackPage = useMemo(() => Number(feedback.data?.last_page || 1) > 1, [feedback.data]);

    return (
        <div className="ui-page space-y-5">
            <PageHeader eyebrow="Customer operations" title="Content & support" description="Durable broadcasts, moderated feedback, operational ticket queues, and published CMS content." />

            <div className="flex flex-wrap gap-2" role="tablist" aria-label="Content and support sections">
                {tabs.map(([key, label]) => <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => { setTab(key); resetFormState(); }} className={tab === key ? 'ui-btn-primary min-h-10 px-4 text-xs' : 'ui-btn-secondary'}>{label}</button>)}
            </div>

            {(formState.error || formState.success) && <div className={`rounded-xl border p-3 text-xs ${formState.error ? 'border-red-500/25 bg-red-500/5 text-red-700 dark:text-red-300' : 'border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300'}`} role={formState.error ? 'alert' : 'status'}>{formState.error || formState.success}</div>}

            {tab === 'broadcast' && (
                <section className="ui-card max-w-3xl" aria-labelledby="broadcast-title">
                    <div className="ui-card-header"><div><h2 id="broadcast-title" className="ui-section-title">Create broadcast</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Creates durable in-app notifications for the selected live member segment.</p></div></div>
                    <form className="ui-card-body grid gap-4" onSubmit={event => { event.preventDefault(); if (validateBroadcast()) setConfirmation({ type: 'broadcast' }); }} noValidate>
                        <Field label="Audience" error={formState.fields.segment}><select className="ui-input min-h-10" value={broadcast.segment} onChange={event => setBroadcast(current => ({ ...current, segment: event.target.value }))}><option value="all">All members</option><option value="active">Active members</option><option value="expired">Expired members</option></select></Field>
                        <Field label="Title" error={formState.fields.title}><input className="ui-input min-h-10" maxLength={160} value={broadcast.title} onChange={event => setBroadcast(current => ({ ...current, title: event.target.value }))} aria-invalid={Boolean(formState.fields.title)} /></Field>
                        <Field label="Message" error={formState.fields.body}><textarea className="ui-input min-h-32 resize-y" maxLength={10000} value={broadcast.body} onChange={event => setBroadcast(current => ({ ...current, body: event.target.value }))} aria-invalid={Boolean(formState.fields.body)} /></Field>
                        <Field label="Action URL (optional)" error={formState.fields.action_url}><input className="ui-input min-h-10" placeholder="/paket or https://…" value={broadcast.action_url} onChange={event => setBroadcast(current => ({ ...current, action_url: event.target.value }))} aria-invalid={Boolean(formState.fields.action_url)} /></Field>
                        <div className="flex justify-end"><button className="ui-btn-primary min-h-10 px-4 text-xs" disabled={formState.busy}>Review broadcast</button></div>
                    </form>
                </section>
            )}

            {tab === 'feedback' && (
                <section className="ui-card" aria-labelledby="feedback-title">
                    <div className="ui-card-header"><div><h2 id="feedback-title" className="ui-section-title">Feedback moderation</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Review member submissions and explicitly approve testimonial use.</p></div><select className="ui-input min-h-10 w-auto" value={feedbackFilter} onChange={event => { setFeedbackFilter(event.target.value); setFeedbackPage(1); }}>{feedbackStatuses.map(status => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}</select></div>
                    {feedback.loading && !feedback.data ? <div className="p-4"><LoadingState label="Loading feedback…" /></div> : feedback.error && !feedback.data ? <div className="p-4"><ErrorState message={feedback.error} onRetry={() => loadFeedback()} /></div> : <>
                        <DataTable rows={feedbackRows} emptyTitle={`No ${feedbackFilter} feedback`} emptyDescription="Change the status filter to inspect another moderation queue." columns={[
                            { key: 'member', label: 'Member', render: row => <div><strong className="block text-slate-900 dark:text-white">{row.user?.name || 'Unknown member'}</strong><span className="text-[11px] text-slate-500">{row.user?.email}</span></div> },
                            { key: 'rating', label: 'Rating', render: row => `${row.rating}/5` },
                            { key: 'message', label: 'Feedback', render: row => <span className="block max-w-xl whitespace-normal">{row.message}</span> },
                            { key: 'status', label: 'Status', render: row => <StatusBadge status={row.status} /> },
                            { key: 'action', label: 'Action', render: row => <button type="button" className="ui-btn-secondary" onClick={() => { resetFormState(); setFeedbackEditor({ ...row, admin_note: row.admin_note || '' }); }}>Moderate</button> },
                        ]} />
                        {hasAnyFeedbackPage && <div className="flex items-center justify-end gap-2 border-t border-slate-200 p-3 dark:border-white/10"><button className="ui-btn-secondary" disabled={feedbackPage <= 1 || feedback.loading} onClick={() => setFeedbackPage(page => page - 1)}>Previous</button><span className="text-[11px] text-slate-500">Page {feedback.data.current_page} of {feedback.data.last_page}</span><button className="ui-btn-secondary" disabled={feedbackPage >= feedback.data.last_page || feedback.loading} onClick={() => setFeedbackPage(page => page + 1)}>Next</button></div>}
                    </>}
                </section>
            )}

            {tab === 'tickets' && (
                <div className="grid gap-5 xl:grid-cols-[minmax(0,.9fr)_minmax(420px,1.1fr)]">
                    <section className="ui-card" aria-labelledby="tickets-title"><div className="ui-card-header"><div><h2 id="tickets-title" className="ui-section-title">Ticket queue</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Newest updated tickets first.</p></div><select className="ui-input min-h-10 w-auto" value={ticketFilter} onChange={event => { setTicketFilter(event.target.value); setTicketPage(1); }}>{ticketStatuses.map(status => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}</select></div>
                        {tickets.loading && !tickets.data ? <div className="p-4"><LoadingState label="Loading tickets…" /></div> : tickets.error && !tickets.data ? <div className="p-4"><ErrorState message={tickets.error} onRetry={() => loadTickets()} /></div> : ticketRows.length ? <div className="divide-y divide-slate-100 dark:divide-white/5">{ticketRows.map(ticket => <button key={ticket.id} type="button" className="block w-full p-4 text-left hover:bg-slate-50 dark:hover:bg-white/[.03]" onClick={() => openTicket(ticket)}><div className="flex items-start justify-between gap-3"><div><strong className="block text-xs text-slate-900 dark:text-white">{ticket.subject}</strong><span className="text-[11px] text-slate-500">{ticket.user?.name} · {ticket.category}</span></div><StatusBadge status={ticket.priority} /></div><div className="mt-2 flex items-center justify-between text-[11px] text-slate-500"><StatusBadge status={ticket.status} /><span>{ticket.messages_count} messages · {formatDateTime(ticket.updated_at)}</span></div></button>)}</div> : <div className="p-4"><EmptyState title={`No ${ticketFilter.replaceAll('_', ' ')} tickets`} description="Select another queue to continue support review." /></div>}
                        {Number(tickets.data?.last_page || 1) > 1 && <div className="flex items-center justify-end gap-2 border-t border-slate-200 p-3 dark:border-white/10"><button className="ui-btn-secondary" disabled={ticketPage <= 1 || tickets.loading} onClick={() => setTicketPage(page => page - 1)}>Previous</button><span className="text-[11px] text-slate-500">Page {tickets.data.current_page} of {tickets.data.last_page}</span><button className="ui-btn-secondary" disabled={ticketPage >= tickets.data.last_page || tickets.loading} onClick={() => setTicketPage(page => page + 1)}>Next</button></div>}
                    </section>
                    <section className="ui-card" aria-labelledby="thread-title"><div className="ui-card-header"><div><h2 id="thread-title" className="ui-section-title">Ticket workspace</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Assignment, status, and member-visible replies.</p></div></div>
                        {ticketThread.loading ? <div className="p-4"><LoadingState label="Loading ticket thread…" /></div> : ticketThread.error ? <div className="p-4"><ErrorState message={ticketThread.error} onRetry={() => openTicket(ticketThread.data)} /></div> : !ticketThread.data ? <div className="p-4"><EmptyState title="Select a ticket" description="Choose a queue item to inspect its complete authorized thread." /></div> : <div className="ui-card-body space-y-4">
                            <div><h3 className="text-sm font-bold text-slate-900 dark:text-white">{ticketThread.data.subject}</h3><p className="mt-1 text-[11px] text-slate-500">{ticketThread.data.user?.name} · {ticketThread.data.user?.email}</p></div>
                            <div className="grid gap-3 sm:grid-cols-2"><Field label="Status" error={formState.fields.status}><select className="ui-input min-h-10" value={ticketDraft.status} onChange={event => setTicketDraft(current => ({ ...current, status: event.target.value }))}>{ticketStatuses.map(status => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}</select></Field><Field label="Assigned admin" error={formState.fields.assigned_to}><select className="ui-input min-h-10" value={ticketDraft.assigned_to} onChange={event => setTicketDraft(current => ({ ...current, assigned_to: event.target.value }))} disabled={admins.loading || Boolean(admins.error)}><option value="">Unassigned</option>{availableAdmins.map(admin => <option key={admin.id} value={admin.id}>{admin.name}</option>)}</select>{admins.error && <button type="button" className="mt-1 text-[11px] text-red-600 underline" onClick={() => loadAdmins()}>Admin list failed — retry</button>}</Field></div>
                            <div className="flex justify-end"><button type="button" className="ui-btn-secondary" disabled={formState.busy} onClick={() => setConfirmation({ type: 'ticket' })}>Review status update</button></div>
                            <div className="max-h-80 space-y-2 overflow-y-auto rounded-xl bg-slate-50 p-3 dark:bg-white/[.03]">{ticketMessages.length ? ticketMessages.map(message => <article key={message.id} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900"><div className="flex justify-between gap-3"><strong className="text-[11px] text-slate-900 dark:text-white">{message.user?.name} · {message.user?.role}</strong><span className="text-[10px] text-slate-400">{formatDateTime(message.created_at)}</span></div><p className="mt-1 whitespace-pre-wrap text-xs leading-5 text-slate-600 dark:text-slate-300">{message.body}</p></article>) : <EmptyState title="No ticket messages" description="This ticket has no visible thread messages." />}</div>
                            <form className="space-y-2" onSubmit={sendReply}><Field label="Reply to member" error={formState.fields.reply}><textarea className="ui-input min-h-24 resize-y" maxLength={10000} value={ticketDraft.reply} onChange={event => setTicketDraft(current => ({ ...current, reply: event.target.value }))} /></Field><div className="flex justify-end"><button className="ui-btn-primary min-h-10 px-4 text-xs" disabled={formState.busy || ['closed'].includes(ticketThread.data.status)}>Send reply</button></div></form>
                        </div>}
                    </section>
                </div>
            )}

            {tab === 'cms' && (
                <section className="ui-card" aria-labelledby="cms-title"><div className="ui-card-header"><div><h2 id="cms-title" className="ui-section-title">Versioned content blocks</h2><p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">Draft changes remain private until explicitly published.</p></div><button type="button" className="ui-btn-primary min-h-10 px-4 text-xs" onClick={() => startContentEditor(null)}>New block</button></div>
                    <div className="flex flex-wrap gap-2 border-b border-slate-200 p-3 dark:border-white/10"><select className="ui-input min-h-10 w-auto" value={contentFilter.key} onChange={event => setContentFilter(current => ({ ...current, key: event.target.value }))}><option value="">All keys</option>{contentKeys.map(key => <option key={key} value={key}>{key}</option>)}</select><select className="ui-input min-h-10 w-auto" value={contentFilter.locale} onChange={event => setContentFilter(current => ({ ...current, locale: event.target.value }))}><option value="">All locales</option><option value="id">Indonesian</option><option value="en">English</option></select></div>
                    {content.loading && !content.data ? <div className="p-4"><LoadingState label="Loading content blocks…" /></div> : content.error && !content.data ? <div className="p-4"><ErrorState message={content.error} onRetry={() => loadContent()} /></div> : <DataTable rows={contentRows} emptyTitle="No matching content blocks" emptyDescription="Create a draft for an approved key and locale." columns={[
                        { key: 'key', label: 'Content', render: row => <div><strong className="block text-slate-900 dark:text-white">{row.key}</strong><span className="text-[11px] text-slate-500">{row.locale.toUpperCase()}</span></div> },
                        { key: 'status', label: 'Publication', render: row => <StatusBadge status={row.is_published ? 'published' : 'draft'} /> },
                        { key: 'editor', label: 'Last editor', render: row => row.editor?.name || '—' },
                        { key: 'updated', label: 'Updated', render: row => formatDateTime(row.updated_at) },
                        { key: 'actions', label: 'Actions', render: row => <div className="flex flex-wrap gap-2"><button type="button" className="ui-btn-secondary" onClick={() => startContentEditor(row)}>Edit draft</button><button type="button" className="ui-btn-secondary" onClick={() => setConfirmation({ type: 'publish', block: row })}>Publish</button></div> },
                    ]} />}
                </section>
            )}

            {feedbackEditor && <div className="fixed inset-0 z-[80] grid place-items-center bg-slate-950/55 p-4"><form className="w-full max-w-xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900" onSubmit={event => { event.preventDefault(); setConfirmation({ type: 'feedback' }); }}><div><h2 className="text-base font-bold text-slate-900 dark:text-white">Moderate feedback</h2><p className="mt-1 text-xs text-slate-500">{feedbackEditor.user?.name} · {feedbackEditor.rating}/5</p></div><blockquote className="rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600 dark:bg-white/[.04] dark:text-slate-300">{feedbackEditor.message}</blockquote><Field label="Status" error={formState.fields.status}><select className="ui-input min-h-10" value={feedbackEditor.status} onChange={event => setFeedbackEditor(current => ({ ...current, status: event.target.value }))}>{feedbackStatuses.map(status => <option key={status} value={status}>{status}</option>)}</select></Field><label className="flex min-h-10 items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200"><input type="checkbox" checked={feedbackEditor.is_testimonial} onChange={event => setFeedbackEditor(current => ({ ...current, is_testimonial: event.target.checked }))} /> Approved testimonial</label><Field label="Admin note" error={formState.fields.admin_note}><textarea className="ui-input min-h-24" maxLength={5000} value={feedbackEditor.admin_note} onChange={event => setFeedbackEditor(current => ({ ...current, admin_note: event.target.value }))} /></Field><div className="flex justify-end gap-2"><button type="button" className="ui-btn-secondary" onClick={() => setFeedbackEditor(null)}>Cancel</button><button className="ui-btn-primary min-h-10 px-4 text-xs">Review change</button></div></form></div>}

            {contentEditor && <div className="fixed inset-0 z-[80] grid place-items-center bg-slate-950/55 p-4"><form className="w-full max-w-2xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900" onSubmit={saveContent}><div><h2 className="text-base font-bold text-slate-900 dark:text-white">{contentEditor.id ? 'Edit content draft' : 'Create content block'}</h2><p className="mt-1 text-xs text-slate-500">JSON is validated locally, then validated again against the server contract.</p></div><div className="grid gap-3 sm:grid-cols-2"><Field label="Key" error={formState.fields.key}><select className="ui-input min-h-10" disabled={Boolean(contentEditor.id)} value={contentEditor.key} onChange={event => setContentEditor(current => ({ ...current, key: event.target.value }))}>{contentKeys.map(key => <option key={key} value={key}>{key}</option>)}</select></Field><Field label="Locale" error={formState.fields.locale}><select className="ui-input min-h-10" disabled={Boolean(contentEditor.id)} value={contentEditor.locale} onChange={event => setContentEditor(current => ({ ...current, locale: event.target.value }))}><option value="id">Indonesian</option><option value="en">English</option></select></Field></div><Field label="Draft JSON" error={formState.fields.draft}><textarea className="ui-input min-h-72 resize-y font-mono text-xs" spellCheck="false" value={contentEditor.draftText} onChange={event => setContentEditor(current => ({ ...current, draftText: event.target.value }))} /></Field><div className="flex justify-end gap-2"><button type="button" className="ui-btn-secondary" onClick={() => setContentEditor(null)} disabled={formState.busy}>Cancel</button><button className="ui-btn-primary min-h-10 px-4 text-xs" disabled={formState.busy}>{formState.busy ? 'Saving…' : 'Save draft'}</button></div></form></div>}

            {confirmation?.type === 'broadcast' && <ConfirmDialog title="Send broadcast?" description={`This creates a durable notification for every member in the “${broadcast.segment}” segment. Recipient count is determined by the server at send time.`} confirmLabel="Send broadcast" onCancel={() => setConfirmation(null)} onConfirm={sendBroadcast} busy={formState.busy} />}
            {confirmation?.type === 'feedback' && <ConfirmDialog title="Save moderation decision?" description={`Feedback will move to “${feedbackEditor.status}”${feedbackEditor.is_testimonial ? ' and become approved for testimonial use' : ''}.`} confirmLabel="Save decision" onCancel={() => setConfirmation(null)} onConfirm={saveFeedback} busy={formState.busy} />}
            {confirmation?.type === 'ticket' && <ConfirmDialog title="Update ticket workflow?" description={`Ticket status will become “${ticketDraft.status.replaceAll('_', ' ')}”${ticketDraft.assigned_to ? ' with the selected assignee' : ' and remain unassigned'}.`} confirmLabel="Update ticket" onCancel={() => setConfirmation(null)} onConfirm={updateTicket} busy={formState.busy} />}
            {confirmation?.type === 'publish' && <ConfirmDialog title="Publish this draft?" description={`${confirmation.block.key} (${confirmation.block.locale}) will replace the currently published content used by the public surface.`} confirmLabel="Publish draft" onCancel={() => setConfirmation(null)} onConfirm={publishContent} busy={formState.busy} />}
        </div>
    );
}
