import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useLocale } from '../contexts/LocaleContext';
import { apiRequest, formatCurrency } from '../lib/api';
import { Button, InlineAlert, Spinner, errorMessage, formatCount, formatLocalDate } from '../components/member/MemberUI';
import DataTable from '../components/dashboard/DataTable';
import QrisCheckout from '../components/QrisCheckout';
import DepositCheckout from '../components/deposit/DepositCheckout';
import { DepositCredit, DepositNotice, DepositPagination, DepositStatus, depositErrorText, useDepositFormat } from '../components/deposit/DepositUI';
import '../components/deposit/deposit.css';

const tabs = [
    { key: 'tokens', label: 'Tokens' },
    { key: 'wallet', label: 'Saldo PAYG' },
    { key: 'subscription', label: 'Langganan' },
    { key: 'storage', label: 'Penyimpanan' },
];
const historyStatuses = [
    ['checkout', 'Belum dikonfirmasi'], ['pending', 'Menunggu persetujuan'], ['cancelled', 'Dibatalkan'],
    ['approved', 'Disetujui'], ['rejected', 'Ditolak'], ['expired', 'Kedaluwarsa'],
];

const STORAGE_TERMINAL = ['approved', 'rejected'];
const STORAGE_GIB = 1024 ** 3;
const STORAGE_MIB = 1024 ** 2;

