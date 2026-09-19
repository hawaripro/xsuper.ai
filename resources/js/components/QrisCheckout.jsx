import React, { useCallback, useEffect, useRef, useState } from "react";
import { apiRequest } from "../lib/api";
import { useLocale } from "../contexts/LocaleContext";
import {
    Button,
    InlineAlert,
    Spinner,
    errorMessage,
    formatCount,
} from "./member/MemberUI";

const TERMINAL_STATUSES = ["approved", "rejected"];

function formatIdr(value) {
    return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value || 0));
}

function formatCountdown(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;
    return `${minutes}:${String(seconds).padStart(2, "0")}`;
}

/**
 * Reusable real QRIS purchase state machine.
 *
 * Flow: select package -> POST /api/period/checkout -> show QR image, exact IDR
 * amount, expiry countdown -> explicit "paid" confirmation -> POST /api/period/order
 * -> poll /api/period/my-orders every 3s until approved/rejected. All timers are
 * cleaned up on step change, close, and unmount. No order is ever submitted
 * without a live checkout reference.
 *
 * Props:
 * - packages: [{ key, label, days, price, is_active }] active catalog entries.
 * - loading/error/onReloadPackages: package catalog state owned by the caller.
 * - initialPackageKey: optional pre-selected package key.
 * - onApproved(order): called once the backend reports the order approved.
 * - onClose: optional close handler; when provided a close button is rendered.
 */
