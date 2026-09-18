import { useEffect, useState } from 'react';
import { useLocale } from '../contexts/LocaleContext';
import { useNotifications } from '../contexts/NotificationContext';
import PageHeader from '../components/dashboard/PageHeader';
import { NotificationConnectionStatus, NotificationFeed } from '../components/dashboard/NotificationMenu';

export default function Notifications() {
    const { t } = useLocale();
    const { unreadCount, loading, pagination, refresh, setPage, markAllRead, connectionState } = useNotifications();
    const [busy, setBusy] = useState(false);
    const firstVisiblePage = Math.max(1, Math.min(pagination.current_page - 2, pagination.last_page - 4));
    const pages = Array.from({ length: Math.min(5, pagination.last_page) }, (_, index) => firstVisiblePage + index);

    useEffect(() => { void refresh(); }, [refresh]);

    const markAll = async () => {
        setBusy(true);
        await markAllRead();
        setBusy(false);
    };

    return (
        <div className="notification-page ui-page">
            <PageHeader title={t('Inbox')} description={t('Pembaruan akun, billing, support, dan operasi penting dalam satu tempat.')} actions={<>
                <button type="button" className="ui-btn-secondary" onClick={refresh} disabled={loading}>{loading ? t('Memuat ulang…') : t('Muat ulang')}</button>
                {unreadCount > 0 && <button type="button" className="ui-btn-primary" onClick={markAll} disabled={busy || loading}>{busy ? t('Memproses…') : t('Tandai semua dibaca')}</button>}
            </>} />
            <div className="notification-page-toolbar">
                <div className="notification-page-summary" role="status"><strong>{unreadCount} {t('Belum dibaca')}</strong><span>{pagination.total} {t('notifikasi')}</span></div>
                <NotificationConnectionStatus />
            </div>
            {!['connected', 'connecting'].includes(connectionState) && <p className="notification-page-hint">{t('Inbox tetap tersedia. Muat ulang untuk pembaruan terbaru.')}</p>}
            <section className="notification-page-surface" aria-label={t('Notifikasi')}><NotificationFeed /></section>
            {pagination.total > 0 && <nav className="notification-pagination" aria-label={t('Halaman inbox')}>
                <p>{t('Halaman')} <strong>{pagination.current_page}</strong> {t('dari')} <strong>{pagination.last_page}</strong></p>
                <div className="notification-pagination-controls">
                    <button type="button" className="notification-page-button" onClick={() => setPage(pagination.current_page - 1)} disabled={loading || pagination.current_page <= 1}>{t('Previous')}</button>
                    {pages.map(number => <button key={number} type="button" className="notification-page-button" aria-label={`${t('Halaman')} ${number}`} aria-current={number === pagination.current_page ? 'page' : undefined} onClick={() => setPage(number)} disabled={loading || number === pagination.current_page}>{number}</button>)}
                    <button type="button" className="notification-page-button" onClick={() => setPage(pagination.current_page + 1)} disabled={loading || pagination.current_page >= pagination.last_page}>{t('Next')}</button>
                </div>
            </nav>}
        </div>
    );
}
