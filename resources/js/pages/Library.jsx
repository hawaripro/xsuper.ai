import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiRequest } from '../lib/api';
import { useAuth } from '../contexts/AuthContext';
import { useLocale } from '../contexts/LocaleContext';
import { useTheme } from '../contexts/ThemeContext';
import DashboardWorkspace from '../components/dashboard/DashboardWorkspace';
import MediaActionDialog from '../components/MediaActionDialog';
import { Button, InlineAlert, errorMessage, formatCount, formatLocalDate } from '../components/member/MemberUI';
import { studioHref } from '../components/studios/studioLinks';
import Icons from '../layouts/SidebarIcons';
import './library.css';

const TYPES = [
    { key: 'all', label: 'Semua', icon: 'dashboard', tone: 'red' },
    { key: 'image', label: 'Gambar', icon: 'image', tone: 'fuchsia' },
    { key: 'video', label: 'Video', icon: 'video', tone: 'violet' },
    { key: 'avatar', label: 'Avatar', icon: 'profile', tone: 'violet' },
    { key: 'model3d', label: '3D', icon: 'cube', tone: 'cyan' },
    { key: 'audio', label: 'Audio', icon: 'audio', tone: 'pink' },
    { key: 'reference', label: 'Unggahan referensi', icon: 'image', tone: 'amber' },
    { key: 'document', label: 'Dokumen', icon: 'download', tone: 'blue' },
    { key: 'file', label: 'File', icon: 'download', tone: 'cyan' },
    { key: 'artifact', label: 'Artefak', icon: 'chat', tone: 'amber' },
    { key: 'download', label: 'Unduhan', icon: 'download', tone: 'blue' },
    { key: 'convert', label: 'Konversi', icon: 'convert', tone: 'cyan' },
    { key: 'rembg', label: 'Hapus Latar', icon: 'image', tone: 'teal' },
];
const TYPE_LABEL = Object.fromEntries(TYPES.map(type => [type.key, type.label]));
const PAGE_LABELS = { image: 'Buka studio gambar', video: 'Buka studio video', avatar: 'Buka studio avatar', model3d: 'Buka Studio 3D', audio: 'Buka studio audio', reference: 'Buka studio video', download: 'Buka unduhan', convert: 'Buka konverter', rembg: 'Buka Hapus Latar' };

