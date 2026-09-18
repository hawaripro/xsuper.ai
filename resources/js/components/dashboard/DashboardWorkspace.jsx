import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useLocale } from '../../contexts/LocaleContext';
import { useNotifications } from '../../contexts/NotificationContext';
import { Button, StatePanel, formatCount, formatLocalDate } from '../member/MemberUI';
import Icons from '../../layouts/SidebarIcons';
import './dashboard-workspace.css';

export default function DashboardWorkspace({ title, description, actions, children }) {
    const { t } = useLocale();
    const [density, setDensity] = useState(() => {
        try { return localStorage.getItem('ultrai-dashboard-density') === 'compact' ? 'compact' : 'comfortable'; }
        catch { return 'comfortable'; }
    });

    function toggleDensity() {
        const next = density === 'compact' ? 'comfortable' : 'compact';
        setDensity(next);
        try { localStorage.setItem('ultrai-dashboard-density', next); }
        catch { /* The current view remains usable when preference storage is unavailable. */ }
    }

    return (
        <div className="dashboard-workspace" data-density={density}>
            <header className="dw-heading">
                <div><h1>{title}</h1><p>{description}</p></div>
                <div className="dw-heading-actions">
                    <button type="button" className="dw-button" onClick={toggleDensity} aria-pressed={density === 'compact'}>
                        {Icons.density}<span>{t('Tampilan ringkas')}</span>
                    </button>
                    {actions}
                </div>
            </header>
            {children}
        </div>
    );
}

export function WorkspaceMetric({ label, value, detail, icon, tone = 'red', loading = false }) {
    return (
        <div className="dw-metric" aria-busy={loading}>
            <dt><span>{label}</span>{icon && <span className="dw-metric-icon" data-tone={tone} aria-hidden="true">{icon}</span>}</dt>
            <dd>{loading ? <span className="dw-skeleton dw-skeleton-number" aria-hidden="true" /> : value}</dd>
            <small>{detail}</small>
        </div>
    );
}

export function WorkspaceModule({ title, icon, tone = 'blue', count, children, open = true, className = '' }) {
    const titleId = useId();
    const [expanded, setExpanded] = useState(open);
    return (
        <details className={`dw-module ${className}`} open={expanded} onToggle={event => setExpanded(event.currentTarget.open)}>
            <summary>
                <span className="dw-module-title"><span className="dw-icon" data-tone={tone} aria-hidden="true">{icon}</span><span id={titleId}>{title}</span></span>
                <span className="dw-module-meta">{count != null && <span className="dw-count">{formatCount(count)}</span>}<span className="dw-chevron" aria-hidden="true">{Icons.chevron}</span></span>
            </summary>
            <div className="dw-module-body" role="region" aria-labelledby={titleId}>{children}</div>
        </details>
    );
}

export function WorkspaceLoading({ label }) {
    return <div className="dw-loading" role="status"><span>{label}</span><span className="dw-skeleton" /><span className="dw-skeleton" /><span className="dw-skeleton dw-skeleton-short" /></div>;
}

