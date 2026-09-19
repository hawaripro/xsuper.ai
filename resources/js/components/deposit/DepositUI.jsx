import { useMemo } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import { Button, formatLocalDate } from '../member/MemberUI';

const statusLabels = {
    checkout: 'Belum dikonfirmasi',
    cancelled: 'Dibatalkan',
    expired: 'Kedaluwarsa',
    pending: 'Menunggu persetujuan',
    approved: 'Disetujui',
    rejected: 'Ditolak',
};

export function useDepositFormat() {
    const { locale } = useLocale();
    return useMemo(() => {
        const language = locale === 'en' ? 'en-US' : 'id-ID';
        const count = new Intl.NumberFormat(language);
        const idr = new Intl.NumberFormat(language, { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
        const unitIdr = new Intl.NumberFormat(language, { style: 'currency', currency: 'IDR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const usd = new Intl.NumberFormat(language, { style: 'currency', currency: 'USD', currencyDisplay: 'code', minimumFractionDigits: 2, maximumFractionDigits: 6 });
        const number = (value, formatter) => value !== null && value !== undefined && Number.isFinite(Number(value)) ? formatter.format(Number(value)) : '—';
        return {
            count: value => number(value, count),
            idr: value => number(value, idr),
            unitIdr: value => number(value, unitIdr),
            usdMicros: value => value === null || value === undefined ? '—' : number(Number(value) / 1_000_000, usd),
            date: value => formatLocalDate(value, { locale: language }),
        };
    }, [locale]);
}

export function DepositStatus({ status }) {
    const { t } = useLocale();
    const tone = status === 'approved' ? 'good' : status === 'pending' ? 'warn' : ['expired', 'rejected'].includes(status) ? 'bad' : 'neutral';
    return <span className={`ui-status ui-status-${tone} deposit-status`}>{t(statusLabels[status] || 'Status tidak tersedia')}</span>;
}

export function DepositCredit({ order }) {
    const { t } = useLocale();
    const format = useDepositFormat();
    return order.kind === 'tokens'
        ? <span className="deposit-numeric">{format.count(order.total_tokens)} {t('token')}</span>
        : <span className="deposit-numeric">{format.usdMicros(order.credit_microusd)} <span className="deposit-muted">PAYG</span></span>;
}

export function DepositNotice({ children, tone = 'info', action }) {
    return (
        <div className={`deposit-notice deposit-notice-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
            <div>{children}</div>
            {action && <div className="deposit-notice-action">{action}</div>}
        </div>
    );
}

export function depositErrorText(error, t) {
    if (error?.status === 401 || error?.status === 419) return t('Sesi Anda telah berakhir. Masuk kembali sebelum melanjutkan pembayaran.');
    if (error?.status === 403) return t('Akun Anda tidak memiliki akses ke tindakan deposit ini.');
    if (error?.status === 404) return t('Deposit tidak ditemukan. Buka kembali deposit dari riwayat akun Anda.');
    if (error?.status === 409 || error?.status === 422) return t('Data pembayaran telah berubah atau tidak valid. Periksa status terbaru sebelum mencoba lagi.');
    if (error?.status === 429) return t('Terlalu banyak permintaan. Tunggu sebentar lalu periksa kembali.');
    return t('Permintaan deposit belum dapat diproses. Periksa koneksi Anda lalu coba lagi.');
}

export function DepositPagination({ pagination, loading, onPage }) {
    const { t } = useLocale();
    const format = useDepositFormat();
    if (!pagination || pagination.total === 0) return null;
    return (
        <nav className="deposit-pagination" aria-label={t('Halaman riwayat deposit')}>
            <p>{t('Halaman')} {format.count(pagination.current_page)} {t('dari')} {format.count(pagination.last_page)} <span className="deposit-muted">· {format.count(pagination.total)} {t('deposit')}</span></p>
            <div>
                <Button variant="secondary" disabled={loading || pagination.current_page <= 1} onClick={() => onPage(pagination.current_page - 1)}>{t('Sebelumnya')}</Button>
                <Button variant="secondary" disabled={loading || pagination.current_page >= pagination.last_page} onClick={() => onPage(pagination.current_page + 1)}>{t('Berikutnya')}</Button>
            </div>
        </nav>
    );
}
