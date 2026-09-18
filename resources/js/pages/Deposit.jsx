import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useLocale } from '../contexts/LocaleContext';
import { apiRequest } from '../lib/api';
import { Button } from '../components/member/MemberUI';
import DataTable from '../components/dashboard/DataTable';
import QrisCheckout from '../components/QrisCheckout';
import DepositCheckout from '../components/deposit/DepositCheckout';
import { DepositCredit, DepositNotice, DepositPagination, DepositStatus, depositErrorText, useDepositFormat } from '../components/deposit/DepositUI';
import '../components/deposit/deposit.css';

const tabs = [
    { key: 'tokens', label: 'Tokens' },
    { key: 'wallet', label: 'Saldo PAYG' },
    { key: 'subscription', label: 'Langganan' },
];
const historyStatuses = [
    ['checkout', 'Belum dikonfirmasi'], ['pending', 'Menunggu persetujuan'],
    ['approved', 'Disetujui'], ['rejected', 'Ditolak'], ['expired', 'Kedaluwarsa'],
];

export default function Deposit() {
    const { t, localizedPath } = useLocale();
    const { refreshUser } = useAuth();
    const format = useDepositFormat();
    const [searchParams, setSearchParams] = useSearchParams();
    const [catalog, setCatalog] = useState({ data: null, loading: true, error: null });
    const [catalogRevision, setCatalogRevision] = useState(0);
    const [history, setHistory] = useState({ data: null, loading: true, error: null, query: '' });
    const [historyRevision, setHistoryRevision] = useState(0);
    const [amount, setAmount] = useState('');
    const [creating, setCreating] = useState(null);
    const [checkoutError, setCheckoutError] = useState(null);
    const [checkoutSnapshot, setCheckoutSnapshot] = useState(null);
    const [subscriptionNotice, setSubscriptionNotice] = useState(false);
    const [paymentBusy, setPaymentBusy] = useState(false);
    const mutationLock = useRef(false);
    const mounted = useRef(true);
    const checkoutTrigger = useRef(null);
    const tabRefs = useRef({});
    const historyHeading = useRef(null);

    const activeTab = tabs.some(item => item.key === searchParams.get('tab')) ? searchParams.get('tab') : 'tokens';
    const historyStatus = historyStatuses.some(([key]) => key === searchParams.get('status')) ? searchParams.get('status') : '';
    const historyKind = ['tokens', 'wallet'].includes(searchParams.get('kind')) ? searchParams.get('kind') : '';
    const pageValue = Number(searchParams.get('page'));
    const page = Number.isSafeInteger(pageValue) && pageValue > 0 ? pageValue : 1;
    const orderValue = Number(searchParams.get('order'));
    const activeOrderId = Number.isSafeInteger(orderValue) && orderValue > 0 ? orderValue : null;
    const displayedTab = activeOrderId && activeTab === 'subscription' ? 'tokens' : activeTab;
    const query = new URLSearchParams({ page: String(page), per_page: '20', ...(historyStatus ? { status: historyStatus } : {}), ...(historyKind ? { kind: historyKind } : {}) }).toString();

    useEffect(() => {
        mounted.current = true;
        return () => { mounted.current = false; };
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        setCatalog(current => ({ ...current, loading: true, error: null }));
        apiRequest('/api/deposits/catalog', { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setCatalog({ data, loading: false, error: null }); })
            .catch(error => { if (!controller.signal.aborted) setCatalog(current => ({ ...current, loading: false, error })); });
        return () => controller.abort();
    }, [catalogRevision]);

    useEffect(() => {
        const controller = new AbortController();
        setHistory(current => ({ data: current.query === query ? current.data : null, query, loading: true, error: null }));
        apiRequest(`/api/deposits?${query}`, { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setHistory({ data, query, loading: false, error: null }); })
            .catch(error => { if (!controller.signal.aborted) setHistory(current => ({ ...current, loading: false, error })); });
        return () => controller.abort();
    }, [query, historyRevision]);

    const updateQuery = (values) => {
        setSearchParams(current => {
            const next = new URLSearchParams(current);
            Object.entries(values).forEach(([key, value]) => {
                if (value === null || value === '') next.delete(key);
                else next.set(key, String(value));
            });
            return next;
        }, { replace: true });
    };

    const selectTab = key => {
        if (paymentBusy) return;
        if (key === 'subscription') setCheckoutSnapshot(null);
        updateQuery({ tab: key === 'tokens' ? null : key, ...(key === 'subscription' ? { order: null } : {}) });
    };
    const tabKeyDown = (event, index) => {
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else return;
        event.preventDefault();
        selectTab(tabs[next].key);
        tabRefs.current[tabs[next].key]?.focus();
    };

    const startCheckout = async (body, trigger) => {
        if (mutationLock.current || activeOrderId || catalog.loading || catalog.error) return;
        mutationLock.current = true;
        checkoutTrigger.current = trigger;
        setCreating(body.kind === 'tokens' ? body.package_code : 'wallet');
        setCheckoutError(null);
        try {
            const result = await apiRequest('/api/deposits/checkout', { method: 'POST', body });
            if (!result?.checkout?.id || !result.checkout.payment_reference) throw new Error('Invalid deposit checkout');
            if (!mounted.current) return;
            setCheckoutSnapshot(result.checkout);
            updateQuery({ order: result.checkout.id, page: null });
        } catch (error) {
            if (mounted.current) setCheckoutError(error);
        } finally {
            mutationLock.current = false;
            if (mounted.current) {
                setCreating(null);
                setHistoryRevision(value => value + 1);
            }
        }
    };

    const showOrder = (order, trigger) => {
        if (paymentBusy) return;
        checkoutTrigger.current = trigger;
        setCheckoutSnapshot(order);
        setCheckoutError(null);
        updateQuery({ order: order.id });
    };
    const closeOrder = () => {
        const focusTab = displayedTab;
        updateQuery({ order: null });
        setCheckoutSnapshot(null);
        requestAnimationFrame(() => {
            if (checkoutTrigger.current?.isConnected) checkoutTrigger.current.focus();
            else tabRefs.current[focusTab]?.focus();
        });
    };
    const updateBalances = useCallback((result) => {
        setCatalog(current => {
            if (!current.data) return current;
            if (current.data.token_balance === result.token_balance && current.data.wallet?.balance_microusd === result.wallet?.balance_microusd) return current;
            return { ...current, data: { ...current.data, token_balance: result.token_balance, wallet: result.wallet } };
        });
    }, []);
    const updatePaymentBusy = useCallback(value => setPaymentBusy(value), []);
    const orderChanged = useCallback((order) => {
        setHistoryRevision(value => value + 1);
        if (order.status === 'approved') setCatalogRevision(value => value + 1);
    }, []);
    const subscriptionApproved = useCallback(() => {
        setSubscriptionNotice(true);
        setCatalogRevision(value => value + 1);
        refreshUser();
    }, [refreshUser]);

    const tokenPackages = Array.isArray(catalog.data?.token_packages) ? catalog.data.token_packages : [];
    const durationPackages = Object.entries(catalog.data?.duration_packages || {})
        .filter(([, item]) => item?.is_active !== false)
        .map(([key, item]) => ({ key, label: t(item.label || key), days: item.days, price: item.price_idr ?? item.price, is_active: item.is_active }));
    const limits = catalog.data?.limits;
    const rate = Number(catalog.data?.conversion?.idr_per_usd);
    const amountValue = /^\d+$/.test(amount) ? Number(amount) : NaN;
    const validAmount = Number.isSafeInteger(amountValue) && amountValue >= Number(limits?.min_idr) && amountValue <= Number(limits?.max_idr) && rate > 0;
    const previewMicros = validAmount ? Math.floor(amountValue * 1_000_000 / rate) : null;
    const controlsDisabled = Boolean(creating || activeOrderId || catalog.loading || catalog.error || paymentBusy);
    const historyRows = history.data?.orders || [];

    return (
        <div className="ui-page deposit-page">
            <header className="deposit-header">
                <div><h1>{t('Deposit')}</h1><p>{t('Isi kredit untuk berkarya, saldo untuk API, atau perpanjang langganan Anda.')}</p></div>
                <Link to={localizedPath('/token-usage')} className="deposit-text-link">{t('Lihat pemakaian')}</Link>
            </header>

            <section className="deposit-balances" aria-label={t('Saldo akun')} aria-busy={catalog.loading}>
                <div><h2>{t('Kredit token')}</h2><p className="deposit-balance-value">{format.count(catalog.data?.token_balance)} <span>{t('token')}</span></p><p>{t('Untuk generasi gambar dan video di workspace.')}</p></div>
                <div><h2>{t('Saldo API PAYG')}</h2><p className="deposit-balance-value">{format.usdMicros(catalog.data?.wallet?.balance_microusd)}</p><p>{t('Saldo USD untuk pemakaian melalui API. Terpisah dari token.')}</p></div>
            </section>

            {catalog.error && <DepositNotice tone="error" action={<Button variant="secondary" onClick={() => setCatalogRevision(value => value + 1)}>{t('Muat ulang katalog')}</Button>}>{depositErrorText(catalog.error, t)}</DepositNotice>}
            {subscriptionNotice && <DepositNotice tone="success">{t('Langganan diperpanjang. Informasi akun telah diperbarui.')}</DepositNotice>}

            <div className="deposit-tabs" role="tablist" aria-label={t('Pilihan deposit')}>
                {tabs.map((item, index) => <button key={item.key} ref={node => { tabRefs.current[item.key] = node; }} type="button" role="tab" id={`deposit-tab-${item.key}`} aria-selected={displayedTab === item.key} aria-controls={`deposit-panel-${item.key}`} tabIndex={displayedTab === item.key ? 0 : -1} disabled={paymentBusy} onClick={() => selectTab(item.key)} onKeyDown={event => tabKeyDown(event, index)}>{t(item.label)}</button>)}
            </div>

            <div className={`deposit-workspace${activeOrderId ? ' has-payment' : ''}`}>
                <div className="deposit-workspace-main">
                    {checkoutError && <DepositNotice tone="error" action={<Button variant="secondary" onClick={() => { setHistoryRevision(value => value + 1); historyHeading.current?.focus(); }}>{t('Periksa riwayat')}</Button>}>
                        <p>{depositErrorText(checkoutError, t)}</p><p>{t('Jika checkout sudah tercatat, lanjutkan dari riwayat. Tidak ada pembayaran yang dikirim otomatis.')}</p>
                    </DepositNotice>}
                    {activeOrderId && <p className="deposit-selection-note">{t('Detail deposit sedang terbuka. Tutup detail untuk memilih deposit lain.')}</p>}

                    <section id="deposit-panel-tokens" className="deposit-tab-panel" role="tabpanel" aria-labelledby="deposit-tab-tokens" hidden={displayedTab !== 'tokens'} tabIndex={0}>
                        <div className="deposit-section-heading"><div><h2>{t('Pilih paket token')}</h2><p>{t('Jumlah di bawah sudah termasuk bonus. Harga per token dihitung dari total kredit yang Anda terima.')}</p></div></div>
                        {catalog.loading && !catalog.data ? <p className="deposit-loading" role="status">{t('Memuat paket token…')}</p> : tokenPackages.length ? <div className="deposit-token-grid">
                            {tokenPackages.map(item => <button className="deposit-token-option" key={item.code} type="button" aria-label={`${t('Beli')} ${format.count(item.total_tokens)} ${t('token')}`} disabled={controlsDisabled} onClick={event => startCheckout({ kind: 'tokens', package_code: item.code }, event.currentTarget)}>
                                <span className="deposit-token-total">{format.count(item.total_tokens)} <span>{t('token')}</span></span>
                                <span className="deposit-token-breakdown">{format.count(item.base_tokens)} {t('token dasar')}<br />+ {format.count(item.bonus_tokens)} {t('bonus')}</span>
                                <span className="deposit-token-price">{format.idr(item.price_idr)}</span>
                                <span className="deposit-token-unit">{Number(item.total_tokens) > 0 ? format.unitIdr(Number(item.price_idr) / Number(item.total_tokens)) : '—'} / {t('token')}</span>
                                <span className="deposit-token-action">{creating === item.code ? t('Membuat checkout…') : t('Beli dengan QRIS')}<svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" /></svg></span>
                            </button>)}
                        </div> : !catalog.error && <div className="deposit-empty"><h3>{t('Paket token belum tersedia')}</h3><p>{t('Admin belum mengaktifkan paket yang dapat dibeli. Muat ulang katalog nanti.')}</p></div>}
                        <p className="deposit-fine-print">{t('Token bukan saldo uang dan tidak mengisi dompet API PAYG. Pilih tab Saldo PAYG untuk kebutuhan API.')}</p>
                    </section>

                    <section id="deposit-panel-wallet" className="deposit-tab-panel" role="tabpanel" aria-labelledby="deposit-tab-wallet" hidden={displayedTab !== 'wallet'} tabIndex={0}>
                        <div className="deposit-section-heading"><div><h2>{t('Isi saldo API PAYG')}</h2><p>{t('Bayar dalam rupiah. Saldo dikreditkan dalam USD menggunakan kurs yang dikunci saat checkout.')}</p></div></div>
                        {catalog.loading && !catalog.data ? <p className="deposit-loading" role="status">{t('Memuat kurs dan batas deposit…')}</p> : catalog.data && <form className="deposit-wallet-form deposit-surface" onSubmit={event => { event.preventDefault(); if (validAmount) startCheckout({ kind: 'wallet', amount_idr: amountValue }, event.nativeEvent.submitter); }}>
                            <label htmlFor="deposit-amount">{t('Jumlah deposit (IDR)')}</label>
                            <div className="deposit-amount-input"><span aria-hidden="true">Rp</span><input id="deposit-amount" type="text" inputMode="numeric" autoComplete="off" pattern="[0-9]+" required value={amount} onChange={event => setAmount(event.target.value)} disabled={controlsDisabled} aria-invalid={amount !== '' && !validAmount} aria-describedby={`deposit-amount-hint${amount !== '' && !validAmount ? ' deposit-amount-error' : ''}`} /></div>
                            <p id="deposit-amount-hint" className="deposit-fine-print">{t('Minimum')} {format.idr(limits?.min_idr)} · {t('Maksimum')} {format.idr(limits?.max_idr)}. {t('Masukkan rupiah bulat tanpa pemisah.')}</p>
                            {amount !== '' && !validAmount && <p id="deposit-amount-error" className="deposit-field-error">{t('Masukkan jumlah dalam batas deposit yang tersedia.')}</p>}
                            <div className="deposit-wallet-preview"><span>{t('Pratinjau kredit API')}</span><output htmlFor="deposit-amount" aria-live="polite">{format.usdMicros(previewMicros)}</output><p>1 USD = {format.idr(rate)}</p></div>
                            <p className="deposit-fine-print">{t('Pratinjau dibulatkan ke bawah hingga 6 desimal USD. Total dan kredit final ditampilkan sebelum Anda membayar.')}</p>
                            <Button type="submit" disabled={controlsDisabled || !validAmount}>{creating === 'wallet' ? t('Membuat checkout…') : t('Lanjut ke QRIS')}</Button>
                        </form>}
                    </section>

                    <section id="deposit-panel-subscription" className="deposit-tab-panel" role="tabpanel" aria-labelledby="deposit-tab-subscription" hidden={displayedTab !== 'subscription'} tabIndex={0}>
                        <div className={`deposit-subscription deposit-surface${activeOrderId ? ' deposit-disabled-surface' : ''}`} aria-disabled={activeOrderId ? 'true' : undefined}>{activeOrderId ? <DepositNotice>{t('Tutup detail deposit sebelum memulai pembayaran langganan.')}</DepositNotice> : <QrisCheckout packages={durationPackages} loading={catalog.loading && !catalog.data} error={catalog.error} onReloadPackages={() => setCatalogRevision(value => value + 1)} onApproved={subscriptionApproved} />}</div>
                    </section>
                </div>
                {activeOrderId && <DepositCheckout key={activeOrderId} id={activeOrderId} initialOrder={checkoutSnapshot?.id === activeOrderId ? checkoutSnapshot : historyRows.find(item => item.id === activeOrderId)} onClose={closeOrder} onBalances={updateBalances} onChanged={orderChanged} onBusyChange={updatePaymentBusy} />}
            </div>

            <section className="deposit-history deposit-surface" aria-labelledby="deposit-history-title">
                <div className="deposit-section-heading"><div><h2 ref={historyHeading} tabIndex={-1} id="deposit-history-title">{t('Riwayat deposit')}</h2><p>{t('Checkout, konfirmasi, dan keputusan admin tersimpan di sini, termasuk setelah Anda meninggalkan halaman.')}</p></div><Button variant="secondary" disabled={history.loading} onClick={() => setHistoryRevision(value => value + 1)}>{history.loading ? t('Memuat…') : t('Perbarui riwayat')}</Button></div>
                <div className="deposit-history-filters">
                    <label>{t('Jenis deposit')}<select value={historyKind} onChange={event => updateQuery({ kind: event.target.value, page: null })}><option value="">{t('Semua jenis')}</option><option value="tokens">{t('Kredit token')}</option><option value="wallet">{t('Saldo API PAYG')}</option></select></label>
                    <label>{t('Status deposit')}<select value={historyStatus} onChange={event => updateQuery({ status: event.target.value, page: null })}><option value="">{t('Semua status')}</option>{historyStatuses.map(([key, label]) => <option key={key} value={key}>{t(label)}</option>)}</select></label>
                </div>
                {history.error && <DepositNotice tone="error" action={<Button variant="secondary" onClick={() => setHistoryRevision(value => value + 1)}>{t('Coba lagi')}</Button>}>{depositErrorText(history.error, t)}</DepositNotice>}
                {history.loading && !history.data ? <p className="deposit-loading" role="status">{t('Memuat riwayat deposit…')}</p> : historyRows.length ? <div className="deposit-history-table" role="region" aria-label={t('Daftar deposit')} tabIndex={0} aria-busy={history.loading}><DataTable rows={historyRows} columns={[
                    { key: 'reference', label: t('Referensi'), render: order => <div><code className="deposit-table-reference">{order.payment_reference}</code><span className="deposit-table-meta">{format.date(order.created_at)}</span></div> },
                    { key: 'kind', label: t('Jenis'), render: order => <div>{order.kind === 'tokens' ? t('Kredit token') : t('Saldo API PAYG')}<span className="deposit-table-meta"><DepositCredit order={order} /></span></div> },
                    { key: 'amount', label: t('Pembayaran'), render: order => <span className="deposit-numeric">{format.idr(order.amount_idr)}</span> },
                    { key: 'status', label: t('Status'), render: order => <DepositStatus status={order.status} /> },
                    { key: 'action', label: t('Tindakan'), render: order => <Button variant="secondary" disabled={Boolean(creating) || paymentBusy} onClick={event => showOrder(order, event.currentTarget)}>{order.status === 'checkout' ? t('Lanjutkan pembayaran') : order.status === 'pending' ? t('Pantau deposit') : t('Lihat detail')}</Button> },
                ]} /></div> : !history.error && <div className="deposit-empty"><h3>{historyKind || historyStatus ? t('Tidak ada deposit yang cocok') : t('Belum ada deposit')}</h3><p>{historyKind || historyStatus ? t('Ubah filter untuk melihat deposit lainnya.') : t('Pilih paket token atau isi saldo PAYG untuk memulai. Checkout Anda akan tercatat di sini.')}</p>{(historyKind || historyStatus) && <Button variant="secondary" onClick={() => updateQuery({ kind: null, status: null, page: null })}>{t('Hapus filter')}</Button>}</div>}
                <DepositPagination pagination={history.data?.pagination} loading={history.loading} onPage={next => updateQuery({ page: next })} />
            </section>
        </div>
    );
}
