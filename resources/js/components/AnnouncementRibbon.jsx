import React, { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { apiRequest } from '../lib/api';
import { useLocale } from '../contexts/LocaleContext';

const levelStyles = {
    info: 'bg-sky-50 text-sky-800 border-sky-200/70 dark:bg-sky-500/10 dark:text-sky-300 dark:border-sky-500/20',
    success: 'bg-emerald-50 text-emerald-800 border-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20',
    warning: 'bg-amber-50 text-amber-900 border-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20',
    critical: 'bg-red-50 text-red-800 border-red-200/70 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/25',
};

/**
 * Left-moving announcement marquee. Fetches the localized announcement from
 * the real endpoint (`GET /api/content/announcement?locale=…`, dashboard
 * surface resolved server-side); renders nothing when the backend returns no
 * announcement. The track duplicates content for a seamless loop;
 * reduced-motion users get a static line via the global
 * `prefers-reduced-motion` override in app.css.
 */
export default function AnnouncementRibbon({ surface = 'dashboard' }) {
    const { locale, localizedPath } = useLocale();
    const { key: locationKey } = useLocation();
    const [announcement, setAnnouncement] = useState(null);

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

    if (!announcement?.message) return null;

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

    return (
        <aside
            role="status"
            data-level={announcement.level ?? 'info'}
            aria-label={announcement.message}
            className={`relative overflow-hidden border-b text-xs leading-5 ${level}`}
        >
            <div className="flex w-max whitespace-nowrap py-1.5 will-change-transform motion-safe:animate-[ribbon-scroll_30s_linear_infinite]">
                <span className="flex items-center px-4">{line}</span>
                <span className="flex items-center px-4" aria-hidden="true">{line}</span>
            </div>
        </aside>
    );
}
