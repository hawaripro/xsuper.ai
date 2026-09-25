import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { apiRequest } from '../lib/api';
import { useLocale } from '../contexts/LocaleContext';

// Dark tints are the former 10% level colors pre-composited over the shell background (#030712):
// the ribbon sticks above scrolling content, so it must stay opaque.
const levelStyles = {
    info: 'bg-sky-50 text-sky-800 border-sky-200/70 dark:bg-[#031728] dark:text-sky-300 dark:border-sky-500/20',
    success: 'bg-emerald-50 text-emerald-800 border-emerald-200/70 dark:bg-[#03191c] dark:text-emerald-300 dark:border-emerald-500/20',
    warning: 'bg-amber-50 text-amber-900 border-amber-200/70 dark:bg-[#1c1510] dark:text-amber-300 dark:border-amber-500/20',
    critical: 'bg-red-50 text-red-800 border-red-200/70 dark:bg-[#1c0a16] dark:text-red-300 dark:border-red-500/25',
};

const SPEED_PX_PER_SECOND = 70;
const HEIGHT_VAR = '--xs-ribbon-h';

/**
 * Left-moving announcement marquee. Fetches the localized announcement from
 * the real endpoint (`GET /api/content/announcement?locale=…`, dashboard
 * surface resolved server-side); renders nothing when the backend returns no
 * announcement.
 *
 * The message is repeated until one group is at least as wide as the ribbon,
 * then the group is duplicated and the track slides by exactly one group
 * width (-50%). The loop therefore restarts pixel-identical: the text never
 * jumps in from the middle when a short message sits in a wide viewport.
 * Duration scales with the group width so the speed stays constant.
 * Reduced-motion users get a static line via the global override in app.css.
 *
 * The ribbon publishes its own height as `--xs-ribbon-h` on the root element
 * ('0px' without an announcement and after unmount), so every full-page shell
 * can start its sticky bars and mobile drawers right below it.
 */
export default function AnnouncementRibbon({ surface = 'dashboard' }) {
    const { locale, localizedPath } = useLocale();
    const { key: locationKey } = useLocation();
    const [announcement, setAnnouncement] = useState(null);
    const ribbon = useRef(null);
    const probe = useRef(null);
    const [layout, setLayout] = useState({ copies: 2, duration: 30 });
    const visible = Boolean(announcement?.message);

    useLayoutEffect(() => {
        const root = document.documentElement;
        const element = ribbon.current;
        if (!visible || !element) {
            root.style.setProperty(HEIGHT_VAR, '0px');
            return undefined;
        }
        const publish = () => root.style.setProperty(HEIGHT_VAR, `${Math.round(element.getBoundingClientRect().height)}px`);
        publish();
        const observer = new ResizeObserver(publish);
        observer.observe(element);
        return () => {
            observer.disconnect();
            root.style.setProperty(HEIGHT_VAR, '0px');
        };
    }, [visible]);

    useLayoutEffect(() => {
        if (!announcement?.message || !ribbon.current || !probe.current) return undefined;
        const measure = () => {
            const container = ribbon.current.getBoundingClientRect().width;
            const unit = probe.current.getBoundingClientRect().width;
            if (!container || !unit) return;
            const copies = Math.max(2, Math.ceil(container / unit) + 1);
            setLayout({ copies, duration: Math.max(12, Math.round((copies * unit) / SPEED_PX_PER_SECOND)) });
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(ribbon.current);
        return () => observer.disconnect();
    }, [announcement]);

    useEffect(() => {
        let controller;
        const refresh = () => {
            controller?.abort();
            controller = new AbortController();
            const { signal } = controller;
            apiRequest(`/api/content/announcement?locale=${locale}`, { signal })
                .then((data) => { if (!signal.aborted) setAnnouncement(data?.announcement ?? null); })
                .catch(() => { if (!signal.aborted) setAnnouncement(null); });
        };
        refresh();
        window.addEventListener('content-publication-changed', refresh);
        window.addEventListener('focus', refresh);
        return () => {
            controller?.abort();
            window.removeEventListener('content-publication-changed', refresh);
            window.removeEventListener('focus', refresh);
        };
    }, [locale, surface, locationKey]);

    if (!visible) return null;

    const level = levelStyles[announcement.level] || levelStyles.info;
    const line = (
        <>
            <span className="shrink-0 font-medium">{announcement.message}</span>
            {announcement.action?.url && announcement.action?.label && (
                <a
                    href={localizedPath(announcement.action.url)}
                    className="shrink-0 ml-3 inline-flex items-center gap-1 font-semibold underline underline-offset-2 decoration-current/50 hover:decoration-current"
                >
                    {announcement.action.label}
                    <svg className="w-3 h-3" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                </a>
            )}
            <span className="shrink-0 mx-6 opacity-40" aria-hidden="true">•</span>
        </>
    );

    const group = (hidden) => Array.from({ length: layout.copies }, (_, index) => (
        <span key={index} ref={!hidden && index === 0 ? probe : undefined} className="flex items-center pl-4">{line}</span>
    ));

    return (
        <aside
            ref={ribbon}
            role="status"
            data-level={announcement.level ?? 'info'}
            aria-label={announcement.message}
            className={`relative overflow-hidden border-b text-xs leading-5 ${level}`}
        >
            <div
                className="flex w-max whitespace-nowrap py-1.5 will-change-transform motion-safe:animate-[ribbon-scroll_var(--ribbon-duration)_linear_infinite]"
                style={{ '--ribbon-duration': `${layout.duration}s` }}
            >
                <span className="flex">{group(false)}</span>
                <span className="flex" aria-hidden="true">{group(true)}</span>
            </div>
        </aside>
    );
}
