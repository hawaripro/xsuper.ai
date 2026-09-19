import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useLocale } from '../../contexts/LocaleContext';
import { apiRequest } from '../../lib/api';
import { Button } from '../member/MemberUI';
import { DepositCredit, DepositNotice, DepositStatus, depositErrorText, useDepositFormat } from './DepositUI';

const OPEN_STATUSES = ['checkout', 'pending'];
const ORDER_STATUSES = [...OPEN_STATUSES, 'expired', 'approved', 'rejected', 'cancelled'];

function countdown(expiresAt, now) {
    const remaining = Math.max(0, Math.ceil((new Date(expiresAt).getTime() - now) / 1000));
    if (!Number.isFinite(remaining)) return '—';
    const hours = Math.floor(remaining / 3600);
    const minutes = Math.floor((remaining % 3600) / 60);
    const seconds = String(remaining % 60).padStart(2, '0');
    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
}

export default function DepositCheckout({ id, initialOrder, onClose, onBalances, onChanged, onBusyChange }) {
    const { t, localizedPath } = useLocale();
    const format = useDepositFormat();
    const [order, setOrder] = useState(initialOrder || null);
    const [checking, setChecking] = useState(true);
    const [verified, setVerified] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [error, setError] = useState(null);
    const [pollError, setPollError] = useState(null);
    const [refresh, setRefresh] = useState(0);
    const [now, setNow] = useState(Date.now);
    const [qrFailed, setQrFailed] = useState(false);
    const [qrAttempt, setQrAttempt] = useState(0);
    const heading = useRef(null);
    const mounted = useRef(true);
    const confirmationLock = useRef(false);
    const readController = useRef(null);
    const timer = useRef(null);
    const lastStatus = useRef(initialOrder?.status || null);
    const callbacks = useRef({ onBalances, onChanged });

    useEffect(() => { callbacks.current = { onBalances, onChanged }; }, [onBalances, onChanged]);
    useEffect(() => { onBusyChange?.(confirming); }, [confirming, onBusyChange]);
    useEffect(() => () => onBusyChange?.(false), [onBusyChange]);
    useEffect(() => {
        mounted.current = true;
        heading.current?.focus({ preventScroll: true });
        heading.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
        return () => { mounted.current = false; };
    }, []);

    const receiveOrder = useCallback((next) => {
        if (!next || Number(next.id) !== Number(id) || !ORDER_STATUSES.includes(next.status)) throw new Error('Invalid deposit response');
        setOrder(next);
        setVerified(true);
        if (lastStatus.current !== null && lastStatus.current !== next.status) {
            callbacks.current.onChanged?.(next);
            heading.current?.focus({ preventScroll: true });
        }
        lastStatus.current = next.status;
    }, [id]);

    useEffect(() => {
        let disposed = false;
        let failures = 0;
        let polling = true;
        const schedule = (delay) => {
            clearTimeout(timer.current);
            if (!disposed && polling) timer.current = setTimeout(checkStatus, delay);
        };
        const checkStatus = async () => {
            if (disposed || confirmationLock.current) return;
            if (document.hidden) { schedule(4000); return; }
            const controller = new AbortController();
            readController.current = controller;
            setChecking(true);
            try {
                const result = await apiRequest(`/api/deposits/${id}`, { signal: controller.signal });
                if (disposed || controller.signal.aborted || confirmationLock.current) return;
                receiveOrder(result?.order);
                callbacks.current.onBalances?.(result);
                setError(null);
                setPollError(null);
                failures = 0;
                polling = OPEN_STATUSES.includes(result.order.status);
                if (polling) schedule(result.order.status === 'pending' ? 4000 : 10000);
            } catch (requestError) {
                if (disposed || controller.signal.aborted) return;
                setPollError(requestError);
                failures += 1;
                polling = failures < 3 && ![401, 403, 404, 419, 429].includes(requestError?.status);
                if (polling) schedule(6000);
            } finally {
                if (!disposed && !controller.signal.aborted) setChecking(false);
            }
        };
        const onVisible = () => {
            if (!document.hidden && polling && !confirmationLock.current) {
                readController.current?.abort();
                clearTimeout(timer.current);
                checkStatus();
            }
        };
        checkStatus();
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            disposed = true;
            clearTimeout(timer.current);
            readController.current?.abort();
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [id, refresh, receiveOrder]);

    useEffect(() => {
        if (order?.status !== 'checkout') return undefined;
        const tick = setInterval(() => { if (!document.hidden) setNow(Date.now()); }, 1000);
        return () => clearInterval(tick);
    }, [order?.status]);

    const timeValid = order?.expires_at && Number.isFinite(new Date(order.expires_at).getTime()) && new Date(order.expires_at).getTime() > now;

    const confirmPaid = async () => {
        if (confirmationLock.current || !verified || pollError || error || order?.status !== 'checkout' || !timeValid) return;
        confirmationLock.current = true;
        readController.current?.abort();
        clearTimeout(timer.current);
        setConfirming(true);
        setChecking(false);
        setError(null);
        try {
            const result = await apiRequest('/api/deposits', { method: 'POST', body: { payment_reference: order.payment_reference } });
            if (!mounted.current) return;
            receiveOrder(result?.order);
            setPollError(null);
        } catch (requestError) {
            if (mounted.current) setError(requestError);
            if (mounted.current) setVerified(false);
        } finally {
            confirmationLock.current = false;
            if (mounted.current) {
                setConfirming(false);
                setRefresh(value => value + 1);
            }
        }
    };

    const refreshStatus = () => {
        setVerified(false);
        setChecking(true);
        setPollError(null);
        setRefresh(value => value + 1);
    };

    const [cancelPrompt, setCancelPrompt] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [cancelError, setCancelError] = useState(null);
    const cancelOrder = async () => {
        if (cancelling || !order?.id) return;
        setCancelling(true);
        setCancelError(null);
        readController.current?.abort();
        clearTimeout(timer.current);
        try {
            const result = await apiRequest(`/api/deposits/${order.id}/cancel`, { method: 'POST' });
            if (!mounted.current) return;
            receiveOrder(result?.order);
            setCancelPrompt(false);
            onChanged?.(result?.order);
        } catch (requestError) {
            if (mounted.current) setCancelError(requestError);
        } finally {
            if (mounted.current) { setCancelling(false); setRefresh(value => value + 1); }
        }
    };

    return (
        <section className="deposit-payment deposit-surface" aria-labelledby="deposit-payment-title" aria-busy={confirming}>
            <div className="deposit-section-heading">
                <h2 id="deposit-payment-title" ref={heading} tabIndex={-1}>{t('Pembayaran deposit')}</h2>
                <Button variant="ghost" onClick={onClose} disabled={confirming}>{t('Tutup')}</Button>
            </div>

            {!order && checking && <p className="deposit-loading" role="status">{t('Memuat pembayaran…')}</p>}
            {pollError && <DepositNotice tone="error" action={<Button variant="secondary" disabled={checking || confirming} onClick={refreshStatus}>{t('Periksa status')}</Button>}>
                <p>{depositErrorText(pollError, t)}</p>
                <p>{t('Status terakhir tetap ditampilkan. Jangan membayar ulang saat status belum pasti.')}</p>
            </DepositNotice>}

            {order && <>
                <div className="deposit-payment-status" aria-live="polite"><DepositStatus status={order.status} /></div>
                <dl className="deposit-payment-summary">
                    <div><dt>{t('Tujuan deposit')}</dt><dd>{order.kind === 'tokens' ? t('Kredit token') : t('Saldo API PAYG')}</dd></div>
                    <div><dt>{t('Total pembayaran')}</dt><dd className="deposit-payment-amount">{format.idr(order.amount_idr)}</dd></div>
                    <div><dt>{t('Kredit setelah disetujui')}</dt><dd><DepositCredit order={order} /></dd></div>
                    {order.kind === 'tokens' ? <div><dt>{t('Rincian token')}</dt><dd>{format.count(order.base_tokens)} + {format.count(order.bonus_tokens)} {t('bonus')}</dd></div> : <div><dt>{t('Kurs saat checkout')}</dt><dd>1 USD = {format.idr(order.idr_per_usd)}</dd></div>}
                    <div className="deposit-reference"><dt>{t('Referensi pembayaran')}</dt><dd><code>{order.payment_reference}</code></dd></div>
                </dl>

                {order.status === 'checkout' && <>
                    {timeValid ? <>
                        <p className="deposit-body-copy">{t('Pindai QRIS dan bayar tepat sesuai total di atas. Setelah transfer berhasil, konfirmasikan pembayaran Anda.')}</p>
                        <div className="deposit-qr-frame">
                            {qrFailed ? <div className="deposit-qr-error"><p>{t('Kode QRIS belum dapat dimuat.')}</p><Button variant="secondary" onClick={() => { setQrFailed(false); setQrAttempt(value => value + 1); }}>{t('Muat ulang QRIS')}</Button></div> : <img key={qrAttempt} src={order.qr_image_url} alt={t('Kode QRIS pembayaran')} width="240" height="240" onError={() => setQrFailed(true)} />}
                        </div>
                        <div className="deposit-expiry">
                            <p>{t('Selesaikan sebelum')} <time dateTime={order.expires_at}>{format.date(order.expires_at)}</time></p>
                            <p className="deposit-countdown" aria-label={t('Sisa waktu pembayaran')}>{countdown(order.expires_at, now)}</p>
                        </div>
                    </> : <DepositNotice>{t('Batas waktu pembayaran telah lewat. Jangan transfer ke checkout ini. Periksa status terbaru atau buka deposit baru dari katalog.')}</DepositNotice>}
                    {error && <DepositNotice tone="error"><p>{depositErrorText(error, t)}</p><p>{t('Konfirmasi mungkin sudah tercatat. Periksa status; jangan transfer lagi.')}</p></DepositNotice>}
                    <Button className="deposit-confirm-button" onClick={confirmPaid} disabled={confirming || !verified || checking || !timeValid || Boolean(error) || Boolean(pollError)}>{confirming ? t('Mengirim konfirmasi…') : t('Saya sudah membayar')}</Button>
                    <p className="deposit-fine-print">{t('Konfirmasi bukan persetujuan otomatis. Kredit baru masuk setelah pembayaran diperiksa admin.')}</p>
                    {error && <Button variant="secondary" disabled={checking || confirming} onClick={refreshStatus}>{t('Periksa sebelum mencoba lagi')}</Button>}
                </>}

                {order.status === 'pending' && <div className="deposit-state-message" role="status">
                    <h3>{t('Menunggu persetujuan admin')}</h3>
                    <p>{t('Konfirmasi Anda tersimpan. Admin akan mencocokkan pembayaran sebelum menambahkan kredit. Tidak perlu membayar ulang.')}</p>
                    <p className="deposit-fine-print">{t('Anda boleh meninggalkan halaman ini. Pantau deposit yang sama dari riwayat.')}</p>
                </div>}
                {['checkout', 'pending'].includes(order.status) && <div className="deposit-cancel-area">
                    {cancelError && <DepositNotice tone="error"><p>{depositErrorText(cancelError, t)}</p></DepositNotice>}
                    {cancelPrompt ? <DepositNotice tone="error">
                        <strong>{t('Batalkan deposit ini?')}</strong>
                        <p>{order.status === 'pending' ? t('Jika Anda sudah transfer, jangan batalkan - tunggu peninjauan admin. Pembatalan bersifat permanen.') : t('Checkout ditutup permanen dan QRIS ini tidak boleh dibayar lagi.')}</p>
                        <div className="deposit-cancel-actions">
                            <Button variant="secondary" onClick={cancelOrder} disabled={cancelling}>{cancelling ? t('Membatalkan…') : t('Ya, batalkan deposit')}</Button>
                            <Button variant="ghost" onClick={() => setCancelPrompt(false)} disabled={cancelling}>{t('Kembali')}</Button>
                        </div>
                    </DepositNotice> : <Button variant="ghost" className="deposit-cancel-link" onClick={() => setCancelPrompt(true)} disabled={confirming || cancelling}>{t('Batalkan deposit')}</Button>}
                </div>}
                {order.status === 'cancelled' && <DepositNotice><strong>{t('Deposit dibatalkan')}</strong><p>{t('Tidak ada kredit yang ditambahkan. Buka deposit baru dari katalog kapan saja.')}</p></DepositNotice>}
                {order.status === 'approved' && <DepositNotice tone="success"><strong>{t('Deposit disetujui')}</strong><p>{order.kind === 'tokens' ? t('Kredit token sudah ditambahkan ke akun Anda.') : t('Saldo USD sudah ditambahkan ke dompet API PAYG Anda.')}</p></DepositNotice>}
                {order.status === 'rejected' && <DepositNotice tone="error"><strong>{t('Deposit ditolak')}</strong><p>{t('Tidak ada kredit yang ditambahkan. Jika Anda sudah transfer, hubungi bantuan dengan referensi pembayaran ini.')}</p></DepositNotice>}
                {order.status === 'expired' && <DepositNotice><strong>{t('Checkout kedaluwarsa')}</strong><p>{t('Checkout ini tidak menerima konfirmasi lagi. Jika sudah transfer, hubungi bantuan sebelum membuat pembayaran lain.')}</p></DepositNotice>}
                {order.note && <div className="deposit-admin-note"><h3>{t('Catatan admin')}</h3><p>{order.note}</p></div>}
                {(order.confirmed_at || order.approved_at || order.rejected_at) && <dl className="deposit-payment-summary deposit-timestamps">
                    {order.confirmed_at && <div><dt>{t('Dikonfirmasi')}</dt><dd>{format.date(order.confirmed_at)}</dd></div>}
                    {(order.approved_at || order.rejected_at) && <div><dt>{t('Ditinjau')}</dt><dd>{format.date(order.approved_at || order.rejected_at)}</dd></div>}
                </dl>}
                <div className="deposit-payment-footer">
                    <Button variant="secondary" disabled={checking || confirming} onClick={refreshStatus}>{checking ? t('Memeriksa…') : t('Periksa status')}</Button>
                    <Link to={localizedPath('/bantuan')} className="deposit-text-link">{t('Butuh bantuan?')}</Link>
                </div>
            </>}
        </section>
    );
}
