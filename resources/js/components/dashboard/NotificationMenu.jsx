import { useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { useLocale } from '../../contexts/LocaleContext';
import { useNotifications } from '../../contexts/NotificationContext';
import '../../../css/notifications.css';

function BellIcon() {
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z" /><path d="M10 21h4" /></svg>;
}

export function NotificationConnectionStatus() {
    const { t } = useLocale();
    const { connectionState } = useNotifications();
    const label = connectionState === 'connected' ? 'Pembaruan langsung aktif'
        : connectionState === 'connecting' ? 'Menghubungkan inbox…'
            : connectionState === 'disabled' ? 'Pembaruan langsung tidak diaktifkan'
                : 'Pembaruan langsung terputus';
    return <span className="notification-connection" data-state={connectionState}><span aria-hidden="true" />{t(label)}</span>;
}

export function NotificationFeed({ compact = false, onNavigate }) {
    const { t, locale, localizedPath } = useLocale();
    const { items, loading, error, refresh, markRead } = useNotifications();
    const [pending, setPending] = useState(new Set());
    const formatter = useMemo(() => new Intl.DateTimeFormat(locale === 'en' ? 'en-US' : 'id-ID', { dateStyle: 'medium', timeStyle: 'short' }), [locale]);
    const visibleItems = compact ? items.slice(0, 6) : items;

    const read = async (id) => {
        setPending(current => new Set(current).add(id));
        await markRead(id);
        setPending(current => {
            const next = new Set(current);
            next.delete(id);
            return next;
        });
    };

    return (
        <div className={`notification-feed${compact ? ' notification-feed--compact' : ''}`} aria-busy={loading}>
            {error && <div className="notification-error" role="alert"><p>{t('Inbox tidak dapat diperbarui.')} {error.message}</p><button type="button" className="ui-btn-secondary" onClick={refresh} disabled={loading}>{t('Coba lagi')}</button></div>}
            {loading && !items.length ? <div className="notification-loading" role="status"><span className="notification-sr-only">{t('Memuat notifikasi…')}</span>{[0, 1, 2].map(row => <div key={row} className="notification-skeleton" aria-hidden="true"><span /><span /><span /></div>)}</div>
                : !items.length && !error ? <div className="notification-empty"><span className="notification-empty-icon"><BellIcon /></span><strong>{t('Inbox bersih')}</strong><p>{t('Notifikasi baru akan muncul di sini.')}</p></div>
                    : <ul className="notification-list">
                        {visibleItems.map(notification => {
                            const date = notification.created_at ? new Date(notification.created_at) : null;
                            const timestamp = date && !Number.isNaN(date.getTime()) ? formatter.format(date) : '';
                            const category = notification.kind === 'support' ? 'Dukungan' : notification.kind === 'referral_reward' ? 'Referral' : notification.kind === 'broadcast' ? 'Pengumuman' : notification.kind === 'media' ? 'Media' : 'Akun';
                            const title = notification.action_url ? <Link to={localizedPath(notification.action_url)} onClick={() => { if (!notification.read_at) void read(notification.id); onNavigate?.(); }}>{t(notification.title)}</Link> : <strong>{t(notification.title)}</strong>;
                            return <li key={notification.id} className="notification-item" data-unread={!notification.read_at}>
                                <span className="notification-unread-marker" aria-hidden="true" />
                                <article className="notification-item-content">
                                    <span className="notification-sr-only">{notification.read_at ? t('Sudah dibaca') : t('Belum dibaca')}</span>
                                    <div className="notification-item-heading">{title}</div>
                                    <p className="notification-body">{t(notification.body)}</p>
                                    <div className="notification-item-meta"><span>{t(category)}</span>{timestamp && <time dateTime={notification.created_at}>{timestamp}</time>}
                                        {!notification.read_at && <button type="button" className="notification-read-action" disabled={pending.has(notification.id)} onClick={() => void read(notification.id)} aria-label={`${t('Tandai dibaca')}: ${notification.title}`}><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m4 10 4 4 8-8" /></svg>{pending.has(notification.id) ? t('Memproses…') : t('Tandai dibaca')}</button>}
                                    </div>
                                </article>
                            </li>;
                        })}
                    </ul>}
        </div>
    );
}

export default function NotificationMenu() {
    const { t, localizedPath } = useLocale();
    const { unreadCount, pagination, loading, refresh, markAllRead } = useNotifications();
    const location = useLocation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [position, setPosition] = useState({ left: 12, top: 72, maxHeight: 'calc(100dvh - 84px)' });
    const trigger = useRef(null);
    const panel = useRef(null);
    const panelId = useId();
    const headingId = useId();
    const close = useCallback((restoreFocus = false) => {
        setOpen(false);
        if (restoreFocus) trigger.current?.focus();
    }, []);

    useEffect(() => { setOpen(false); }, [location.pathname, location.search]);

    useLayoutEffect(() => {
        if (!open) return;
        const place = () => {
            const anchor = trigger.current?.getBoundingClientRect();
            if (!anchor) return;
            const width = Math.min(400, window.innerWidth - 24);
            const top = window.innerHeight - anchor.bottom < 240 ? 12 : anchor.bottom + 8;
            setPosition({ left: Math.max(12, Math.min(anchor.right - width, window.innerWidth - width - 12)), top, maxHeight: window.innerHeight - top - 12 });
        };
        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open]);

    useEffect(() => {
        if (!open) return;
        void refresh();
        panel.current?.focus();
        const outside = (event) => {
            if (!panel.current?.contains(event.target) && !trigger.current?.contains(event.target)) close();
        };
        const keyboard = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('focusin', outside);
        document.addEventListener('keydown', keyboard);
        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('focusin', outside);
            document.removeEventListener('keydown', keyboard);
        };
    }, [open, refresh, close]);

    const markAll = async () => {
        setBusy(true);
        await markAllRead();
        setBusy(false);
    };

    return <>
        <button type="button" ref={trigger} className="notification-trigger" aria-label={`${t('Inbox')}: ${unreadCount} ${t('Belum dibaca')}`} aria-haspopup="dialog" aria-expanded={open} aria-controls={open ? panelId : undefined} onClick={() => setOpen(current => !current)}><BellIcon />{unreadCount > 0 && <span className="notification-count" aria-hidden="true">{unreadCount > 99 ? '99+' : unreadCount}</span>}</button>
        {open && createPortal(<section ref={panel} id={panelId} role="dialog" aria-labelledby={headingId} tabIndex={-1} className="notification-popover" style={position}>
            <header className="notification-popover-header"><div><h2 id={headingId}>{t('Inbox')}{unreadCount > 0 && <span>{unreadCount}</span>}</h2><NotificationConnectionStatus /></div><button type="button" className="notification-close" aria-label={t('Tutup')} onClick={() => close(true)}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg></button></header>
            {unreadCount > 0 && <div className="notification-popover-actions"><button type="button" className="notification-text-action" onClick={markAll} disabled={busy || loading}>{busy ? t('Memproses…') : t('Tandai semua dibaca')}</button></div>}
            {pagination.current_page > 1 && <p className="notification-page-hint">{t('Halaman')} {pagination.current_page}</p>}
            <div className="notification-popover-scroll"><NotificationFeed compact onNavigate={() => close()} /></div>
            <footer className="notification-popover-footer"><Link to={localizedPath('/notifications')} onClick={() => close()}>{t('Buka inbox')}<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" /></svg></Link><button type="button" className="notification-text-action" onClick={refresh} disabled={loading}>{loading ? t('Memuat ulang…') : t('Muat ulang')}</button></footer>
        </section>, document.body)}
    </>;
}
