import React, { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiRequest } from "../lib/api";
import OnboardingWizard from "../components/OnboardingWizard";
import {
    Button,
    InlineAlert,
    MemberPage,
    Metric,
    PageHeader,
    Panel,
    SectionHeader,
    Spinner,
    StatePanel,
    StatusBadge,
    errorMessage,
    formatCount,
    formatLocalDate,
    formatUsdMicros,
} from "../components/member/MemberUI";

function greeting(name) {
    const hour = new Date().getHours();
    const time = hour < 12 ? "Selamat pagi" : hour < 15 ? "Selamat siang" : hour < 18 ? "Selamat sore" : "Selamat malam";
    return name ? `${time}, ${name}` : time;
}

function actionTone(key) {
    if (key === "chat") return "bg-red-500/10 text-red-600 dark:text-red-300";
    if (key === "history") return "bg-blue-500/10 text-blue-600 dark:text-blue-300";
    if (key === "video") return "bg-violet-500/10 text-violet-600 dark:text-violet-300";
    return "bg-slate-500/10 text-slate-600 dark:text-slate-300";
}

function ActionIcon({ name }) {
    const path = name === "chat" ? "M8 10h8M8 14h5m8-2a9 9 0 1 1-3.18-6.88L21 4v4.5" : name === "history" ? "M3 12a9 9 0 1 0 3-6.7M3 4v5h5m4-3v6l4 2" : name === "video" ? "m15 10 4.55-2.27A1 1 0 0 1 21 8.62v6.76a1 1 0 0 1-1.45.9L15 14M5 6h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z" : "M12 3v18m6-15H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H6";
    return <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={path} /></svg>;
}

function PurchaseModal({ open, onClose, packages, packagesLoading, packagesError, reloadPackages, accountDays, onOrdered }) {
    const [selected, setSelected] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [created, setCreated] = useState(null);

    useEffect(() => {
        if (!open) { setSelected(null); setError(null); setCreated(null); setSubmitting(false); }
    }, [open]);

    if (!open) return null;
    const submit = async () => {
        if (!selected) return;
        setSubmitting(true); setError(null);
        try {
            const response = await apiRequest("/api/period/order", { method: "POST", body: { package: selected.key } });
            setCreated(response?.order || null);
            onOrdered?.();
        } catch (requestError) { setError(requestError); }
        finally { setSubmitting(false); }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="duration-modal-title">
            <button type="button" aria-label="Tutup modal" className="absolute inset-0 bg-slate-950/70 backdrop-blur-sm" onClick={onClose} />
            <Panel className="relative max-h-[88vh] w-full max-w-lg overflow-y-auto p-5 shadow-2xl">
                <div className="flex items-start justify-between gap-4">
                    <div><h2 id="duration-modal-title" className="text-base font-bold text-slate-950 dark:text-white">Tambah durasi</h2><p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Sisa akun saat ini: {accountDays == null ? "—" : `${accountDays} hari`}</p></div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/[0.07] dark:hover:text-white" aria-label="Tutup"><svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M6 6l12 12M18 6 6 18" /></svg></button>
                </div>
                {created ? (
                    <div className="mt-5 space-y-4"><InlineAlert tone="success">Order #{created.id} berhasil dibuat dan menunggu persetujuan admin.</InlineAlert><div className="grid grid-cols-2 gap-2"><Metric label="Paket" value={selected?.label || created.package} /><Metric label="Status" value={created.status || "pending"} /></div><Button className="w-full" onClick={onClose}>Selesai</Button></div>
                ) : packagesLoading ? (
                    <div className="flex min-h-44 items-center justify-center"><Spinner label="Memuat paket" /></div>
                ) : packagesError ? (
                    <div className="mt-4"><StatePanel type="error" title="Paket tidak dapat dimuat" description={errorMessage(packagesError)} action={<Button variant="secondary" onClick={reloadPackages}>Coba lagi</Button>} /></div>
                ) : packages.length === 0 ? (
                    <div className="mt-4"><StatePanel type="error" title="Tidak ada paket aktif" description="Pengelola belum menyediakan paket durasi yang dapat dibeli." compact /></div>
                ) : (
                    <div className="mt-4 space-y-4">
                        {error && <InlineAlert tone="error">{errorMessage(error, "Order durasi gagal dibuat.")}</InlineAlert>}
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {packages.map((pkg) => (
                                <button key={pkg.key} type="button" onClick={() => setSelected(pkg)} className={`rounded-lg border p-3 text-left transition ${selected?.key === pkg.key ? "border-red-500 bg-red-50 ring-2 ring-red-500/10 dark:bg-red-500/10" : "border-slate-200 hover:border-slate-300 dark:border-white/[0.08] dark:hover:border-white/20"}`}>
                                    <span className="block text-[12px] font-semibold text-slate-900 dark:text-white">{pkg.label}</span>
                                    <span className="mt-1 block text-[11px] text-slate-500">{formatCount(pkg.days)} hari</span>
                                    <span className="mt-2 block text-[12px] font-bold text-red-600 dark:text-red-400">{new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(pkg.price || 0))}</span>
                                </button>
                            ))}
                        </div>
                        <InlineAlert tone="info">Pembayaran mengikuti alur persetujuan order yang aktif. Status order tidak dianggap berhasil sebelum backend menyatakannya disetujui.</InlineAlert>
                        <div className="flex justify-end gap-2"><Button variant="secondary" onClick={onClose}>Batal</Button><Button onClick={submit} disabled={!selected || submitting}>{submitting ? "Membuat order…" : "Buat order"}</Button></div>
                    </div>
                )}
            </Panel>
        </div>
    );
}