function formatBytes(value) {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    if (bytes >= STORAGE_GIB) { const gb = bytes / STORAGE_GIB; return `${gb.toFixed(Number.isInteger(gb) ? 0 : 1)} GB`; }
    if (bytes >= STORAGE_MIB) return `${Math.round(bytes / STORAGE_MIB)} MB`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${Math.round(bytes)} B`;
}

function formatCountdown(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function StorageUsageBar({ storage }) {
    const { t, locale } = useLocale();
    const dateLocale = locale === 'en' ? 'en-US' : 'id-ID';
    const used = Number(storage?.used_bytes) || 0;
    const quota = Number(storage?.quota_bytes) || 0;
    const unlimited = Boolean(storage?.unlimited);
    const exceeded = Boolean(storage?.exceeded);
    const pct = unlimited || quota <= 0 ? 0 : Math.min(100, Math.round((used / quota) * 100));
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-white/[0.07] dark:bg-white/[0.025]">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-[12px] font-semibold text-slate-900 dark:text-white">{t('Penyimpanan terpakai')}</span>
                <span className="text-[12px] font-semibold tabular-nums text-slate-700 dark:text-slate-200">{formatBytes(used)}{unlimited ? '' : ` / ${formatBytes(quota)}`}</span>
            </div>
            {!unlimited && (
                <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                    <div className={`h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none ${exceeded ? 'bg-red-600 dark:bg-red-500' : 'bg-red-500 dark:bg-red-400'}`} style={{ width: `${exceeded ? 100 : pct}%` }} />
                </div>
            )}
            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span>{unlimited ? t('Penyimpanan tanpa batas') : exceeded ? t('Kuota penyimpanan terlampaui') : `${t('Sisa')} ${formatBytes(storage?.remaining_bytes)}`}</span>
                {!unlimited && storage?.upgrade_expires_at && <span>{t('Upgrade berlaku sampai')} {formatLocalDate(storage.upgrade_expires_at, { locale: dateLocale })}</span>}
            </div>
            {Number(storage?.upgrade_bytes) > 0 && <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{t('Dasar')} {formatBytes(storage?.base_bytes)} + {t('upgrade')} {formatBytes(storage?.upgrade_bytes)}</p>}
        </div>
    );
}

function StorageUpgrade({ active }) {
    const { t, locale } = useLocale();
    const dateLocale = locale === 'en' ? 'en-US' : 'id-ID';
    const [usage, setUsage] = useState({ data: null, loading: true, error: null });
    const [plans, setPlans] = useState({ data: null, loading: true, error: null });
    const [orders, setOrders] = useState({ data: null, loading: true, error: null });
    const [revision, setRevision] = useState(0);
    const [step, setStep] = useState('select');
    const [selected, setSelected] = useState(null);
    const [checkout, setCheckout] = useState(null);
    const [order, setOrder] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [existingOrder, setExistingOrder] = useState(false);
    const [remainingMs, setRemainingMs] = useState(null);
    const [cancelPromptId, setCancelPromptId] = useState(null);
    const [cancellingId, setCancellingId] = useState(null);
    const countdownRef = useRef(null);
    const pollRef = useRef(null);
    const mountedRef = useRef(true);

    const stopCountdown = useCallback(() => { if (countdownRef.current) { clearInterval(countdownRef.current); countdownRef.current = null; } }, []);
    const stopPolling = useCallback(() => { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } }, []);
    const refresh = useCallback(() => setRevision(value => value + 1), []);

    useEffect(() => {
        mountedRef.current = true;
        return () => { mountedRef.current = false; stopCountdown(); stopPolling(); };
    }, [stopCountdown, stopPolling]);

    useEffect(() => {
        if (!active) return undefined;
        const controller = new AbortController();
        setUsage(current => ({ ...current, loading: true, error: null }));
        setPlans(current => ({ ...current, loading: true, error: null }));
        setOrders(current => ({ ...current, loading: true, error: null }));
        apiRequest('/api/storage/usage', { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setUsage({ data: data?.storage || null, loading: false, error: null }); })
            .catch(requestError => { if (!controller.signal.aborted) setUsage(current => ({ ...current, loading: false, error: requestError })); });
        apiRequest('/api/storage/plans', { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setPlans({ data: Array.isArray(data?.plans) ? data.plans : [], loading: false, error: null }); })
            .catch(requestError => { if (!controller.signal.aborted) setPlans(current => ({ ...current, loading: false, error: requestError })); });
        apiRequest('/api/storage/my-orders', { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setOrders({ data: Array.isArray(data?.orders) ? data.orders : [], loading: false, error: null }); })
            .catch(requestError => { if (!controller.signal.aborted) setOrders(current => ({ ...current, loading: false, error: requestError })); });
        return () => controller.abort();
    }, [active, revision]);

    const resetFlow = useCallback(() => {
        stopCountdown(); stopPolling();
        setStep('select'); setSelected(null); setCheckout(null); setOrder(null);
        setBusy(false); setError(null); setExistingOrder(false); setRemainingMs(null);
    }, [stopCountdown, stopPolling]);

    const startCheckout = useCallback(async (plan) => {
        setBusy(true); setError(null); setExistingOrder(false); stopPolling();
        try {
            const response = await apiRequest('/api/storage/checkout', { method: 'POST', body: { plan: plan.key } });
            const created = response?.checkout;
            if (!created?.payment_reference) throw new Error(t('Checkout tidak mengembalikan referensi pembayaran.'));
            if (!mountedRef.current) return;
            setSelected(plan); setCheckout(created); setOrder(null); setStep('pay');
            const expiry = new Date(created.expires_at).getTime();
            const tick = () => {
                const left = expiry - Date.now();
                if (!mountedRef.current) return;
                setRemainingMs(left);
                if (left <= 0) { stopCountdown(); setStep('select'); setCheckout(null); setError(new Error(t('Waktu pembayaran habis. Buat checkout baru untuk melanjutkan.'))); }
            };
            stopCountdown(); tick(); countdownRef.current = setInterval(tick, 1000);
        } catch (requestError) {
            if (!mountedRef.current) return;
            setError(requestError);
        } finally {
            if (mountedRef.current) setBusy(false);
        }
    }, [stopCountdown, stopPolling, t]);

    const confirmPaid = useCallback(async () => {
        if (!checkout?.payment_reference || !selected) return;
        setBusy(true); setError(null);
        try {
            const response = await apiRequest('/api/storage/order', { method: 'POST', body: { plan: selected.key, payment_reference: checkout.payment_reference } });
            if (!mountedRef.current) return;
            stopCountdown();
            setOrder(response?.order || null);
            setStep('wait');
            setRevision(value => value + 1);
        } catch (requestError) {
            if (!mountedRef.current) return;
            if (requestError?.details?.existing) setExistingOrder(true);
            setError(requestError);
        } finally {
            if (mountedRef.current) setBusy(false);
        }
    }, [checkout, selected, stopCountdown]);

    const cancelOrder = useCallback(async (id) => {
        if (!id || cancellingId) return;
        setCancellingId(id); setError(null);
        try {
            await apiRequest(`/api/storage/order/${id}/cancel`, { method: 'POST' });
            if (!mountedRef.current) return;
            setCancelPromptId(null);
            if (order?.id === id) resetFlow();
            setRevision(value => value + 1);
        } catch (requestError) {
            if (mountedRef.current) setError(requestError);
        } finally {
            if (mountedRef.current) setCancellingId(null);
        }
    }, [cancellingId, order?.id, resetFlow]);

    useEffect(() => {
        if (step !== 'wait') { stopPolling(); return undefined; }
        const check = async () => {
            try {
                const response = await apiRequest('/api/storage/my-orders');
                const list = Array.isArray(response?.orders) ? response.orders : [];
                if (mountedRef.current) setOrders({ data: list, loading: false, error: null });
                const current = list.find(item => item.payment_reference === checkout?.payment_reference)
                    || (order?.id ? list.find(item => item.id === order.id) : null)
                    || list.find(item => String(item.status).toLowerCase() === 'pending');
                if (!current || !mountedRef.current) return;
                setOrder(current);
                if (STORAGE_TERMINAL.includes(String(current.status).toLowerCase())) {
                    stopPolling();
                    const approved = String(current.status).toLowerCase() === 'approved';
                    setStep(approved ? 'approved' : 'rejected');
                    if (approved) setRevision(value => value + 1);
                }
            } catch { /* transient poll failure: keep polling */ }
        };
        check();
        pollRef.current = setInterval(check, 3000);
        return () => stopPolling();
    }, [step, checkout, order?.id, stopPolling]);

    const planList = Array.isArray(plans.data) ? plans.data : [];
    const orderList = Array.isArray(orders.data) ? orders.data : [];

    return (
        <div className="space-y-5">
            {usage.loading && !usage.data ? (
                <div className="flex min-h-[72px] items-center justify-center rounded-xl border border-slate-200 bg-slate-50/70 dark:border-white/[0.07] dark:bg-white/[0.025]"><Spinner label={t('Memuat penggunaan penyimpanan')} /></div>
            ) : usage.error ? (
                <InlineAlert tone="error" action={<Button variant="secondary" onClick={refresh}>{t('Coba lagi')}</Button>}>{errorMessage(usage.error, t('Penggunaan penyimpanan tidak dapat dimuat.'))}</InlineAlert>
            ) : usage.data ? <StorageUsageBar storage={usage.data} /> : null}

            <div className="space-y-4">
                {step === 'select' && (
                    <>
                        {error && <InlineAlert tone="error">{errorMessage(error, t('Checkout QRIS gagal dibuat.'))}</InlineAlert>}
                        {plans.loading && !plans.data ? (
                            <div className="flex min-h-44 items-center justify-center"><Spinner label={t('Memuat paket penyimpanan')} /></div>
                        ) : plans.error ? (
                            <div className="space-y-3">
                                <InlineAlert tone="error">{errorMessage(plans.error, t('Paket penyimpanan tidak dapat dimuat.'))}</InlineAlert>
                                <Button variant="secondary" onClick={refresh}>{t('Coba lagi')}</Button>
                            </div>
                        ) : planList.length === 0 ? (
                            <InlineAlert tone="info">{t('Pengelola belum menyediakan paket penyimpanan yang dapat dibeli.')}</InlineAlert>
                        ) : (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {planList.map(plan => (
                                    <button key={plan.key} type="button" disabled={busy} onClick={() => startCheckout(plan)} className={`rounded-lg border p-3 text-left transition disabled:opacity-60 ${selected?.key === plan.key && busy ? 'border-red-500 bg-red-50 ring-2 ring-red-500/10 dark:bg-red-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-white/[0.08] dark:hover:border-white/20'}`}>
                                        <span className="block text-[12px] font-semibold text-slate-900 dark:text-white">{plan.label}</span>
                                        <span className="mt-1 block text-[11px] text-slate-500 dark:text-slate-400">+{formatBytes(plan.extra_bytes)} · {formatCount(plan.days)} {t('hari')}</span>
                                        <span className="mt-2 block text-[12px] font-bold text-red-600 dark:text-red-400">{formatCurrency(plan.price_idr)}</span>
                                        {busy && selected?.key === plan.key && <span className="mt-2 block"><Spinner label={t('Membuat checkout')} /></span>}
                                    </button>
                                ))}
                            </div>
                        )}
                    </>
                )}

                {step === 'pay' && checkout && (
                    <div className="space-y-4 text-center">
                        <div>
                            <h3 className="text-base font-bold text-slate-950 dark:text-white">{t('Pindai QRIS untuk membayar')}</h3>
                            <p className="mt-1 text-[12px] text-slate-500 dark:text-slate-400">{selected?.label} — <span className="font-bold text-red-600 dark:text-red-400">{formatCurrency(checkout.amount_idr)}</span></p>
                        </div>
                        <div className="inline-block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10">
                            <img src={checkout.qr_image_url} alt={t('Kode QRIS pembayaran')} className="h-[240px] w-[240px] object-contain" />
                        </div>
                        <p className={`text-[12px] font-semibold tabular-nums ${remainingMs !== null && remainingMs < 60000 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300'}`}>{t('Kode kedaluwarsa dalam')} {remainingMs === null ? '—' : formatCountdown(remainingMs)}</p>
                        {error && (
                            <div className="text-left">
                                <InlineAlert tone="error">{errorMessage(error, t('Konfirmasi pembayaran gagal.'))}</InlineAlert>
                                {existingOrder && <div className="mt-2"><Button variant="secondary" onClick={() => { setError(null); setExistingOrder(false); stopCountdown(); setStep('wait'); }}>{t('Lacak order yang menunggu persetujuan')}</Button></div>}
                            </div>
                        )}
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-center">
                            <Button onClick={confirmPaid} disabled={busy}>{busy ? t('Mengonfirmasi…') : t('Saya sudah membayar')}</Button>
                            <Button variant="secondary" onClick={resetFlow} disabled={busy}>{t('Pilih paket lain')}</Button>
                        </div>
                    </div>
                )}

                {step === 'wait' && (
                    <div className="space-y-3 text-center">
                        <div className="flex justify-center"><Spinner label={t('Menunggu persetujuan admin')} /></div>
                        <p className="text-[12px] text-slate-500 dark:text-slate-400">{t('Order Anda sudah tercatat dan menunggu persetujuan admin. Halaman ini diperbarui otomatis.')}</p>
                        {order?.id && <p className="text-[11px] text-slate-400 dark:text-slate-500">ULTR-{String(order.id).padStart(4, '0')}</p>}
                        {error && <InlineAlert tone="error">{errorMessage(error, t('Order tidak dapat dibatalkan.'))}</InlineAlert>}
                        {order?.id && (cancelPromptId === order.id ? (
                            <div className="space-y-2">
                                <p className="text-[12px] font-semibold text-red-600 dark:text-red-300">{t('Batalkan order penyimpanan ini? Jika Anda sudah transfer, jangan batalkan - tunggu peninjauan admin.')}</p>
                                <div className="flex justify-center gap-2">
                                    <Button variant="secondary" onClick={() => cancelOrder(order.id)} disabled={cancellingId === order.id}>{cancellingId === order.id ? t('Membatalkan…') : t('Ya, batalkan order')}</Button>
                                    <Button variant="ghost" onClick={() => setCancelPromptId(null)} disabled={cancellingId === order.id}>{t('Kembali')}</Button>
                                </div>
                            </div>
                        ) : (
                            <Button variant="ghost" onClick={() => setCancelPromptId(order.id)} disabled={cancellingId !== null}>{t('Batalkan order')}</Button>
                        ))}
                    </div>
                )}

                {step === 'approved' && (
                    <div className="space-y-3">
                        <InlineAlert tone="success">{t('Pembayaran disetujui. Kuota penyimpanan Anda sudah bertambah.')}</InlineAlert>
                        <div className="flex justify-end gap-2"><Button variant="secondary" onClick={resetFlow}>{t('Beli lagi')}</Button></div>
                    </div>
                )}

                {step === 'rejected' && (
                    <div className="space-y-3">
                        <InlineAlert tone="error">{t('Order ditolak oleh admin. Silakan buat checkout baru atau hubungi pengelola.')}</InlineAlert>
                        <div className="flex justify-end gap-2"><Button variant="secondary" onClick={resetFlow}>{t('Coba lagi')}</Button></div>
                    </div>
                )}
            </div>

            <div className="space-y-2 border-t border-slate-200 pt-4 dark:border-white/[0.08]">
                <div className="flex items-center justify-between gap-3">
                    <h3 className="text-[13px] font-bold text-slate-900 dark:text-white">{t('Pesanan penyimpanan')}</h3>
                    <Button variant="secondary" disabled={orders.loading} onClick={refresh}>{orders.loading ? t('Memuat…') : t('Perbarui')}</Button>
                </div>
                {orders.loading && !orders.data ? (
                    <div className="py-2"><Spinner label={t('Memuat pesanan penyimpanan')} /></div>
                ) : orders.error ? (
                    <InlineAlert tone="error" action={<Button variant="secondary" onClick={refresh}>{t('Coba lagi')}</Button>}>{errorMessage(orders.error, t('Pesanan penyimpanan tidak dapat dimuat.'))}</InlineAlert>
                ) : orderList.length === 0 ? (
                    <InlineAlert tone="info">{t('Belum ada pesanan penyimpanan.')}</InlineAlert>
                ) : (
                    <ul className="space-y-2">
                        {orderList.map(item => {
                            const status = String(item.status || '').toLowerCase();
                            const pending = status === 'pending';
                            return (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-white/[0.08]">
                                    <div className="min-w-0">
                                        <p className="text-[12px] font-semibold text-slate-900 dark:text-white">{item.label || item.plan_key}</p>
                                        <p className="mt-0.5 text-[11px] tabular-nums text-slate-500 dark:text-slate-400">{item.extra_bytes ? `+${formatBytes(item.extra_bytes)} · ` : ''}{item.days ? `${formatCount(item.days)} ${t('hari')} · ` : ''}{formatCurrency(item.price ?? item.price_idr)}</p>
                                        <p className="mt-0.5 text-[10px] text-slate-400 dark:text-slate-500">{formatLocalDate(item.created_at, { locale: dateLocale })}{item.payment_reference ? ` · ${item.payment_reference}` : ''}</p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <DepositStatus status={status} />
                                        {pending && (cancelPromptId === item.id ? (
                                            <span className="flex items-center gap-1">
                                                <Button variant="secondary" onClick={() => cancelOrder(item.id)} disabled={cancellingId === item.id}>{cancellingId === item.id ? t('Membatalkan…') : t('Ya')}</Button>
                                                <Button variant="ghost" onClick={() => setCancelPromptId(null)} disabled={cancellingId === item.id}>{t('Batal')}</Button>
                                            </span>
                                        ) : (
                                            <Button variant="ghost" onClick={() => setCancelPromptId(item.id)} disabled={cancellingId !== null}>{t('Batalkan')}</Button>
                                        ))}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </div>
    );
}

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
    const displayedTab = activeOrderId && (activeTab === 'subscription' || activeTab === 'storage') ? 'tokens' : activeTab;
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
        const selfContained = key === 'subscription' || key === 'storage';
        if (selfContained) setCheckoutSnapshot(null);
        updateQuery({ tab: key === 'tokens' ? null : key, ...(selfContained ? { order: null } : {}) });
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
                    <section id="deposit-panel-storage" className="deposit-tab-panel" role="tabpanel" aria-labelledby="deposit-tab-storage" hidden={displayedTab !== 'storage'} tabIndex={0}>
                        <div className="deposit-section-heading"><div><h2>{t('Tingkatkan penyimpanan')}</h2><p>{t('Perluas kuota Library Anda untuk jangka waktu tertentu. Bayar dengan QRIS lalu tunggu persetujuan admin.')}</p></div></div>
                        <div className={`deposit-subscription deposit-surface${activeOrderId ? ' deposit-disabled-surface' : ''}`} aria-disabled={activeOrderId ? 'true' : undefined}>{activeOrderId ? <DepositNotice>{t('Tutup detail deposit sebelum membeli penyimpanan.')}</DepositNotice> : <StorageUpgrade active={displayedTab === 'storage'} />}</div>
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
