import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiRequest } from '../lib/api';
import { useAuth } from '../contexts/AuthContext';
import { useLocale } from '../contexts/LocaleContext';
import OnboardingWizard from '../components/OnboardingWizard';
import QrisCheckout from '../components/QrisCheckout';
import MediaActionDialog from '../components/MediaActionDialog';
import DashboardWorkspace, { WorkspaceInbox, WorkspaceLoading, WorkspaceMetric, WorkspaceModule, WorkspaceUsageChart } from '../components/dashboard/DashboardWorkspace';
import { Button, InlineAlert, StatePanel, StatusBadge, errorMessage, formatCount, formatLocalDate, formatUsdMicros } from '../components/member/MemberUI';
import Icons from '../layouts/SidebarIcons';

const INITIAL_SOURCES = {
    dashboard: { data: null, loading: true, error: null },
    balance: { data: null, loading: true, error: null },
    usage: { data: null, loading: true, error: null },
};
const ACTION_DETAILS = {
    chat: { icon: 'chat', tone: 'emerald', description: 'Pikirkan, tulis, dan kembangkan ide.' },
    image: { icon: 'image', tone: 'fuchsia', description: 'Dari prompt menjadi gambar di studio.' },
    video: { icon: 'video', tone: 'violet', description: 'Prompt, referensi, dan video dalam satu studio.' },
    audio: { icon: 'audio', tone: 'pink', description: 'Voiceover dan musik dari model yang tersedia.' },
    download: { icon: 'download', tone: 'blue', description: 'Unduh media yang Anda miliki atau boleh gunakan.' },
    convert: { icon: 'convert', tone: 'cyan', description: 'Ubah format video, audio, dan gambar.' },
    history: { icon: 'history', tone: 'blue', description: 'Lanjutkan percakapan yang tersimpan.' },
    usage: { icon: 'token', tone: 'cyan', description: 'Rincian pemakaian, token, dan saldo PAYG.' },
    extend: { icon: 'paket', tone: 'amber', description: 'Token, saldo PAYG, dan langganan.' },
    api: { icon: 'api', tone: 'emerald', description: 'Buka layanan API UltrAI.' },
};

function greeting(t) {
    const hour = new Date().getHours();
    return t(hour < 12 ? 'Selamat pagi' : hour < 15 ? 'Selamat siang' : hour < 18 ? 'Selamat sore' : 'Selamat malam');
}

function PurchaseModal({ onClose, packages, packagesLoading, packagesError, reloadPackages, accountDays, onOrdered }) {
    const { t } = useLocale();
    return (
        <MediaActionDialog title={t('Tambah durasi')} description={`${t('Sisa akun saat ini')}: ${accountDays === undefined ? t('Belum tersedia') : accountDays === null ? t('Tanpa batas waktu') : `${formatCount(accountDays)} ${t('hari')}`}`} closeLabel={t('Tutup')} onClose={onClose}>
            <QrisCheckout packages={packages} loading={packagesLoading} error={packagesError} onReloadPackages={reloadPackages} onApproved={onOrdered} onClose={onClose} />
        </MediaActionDialog>
    );
}