export default function QrisCheckout({ packages, loading = false, error: packagesError = null, onReloadPackages, initialPackageKey = null, onApproved, onClose }) {
    const { t } = useLocale();
    const [step, setStep] = useState("select"); // select | pay | wait | approved | rejected
    const [selected, setSelected] = useState(null);
    const [checkout, setCheckout] = useState(null);
    const [order, setOrder] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [existingOrder, setExistingOrder] = useState(false);
    const [remainingMs, setRemainingMs] = useState(null);
    const countdownRef = useRef(null);
    const pollRef = useRef(null);
    const mountedRef = useRef(true);

    const stopCountdown = useCallback(() => {
        if (countdownRef.current) { clearInterval(countdownRef.current); countdownRef.current = null; }
    }, []);
    const stopPolling = useCallback(() => {
        if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    }, []);

    useEffect(() => {
        mountedRef.current = true;
        return () => { mountedRef.current = false; stopCountdown(); stopPolling(); };
    }, [stopCountdown, stopPolling]);

    // Pre-select when caller supplies a package key.
    useEffect(() => {
        if (!initialPackageKey || !Array.isArray(packages)) return;
        const match = packages.find((pkg) => pkg.key === initialPackageKey);
        if (match) setSelected((current) => current ?? match);
    }, [initialPackageKey, packages]);

    const resetFlow = useCallback(() => {
        stopCountdown(); stopPolling();
        setStep("select"); setSelected(null); setCheckout(null); setOrder(null);
        setBusy(false); setError(null); setExistingOrder(false); setRemainingMs(null);
    }, [stopCountdown, stopPolling]);

    const handleClose = useCallback(() => {
        resetFlow();
        onClose?.();
    }, [resetFlow, onClose]);

    // Step 1 -> 2: create a real checkout on the backend.
    const startCheckout = useCallback(async (pkg) => {
        setBusy(true); setError(null); setExistingOrder(false);
        stopPolling();
        try {
            const response = await apiRequest("/api/period/checkout", { method: "POST", body: { package: pkg.key } });
            const created = response?.checkout;
            if (!created?.payment_reference) throw new Error(t("Checkout tidak mengembalikan referensi pembayaran."));
            if (!mountedRef.current) return;
            setSelected(pkg);
            setCheckout(created);
            setOrder(null);
            setStep("pay");
            const expiry = new Date(created.expires_at).getTime();
            const tick = () => {
                const left = expiry - Date.now();
                if (!mountedRef.current) return;
                setRemainingMs(left);
                if (left <= 0) { stopCountdown(); setStep("select"); setCheckout(null); setError(new Error(t("Waktu pembayaran habis. Buat checkout baru untuk melanjutkan."))); }
            };
            stopCountdown();
            tick();
            countdownRef.current = setInterval(tick, 1000);
        } catch (requestError) {
            if (!mountedRef.current) return;
            setError(requestError);
        } finally {
            if (mountedRef.current) setBusy(false);
        }
    }, [stopCountdown, stopPolling, t]);

    // Step 2 -> 3: explicit "paid" confirmation creates the pending order.
    const confirmPaid = useCallback(async () => {
        if (!checkout?.payment_reference || !selected) return;
        setBusy(true); setError(null);
        try {
            const response = await apiRequest("/api/period/order", {
                method: "POST",
                body: { package: selected.key, payment_reference: checkout.payment_reference },
            });
            if (!mountedRef.current) return;
            stopCountdown();
            setOrder(response?.order || null);
            setStep("wait");
        } catch (requestError) {
            if (!mountedRef.current) return;
            if (requestError?.details?.existing) setExistingOrder(true);
            setError(requestError);
        } finally {
            if (mountedRef.current) setBusy(false);
        }
    }, [checkout, selected, stopCountdown]);

    // Step 3: the member may withdraw a pending order before the admin decision.
    const [cancelPrompt, setCancelPrompt] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const cancelOrder = useCallback(async () => {
        if (!order?.id || cancelling) return;
        setCancelling(true); setError(null);
        try {
            await apiRequest(`/api/period/order/${order.id}/cancel`, { method: "POST" });
            if (!mountedRef.current) return;
            stopPolling();
            setCancelPrompt(false);
            setOrder(null);
            setSelected(null);
            setCheckout(null);
            setStep("select");
        } catch (requestError) {
            if (mountedRef.current) setError(requestError);
        } finally {
            if (mountedRef.current) setCancelling(false);
        }
    }, [order?.id, cancelling, stopPolling]);

    // Step 3: poll every 3s until the order reaches a terminal status.
    useEffect(() => {
        if (step !== "wait") { stopPolling(); return undefined; }
        const check = async () => {
            try {
                const response = await apiRequest("/api/period/my-orders");
                const orders = Array.isArray(response?.orders) ? response.orders : [];
                const current = orders.find((item) => item.payment_reference === checkout?.payment_reference)
                    || (order?.id ? orders.find((item) => item.id === order.id) : null)
                    || orders.find((item) => String(item.status).toLowerCase() === "pending");
                if (!current || !mountedRef.current) return;
                setOrder(current);
                if (TERMINAL_STATUSES.includes(String(current.status).toLowerCase())) {
                    stopPolling();
                    const approved = String(current.status).toLowerCase() === "approved";
                    setStep(approved ? "approved" : "rejected");
                    if (approved) onApproved?.(current);
                }
            } catch { /* transient poll failure: keep polling */ }
        };
        check();
        pollRef.current = setInterval(check, 3000);
        return () => stopPolling();
    }, [step, checkout, order?.id, onApproved, stopPolling]);

    const activePackages = Array.isArray(packages) ? packages : [];

    return (
        <div className="space-y-4">
            {onClose && (
                <div className="flex justify-end">
                    <button type="button" onClick={handleClose} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/[0.07] dark:hover:text-white" aria-label={t("Tutup")}>
                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            )}

            {step === "select" && (
                <>
                    {error && <InlineAlert tone="error">{errorMessage(error, t("Checkout QRIS gagal dibuat."))}</InlineAlert>}
                    {loading ? (
                        <div className="flex min-h-44 items-center justify-center"><Spinner label={t("Memuat paket")} /></div>
                    ) : packagesError ? (
                        <div className="space-y-3">
                            <InlineAlert tone="error">{errorMessage(packagesError, t("Paket tidak dapat dimuat."))}</InlineAlert>
                            {onReloadPackages && <Button variant="secondary" onClick={onReloadPackages}>{t("Coba lagi")}</Button>}
                        </div>
                    ) : activePackages.length === 0 ? (
                        <InlineAlert tone="info">{t("Pengelola belum menyediakan paket durasi yang dapat dibeli.")}</InlineAlert>
                    ) : (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {activePackages.map((pkg) => (
                                <button
                                    key={pkg.key}
                                    type="button"
                                    disabled={busy}
                                    onClick={() => startCheckout(pkg)}
                                    className={`rounded-lg border p-3 text-left transition disabled:opacity-60 ${selected?.key === pkg.key && busy ? "border-red-500 bg-red-50 ring-2 ring-red-500/10 dark:bg-red-500/10" : "border-slate-200 hover:border-slate-300 dark:border-white/[0.08] dark:hover:border-white/20"}`}
                                >
                                    <span className="block text-[12px] font-semibold text-slate-900 dark:text-white">{pkg.label}</span>
                                    <span className="mt-1 block text-[11px] text-slate-500 dark:text-slate-400">{formatCount(pkg.days)} {t("hari")}</span>
                                    <span className="mt-2 block text-[12px] font-bold text-red-600 dark:text-red-400">{formatIdr(pkg.price)}</span>
                                    {busy && selected?.key === pkg.key && <span className="mt-2 block"><Spinner label={t("Membuat checkout")} /></span>}
                                </button>
                            ))}
                        </div>
                    )}
                </>
            )}

            {step === "pay" && checkout && (
                <div className="space-y-4 text-center">
                    <div>
                        <h3 className="text-base font-bold text-slate-950 dark:text-white">{t("Pindai QRIS untuk membayar")}</h3>
                        <p className="mt-1 text-[12px] text-slate-500 dark:text-slate-400">
                            {selected?.label} — <span className="font-bold text-red-600 dark:text-red-400">{formatIdr(checkout.amount_idr)}</span>
                        </p>
                    </div>
                    <div className="inline-block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10">
                        <img src={checkout.qr_image_url} alt={t("Kode QRIS pembayaran")} className="h-[240px] w-[240px] object-contain" />
                    </div>
                    <p className={`text-[12px] font-semibold tabular-nums ${remainingMs !== null && remainingMs < 60_000 ? "text-red-600 dark:text-red-400" : "text-slate-600 dark:text-slate-300"}`}>
                        {t("Kode kedaluwarsa dalam")} {remainingMs === null ? "—" : formatCountdown(remainingMs)}
                    </p>
                    {error && (
                        <div className="text-left">
                            <InlineAlert tone="error">{errorMessage(error, t("Konfirmasi pembayaran gagal."))}</InlineAlert>
                            {existingOrder && (
                                <div className="mt-2">
                                    <Button variant="secondary" onClick={() => { setError(null); setExistingOrder(false); stopCountdown(); setStep("wait"); }}>{t("Lacak order yang menunggu persetujuan")}</Button>
                                </div>
                            )}
                        </div>
                    )}
                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-center">
                        <Button onClick={confirmPaid} disabled={busy}>{busy ? t("Mengonfirmasi…") : t("Saya sudah membayar")}</Button>
                        <Button variant="secondary" onClick={resetFlow} disabled={busy}>{t("Pilih paket lain")}</Button>
                    </div>
                </div>
            )}

            {step === "wait" && (
                <div className="space-y-3 text-center">
                    <div className="flex justify-center"><Spinner label={t("Menunggu persetujuan admin")} /></div>
                    <p className="text-[12px] text-slate-500 dark:text-slate-400">{t("Order Anda sudah tercatat dan menunggu persetujuan admin. Halaman ini diperbarui otomatis.")}</p>
                    {order?.id && <p className="text-[11px] text-slate-400 dark:text-slate-500">ULTR-{String(order.id).padStart(4, "0")}</p>}
                    {error && <InlineAlert tone="error">{errorMessage(error, t("Order tidak dapat dibatalkan."))}</InlineAlert>}
                    {cancelPrompt ? (
                        <div className="space-y-2">
                            <p className="text-[12px] font-semibold text-red-600 dark:text-red-300">{t("Batalkan order langganan ini? Jika Anda sudah transfer, jangan batalkan - tunggu peninjauan admin.")}</p>
                            <div className="flex justify-center gap-2">
                                <Button variant="secondary" onClick={cancelOrder} disabled={cancelling}>{cancelling ? t("Membatalkan…") : t("Ya, batalkan order")}</Button>
                                <Button variant="ghost" onClick={() => setCancelPrompt(false)} disabled={cancelling}>{t("Kembali")}</Button>
                            </div>
                        </div>
                    ) : (
                        <Button variant="ghost" onClick={() => setCancelPrompt(true)} disabled={cancelling}>{t("Batalkan order")}</Button>
                    )}
                </div>
            )}

            {step === "approved" && (
                <div className="space-y-3">
                    <InlineAlert tone="success">{t("Pembayaran disetujui. Durasi akun Anda sudah bertambah.")}</InlineAlert>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={resetFlow}>{t("Beli lagi")}</Button>
                        {onClose && <Button onClick={handleClose}>{t("Selesai")}</Button>}
                    </div>
                </div>
            )}

            {step === "rejected" && (
                <div className="space-y-3">
                    <InlineAlert tone="error">{t("Order ditolak oleh admin. Silakan buat checkout baru atau hubungi pengelola.")}</InlineAlert>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={resetFlow}>{t("Coba lagi")}</Button>
                        {onClose && <Button onClick={handleClose}>{t("Tutup")}</Button>}
                    </div>
                </div>
            )}
        </div>
    );
}