function bytes(value, locale) {
    if (typeof value !== 'number' || value < 0) return null;
    const units = ['B', 'KiB', 'MiB', 'GiB'];
    let size = value; let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: unit ? 1 : 0 }).format(size)} ${units[unit]}`;
}
function clock(value) {
    if (typeof value !== 'number' || value <= 0) return null;
    const total = Math.round(value);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}
function mediaKind(item) {
    if (!item.preview_url || item.previewable === false) return null;
    const mime = item.mime_type || '';
    return mime.startsWith('image/') ? 'image' : mime.startsWith('video/') ? 'video' : mime.startsWith('audio/') ? 'audio' : null;
}

function Preview({ item, title }) {
    const kind = mediaKind(item);
    if (kind === 'image') return <img src={item.preview_url} alt={title} loading="lazy" />;
    if (kind === 'video') return <video src={item.preview_url} poster={item.poster_url || undefined} preload="metadata" muted playsInline aria-label={title} />;
    return <span className={`lib-tile-icon lib-tone-${TYPES.find(type => type.key === item.type)?.tone || 'red'}`} aria-hidden="true">{Icons[TYPES.find(type => type.key === item.type)?.icon || 'dashboard']}</span>;
}

function Lightbox({ item, onClose, t, locale }) {
    const { localizedPath } = useLocale();
    const kind = mediaKind(item);
    return (
        <MediaActionDialog title={item.title} description={[t(TYPE_LABEL[item.type] || item.type), bytes(item.size_bytes, locale), clock(item.duration), formatLocalDate(item.created_at, { locale })].filter(Boolean).join(' · ')} closeLabel={t('Tutup')} onClose={onClose}>
            <div className="lib-lightbox">
                {kind === 'image' && <img src={item.preview_url} alt={item.title} />}
                {kind === 'video' && <video src={item.preview_url} controls autoPlay playsInline aria-label={item.title} />}
                {kind === 'audio' && <audio src={item.preview_url} controls autoPlay aria-label={item.title} />}
                {!kind && <p className="dw-note">{t(item.type === 'model3d' && item.previewable ? 'Pratinjau interaktif tersedia di Studio 3D.' : 'Format ini tidak memiliki pratinjau di browser. Unduh file untuk membukanya.')}</p>}
            </div>
            <div className="lib-lightbox-actions">
                {item.download_url && <a className="dw-button dw-button-primary" href={item.download_url} download>{Icons.download}<span>{t('Unduh')}</span></a>}
                {item.type === 'model3d' && item.page_url && <Link className="dw-button" to={localizedPath(item.page_url)}>{t('Buka Studio 3D')}</Link>}
            </div>
        </MediaActionDialog>
    );
}

function daysLeft(value) {
    if (!value) return null;
    const ms = new Date(value).getTime() - Date.now();
    if (Number.isNaN(ms)) return null;
    return Math.max(0, Math.ceil(ms / 86400000));
}

function StorageMeter({ storage, t, locale }) {
    const { theme } = useTheme();
    const shell = { display: 'grid', gap: 10, padding: '14px 16px', border: '1px solid var(--dw-line)', borderRadius: 14, background: 'var(--dw-surface)', color: 'var(--dw-ink)' };
    const note = <p className="dw-note">{Icons.info}<span>{t('Media dihapus otomatis setelah')} {formatCount(storage.retention_days)} {t('hari — pembersihan berjalan setiap minggu.')}</span></p>;
    if (storage.unlimited) {
        return (
            <section className="lib-storage" style={shell}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                    <span style={{ fontSize: 13, fontWeight: 700 }}>{t('Penyimpanan')}</span>
                    <span style={{ padding: '2px 10px', borderRadius: 999, background: 'var(--dw-accent-soft)', color: 'var(--dw-accent)', fontSize: 11, fontWeight: 700 }}>{t('Tak terbatas')}</span>
                </div>
                <span style={{ fontSize: 11, color: 'var(--dw-muted)' }}>{t('Terpakai')} {bytes(storage.used_bytes, locale)}</span>
                {note}
            </section>
        );
    }
    const used = storage.used_bytes || 0;
    const quota = storage.quota_bytes || 0;
    const pct = quota > 0 ? Math.min(100, Math.round((used / quota) * 100)) : (storage.exceeded ? 100 : 0);
    const dark = theme === 'dark';
    const fill = storage.exceeded ? (dark ? '#f87171' : '#dc2626') : pct > 80 ? (dark ? '#fbbf24' : '#d97706') : (dark ? '#34d399' : '#059669');
    const dl = daysLeft(storage.upgrade_expires_at);
    const bonusExpiry = dl == null ? '' : dl === 0 ? ` · ${t('berakhir hari ini')}` : ` · ${formatCount(dl)} ${t('hari lagi')}`;
    return (
        <section className="lib-storage" style={shell}>
            <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12 }}>
                <span style={{ fontSize: 13, fontWeight: 700 }}>{t('Penyimpanan')}</span>
                <span style={{ fontSize: 12, fontWeight: 650, color: 'var(--dw-muted)' }}><strong style={{ color: 'var(--dw-ink)' }}>{bytes(used, locale)}</strong> / {bytes(quota, locale)}</span>
            </div>
            <div role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={t('Penggunaan penyimpanan')} style={{ height: 10, borderRadius: 999, background: 'var(--dw-surface-alt)', overflow: 'hidden' }}>
                <div className="transition-all duration-500 ease-out motion-reduce:transition-none" style={{ width: `${pct}%`, height: '100%', backgroundColor: fill, borderRadius: 999 }} />
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 8, fontSize: 11, color: 'var(--dw-muted)' }}>
                <span>{storage.exceeded ? t('Kuota penyimpanan terlampaui') : `${t('Tersisa')} ${bytes(storage.remaining_bytes, locale)}`}</span>
                {storage.upgrade_bytes > 0 && <span style={{ display: 'inline-flex', alignItems: 'center', padding: '2px 8px', borderRadius: 999, background: 'var(--dw-accent-soft)', color: 'var(--dw-accent)', fontWeight: 650 }}>{t('Bonus')} +{bytes(storage.upgrade_bytes, locale)}{bonusExpiry}</span>}
            </div>
            {note}
        </section>
    );
}

export default function Library() {
    const { user } = useAuth();
    const { t, locale, localizedPath } = useLocale();
    const [searchParams, setSearchParams] = useSearchParams();
    const type = TYPES.some(entry => entry.key === searchParams.get('type')) ? searchParams.get('type') : 'all';
    const page = Math.max(1, Number(searchParams.get('page')) || 1);
    const [state, setState] = useState({ data: null, loading: true, error: null });
    const [open, setOpen] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState(null);
    const request = useRef(null);

    const load = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setState(current => ({ ...current, loading: true, error: null }));
        try {
            const data = await apiRequest(`/api/library?${new URLSearchParams({ type, page: String(page), per_page: '24' })}`, { signal: controller.signal });
            if (!controller.signal.aborted) setState({ data, loading: false, error: null });
        } catch (error) {
            if (!controller.signal.aborted) setState(current => ({ ...current, loading: false, error }));
        }
    }, [type, page]);

    useEffect(() => { load(); return () => request.current?.abort(); }, [load, user?.id]);

    const select = (next, nextPage = 1) => {
        const params = new URLSearchParams(searchParams);
        if (next === 'all') params.delete('type'); else params.set('type', next);
        if (nextPage > 1) params.set('page', String(nextPage)); else params.delete('page');
        setSearchParams(params);
    };
    const confirmDelete = async () => {
        if (!pendingDelete?.delete_url) return;
        setDeleting(true);
        setDeleteError(null);
        try {
            await apiRequest(pendingDelete.delete_url, { method: 'DELETE' });
            setPendingDelete(null);
            load();
        } catch (error) {
            setDeleteError(error);
        } finally {
            setDeleting(false);
        }
    };

    const items = state.data?.items || [];
    const counts = state.data?.counts || {};
    const pagination = state.data?.pagination;
    const storage = state.data?.storage || null;

    return (
        <DashboardWorkspace title={t('Library')} description={t('Semua file milik Anda: unggahan referensi, hasil gambar, video, audio, unduhan, dan konversi.')} actions={<button type="button" className="dw-button" onClick={load} disabled={state.loading}>{Icons.refresh}<span>{t('Muat ulang')}</span></button>}>
            {storage?.exceeded && (
                <InlineAlert tone="error" action={<Link className="dw-button dw-button-primary" to={localizedPath('/deposit?tab=storage')}>{Icons.arrow}<span>{t('Tingkatkan penyimpanan')}</span></Link>}>
                    <strong>{t('Penyimpanan penuh.')}</strong> {t('Unduh lalu hapus item lama, atau tingkatkan penyimpanan Anda.')}
                </InlineAlert>
            )}
            {storage && <StorageMeter storage={storage} t={t} locale={locale} />}
            <div className="lib-filters" role="tablist" aria-label={t('Jenis file')}>
                {TYPES.map(entry => <button key={entry.key} type="button" role="tab" aria-selected={type === entry.key} className={`lib-filter lib-tone-${entry.tone}`} onClick={() => select(entry.key)}>{Icons[entry.icon]}<span>{t(entry.label)}</span>{counts[entry.key] != null && <small>{formatCount(counts[entry.key])}</small>}</button>)}
            </div>
            {state.error && <InlineAlert tone="error" action={<Button variant="ghost" onClick={load}>{t('Coba lagi')}</Button>}><strong>{t('Library tidak dapat dimuat.')}</strong> {t(errorMessage(state.error))}</InlineAlert>}
            {deleteError && !pendingDelete && <InlineAlert tone="error">{t(errorMessage(deleteError))}</InlineAlert>}
            {state.loading && !state.data ? (
                <div className="lib-grid" aria-busy="true">{Array.from({ length: 8 }, (_, index) => <div key={index} className="lib-card lib-card-skeleton" aria-hidden="true"><div className="lib-tile" /><div className="lib-card-body"><span className="dw-skeleton" /><span className="dw-skeleton" /></div></div>)}</div>
            ) : items.length === 0 ? (
                <div className="dw-empty lib-empty">
                    <span className="lib-tile-icon lib-tone-red" aria-hidden="true">{Icons.image}</span>
                    <h3>{t(type === 'all' ? 'Belum ada file di library' : 'Belum ada file untuk jenis ini')}</h3>
                    <p>{t('Hasil dari studio gambar, video, audio, serta unduhan dan konversi akan tersimpan di sini secara otomatis.')}</p>
                    <div className="lib-empty-actions">
                        <Link className="dw-button dw-button-primary" to={localizedPath(studioHref({ kind: 'image' }))}>{Icons.image}<span>{t('Buat gambar')}</span></Link>
                        <Link className="dw-button" to={localizedPath('/downloads')}>{Icons.download}<span>{t('Unduh video')}</span></Link>
                    </div>
                </div>
            ) : (
                <div className="lib-grid" aria-busy={state.loading}>
                    {items.map(item => {
                        const meta = [t(TYPE_LABEL[item.type] || item.type), item.format ? item.format.toUpperCase() : null, bytes(item.size_bytes, locale), clock(item.duration)].filter(Boolean).join(' · ');
                        return (
                            <article key={item.id} className="lib-card">
                                <button type="button" className="lib-tile" onClick={() => setOpen(item)} aria-label={`${t('Pratinjau')}: ${item.title}`}>
                                    <Preview item={item} title={item.title} />
                                    <span className={`lib-badge lib-tone-${TYPES.find(entry => entry.key === item.type)?.tone || 'red'}`}>{t(TYPE_LABEL[item.type] || item.type)}</span>
                                </button>
                                <div className="lib-card-body">
                                    <h3 title={item.title}>{item.title}</h3>
                                    <p>{meta}</p>
                                    <time dateTime={item.created_at}>{formatLocalDate(item.created_at, { locale })}</time>
                                    <div className="lib-card-actions">
                                        {item.page_url && <Link className="lib-action" to={localizedPath(item.page_url)} title={t(PAGE_LABELS[item.type] || 'Buka')}>{Icons.arrow}<span>{t('Buka')}</span></Link>}
                                        {item.download_url && <a className="lib-action" href={item.download_url} download>{Icons.download}<span>{t('Unduh')}</span></a>}
                                        {item.deletable && <button type="button" className="lib-action lib-action-danger" onClick={() => { setDeleteError(null); setPendingDelete(item); }}>{Icons.close}<span>{t('Hapus')}</span></button>}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
            {pagination && pagination.last_page > 1 && (
                <nav className="lib-pagination" aria-label={t('Halaman library')}>
                    <button type="button" className="dw-button" disabled={pagination.current_page <= 1 || state.loading} onClick={() => select(type, pagination.current_page - 1)}>{t('Sebelumnya')}</button>
                    <span>{t('Halaman')} {formatCount(pagination.current_page)} / {formatCount(pagination.last_page)} · {formatCount(pagination.total)} {t('file')}</span>
                    <button type="button" className="dw-button" disabled={pagination.current_page >= pagination.last_page || state.loading} onClick={() => select(type, pagination.current_page + 1)}>{t('Berikutnya')}</button>
                </nav>
            )}
            <p className="dw-note">{Icons.info}{t('File hasil studio tetap terhubung dengan riwayat dan tagihan akun. File unduhan dan konversi dapat dihapus kapan saja.')}</p>
            {open && <Lightbox item={open} onClose={() => setOpen(null)} t={t} locale={locale} />}
            {pendingDelete && <MediaActionDialog title={t('Hapus file ini?')} description={`${pendingDelete.title} — ${t('File beserta pekerjaannya akan dihapus permanen dari akun Anda.')}`} closeLabel={t('Batal')} confirmLabel={t('Hapus')} busyLabel={t('Menghapus…')} busy={deleting} error={deleteError ? t(errorMessage(deleteError)) : null} onConfirm={confirmDelete} onClose={() => { if (!deleting) setPendingDelete(null); }} />}
        </DashboardWorkspace>
    );
}