export default function Dashboard() {
    const [dashboard, setDashboard] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [packages, setPackages] = useState([]);
    const [packagesLoading, setPackagesLoading] = useState(false);
    const [packagesError, setPackagesError] = useState(null);
    const [showPurchase, setShowPurchase] = useState(false);
    const [showOnboarding, setShowOnboarding] = useState(false);
    const [onboardingError, setOnboardingError] = useState(null);

    const loadDashboard = useCallback(async () => {
        setLoading(true); setError(null);
        try { setDashboard(await apiRequest("/api/dashboard")); }
        catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }, []);

    const loadPackages = useCallback(async () => {
        setPackagesLoading(true); setPackagesError(null);
        try {
            const response = await apiRequest("/api/period/packages");
            const catalog = response?.packages && typeof response.packages === "object" ? response.packages : {};
            setPackages(Object.entries(catalog).filter(([, pkg]) => pkg?.is_active).map(([key, pkg]) => ({ key, ...pkg })));
        } catch (requestError) { setPackagesError(requestError); setPackages([]); }
        finally { setPackagesLoading(false); }
    }, []);

    useEffect(() => { loadDashboard(); }, [loadDashboard]);
    useEffect(() => {
        let active = true;
        apiRequest("/api/onboarding/status").then((response) => { if (active) setShowOnboarding(!response?.completed); }).catch((requestError) => { if (active) setOnboardingError(requestError); });
        return () => { active = false; };
    }, []);
    useEffect(() => { if (showPurchase) loadPackages(); }, [showPurchase, loadPackages]);

    if (loading) return <MemberPage><PageHeader eyebrow="Control center" title="Dashboard" description="Ringkasan akun, penggunaan, dan layanan Anda." /><Panel className="flex min-h-72 items-center justify-center"><Spinner label="Memuat ringkasan akun" /></Panel></MemberPage>;
    if (error) return <MemberPage><PageHeader eyebrow="Control center" title="Dashboard" description="Ringkasan akun, penggunaan, dan layanan Anda." /><Panel className="p-4"><StatePanel type="error" title="Dashboard tidak dapat dimuat" description={errorMessage(error)} action={<Button variant="secondary" onClick={loadDashboard}>Coba lagi</Button>} /></Panel></MemberPage>;

    const account = dashboard?.account || {};
    const usage = dashboard?.usage || {};
    const wallet = dashboard?.wallet || {};
    const activity = dashboard?.activity || {};
    const services = Array.isArray(dashboard?.services) ? dashboard.services : [];
    const actions = Array.isArray(dashboard?.actions) ? dashboard.actions : [];

    return (
        <MemberPage>
            {showOnboarding && <OnboardingWizard onComplete={() => setShowOnboarding(false)} />}
            <PageHeader
                eyebrow="Control center"
                title={greeting(account.name)}
                description="Ringkasan langsung dari aktivitas dan status akun Anda."
                actions={<><Button variant="secondary" onClick={loadDashboard}>Muat ulang</Button><Button onClick={() => setShowPurchase(true)}>Tambah durasi</Button></>}
            />
            {onboardingError && <InlineAlert tone="warning" action={<Button variant="ghost" onClick={() => window.location.reload()}>Coba lagi</Button>}>Status onboarding tidak dapat diperiksa: {errorMessage(onboardingError)}</InlineAlert>}
            {account.is_expired && <InlineAlert tone="error"><strong>Masa aktif akun berakhir.</strong> Perpanjang durasi untuk memulihkan akses fitur yang dilindungi.</InlineAlert>}
            {!account.is_active && <InlineAlert tone="error"><strong>Akun tidak aktif.</strong> Hubungi dukungan jika status ini tidak sesuai.</InlineAlert>}
            {Number(activity.devices?.pending || 0) > 0 && <InlineAlert tone="warning">{formatCount(activity.devices.pending)} perangkat menunggu persetujuan. Fitur tertentu dapat ditolak sampai perangkat disetujui.</InlineAlert>}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Metric label="Masa aktif" value={account.days_remaining == null ? "—" : `${formatCount(account.days_remaining)} hari`} detail={account.expires_at ? `Hingga ${formatLocalDate(account.expires_at)}` : "Tanggal berakhir tidak tersedia"} />
                <Metric label="Saldo wallet" value={formatUsdMicros(wallet.balance_microusd)} detail={`${formatCount(wallet.transaction_count)} transaksi`} />
                <Metric label="Percakapan" value={formatCount(activity.conversation_count)} detail="Percakapan tersimpan" />
                <Metric label="Pemakaian API" value={formatCount(usage.total_requests)} detail={`${formatCount(usage.total_tokens)} token`} />
            </div>

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,.65fr)]">
                <div className="space-y-5">
                    <Panel className="p-4">
                        <SectionHeader title="Akses cepat" description="Hanya tindakan yang diizinkan backend untuk akun ini." />
                        {actions.length === 0 ? <div className="mt-4"><StatePanel title="Tidak ada tindakan tersedia" description="Status, masa aktif, atau izin akun saat ini tidak membuka tindakan apa pun." compact /></div> : <div className="mt-4 grid gap-2 sm:grid-cols-2">{actions.map((action) => {
                            const external = /^https?:\/\//.test(action.href || "");
                            const content = <><span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${actionTone(action.key)}`}><ActionIcon name={action.key} /></span><span className="min-w-0"><span className="block text-[12px] font-semibold text-slate-900 dark:text-white">{action.label}</span><span className="mt-0.5 block truncate text-[10px] text-slate-500">{external ? "Buka layanan eksternal" : action.href}</span></span><span className="ml-auto text-slate-400">→</span></>;
                            const classes = "flex min-h-14 items-center gap-3 rounded-lg border border-slate-200 p-3 transition hover:border-red-200 hover:bg-red-50/50 dark:border-white/[0.08] dark:hover:border-red-500/20 dark:hover:bg-red-500/[0.06]";
                            return external ? <a key={action.key} href={action.href} target="_blank" rel="noreferrer" className={classes}>{content}</a> : <Link key={action.key} to={action.href} className={classes}>{content}</Link>;
                        })}</div>}
                    </Panel>

                    <Panel className="overflow-hidden">
                        <div className="border-b border-slate-200 p-4 dark:border-white/[0.08]"><SectionHeader title="Aktivitas percakapan terbaru" description="Maksimal delapan percakapan yang terakhir diperbarui." action={actions.some((action) => action.key === "history") && <Link to="/history" className="text-[11px] font-semibold text-red-600 hover:underline dark:text-red-400">Lihat semua</Link>} /></div>
                        {!Array.isArray(activity.recent) || activity.recent.length === 0 ? <div className="p-4"><StatePanel title="Belum ada aktivitas" description="Percakapan terbaru akan tampil setelah Anda menggunakan Chat AI." compact /></div> : <div className="divide-y divide-slate-100 dark:divide-white/[0.06]">{activity.recent.map((item) => <div key={item.id} className="flex items-start justify-between gap-3 px-4 py-3"><div className="min-w-0"><p className="truncate text-[12px] font-semibold text-slate-900 dark:text-white">{item.title || "Percakapan tanpa judul"}</p><p className="mt-0.5 truncate text-[10px] text-slate-500">{item.model || "Model tidak dicantumkan"}</p></div><time className="shrink-0 text-[10px] text-slate-500">{formatLocalDate(item.occurred_at)}</time></div>)}</div>}
                    </Panel>
                </div>

                <div className="space-y-5">
                    <Panel className="p-4">
                        <SectionHeader title="Status akun" description="Status akses, order, dan perangkat terdaftar." />
                        <dl className="mt-4 divide-y divide-slate-100 dark:divide-white/[0.06]">
                            {[
                                ["Akun", <StatusBadge value={account.is_active && !account.is_expired ? "active" : "disabled"} label={account.is_active && !account.is_expired ? "Aktif" : "Terbatas"} />],
                                ["Order menunggu", formatCount(activity.orders?.pending)],
                                ["Perangkat aktif", formatCount(activity.devices?.active)],
                                ["Perangkat diblokir", formatCount(activity.devices?.blocked)],
                                ["Pemakaian terakhir", usage.last_used_at ? formatLocalDate(usage.last_used_at) : "Belum ada"],
                            ].map(([label, value]) => <div key={label} className="flex items-center justify-between gap-3 py-2.5 text-[12px]"><dt className="text-slate-500 dark:text-slate-400">{label}</dt><dd className="text-right font-medium text-slate-900 dark:text-slate-100">{value}</dd></div>)}
                        </dl>
                    </Panel>

                    <Panel className="p-4">
                        <SectionHeader title="Status layanan" description="Status provider yang dikonfigurasi pengelola; bukan asumsi frontend." />
                        {services.length === 0 ? <div className="mt-4"><StatePanel title="Belum ada status layanan" description="Backend belum mengembalikan provider untuk ditampilkan." compact /></div> : <div className="mt-3 max-h-72 divide-y divide-slate-100 overflow-y-auto dark:divide-white/[0.06]">{services.map((service) => <div key={service.key} className="flex items-start justify-between gap-3 py-2.5"><div className="min-w-0"><p className="truncate text-[12px] font-medium text-slate-900 dark:text-white">{service.name}</p><p className="mt-0.5 text-[10px] text-slate-500">{service.last_checked_at ? `Diperiksa ${formatLocalDate(service.last_checked_at)}` : "Belum pernah diperiksa"}</p></div><StatusBadge value={service.is_enabled ? service.status : "disabled"} label={service.is_enabled ? service.status : "Nonaktif"} /></div>)}</div>}
                    </Panel>
                </div>
            </div>

            <PurchaseModal open={showPurchase} onClose={() => setShowPurchase(false)} packages={packages} packagesLoading={packagesLoading} packagesError={packagesError} reloadPackages={loadPackages} accountDays={account.days_remaining} onOrdered={loadDashboard} />
        </MemberPage>
    );
}