function chartDate(value, locale) {
    const date = /^\d{4}-\d{2}$/.test(value) ? new Date(`${value}-01T12:00:00Z`) : new Date(`${value}T12:00:00Z`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(locale === 'en' ? 'en-US' : 'id-ID', { month: 'short', ...(/^\d{4}-\d{2}$/.test(value) ? { year: 'numeric' } : { day: 'numeric' }), timeZone: 'UTC' }).format(date);
}

function UsageTooltip({ active, payload, label }) {
    const { t, locale } = useLocale();
    if (!active || !payload?.length) return null;
    return <div className="dw-chart-tooltip"><strong>{chartDate(label, locale)}</strong><span>{formatCount(payload[0].value)} {t('permintaan')}</span></div>;
}

export function WorkspaceUsageChart({ rows, loading, error, onRetry, controls, description, reportPath }) {
    const { t, locale, localizedPath } = useLocale();
    const titleId = useId();
    const total = rows.reduce((sum, row) => sum + Number(row.requests || 0), 0);
    return (
        <section className="dw-analysis" aria-labelledby={titleId} aria-busy={loading}>
            <header className="dw-section-head"><div><h2 id={titleId}>{t('Pola penggunaan')}</h2><p>{description}</p></div>{controls}</header>
            {loading ? <WorkspaceLoading label={t('Memuat pemakaian')} /> : error ? <StatePanel type="error" title={t('Pemakaian tidak dapat dimuat')} description={error} action={<Button variant="secondary" onClick={onRetry}>{t('Coba lagi')}</Button>} /> : !rows.length ? <div className="dw-chart-empty"><span className="dw-icon" data-tone="cyan" aria-hidden="true">{Icons.token}</span><h3>{t('Belum ada pemakaian pada periode ini')}</h3><p>{t('Permintaan yang tercatat akan membentuk grafik penggunaan Anda.')}</p></div> : (
                <>
                    <div className="dw-chart-caption"><span>{t('Permintaan per periode')}</span><span>{Icons.token}{t('Data pemakaian tercatat')}</span></div>
                    <div className="dw-chart" role="group" aria-label={t('Grafik jumlah permintaan')}>
                        <ResponsiveContainer width="100%" height={252} minWidth={0}>
                            <AreaChart data={rows} margin={{ top: 18, right: 10, bottom: 4, left: 0 }} accessibilityLayer>
                                <CartesianGrid vertical={false} stroke="var(--dw-line)" strokeDasharray="3 5" />
                                <XAxis dataKey="period" tickFormatter={value => chartDate(value, locale)} axisLine={false} tickLine={false} minTickGap={28} tick={{ fill: 'var(--dw-muted)', fontSize: 11 }} dy={8} />
                                <YAxis width={42} allowDecimals={false} axisLine={false} tickLine={false} tick={{ fill: 'var(--dw-muted)', fontSize: 11 }} tickFormatter={value => new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'id-ID', { notation: 'compact' }).format(value)} />
                                <Tooltip content={<UsageTooltip />} cursor={{ stroke: 'var(--dw-muted)', strokeDasharray: '3 4' }} />
                                <Area type="linear" dataKey="requests" name={t('Permintaan')} stroke="var(--dw-accent)" strokeWidth={2.5} fill="var(--dw-accent-soft)" fillOpacity={1} isAnimationActive={false} dot={rows.length <= 12 ? { r: 3, strokeWidth: 2, fill: 'var(--dw-surface)' } : false} activeDot={{ r: 5, strokeWidth: 2, fill: 'var(--dw-surface)' }} />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="dw-chart-total"><span>{t('Total')} <strong>{formatCount(total)} {t('permintaan')}</strong></span><span>{t('Hanya periode dengan aktivitas tercatat')}</span></div>
                    <details className="dw-chart-data"><summary>{t('Lihat data grafik')}</summary><div className="dw-table-scroll"><table><caption className="sr-only">{t('Data pemakaian tercatat')}</caption><thead><tr><th scope="col">{t('Periode')}</th><th scope="col">{t('Permintaan')}</th><th scope="col">{t('Token pemakaian')}</th></tr></thead><tbody>{rows.map(row => <tr key={row.period}><th scope="row">{chartDate(row.period, locale)}</th><td>{formatCount(row.requests)}</td><td>{formatCount(row.tokens)}</td></tr>)}</tbody></table></div></details>
                </>
            )}
            <footer className="dw-section-footer"><span>{Icons.info}{t('Token generator dan biaya PAYG tetap terpisah.')}</span><Link to={localizedPath(reportPath)} className="dw-text-link">{t('Laporan lengkap')}{Icons.arrow}</Link></footer>
        </section>
    );
}

export function WorkspaceInbox() {
    const { t, locale, localizedPath } = useLocale();
    const { items, unreadCount, loading, error, pagination, refresh, connectionState } = useNotifications();
    const connection = connectionState === 'connected' ? t('Pembaruan realtime aktif') : t('Status koneksi tersedia di bel notifikasi.');
    return (
        <WorkspaceModule title={t('Pembaruan')} icon={Icons.bell} tone="pink" count={!loading && !error ? unreadCount : undefined}>
            {loading && !items.length ? <WorkspaceLoading label={t('Memuat notifikasi')} /> : error ? <StatePanel type="error" title={t('Notifikasi tidak dapat dimuat')} description={typeof error === 'string' ? error : error.message} compact action={<Button variant="ghost" onClick={refresh}>{t('Coba lagi')}</Button>} /> : !items.length ? <div className="dw-empty"><h3>{t('Belum ada notifikasi')}</h3><p>{t('Hasil media, balasan dukungan, dan pembaruan akun akan muncul di sini.')}</p></div> : <ul className="dw-event-list">{items.slice(0, 4).map(item => <li key={item.id}><Link to={localizedPath('/notifications')} className="dw-event"><span className={`dw-event-dot ${item.read_at ? 'is-read' : ''}`} aria-hidden="true" /><span><strong>{item.title}</strong><p>{item.body}</p><time dateTime={item.created_at}>{formatLocalDate(item.created_at, { locale: locale === 'en' ? 'en-US' : 'id-ID' })}</time></span></Link></li>)}</ul>}
            <footer className="dw-module-footer"><span>{pagination?.current_page > 1 ? `${t('Halaman')} ${pagination.current_page}` : connection}</span><Link className="dw-text-link" to={localizedPath('/notifications')}>{t('Buka Inbox')}{Icons.arrow}</Link></footer>
        </WorkspaceModule>
    );
}
