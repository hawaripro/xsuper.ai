import { useEffect, useRef, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import { apiRequest } from '../../lib/api';
import DataTable from '../dashboard/DataTable';
import { Button } from '../member/MemberUI';
import { DepositCredit, DepositNotice, DepositPagination, DepositStatus, depositErrorText, useDepositFormat } from './DepositUI';
import './deposit.css';

function DepositReview({ review, onClose, onSaved }) {
    const { t } = useLocale();
    const format = useDepositFormat();
    const [acknowledged, setAcknowledged] = useState(false);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [mustRefresh, setMustRefresh] = useState(false);
    const dialog = useRef(null);
    const cancel = useRef(null);
    const lock = useRef(false);
    const mounted = useRef(true);
    const order = review.order;
    const approving = review.action === 'approve';

    useEffect(() => {
        mounted.current = true;
        const trigger = document.activeElement;
        const element = dialog.current;
        element.showModal();
        cancel.current?.focus();
        return () => {
            mounted.current = false;
            element.close();
            if (trigger?.isConnected) trigger.focus();
        };
    }, []);

    const submit = async event => {
        event.preventDefault();
        if (lock.current || !acknowledged || mustRefresh) return;
        lock.current = true;
        setBusy(true);
        setError(null);
        try {
            const result = await apiRequest(`/api/admin/deposits/${order.id}/${review.action}`, { method: 'POST', body: note.trim() ? { note: note.trim() } : {} });
            if (!result?.order || result.order.status !== (approving ? 'approved' : 'rejected')) throw new Error('Invalid deposit decision');
            if (mounted.current) onSaved(result.order);
        } catch (requestError) {
            if (!mounted.current) return;
            setError(requestError);
            // Never replay a financial decision after an ambiguous response.
            setMustRefresh(true);
        } finally {
            lock.current = false;
            if (mounted.current) setBusy(false);
        }
    };

    return (
        <dialog ref={dialog} className="deposit-review" aria-labelledby="deposit-review-title" aria-describedby="deposit-review-description" onCancel={event => { event.preventDefault(); if (!lock.current) onClose(); }}>
            <form onSubmit={submit}>
                <h2 id="deposit-review-title">{approving ? t('Setujui deposit?') : t('Tolak deposit?')}</h2>
                <p id="deposit-review-description" className="deposit-review-description">{approving ? t('Pastikan dana telah diterima. Persetujuan langsung menambahkan kredit ke akun anggota dan tidak dapat dibatalkan di sini.') : t('Penolakan tidak menambahkan kredit. Sertakan alasan yang membantu anggota memahami keputusan ini.')}</p>
                <dl className="deposit-payment-summary">
                    <div><dt>{t('Anggota')}</dt><dd>{order.user?.name}<span className="deposit-table-meta">{order.user?.email}</span></dd></div>
                    <div><dt>{t('Total pembayaran')}</dt><dd>{format.idr(order.amount_idr)}</dd></div>
                    <div><dt>{t('Kredit yang ditambahkan')}</dt><dd>{approving ? <DepositCredit order={order} /> : t('Tidak ada')}</dd></div>
                    <div className="deposit-reference"><dt>{t('Referensi pembayaran')}</dt><dd><code>{order.payment_reference}</code></dd></div>
                </dl>
                <label className="deposit-review-note">{t('Catatan untuk anggota (opsional)')}<textarea maxLength={1000} value={note} onChange={event => setNote(event.target.value)} disabled={busy || mustRefresh} /></label>
                <label className="deposit-review-confirmation"><input type="checkbox" required checked={acknowledged} onChange={event => setAcknowledged(event.target.checked)} disabled={busy || mustRefresh} /><span>{approving ? t('Saya sudah mencocokkan dana masuk, jumlah pembayaran, dan referensi ini.') : t('Saya sudah meninjau pembayaran ini dan ingin menolak deposit tanpa menambahkan kredit.')}</span></label>
                {error && <DepositNotice tone="error"><p>{depositErrorText(error, t)}</p><p>{t('Keputusan mungkin sudah tersimpan. Tutup dan muat ulang antrean sebelum mengambil tindakan berikutnya.')}</p></DepositNotice>}
                <div className="deposit-review-buttons">
                    <Button variant="secondary" ref={cancel} disabled={busy} onClick={onClose}>{mustRefresh ? t('Tutup dan muat ulang') : t('Batal')}</Button>
                    <Button type="submit" variant={approving ? 'primary' : 'danger'} disabled={busy || !acknowledged || mustRefresh}>{busy ? t('Menyimpan keputusan…') : approving ? t('Setujui dan tambah kredit') : t('Tolak deposit')}</Button>
                </div>
            </form>
        </dialog>
    );
}

export default function DepositQueue({ refreshKey = 0, onQueueChanged }) {
    const { t } = useLocale();
    const format = useDepositFormat();
    const [filters, setFilters] = useState({ status: 'pending', kind: '', page: 1 });
    const [resource, setResource] = useState({ data: null, loading: true, error: null, query: '' });
    const [revision, setRevision] = useState(0);
    const [review, setReview] = useState(null);
    const [notice, setNotice] = useState(null);
    const query = new URLSearchParams({ page: String(filters.page), per_page: '20', ...(filters.status ? { status: filters.status } : {}), ...(filters.kind ? { kind: filters.kind } : {}) }).toString();

    useEffect(() => {
        const controller = new AbortController();
        setResource(current => ({ data: current.query === query ? current.data : null, query, loading: true, error: null }));
        apiRequest(`/api/admin/deposits?${query}`, { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setResource({ data, query, loading: false, error: null }); })
            .catch(error => { if (!controller.signal.aborted) setResource(current => ({ ...current, loading: false, error })); });
        return () => controller.abort();
    }, [query, revision, refreshKey]);

    const closeReview = () => {
        setReview(null);
        setRevision(value => value + 1);
    };
    const saved = order => {
        setNotice({ status: order.status, reference: order.payment_reference });
        onQueueChanged?.();
        closeReview();
    };
    const rows = resource.data?.orders || [];

    return (
        <section className="deposit-admin deposit-surface" aria-labelledby="deposit-queue-title">
            <div className="deposit-section-heading">
                <div><h2 id="deposit-queue-title">{t('Antrean deposit')}</h2><p>{t('Cocokkan pembayaran QRIS dengan dana yang diterima sebelum menyetujui. Token dan saldo USD dikreditkan ke saldo yang berbeda.')}</p><p>{t('Menunggu persetujuan')}: <strong>{format.count(resource.data?.pending_count)}</strong></p></div>
                <Button variant="secondary" disabled={resource.loading || Boolean(review)} onClick={() => setRevision(value => value + 1)}>{resource.loading ? t('Memuat…') : t('Perbarui deposit')}</Button>
            </div>
            <div className="deposit-history-filters">
                <label>{t('Status deposit')}<select value={filters.status} disabled={Boolean(review)} onChange={event => setFilters(current => ({ ...current, status: event.target.value, page: 1 }))}><option value="pending">{t('Menunggu persetujuan')}</option><option value="approved">{t('Disetujui')}</option><option value="rejected">{t('Ditolak')}</option><option value="">{t('Semua konfirmasi')}</option></select></label>
                <label>{t('Jenis deposit')}<select value={filters.kind} disabled={Boolean(review)} onChange={event => setFilters(current => ({ ...current, kind: event.target.value, page: 1 }))}><option value="">{t('Semua jenis')}</option><option value="tokens">{t('Kredit token')}</option><option value="wallet">{t('Saldo API PAYG')}</option></select></label>
            </div>
            {notice && <DepositNotice tone={notice.status === 'approved' ? 'success' : 'info'}><strong>{notice.status === 'approved' ? t('Deposit disetujui dan kredit ditambahkan.') : t('Deposit ditolak. Tidak ada kredit yang ditambahkan.')}</strong><p className="deposit-reference"><code>{notice.reference}</code></p></DepositNotice>}
            {resource.error && <DepositNotice tone="error" action={<Button variant="secondary" onClick={() => setRevision(value => value + 1)}>{t('Coba lagi')}</Button>}><p>{depositErrorText(resource.error, t)}</p><p>{t('Antrean belum terverifikasi. Muat ulang sebelum menyetujui atau menolak deposit.')}</p></DepositNotice>}
            {resource.loading && !resource.data ? <p className="deposit-loading" role="status">{t('Memuat antrean deposit…')}</p> : rows.length ? <div className="deposit-history-table" role="region" aria-label={t('Daftar konfirmasi deposit')} tabIndex={0} aria-busy={resource.loading}><DataTable rows={rows} columns={[
                { key: 'member', label: t('Anggota'), render: order => <div><strong>{order.user?.name}</strong><span className="deposit-table-meta">{order.user?.email}</span></div> },
                { key: 'reference', label: t('Referensi'), render: order => <div><code className="deposit-table-reference">{order.payment_reference}</code><span className="deposit-table-meta">{format.date(order.confirmed_at)}</span></div> },
                { key: 'credit', label: t('Tujuan kredit'), render: order => <div>{order.kind === 'tokens' ? t('Kredit token') : t('Saldo API PAYG')}<strong className="deposit-table-meta"><DepositCredit order={order} /></strong>{order.kind === 'tokens' ? <span className="deposit-table-meta">{format.count(order.base_tokens)} + {format.count(order.bonus_tokens)} {t('bonus')}</span> : <span className="deposit-table-meta">1 USD = {format.idr(order.idr_per_usd)}</span>}</div> },
                { key: 'amount', label: t('Pembayaran'), render: order => <strong className="deposit-numeric">{format.idr(order.amount_idr)}</strong> },
                { key: 'status', label: t('Status'), render: order => <div><DepositStatus status={order.status} />{(order.approved_at || order.rejected_at) && <span className="deposit-table-meta">{format.date(order.approved_at || order.rejected_at)}</span>}{order.note && <details className="deposit-table-meta"><summary>{t('Catatan admin')}</summary><p>{order.note}</p></details>}</div> },
                { key: 'action', label: t('Tindakan'), render: order => order.status === 'pending' ? <div className="deposit-admin-actions"><Button variant="secondary" disabled={resource.loading || Boolean(resource.error) || Boolean(review)} onClick={() => { setNotice(null); setReview({ order, action: 'approve' }); }}>{t('Setujui')}</Button><Button variant="danger" disabled={resource.loading || Boolean(resource.error) || Boolean(review)} onClick={() => { setNotice(null); setReview({ order, action: 'reject' }); }}>{t('Tolak')}</Button></div> : <span className="deposit-muted">{t('Selesai ditinjau')}</span> },
            ]} /></div> : !resource.error && <div className="deposit-empty"><h3>{filters.status === 'pending' ? t('Tidak ada deposit menunggu') : t('Tidak ada deposit yang cocok')}</h3><p>{t('Konfirmasi pembayaran anggota muncul di sini. Ubah filter untuk melihat keputusan sebelumnya.')}</p></div>}
            <DepositPagination pagination={resource.data?.pagination} loading={resource.loading || Boolean(review)} onPage={page => setFilters(current => ({ ...current, page }))} />
            {review && <DepositReview review={review} onClose={closeReview} onSaved={saved} />}
        </section>
    );
}
