import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useLocale } from '../../contexts/LocaleContext';
import { useNotifications } from '../../contexts/NotificationContext';
import { Button, StatePanel, formatCount, formatLocalDate } from '../member/MemberUI';
import Icons from '../../layouts/SidebarIcons';
import './dashboard-workspace.css';

export default function DashboardWorkspace({ hero, eyebrow, title, description, actions, children }) {
    return (
        <div className="dashboard-workspace">
            {hero ? (
                <header className="dw-heading dw-heading-hero">
                    {hero}
                    {actions && <div className="dw-heading-actions">{actions}</div>}
                </header>
            ) : (
                <header className="dw-heading">
                    <div>
                        {eyebrow && <p className="dw-eyebrow">{eyebrow}</p>}
                        <h1>{title}</h1>
                        <p>{description}</p>
                    </div>
                    {actions && <div className="dw-heading-actions">{actions}</div>}
                </header>
            )}
            {children}
        </div>
    );
}

// Live greeting hero: rounded panel with an animated dot field, a pulsing status pill,
// the time-of-day greeting, and a clock card that ticks each minute.
export function DashboardHero({ pill, greeting, name, description, clockLabel, locale = 'id' }) {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const tick = () => setNow(new Date());
        const timer = setInterval(tick, 30_000);
        return () => clearInterval(timer);
    }, []);
    const intl = locale === 'en' ? 'en-US' : 'id-ID';
    const weekday = new Intl.DateTimeFormat(intl, { weekday: 'long' }).format(now);
    const clock = new Intl.DateTimeFormat(intl, { hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
    const datePart = new Intl.DateTimeFormat(intl, { day: 'numeric', month: 'long', year: 'numeric' }).format(now);
    return (
        <div className="dw-hero">
            <div className="dw-hero-dots" aria-hidden="true" />
            <div className="dw-hero-glow" aria-hidden="true" />
            <div className="dw-hero-glow dw-hero-glow-2" aria-hidden="true" />
            <div className="dw-hero-body">
                <div className="dw-hero-copy">
                    {pill && <p className="dw-eyebrow dw-hero-pill">{pill}</p>}
                    <h1 className="dw-hero-title">{greeting}, <span className="dw-hero-name">{name}</span></h1>
                    {description && <p className="dw-hero-desc">{description}</p>}
                </div>
                <div className="dw-hero-clock" role="group" aria-label={clockLabel}>
                    <span className="dw-hero-weekday">{weekday}</span>
                    <time className="dw-hero-time" dateTime={now.toISOString()}>{clock}</time>
                    <span className="dw-hero-date">{datePart}</span>
                </div>
            </div>
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
    const loc = locale === 'en' ? 'en-US' : 'id-ID';
    if (/^\d{4}-\d{2}$/.test(value)) {
        const month = new Date(`${value}-01T12:00:00Z`);
        return Number.isNaN(month.getTime()) ? value : new Intl.DateTimeFormat(loc, { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(month);
    }
    // Hourly buckets ("1 hari") arrive as an ISO UTC hour, e.g. 2025-09-20T14:00:00Z.
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:00:00Z$/.test(value)) {
        const hour = new Date(value);
        return Number.isNaN(hour.getTime()) ? value : new Intl.DateTimeFormat(loc, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC' }).format(hour);
    }
    const date = new Date(`${value}T12:00:00Z`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(loc, { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(date);
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