export default function Dashboard() {
    const { user, refreshUser } = useAuth();
    const { locale, t, localizedPath } = useLocale();
    const [sources, setSources] = useState(INITIAL_SOURCES);
    const [period, setPeriod] = useState('daily');
    const requests = useRef({});
    const [packages, setPackages] = useState([]);
    const [packagesLoading, setPackagesLoading] = useState(false);
    const [packagesError, setPackagesError] = useState(null);
    const [showPurchase, setShowPurchase] = useState(false);
    const [showOnboarding, setShowOnboarding] = useState(false);
    const [onboardingError, setOnboardingError] = useState(null);
    const [onboardingRevision, setOnboardingRevision] = useState(0);
    const packageRequest = useRef(null);

    const loadSource = useCallback(async (key, selectedPeriod = 'daily') => {
        requests.current[key]?.abort();
        const controller = new AbortController();
        requests.current[key] = controller;
        setSources(current => ({ ...current, [key]: { ...current[key], loading: true, error: null } }));
        const endpoint = key === 'dashboard' ? '/api/dashboard' : key === 'balance' ? '/api/t/balance' : `/api/usage/me?period=${selectedPeriod}`;
        try {
            const data = await apiRequest(endpoint, { signal: controller.signal });
            if (!controller.signal.aborted) setSources(current => ({ ...current, [key]: { data, loading: false, error: null } }));
        } catch (error) {
            if (!controller.signal.aborted) setSources(current => ({ ...current, [key]: { ...current[key], loading: false, error } }));
        }
    }, []);

    const loadPackages = useCallback(async () => {
        packageRequest.current?.abort();
        const controller = new AbortController();
        packageRequest.current = controller;
        setPackagesLoading(true);
        setPackagesError(null);
        try {
            const response = await apiRequest('/api/period/packages', { signal: controller.signal });
            const catalog = response?.packages && typeof response.packages === 'object' ? response.packages : {};
            if (!controller.signal.aborted) setPackages(Object.entries(catalog).filter(([, pkg]) => pkg?.is_active).map(([key, pkg]) => ({ key, ...pkg })));
        } catch (error) {
            if (!controller.signal.aborted) { setPackagesError(error); setPackages([]); }
        } finally {
            if (!controller.signal.aborted) setPackagesLoading(false);
        }
    }, []);

    useEffect(() => {
        const currentRequests = requests.current;
        setSources(INITIAL_SOURCES);
        loadSource('dashboard');
        loadSource('balance');
        return () => { Object.values(currentRequests).forEach(controller => controller.abort()); packageRequest.current?.abort(); };
    }, [loadSource, user?.id]);
    useEffect(() => { loadSource('usage', period); }, [loadSource, period, user?.id]);
    useEffect(() => {
        const controller = new AbortController();
        setOnboardingError(null);
        apiRequest('/api/onboarding/status', { signal: controller.signal }).then(response => {
            if (!controller.signal.aborted) setShowOnboarding(!response?.completed);
        }).catch(error => { if (!controller.signal.aborted) setOnboardingError(error); });
        return () => controller.abort();
    }, [onboardingRevision, user?.id]);
    useEffect(() => {
        if (showPurchase) loadPackages();
        return () => packageRequest.current?.abort();
    }, [showPurchase, loadPackages]);

    function refreshDashboard() {
        loadSource('dashboard');
        loadSource('balance');
        loadSource('usage', period);
    }
    function purchaseApproved() { refreshDashboard(); refreshUser(); }

    const dashboard = sources.dashboard.data;
    const account = dashboard?.account;
    const usage = dashboard?.usage;
    const wallet = dashboard?.wallet;
    const activity = dashboard?.activity;
    const services = Array.isArray(dashboard?.services) ? dashboard.services : [];
    const isAdmin = user?.role === 'admin';
    const permissions = user?.permissions || {};
    const allowed = (key, fallback = true) => isAdmin || (permissions[key] ?? fallback) === true;
    const hasAccess = Boolean(account && (isAdmin || (account.is_active && !account.is_expired)));
    const hasHistory = allowed('chat_history') && allowed('chat');
    const dateLocale = locale === 'en' ? 'en-US' : 'id-ID';
    const unavailable = t('Belum tersedia');
    const loadingAny = Object.values(sources).some(source => source.loading);
    const studioActions = [
        ...(allowed('chat') ? [{ key: 'chat', label: 'Mulai percakapan', href: '/chat' }] : []),
        { key: 'image', label: 'Buat gambar', href: '/generate-image' },
        ...(allowed('video_generator', false) ? [{ key: 'video', label: 'Buat video', href: '/video' }] : []),
        ...(allowed('audio_generator') ? [{ key: 'audio', label: 'Audio', href: '/audio' }] : []),
        ...(allowed('video_downloader') ? [{ key: 'download', label: 'Downloads', href: '/downloads' }] : []),
        ...(allowed('media_converter') ? [{ key: 'convert', label: 'Converter', href: '/converter' }] : []),
    ];
    const accountActions = (dashboard?.actions || []).filter(action => !studioActions.some(studio => studio.href === action.href) && ACTION_DETAILS[action.key] && (action.key !== 'history' || hasHistory));
    const timeline = sources.usage.data?.period === period ? sources.usage.data.timeline || [] : [];

    function renderAction(action, locked = false) {
        const detail = ACTION_DETAILS[action.key];
        const content = <><span className="dw-icon" data-tone={detail.tone} aria-hidden="true">{Icons[detail.icon]}</span><span><strong>{t(action.label)}</strong><small>{locked ? t('Perpanjang masa aktif untuk membuat karya baru.') : t(detail.description)}</small></span>{!locked && Icons.arrow}</>;
        if (locked) return <div key={action.key} className="dw-tool" aria-disabled="true">{content}</div>;
        if (action.href === 'https://api.ultrai.id') return <a key={action.key} className="dw-tool" href={action.href} target="_blank" rel="noopener noreferrer">{content}</a>;
        if (!action.href?.startsWith('/') || action.href.startsWith('//')) return null;
        return <Link key={action.key} className="dw-tool" to={localizedPath(action.href)}>{content}</Link>;
    }

    return (
        <DashboardWorkspace title={`${greeting(t)}, ${account?.name || user?.name || t('Pengguna')}`} description={t('Mulai berkarya, pantau pemakaian, dan lanjutkan pekerjaan Anda.')} actions={<><button type="button" className="dw-button" onClick={refreshDashboard} disabled={loadingAny}>{Icons.refresh}<span>{t('Muat ulang')}</span></button><button type="button" className="dw-button dw-button-primary" onClick={() => setShowPurchase(true)}>{Icons.period}<span>{t('Tambah durasi')}</span></button></>}>
            {showOnboarding && locale === 'id' && <OnboardingWizard onComplete={() => setShowOnboarding(false)} />}
            {onboardingError && <InlineAlert tone="warning" action={<Button variant="ghost" onClick={() => setOnboardingRevision(value => value + 1)}>{t('Coba lagi')}</Button>}>{t('Status onboarding tidak dapat diperiksa')}: {t(errorMessage(onboardingError))}</InlineAlert>}
            {sources.dashboard.error && <InlineAlert tone="error" action={<Button variant="ghost" onClick={() => loadSource('dashboard')}>{t('Coba lagi')}</Button>}><strong>{t('Ringkasan akun tidak dapat diperbarui.')}</strong> {t(errorMessage(sources.dashboard.error))}{dashboard && <span> {t('Data terakhir tetap ditampilkan.')}</span>}</InlineAlert>}
            {account?.is_expired && <InlineAlert tone="error" action={<Button variant="ghost" onClick={() => setShowPurchase(true)}>{t('Tambah durasi')}</Button>}><strong>{t('Masa aktif akun berakhir.')}</strong> {t('Perpanjang durasi untuk memulihkan akses fitur yang dilindungi.')}</InlineAlert>}
            {account?.is_active === false && <InlineAlert tone="error"><strong>{t('Akun tidak aktif.')}</strong> {t('Hubungi dukungan jika status ini tidak sesuai.')} <Link className="underline" to={localizedPath('/bantuan')}>{t('Help & Support')}</Link></InlineAlert>}
            {Number(activity?.devices?.pending || 0) > 0 && <InlineAlert tone="warning">{formatCount(activity.devices.pending)} {t('perangkat menunggu persetujuan. Fitur tertentu dapat ditolak sampai perangkat disetujui.')}</InlineAlert>}

            <dl className="dw-metrics" aria-label={t('Ringkasan akun')}>
                <WorkspaceMetric label={t('Saldo token generator')} icon={Icons.token} tone="fuchsia" value={sources.balance.data ? formatCount(sources.balance.data.balance) : unavailable} loading={sources.balance.loading && !sources.balance.data} detail={<>{t('Untuk gambar, video, dan audio.')} <Link to={localizedPath('/deposit?tab=tokens')}>{t('Isi token')}</Link></>} />
                <WorkspaceMetric label={t('Saldo API PAYG · USD')} icon={Icons.paket} tone="emerald" value={wallet ? formatUsdMicros(wallet.balance_microusd) : unavailable} loading={sources.dashboard.loading && !dashboard} detail={<>{t('Terpisah dari token generator.')} <Link to={localizedPath('/deposit?tab=wallet')}>{t('Isi saldo')}</Link></>} />
                <WorkspaceMetric label={t('Permintaan tercatat')} icon={Icons.analytics} tone="cyan" value={usage ? formatCount(usage.total_requests) : unavailable} loading={sources.dashboard.loading && !dashboard} detail={usage ? `${formatCount(usage.total_tokens)} ${t('token pemakaian · sepanjang waktu')}` : t('Pemakaian akun Anda, bukan saldo generator.')} />
                <WorkspaceMetric label={t('Masa aktif')} icon={Icons.period} tone="amber" value={account ? (account.is_expired ? t('Berakhir') : account.days_remaining == null ? t('Tanpa batas') : `${formatCount(account.days_remaining)} ${t('hari')}`) : unavailable} loading={sources.dashboard.loading && !dashboard} detail={account?.expires_at ? `${t('Hingga')} ${formatLocalDate(account.expires_at, { locale: dateLocale })}` : account ? t('Tidak ada tanggal kedaluwarsa akun.') : t('Status akun belum dapat dimuat.')} />
            </dl>
            {sources.balance.error && <InlineAlert tone="warning" action={<Button variant="ghost" onClick={() => loadSource('balance')}>{t('Coba lagi')}</Button>}>{t('Saldo token tidak dapat diperbarui.')}{sources.balance.data && <> {t('Data terakhir tetap ditampilkan.')}</>} {t(errorMessage(sources.balance.error))}</InlineAlert>}

            <div className="dw-board">
                <div className="dw-stack">
                    <WorkspaceUsageChart rows={timeline} loading={sources.usage.loading} error={sources.usage.error ? t(errorMessage(sources.usage.error)) : null} onRetry={() => loadSource('usage', period)} description={period === 'daily' ? t('Permintaan akun Anda selama 30 hari terakhir.') : t('Permintaan akun Anda selama 12 bulan terakhir.')} reportPath="/token-usage" controls={<div className="dw-periods" role="group" aria-label={t('Periode pemakaian')}><button type="button" aria-pressed={period === 'daily'} onClick={() => setPeriod('daily')}>{t('30 hari')}</button><button type="button" aria-pressed={period === 'monthly'} onClick={() => setPeriod('monthly')}>{t('12 bulan')}</button></div>} />
                    <WorkspaceModule title={t('Aktivitas percakapan terbaru')} icon={Icons.history} tone="blue" count={hasHistory ? activity?.conversation_count : undefined}>
                        {sources.dashboard.loading && !dashboard ? <WorkspaceLoading label={t('Memuat aktivitas')} /> : !dashboard ? <StatePanel type="error" title={t('Aktivitas tidak dapat dimuat')} description={t('Muat ulang ringkasan akun untuk melihat aktivitas.')} compact action={<Button variant="ghost" onClick={() => loadSource('dashboard')}>{t('Coba lagi')}</Button>} /> : !hasHistory ? <div className="dw-empty"><h3>{t('Riwayat chat tidak tersedia untuk akun ini')}</h3><p>{t('Hubungi dukungan untuk meninjau izin akses Anda.')}</p></div> : !activity?.recent?.length ? <div className="dw-empty"><h3>{t('Belum ada aktivitas')}</h3><p>{t('Percakapan terbaru akan tampil setelah Anda menggunakan Chat AI.')}</p></div> : <ul className="dw-list">{activity.recent.map(item => <li key={item.id}><Link className="dw-activity" to={localizedPath(`/history?conversation=${encodeURIComponent(item.id)}`)}><span className="dw-icon" data-tone="emerald" aria-hidden="true">{Icons.chat}</span><span><strong>{item.title || t('Percakapan tanpa judul')}</strong><small>{item.model || t('Model tidak dicantumkan')}</small><small><time dateTime={item.occurred_at}>{formatLocalDate(item.occurred_at, { locale: dateLocale })}</time></small></span></Link></li>)}</ul>}
                        {hasHistory && <footer className="dw-module-footer"><span>{t('Maksimal delapan percakapan yang terakhir diperbarui.')}</span><Link className="dw-text-link" to={localizedPath('/history')}>{t('Buka Library')}{Icons.arrow}</Link></footer>}
                    </WorkspaceModule>
                    <WorkspaceInbox />
                </div>
                <div className="dw-stack dw-side-stack">
                    <WorkspaceModule title={t('Mulai dari alat')} icon={Icons.dashboard} tone="violet" className="dw-tools">
                        {sources.dashboard.loading && !dashboard ? <WorkspaceLoading label={t('Memuat akses workspace')} /> : !dashboard ? <div className="dw-empty"><h3>{t('Akses akun belum dapat diperiksa')}</h3><p>{t('Muat ulang ringkasan akun untuk membuka pintasan.')}</p><Button className="mt-3" variant="secondary" onClick={() => loadSource('dashboard')}>{t('Coba lagi')}</Button></div> : studioActions.map(action => renderAction(action, !hasAccess))}
                    </WorkspaceModule>
                    <WorkspaceModule title={t('Status akun')} icon={Icons.profile} tone="amber">
                        {!account ? <div className="dw-empty"><p>{sources.dashboard.loading ? t('Memuat status akun') : t('Status akun belum dapat dimuat.')}</p></div> : <><dl className="dw-account">{[
                            [t('Akses akun'), <StatusBadge value={hasAccess ? 'active' : 'disabled'} label={hasAccess ? t('Aktif') : t('Terbatas')} />],
                            [t('Order menunggu'), formatCount(activity?.orders?.pending)],
                            [t('Perangkat aktif'), formatCount(activity?.devices?.active)],
                            [t('Perangkat diblokir'), formatCount(activity?.devices?.blocked)],
                            [t('Pemakaian terakhir'), usage?.last_used_at ? formatLocalDate(usage.last_used_at, { locale: dateLocale }) : t('Belum ada')],
                        ].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl><footer className="dw-module-footer"><span>{t('Kelola akses dan perangkat Anda.')}</span><Link className="dw-text-link" to={localizedPath('/profile')}>{t('Profil')}{Icons.arrow}</Link></footer></>}
                    </WorkspaceModule>
                    <WorkspaceModule title={t('Akun & penagihan')} icon={Icons.paket} tone="cyan" open={false} className="dw-tools">
                        {accountActions.length ? accountActions.map(action => renderAction(action)) : <div className="dw-empty"><p>{t('Tagihan dan langganan tetap dapat dibuka dari menu Deposit.')}</p><Link to={localizedPath('/deposit')} className="dw-text-link">{t('Deposit')}{Icons.arrow}</Link></div>}
                    </WorkspaceModule>
                    <WorkspaceModule title={t('Status layanan')} icon={Icons.provider} tone="emerald" count={dashboard ? services.length : undefined} open={false}>
                        <p className="dw-note">{t('Status terakhir yang dilaporkan provider, bukan pemeriksaan langsung.')}</p>
                        {!services.length ? <div className="dw-empty"><h3>{t('Belum ada status layanan')}</h3><p>{t('Backend belum mengembalikan provider untuk ditampilkan.')}</p></div> : <div className="dw-services">{services.map(service => <div key={service.key} className="dw-service"><div><strong>{service.name}</strong><p>{service.last_checked_at ? `${t('Diperiksa')} ${formatLocalDate(service.last_checked_at, { locale: dateLocale })}` : t('Belum pernah diperiksa')}</p></div><StatusBadge value={service.is_enabled ? service.status : 'disabled'} label={service.is_enabled ? service.status || t('Tidak diketahui') : t('Nonaktif')} /></div>)}</div>}
                    </WorkspaceModule>
                </div>
            </div>
            <p className="dw-note">{Icons.density}{t('Klik judul modul untuk merapikan ruang kerja. Ringkasan angka dan grafik tetap terlihat.')}</p>
            {showPurchase && <PurchaseModal onClose={() => setShowPurchase(false)} packages={packages} packagesLoading={packagesLoading} packagesError={packagesError} reloadPackages={loadPackages} accountDays={account?.days_remaining} onOrdered={purchaseApproved} />}
        </DashboardWorkspace>
    );
}
