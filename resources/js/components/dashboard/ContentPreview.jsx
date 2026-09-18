import { useEffect } from 'react';
import { useLocale } from '../../contexts/LocaleContext';

/**
 * Read-only, draft-only preview of a CMS content block. Never reads the
 * published snapshot and never touches the network or the database; it renders
 * only the `draft` prop handed to it. All text is escaped by React; action
 * links render only for safe http/https/root-relative URLs, otherwise the
 * label renders as inert text.
 */
function safeHref(url) {
    if (typeof url !== 'string' || url.length === 0 || url.length > 500) return null;
    if (url.startsWith('/') && !url.startsWith('//')) return url;
    try {
        const parsed = new URL(url);
        return ['http:', 'https:'].includes(parsed.protocol) ? url : null;
    } catch {
        return null;
    }
}

function ActionLink({ action }) {
    if (!action || typeof action !== 'object') return null;
    const href = safeHref(action.url);
    const label = typeof action.label === 'string' ? action.label : '';
    if (!href) return <span className="mt-3 inline-block text-xs font-semibold text-slate-500 dark:text-slate-400">{label}</span>;
    return <span className="mt-3 inline-block text-xs font-semibold text-sky-600 underline dark:text-sky-300">{label}</span>;
}

function HeroPreview({ draft }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[.03]">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white">{draft.headline}</h3>
            <p className="mt-2 whitespace-pre-wrap text-xs leading-5 text-slate-600 dark:text-slate-300">{draft.description}</p>
            <ActionLink action={draft.primary_action} />
        </div>
    );
}

function FaqPreview({ draft }) {
    const items = Array.isArray(draft.items) ? draft.items : [];
    return (
        <div className="space-y-2">
            {items.map((item, index) => (
                <details key={index} className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                    <summary className="cursor-pointer text-xs font-semibold text-slate-900 dark:text-white">{item.question}</summary>
                    <p className="mt-2 whitespace-pre-wrap text-xs leading-5 text-slate-600 dark:text-slate-300">{item.answer}</p>
                </details>
            ))}
        </div>
    );
}

const announcementLevels = {
    info: 'border-sky-500/30 bg-sky-500/5 text-sky-800 dark:text-sky-200',
    success: 'border-emerald-500/30 bg-emerald-500/5 text-emerald-800 dark:text-emerald-200',
    warning: 'border-amber-500/30 bg-amber-500/5 text-amber-800 dark:text-amber-200',
    critical: 'border-red-500/30 bg-red-500/5 text-red-800 dark:text-red-200',
};

function AnnouncementPreview({ draft }) {
    const level = announcementLevels[draft.level] || announcementLevels.info;
    return (
        <div className={`rounded-xl border p-4 text-xs leading-5 ${level}`}>
            <strong className="block text-[11px] uppercase tracking-wide">{draft.level || 'info'}</strong>
            <p className="mt-1 whitespace-pre-wrap">{draft.message}</p>
            <ActionLink action={draft.action} />
        </div>
    );
}

function ArticlesPreview({ draft }) {
    const items = Array.isArray(draft.items) ? draft.items : [];
    return (
        <div className="space-y-2">
            {items.map((item, index) => (
                <article key={item.slug || index} className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                    <h4 className="text-xs font-bold text-slate-900 dark:text-white">{item.title}</h4>
                    <p className="mt-1 text-[11px] leading-5 text-slate-500 dark:text-slate-400">{item.summary}</p>
                    <details className="mt-1">
                        <summary className="cursor-pointer text-[11px] font-semibold text-sky-600 dark:text-sky-300">{item.slug}</summary>
                        <p className="mt-2 whitespace-pre-wrap text-xs leading-5 text-slate-600 dark:text-slate-300">{item.body}</p>
                    </details>
                </article>
            ))}
        </div>
    );
}

const previewByKey = {
    'home.hero': HeroPreview,
    'home.faq': FaqPreview,
    'system.announcement': AnnouncementPreview,
    'help.articles': ArticlesPreview,
};

function canPreview(key, draft) {
    if (!draft || typeof draft !== 'object' || Array.isArray(draft)) return false;
    if (key === 'home.hero') return typeof draft.headline === 'string' && typeof draft.description === 'string';
    if (key === 'system.announcement') return typeof draft.message === 'string' && (draft.level == null || typeof draft.level === 'string');
    const fields = key === 'home.faq' ? ['question', 'answer'] : ['slug', 'title', 'body'];
    return Array.isArray(draft.items) && draft.items.every(item => item && typeof item === 'object'
        && fields.every(field => typeof item[field] === 'string')
        && (item.summary == null || typeof item.summary === 'string'));
}

export default function ContentPreview({ block, onClose }) {
    const { t } = useLocale();
    const Preview = previewByKey[block?.key];
    const draft = block?.draft && typeof block.draft === 'object' ? block.draft : {};

    useEffect(() => {
        const onKey = event => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    if (!block || !Preview) return null;

    return (
        <div className="fixed inset-0 z-[85] grid place-items-center bg-slate-950/55 p-4" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) onClose(); }}>
            <div className="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="content-preview-title" aria-describedby="content-preview-note">
                <div className="flex items-start justify-between gap-3 border-b border-slate-200 p-4 dark:border-white/10">
                    <div>
                        <h2 id="content-preview-title" className="text-base font-bold text-slate-900 dark:text-white">{t("Draft preview")}</h2>
                        <p id="content-preview-note" className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{t("This is the unpublished draft only. Nothing is sent anywhere.")}{' '}<span className="font-mono">{block.key}</span> · <span>{(block.locale || '').toUpperCase()}</span></p>
                    </div>
                    <button type="button" autoFocus className="ui-btn-secondary" onClick={onClose}>{t("Close")}</button>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {canPreview(block.key, draft) ? <Preview draft={draft} /> : <p role="alert" className="text-sm text-red-700 dark:text-red-300">{t('Draft content is incomplete or invalid. Close the preview to correct it; your changes are kept.')}</p>}
                </div>
            </div>
        </div>
    );
}
