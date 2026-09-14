import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiRequest, formatDateTime } from '../lib/api';
import PageHeader from '../components/dashboard/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/dashboard/AsyncState';

export default function Notifications() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        setLoading(true); setError(null);
        try { setData(await apiRequest('/api/notifications?per_page=50')); }
        catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { load(); }, [load]);

    const markRead = async (notification) => {
        if (notification.read_at) return;
        try {
            const response = await apiRequest(`/api/notifications/${notification.id}/read`, { method: 'PATCH' });
            setData(current => ({ ...current, unread_count: Math.max(0, current.unread_count - 1), notifications: current.notifications.map(item => item.id === notification.id ? response.notification : item) }));
        } catch (requestError) { setError(requestError); }
    };

    const markAll = async () => {
        setBusy(true); setError(null);
        try {
            await apiRequest('/api/notifications/read-all', { method: 'POST' });
            setData(current => ({ ...current, unread_count: 0, notifications: current.notifications.map(item => ({ ...item, read_at: item.read_at || new Date().toISOString() })) }));
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    };

    return (
        <div className="ui-page space-y-4">
            <PageHeader eyebrow="Account" title="Inbox" description="Pembaruan akun, billing, support, dan operasi penting dalam satu tempat." actions={data?.unread_count > 0 ? <button type="button" className="ui-btn-secondary" onClick={markAll} disabled={busy}>{busy ? 'Memproses…' : `Tandai semua dibaca (${data.unread_count})`}</button> : null} />
            {error && !loading && <ErrorState message={error.message} onRetry={load} />}
            {loading ? <LoadingState label="Memuat notifikasi…" /> : !data?.notifications?.length ? <EmptyState title="Inbox bersih" description="Notifikasi baru akan muncul di sini." /> : (
                <section className="ui-card divide-y divide-slate-100 overflow-hidden dark:divide-white/[0.06]">
                    {data.notifications.map(notification => {
                        const content = (
                            <article onMouseEnter={() => markRead(notification)} className={`flex gap-3 p-4 transition-colors ${notification.read_at ? 'bg-transparent' : 'bg-red-50/65 dark:bg-red-500/[0.06]'}`}>
                                <span className={`mt-1.5 h-2 w-2 flex-none rounded-full ${notification.read_at ? 'bg-slate-300 dark:bg-slate-700' : 'bg-red-500'}`} aria-label={notification.read_at ? 'Sudah dibaca' : 'Belum dibaca'} />
                                <div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-4"><strong className="text-[13px] text-slate-900 dark:text-white">{notification.title}</strong><time className="flex-none text-[10px] text-slate-400">{formatDateTime(notification.created_at)}</time></div><p className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{notification.body}</p><span className="mt-2 inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-semibold capitalize text-slate-500 dark:bg-white/[0.06] dark:text-slate-400">{notification.kind}</span></div>
                            </article>
                        );
                        return notification.action_url ? <Link key={notification.id} to={notification.action_url} onClick={() => markRead(notification)}>{content}</Link> : <div key={notification.id} onClick={() => markRead(notification)}>{content}</div>;
                    })}
                </section>
            )}
        </div>
    );
}
