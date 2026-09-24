import React, { memo, useState, useEffect, useLayoutEffect, useRef, useCallback, useContext, useMemo } from 'react';
import { useNavigate, Link, useLocation, useBlocker, UNSAFE_DataRouterContext } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import AnnouncementRibbon from '../components/AnnouncementRibbon';
import { UltrLockup } from '../components/UltrLogo';
import { apiRequest } from '../lib/api';
import GenerationProgress from '../components/GenerationProgress';
import MediaActionDialog from '../components/MediaActionDialog';
import useChatWorkspace from '../hooks/useChatWorkspace';
import WorkspaceNavigation from '../components/chat/WorkspaceNavigation';
import WorkspaceContextPanel from '../components/chat/WorkspaceContextPanel';
import SafeMarkdown from '../components/chat/SafeMarkdown';
import VoiceInput from '../components/chat/VoiceInput';
import { SaveArtifactDialog } from '../components/chat/ArtifactPanel';
import './chat-workspace.css';

// ============================================
// Icons (Studio line-icon style)
// ============================================
const stroke = { fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' };
const Icon = {
    chat: <svg {...stroke} viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>,
    image: <svg {...stroke} viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m21 15-5-5L5 21" /></svg>,
    video: <svg {...stroke} viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="2" /><path d="m10 8 6 4-6 4V8Z" /></svg>,
    search: <svg {...stroke} viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" /></svg>,
    close: <svg {...stroke} viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18" /></svg>,
    menu: <svg {...stroke} viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16" /></svg>,
    collapse: <svg {...stroke} viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3" /><path d="M9 4v16m7-11-3 3 3 3" /></svg>,
    panel: <svg {...stroke} viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3" /><path d="M15 4v16" /></svg>,
    chevron: <svg {...stroke} viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>,
    send: <svg {...stroke} viewBox="0 0 24 24"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z" /></svg>,
    stop: <svg {...stroke} viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>,
    attach: <svg {...stroke} viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" /></svg>,
    file: <svg {...stroke} viewBox="0 0 24 24"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10Zm0 0v7h7M8 14h8m-8 3h5" /></svg>,
    copy: <svg {...stroke} viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M15 8V4H4v11h4" /></svg>,
    check: <svg {...stroke} viewBox="0 0 24 24"><path d="m4 12 5 5L20 7" /></svg>,
    save: <svg {...stroke} viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z" /><path d="M7 3v6h8V3M7 21v-7h10v7" /></svg>,
    refresh: <svg {...stroke} viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.9-3M4 4v4h4M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4" /></svg>,
    alert: <svg {...stroke} viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01" /><path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /></svg>,
    arrowDown: <svg {...stroke} viewBox="0 0 24 24"><path d="M12 5v14m-6-6 6 6 6-6" /></svg>,
    sun: <svg {...stroke} viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" /></svg>,
    moon: <svg {...stroke} viewBox="0 0 24 24"><path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z" /></svg>,
    grid: <svg {...stroke} viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg>,
    spark: <svg {...stroke} viewBox="0 0 24 24"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5ZM20 2v4m-2-2h4" /></svg>,
    lock: <svg {...stroke} viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></svg>,
    arrow: <svg {...stroke} viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>,
    message: <svg {...stroke} viewBox="0 0 24 24"><path d="M20 11.5a8 8 0 0 1-8 8H4l1.8-4A8 8 0 1 1 20 11.5Z" /></svg>,
};

// The previous inline <svg> drew a letter "U" — a leftover from the old UltrAI
// identity. Two marks replace it: the full app icon for the standalone hero, and
// a white X glyph for the small avatars, which keep their category-coloured
// gradient (a red plate inside a red gradient would read as mud).
const Sigil = ({ className = '' }) => (
    <img src="/xsuper-icon-v2.png" alt="" aria-hidden="true" className={className} decoding="async" />
);

const XGlyph = ({ className = '' }) => (
    <img src="/xsuper-x-white-v2.png" alt="" aria-hidden="true" className={className} decoding="async" />
);

// ============================================
// Constants & Config
// ============================================
const CATEGORY_CONFIG = {
    chat: { label: 'Chat', icon: Icon.chat, accent: 'var(--red-500)' },
    image: { label: 'Image', icon: Icon.image, accent: '#8b5cf6' },
    video: { label: 'Video', icon: Icon.video, accent: '#f97316' },
};
const WORKING = new Set(['sending', 'queued', 'pending', 'running', 'streaming']);
const GUARD_CANCELLED = Symbol('guard-cancelled');
// Artifact edits and the save dialog belong to one conversation; notes belong to the workspace.
const CONVERSATION_SCOPES = ['artifact', 'save'];
const PANEL_SCOPES = ['notes', 'artifact'];
const ALL_SCOPES = ['notes', 'artifact', 'save'];

// ============================================
// Scrub branded text
// ============================================
function rebrandText(text) {
    if (!text) return text;
    var p = [101,110,111,119,120].map(c => String.fromCharCode(c)).join('');
    return text
        .replace(new RegExp(p + 'ai', 'gi'), 'XSuper.ai')
        .replace(new RegExp(p + '\\s*labs', 'gi'), 'XSuper.ai')
        .replace(new RegExp(p, 'gi'), 'XSuper.ai')
        .replace(/XSuper.ai\s*Labs/gi, 'XSuper.ai')
        .replace(/\bKiro\b/gi, 'XSuper.ai');
}

function ownedUrl(value) {
    if (typeof value !== 'string' || !value || /[\u0000-\u0020\\]/.test(value)) return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && url.pathname.startsWith('/api/') && !url.username && !url.password ? `${url.pathname}${url.search}` : null;
    } catch { return null; }
}

function sizeLabel(bytes) {
    const value = Number(bytes) || 0;
    return value < 1024 ? `${value} B` : value < 1048576 ? `${(value / 1024).toFixed(1)} KB` : `${(value / 1048576).toFixed(1)} MB`;
}

function useMediaQuery(query) {
    const [matches, setMatches] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);
    useEffect(() => {
        const media = window.matchMedia(query);
        const update = () => setMatches(media.matches);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, [query]);
    return matches;
}

// ============================================
// Chat Message Component
// ============================================
const ChatMessage = memo(function ChatMessage({ message, userName, isDark, categoryColor, t, pending = false, modelName, startedAt, error, conversationReady, canAct, actReason, onRetry, onContinue, onSave, onSaveCode }) {
    const isUser = message.role === 'user';
    const catCfg = CATEGORY_CONFIG[categoryColor] || CATEGORY_CONFIG.chat;
    const [copied, setCopied] = useState(false);
    const copyTimer = useRef(null);
    useEffect(() => () => clearTimeout(copyTimer.current), []);
    const status = message.status || 'completed';
    const displayText = isUser ? String(message.content || '') : rebrandText(String(message.content || ''));
    const working = !isUser && WORKING.has(status);
    const saved = /^\d+$/.test(String(message.id));
    const truncated = status === 'completed' && message.finish_reason === 'length';
    const interrupted = !isUser && (status === 'stopped' || status === 'failed');
    const canSave = !isUser && saved && conversationReady && ['completed', 'stopped', 'failed'].includes(status) && displayText.trim() !== '';
    const attachments = Array.isArray(message.attachments) ? message.attachments : [];

    const handleCopy = () => {
        navigator.clipboard?.writeText(displayText).then(() => {
            setCopied(true);
            clearTimeout(copyTimer.current);
            copyTimer.current = setTimeout(() => setCopied(false), 2000);
        }).catch(() => {});
    };
    const note = status === 'failed' ? [t('Jawaban gagal.'), error, displayText ? t('Teks di atas tersimpan sebagian.') : ''].filter(Boolean).join(' ')
        : status === 'stopped' ? (displayText ? t('Jawaban dihentikan. Teks di atas tersimpan sebagian.') : t('Jawaban dihentikan sebelum ada teks.'))
            : t('Jawaban terhenti karena batas panjang model.');

    return (
        <div className={`cw-msg group ${isUser ? 'cw-msg-user' : 'cw-msg-assistant'}${pending ? ' cw-msg-pending' : ''}`} data-status={status}>
            <div className={`cw-avatar ${isUser ? 'cw-avatar-user' : 'cw-avatar-ai'}`} style={!isUser ? { background: `linear-gradient(135deg, ${catCfg.accent}, var(--red-600))` } : undefined}>
                {isUser ? (userName?.[0]?.toUpperCase() || 'U') : <XGlyph className="cw-sigil-icon" />}
            </div>
            <div className="cw-msg-body">
                <div className="cw-msg-meta">
                    <span className="cw-msg-author">{isUser ? (userName || 'You') : 'XSuper.ai'}</span>
                    {/* A retry or continuation is a separate saved answer; say which one it follows. */}
                    {!isUser && (message.continuation_of || message.retry_of) && (
                        <span className="cw-msg-relation">{message.continuation_of ? t('Lanjutan jawaban sebelumnya') : t('Jawaban ulang')}</span>
                    )}
                    {!isUser && displayText && !working && (
                        <button type="button" onClick={handleCopy} className={`cw-copy-btn ${copied ? 'cw-copy-done' : ''}`} aria-label={t('Salin')} title={t('Salin')}>
                            {copied ? Icon.check : Icon.copy}
                            <span>{copied ? t('Disalin') : t('Salin')}</span>
                        </button>
                    )}
                    {canSave && onSave && (
                        <button type="button" onClick={() => onSave(message)} className="cw-copy-btn" aria-label={t('Simpan sebagai artefak')} title={t('Simpan sebagai artefak')}>
                            {Icon.save}
                            <span>{t('Simpan')}</span>
                        </button>
                    )}
                </div>

                {attachments.length > 0 && (
                    <div className="cw-attach-row">
                        {attachments.map((file, index) => {
                            const ready = file.status !== 'error';
                            const href = ready ? ownedUrl(file.download_url) : null;
                            const image = file.kind === 'image' || String(file.mime || '').startsWith('image/');
                            const name = file.name || t('Lampiran');
                            const body = <>{image ? Icon.image : Icon.file}<span className="cw-attach-name">{name}</span>{!ready && <span className="cw-attach-state">{t('Tidak tersedia')}</span>}</>;
                            return href
                                ? <a key={file.id || index} href={href} className="cw-attach-chip" title={name} download>{body}</a>
                                : <span key={file.id || index} className={`cw-attach-chip${ready ? '' : ' cw-attach-chip-error'}`} title={file.error || name}>{body}</span>;
                        })}
                    </div>
                )}

                {isUser ? (displayText && <div className="cw-msg-content cw-msg-user-text" dir="auto">{displayText}</div>)
                    : displayText ? (
                        <div className={`chat-content cw-msg-content ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                            <SafeMarkdown content={displayText} onSaveCode={canSave ? onSaveCode : undefined} sourceMessageId={message.id} />
                            {status === 'streaming' && <span className="cw-caret" aria-hidden="true" />}
                        </div>
                    ) : null}
                {working && !displayText && <GenerationProgress kind="chat" stage={status === 'queued' || status === 'pending' ? 'queued' : 'waiting'} model={modelName} startedAt={startedAt} compact />}
                {pending && <p className="cw-msg-pending-note" role="status">{status === 'uncertain' ? t('Belum dikonfirmasi server.') : t('Mengirim…')}</p>}
                {(interrupted || truncated) && (
                    <div className={`cw-msg-state${status === 'failed' ? ' cw-msg-state-failed' : ''}`} role="status">
                        <span>{note}</span>
                        {saved && (
                            <span className="cw-msg-actions">
                                {interrupted && onRetry && <button type="button" className="cw-continue-btn" onClick={() => onRetry(message.id)} disabled={!canAct} title={canAct ? undefined : actReason}>{Icon.refresh}{t('Coba ulang')}</button>}
                                {displayText.trim() && onContinue && <button type="button" className="cw-continue-btn" onClick={() => onContinue(message.id)} disabled={!canAct} title={canAct ? undefined : actReason}>{Icon.arrow}{t('Lanjutkan jawaban')}</button>}
                            </span>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
});

// ============================================
// Composer attachment chip (real upload state only)
// ============================================
function AttachmentChip({ file, t, onRemove, onRetry }) {
    const { locale } = useLocale();
    const image = file.kind === 'image' || String(file.mime || '').startsWith('image/');
    const preview = image && file.status === 'ready' ? ownedUrl(file.preview_url) : null;
    const name = file.name || t('Lampiran');
    const status = file.removing ? t('Menghapus…')
        : file.status === 'uploading' ? (file.progress == null ? t('Mengunggah…') : `${t('Mengunggah')} ${file.progress}%`)
            : file.status === 'processing' ? t('Menunggu konfirmasi server…')
                : file.status === 'failed' ? t('Upload gagal')
                    : file.status === 'error' ? t('Tidak tersedia') : t('Siap');
    // Type and size of the real file (brief: name, type/size and state for every attachment).
    const extension = /\.([A-Za-z0-9]{1,8})$/.exec(name)?.[1]?.toUpperCase();
    const bytes = Number(file.size ?? file.size_bytes);
    const size = Number.isFinite(bytes) && bytes >= 0 ? (bytes < 1024 ? `${bytes} B` : `${new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'id-ID', { maximumFractionDigits: 1 }).format(bytes / (bytes < 1048576 ? 1024 : 1048576))} ${bytes < 1048576 ? 'KB' : 'MB'}`) : null;
    const meta = [extension || (image ? t('Gambar') : null), size, status].filter(Boolean).join(' · ');
    return (
        <div className={`cw-chip group${file.status && file.status !== 'ready' ? ` cw-chip-${file.status}` : ''}`} title={file.error ? t(file.error) : name}>
            {preview ? <img src={preview} alt="" className="cw-chip-thumb" /> : <span className="cw-chip-icon">{image ? Icon.image : Icon.file}</span>}
            <span className="cw-chip-text">
                <span className="cw-chip-name">{name}</span>
                <span className="cw-chip-state">{meta}</span>
                {file.status === 'uploading' && <progress className="cw-chip-progress" max={100} value={file.progress ?? undefined} aria-label={`${t('Mengunggah')} ${name}`} />}
            </span>
            {file.status === 'failed' && file.file && (
                <button type="button" onClick={onRetry} className="cw-chip-retry" aria-label={`${t('Ulangi upload')}: ${name}`} title={t('Ulangi upload')}>{Icon.refresh}</button>
            )}
            <button type="button" onClick={onRemove} disabled={Boolean(file.removing)} className="cw-chip-remove" aria-label={`${t('Hapus lampiran')}: ${name}`} title={t('Hapus lampiran')}>
                {Icon.close}
            </button>
        </div>
    );
}

// ============================================
// Model Selector Dropdown
// ============================================
function ModelSelector({ models, selectedModel, onSelect, disabled, loading, lockReason, t }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const dropdownRef = useRef(null);
    const triggerRef = useRef(null);

    useEffect(() => {
        const handler = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) setOpen(false);
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const filteredModels = useMemo(() => {
        const term = search.trim().toLowerCase();
        return models.filter(model => !term || [model.name, model.id].some(value => String(value || '').toLowerCase().includes(term)));
    }, [models, search]);
    const currentModel = models.find(model => model.id === selectedModel);
    const expanded = open && !disabled;
    const close = () => { setOpen(false); triggerRef.current?.focus(); };
    const onDropdownKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        const options = Array.from(dropdownRef.current?.querySelectorAll('[role="option"]') || []);
        if (!options.length) return;
        const index = options.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : options.length - 1) : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options[next].focus();
        } else if (index >= 0 && ['Home', 'End'].includes(event.key)) {
            event.preventDefault();
            options[event.key === 'Home' ? 0 : options.length - 1].focus();
        }
    };

    return (
        <div className="cw-model-selector" ref={dropdownRef}>
            <button
                ref={triggerRef}
                type="button"
                onClick={() => setOpen(!open)}
                onKeyDown={(event) => { if (event.key === 'ArrowDown' && !disabled) { event.preventDefault(); setOpen(true); } }}
                disabled={disabled}
                className={`cw-model-trigger ${expanded ? 'cw-model-trigger-open' : ''}`}
                aria-expanded={expanded}
                aria-haspopup="listbox"
                aria-controls={expanded ? 'cw-chat-model-list' : undefined}
                title={lockReason || undefined}
                aria-describedby={lockReason ? 'cw-model-lock-reason' : undefined}
            >
                <span className="cw-model-swatch" style={{ background: 'linear-gradient(135deg, var(--red-500), var(--red-600))' }}>{Icon.chat}</span>
                <span className="cw-model-name">{rebrandText(currentModel?.name || currentModel?.id || t(loading ? 'Memuat model…' : 'Pilih model chat'))}</span>
                {/* A disabled trigger cannot take focus, so the lock is visible and its reason is announced with the control. */}
                {lockReason ? <span className="cw-model-lock" aria-hidden="true">{Icon.lock}{t('Terkunci')}</span> : <span className={`cw-chevron ${expanded ? 'cw-chevron-open' : ''}`}>{Icon.chevron}</span>}
            </button>
            {lockReason && <span id="cw-model-lock-reason" className="cw-sr-only">{lockReason}</span>}
            {expanded && (
                <>
                    <div className="cw-dropdown-overlay" onClick={close} aria-hidden="true" />
                    <div className="cw-dropdown" onKeyDown={onDropdownKeyDown}>
                        <div className="cw-dropdown-search">
                            <span className="cw-dropdown-search-icon">{Icon.search}</span>
                            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('Cari model...')} aria-label={t('Cari model...')} autoFocus />
                        </div>
                        <div id="cw-chat-model-list" className="cw-dropdown-list scrollbar-thin" role="listbox" aria-label={t('Model chat')} onWheel={(event) => event.stopPropagation()} onTouchMove={(event) => event.stopPropagation()}>
                            {filteredModels.length === 0 ? <div className="cw-dropdown-empty">{t('Tidak ada model ditemukan')}</div> : filteredModels.map(model => (
                                <button key={model.id} type="button" role="option" aria-selected={model.id === selectedModel} onClick={() => { onSelect(model.id); setSearch(''); close(); }} className={`cw-model-option ${model.id === selectedModel ? 'cw-model-option-active' : ''}`}>
                                    <span className="cw-model-swatch cw-model-swatch-sm" style={{ background: 'linear-gradient(135deg, var(--red-500), var(--red-600))' }}>{Icon.chat}</span>
                                    <span className="cw-model-option-name">{rebrandText(model.name || model.id)}</span>
                                    {model.id === selectedModel && <span className="cw-check">{Icon.check}</span>}
                                </button>
                            ))}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

// ============================================
// Mode Cards (Chat / Image / Video — real routes only)
// ============================================
// Indonesian nouns do not inflect for number; English counts need "1 model" / "2 models".
function countNoun(count, locale, id, enOne, enMany) {
    return locale === 'en' ? (count === 1 ? enOne : enMany) : id;
}

function ModeCards({ modelCounts, canImage, canVideo, onPickChat, onGoImage, onGoVideo, t }) {
    const { locale } = useLocale();
    const cards = [];
    if ((modelCounts.chat || 0) > 0) {
        cards.push({ key: 'chat', icon: Icon.chat, title: 'Chat', desc: t('Meringkas, menulis, ide, coding'), count: modelCounts.chat, action: onPickChat, accent: 'var(--red-500)' });
    }
    if (canImage) {
        cards.push({ key: 'image', icon: Icon.image, title: 'Image', desc: t('Hasilkan gambar dari deskripsi teks'), count: modelCounts.image, action: onGoImage, accent: '#8b5cf6' });
    }
    if (canVideo) {
        cards.push({ key: 'video', icon: Icon.video, title: 'Video', desc: t('Hasilkan video dari deskripsi teks'), count: modelCounts.video, action: onGoVideo, accent: '#f97316' });
    }
    if (cards.length === 0) return null;
    return (
        <div className="cw-mode-grid" role="list">
            {cards.map(c => (
                <button key={c.key} role="listitem" onClick={c.action} className="cw-mode-card" style={{ '--mode-accent': c.accent }}>
                    <span className="cw-mode-icon">{c.icon}</span>
                    <span className="cw-mode-text">
                        <span className="cw-mode-title">{c.title}</span>
                        <span className="cw-mode-desc">{c.desc}</span>
                    </span>
                    {c.count > 0 && <span className="cw-mode-count">{c.count} {countNoun(c.count, locale, 'model', 'model', 'models')}</span>}
                    <span className="cw-mode-arrow">{Icon.arrow}</span>
                </button>
            ))}
        </div>
    );
}

// ============================================
// Route leave guard (react-router blocker; data routers only)
// ============================================
function RouteLeaveGuard({ when, description, onDiscard, t }) {
    const shouldBlock = useCallback(({ currentLocation, nextLocation }) => when && currentLocation.pathname !== nextLocation.pathname, [when]);
    const blocker = useBlocker(shouldBlock);
    if (blocker.state !== 'blocked') return null;
    return (
        <MediaActionDialog
            title={t('Tinggalkan ruang chat?')}
            description={description}
            closeLabel={t('Tetap di sini')}
            confirmLabel={t('Tinggalkan halaman')}
            onClose={() => blocker.reset()}
            onConfirm={() => { onDiscard?.(); blocker.proceed(); }}
        />
    );
}

const MemoNavigation = memo(WorkspaceNavigation);
const MemoContextPanel = memo(WorkspaceContextPanel);

// ============================================
// Main Full Page Chat Component
// ============================================
export default function ChatFullPage() {
    const { user } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const { locale, t, localizedPath } = useLocale();
    const navigate = useNavigate();
    const location = useLocation();
    const inDataRouter = useContext(UNSAFE_DataRouterContext) != null;
    const isDark = theme === 'dark';
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const panelIsDrawer = useMediaQuery('(max-width: 1180px)');

    const [models, setModels] = useState([]);
    const [modelsLoading, setModelsLoading] = useState(true);
    const [modelsError, setModelsError] = useState(null);
    const [selectedModel, setSelectedModel] = useState('');
    const [showSidebar, setShowSidebar] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [isDragging, setIsDragging] = useState(false);
    const [panelOpen, setPanelOpen] = useState(false);
    const [panelTab, setPanelTab] = useState('context');
    const [activeArtifactId, setActiveArtifactId] = useState(null);
    const [artifactRefresh, setArtifactRefresh] = useState(0);
    const [saveSource, setSaveSource] = useState(null);
    const [notesDirty, setNotesDirty] = useState(false);
    const [artifactDirty, setArtifactDirty] = useState(false);
    const [saveDirty, setSaveDirty] = useState(false);
    const [discardRequest, setDiscardRequest] = useState(null);
    const [pageError, setPageError] = useState('');
    const [showJump, setShowJump] = useState(false);
    const inputRef = useRef(null);
    const fileInputRef = useRef(null);
    const threadRef = useRef(null);
    const pinnedRef = useRef(true);
    const modelRequestRef = useRef(null);
    const pendingDiscard = useRef(null);
    const chatModels = useMemo(() => models.filter(model => model.category === 'chat'), [models]);
    const chatModelsRef = useRef(chatModels);
    chatModelsRef.current = chatModels;
    const modelsLoadingRef = useRef(modelsLoading);
    modelsLoadingRef.current = modelsLoading;

    // Conversation/operation models are applied only when they are still offered to this account.
    const handleModelChange = useCallback((id) => {
        if (!id) return;
        const list = chatModelsRef.current;
        if (!modelsLoadingRef.current && list.length && !list.some((model) => model.id === id)) return;
        setSelectedModel(id);
    }, []);
    const { state, actions } = useChatWorkspace({ user, selectedModel, onModelChange: handleModelChange });
    const stateRef = useRef(state);
    stateRef.current = state;
    const selectedChatModel = chatModels.find(model => model.id === selectedModel);
    const canSendToModel = Boolean(selectedChatModel && !modelsLoading);
    const modelsById = useMemo(() => new Map(models.map((model) => [model.id, model])), [models]);
    const modelLabel = useCallback((id) => rebrandText(modelsById.get(id)?.name || id || ''), [modelsById]);

    // ===== Dirty guard: notes, artifact editor and save dialog =====
    const dirtyRef = useRef({ notes: false, artifact: false, save: false });
    dirtyRef.current = { notes: notesDirty, artifact: artifactDirty, save: saveDirty };
    const artifactDiscardRef = useRef(null);
    const discardArtifactDraft = useCallback(() => { if (dirtyRef.current.artifact) artifactDiscardRef.current?.(); }, []);
    const anyDirty = notesDirty || artifactDirty || saveDirty;
    const guard = useCallback((scopes, run) => {
        const dirty = scopes.filter((scope) => dirtyRef.current[scope]);
        if (!dirty.length) return Promise.resolve().then(run);
        pendingDiscard.current?.resolve(GUARD_CANCELLED);
        return new Promise((resolve, reject) => {
            const request = {
                scopes: dirty,
                resolve: (value) => {
                    if (pendingDiscard.current === request) { pendingDiscard.current = null; setDiscardRequest(null); }
                    resolve(value);
                },
                proceed: () => {
                    if (pendingDiscard.current === request) { pendingDiscard.current = null; setDiscardRequest(null); }
                    if (dirty.includes('save')) setSaveSource(null);
                    if (dirty.includes('artifact')) discardArtifactDraft();
                    Promise.resolve().then(run).then(resolve, reject);
                },
            };
            pendingDiscard.current = request;
            setDiscardRequest(request);
        });
    }, [discardArtifactDraft]);
    useEffect(() => () => pendingDiscard.current?.resolve(GUARD_CANCELLED), []);
    useEffect(() => {
        if (!anyDirty && !state.activeStreams) return undefined;
        const protect = (event) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', protect);
        return () => window.removeEventListener('beforeunload', protect);
    }, [anyDirty, state.activeStreams]);

    // ===== Models =====
    const loadModels = useCallback(async () => {
        modelRequestRef.current?.abort();
        const controller = new AbortController();
        modelRequestRef.current = controller;
        setModelsLoading(true);
        setModelsError(null);
        try {
            const data = await apiRequest('/api/c/am', { signal: controller.signal });
            if (!Array.isArray(data?.models)) throw new Error('Katalog model tidak dapat dibaca. Muat ulang untuk mencoba lagi.');
            setModels(data.models);
            const eligible = data.models.filter(model => model.category === 'chat');
            setSelectedModel(current => eligible.some(model => model.id === current) ? current : (eligible[0]?.id || ''));
        } catch (error) {
            if (error.name !== 'AbortError') setModelsError(error);
        } finally {
            if (!controller.signal.aborted) setModelsLoading(false);
        }
    }, []);

    const activeStreamsRef = useRef(0);
    activeStreamsRef.current = state.activeStreams;
    useEffect(() => {
        loadModels();
        const onFocus = () => { if (document.visibilityState !== 'hidden' && !activeStreamsRef.current) loadModels(); };
        window.addEventListener('focus', onFocus);
        return () => {
            window.removeEventListener('focus', onFocus);
            modelRequestRef.current?.abort();
        };
    }, [loadModels]);

    const handleSelectModel = useCallback((id) => {
        try { actions.changeModel(id); setPageError(''); }
        catch (error) { setPageError(error.message); }
    }, [actions]);
    const refreshModelStatus = () => {
        loadModels();
        actions.refreshCapabilities().catch(() => {});
    };

    // ===== URL: template prefill, deep links and the active conversation =====
    useEffect(() => {
        const prefill = location.state?.prefillPrompt;
        if (typeof prefill !== 'string' || !prefill) return;
        actions.setDraft(prefill);
        navigate({ pathname: location.pathname, search: location.search, hash: location.hash }, { replace: true, state: null });
        requestAnimationFrame(() => inputRef.current?.focus());
    }, [location.state, actions]);

    const locationRef = useRef(location);
    locationRef.current = location;
    const syncConversationUrl = useCallback((id) => {
        const current = locationRef.current;
        const params = new URLSearchParams(current.search);
        if ((params.get('conversation') || null) === (id || null)) return;
        if (id) params.set('conversation', id); else params.delete('conversation');
        const search = params.toString();
        navigate({ pathname: current.pathname, search: search ? `?${search}` : '', hash: current.hash }, { replace: true, state: current.state });
    }, [navigate]);

    // Deep link: /chat?conversation=<id> (dashboard search, library, notifications, refresh).
    const requestedConversation = new URLSearchParams(location.search).get('conversation');
    useEffect(() => {
        if (!requestedConversation || requestedConversation === stateRef.current.conversationId) return;
        guard(ALL_SCOPES, () => {
            setShowSidebar(false);
            pinnedRef.current = true;
            return actions.selectConversation(requestedConversation);
        }).then((result) => { if (result === GUARD_CANCELLED) syncConversationUrl(stateRef.current.conversationId); }).catch(() => {});
    }, [requestedConversation, actions, guard, syncConversationUrl]);

    const previousConversation = useRef(undefined);
    useEffect(() => {
        const previous = previousConversation.current;
        previousConversation.current = state.conversationId;
        if (previous === undefined || previous === state.conversationId) return;
        syncConversationUrl(state.conversationId);
    }, [state.conversationId, syncConversationUrl]);

    // Artifact selection and an open save dialog belong to the conversation that opened them.
    useEffect(() => { setActiveArtifactId(null); setSaveSource(null); setPageError(''); }, [state.conversationId]);

    // ===== Reading position =====
    const updatePinned = useCallback(() => {
        const element = threadRef.current;
        if (!element) return;
        const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 96;
        pinnedRef.current = atBottom;
        setShowJump(!atBottom);
    }, []);
    const scrollToLatest = useCallback((smooth) => {
        const element = threadRef.current;
        if (!element) return;
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        element.scrollTo({ top: element.scrollHeight, behavior: smooth && !reduced ? 'smooth' : 'auto' });
        pinnedRef.current = true;
        setShowJump(false);
    }, []);
    const pendingMessage = useMemo(() => state.pendingTurn
        ? { id: 'pending-turn', role: 'user', content: state.pendingTurn.text, attachments: state.pendingTurn.attachments, status: state.pendingTurn.status }
        : null, [state.pendingTurn?.text, state.pendingTurn?.attachments, state.pendingTurn?.status]);
    useLayoutEffect(() => { pinnedRef.current = true; setShowJump(false); }, [state.conversationId]);
    // New content follows only while the reader is already at the latest message.
    useLayoutEffect(() => {
        const element = threadRef.current;
        if (element && pinnedRef.current) element.scrollTop = element.scrollHeight;
    }, [state.messages, pendingMessage, state.operation?.status]);

    // ===== Composer =====
    useLayoutEffect(() => {
        const element = inputRef.current;
        if (!element) return;
        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
    }, [state.draft]);

    const handleSend = () => {
        pinnedRef.current = true;
        setPageError('');
        actions.send().catch(() => {});
        inputRef.current?.focus();
    };
    const handleComposerKeyDown = (event) => {
        if (event.key === 'Enter' && !event.shiftKey) { pinnedRef.current = true; setPageError(''); }
        actions.handleComposerKeyDown(event);
    };
    const handleStop = () => { actions.stop().catch(() => {}); };
    const handleReconnect = () => { pinnedRef.current = true; setPageError(''); actions.retry(null).catch(() => {}); };
    const handleRetry = useCallback((id) => {
        pinnedRef.current = true;
        setPageError('');
        actions.retry(id).catch((error) => setPageError(error.message));
    }, [actions]);
    const handleContinue = useCallback((id) => {
        pinnedRef.current = true;
        setPageError('');
        actions.continue(id).catch((error) => setPageError(error.message));
    }, [actions]);
    const appendTranscript = useCallback((text) => {
        const value = String(text || '').trim();
        if (!value) return;
        const current = stateRef.current.draft;
        actions.setDraft(current.trim() ? `${current.replace(/\s+$/, '')} ${value}` : value);
        requestAnimationFrame(() => inputRef.current?.focus());
    }, [actions]);
    const handleFileSelect = (event) => {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        if (files.length) actions.uploadFiles(files).catch(() => {});
    };

    // ===== Artifacts =====
    const conversationIdRef = useRef(state.conversationId);
    conversationIdRef.current = state.conversationId;
    const handleSaveMessage = useCallback((message) => {
        if (!conversationIdRef.current) return;
        setSaveSource({ content: rebrandText(String(message.content || '')), language: 'markdown', sourceMessageId: message.id });
    }, []);
    const handleSaveCode = useCallback(({ content, language, sourceMessageId }) => {
        if (!conversationIdRef.current) throw new Error(t('Kirim pesan pertama sebelum menyimpan artefak.'));
        setSaveSource({ content, language, sourceMessageId });
    }, [t]);
    const handleArtifactSaved = useCallback((artifact) => {
        setSaveSource(null);
        if (artifact?.id) setActiveArtifactId(artifact.id);
        setArtifactRefresh((value) => value + 1);
        setPanelTab('artifacts');
        setPanelOpen(true);
    }, []);
    const latestSaveable = useMemo(() => state.messages.findLast((message) => message.role === 'assistant'
        && ['completed', 'stopped', 'failed'].includes(message.status) && /^\d+$/.test(message.id) && String(message.content || '').trim()), [state.messages]);
    const requestSaveFromChat = useMemo(() => latestSaveable && state.conversationId ? () => handleSaveMessage(latestSaveable) : undefined,
        [latestSaveable, state.conversationId, handleSaveMessage]);
    const artifactsProps = useMemo(() => ({
        conversationId: state.conversationId, activeArtifactId, onSelectArtifact: setActiveArtifactId,
        onDirtyChange: setArtifactDirty, onRequestSaveFromMessage: requestSaveFromChat, discardRef: artifactDiscardRef, refreshKey: artifactRefresh,
    }), [state.conversationId, activeArtifactId, requestSaveFromChat, artifactRefresh]);

    // ===== Right panel =====
    const closePanel = useCallback(() => guard(PANEL_SCOPES, () => setPanelOpen(false)), [guard]);
    const togglePanel = () => { if (panelOpen) closePanel(); else setPanelOpen(true); };
    const attachmentHandlers = useMemo(() => ({ upload: actions.uploadFiles, remove: actions.removeAttachment, retry: actions.retryUpload }), [actions]);
    const workspaceId = state.workspace?.id;
    const saveNotes = useCallback((notes, version) => actions.saveWorkspace(workspaceId, notes, version), [actions, workspaceId]);

    // ===== Sidebar navigation (guarded) =====
    const expandSidebar = useCallback(() => setSidebarCollapsed(false), []);
    const navActions = useMemo(() => ({
        ...actions,
        selectConversation: (id) => {
            if (String(id) === conversationIdRef.current) { setShowSidebar(false); return actions.selectConversation(id); }
            return guard(CONVERSATION_SCOPES, () => {
                setShowSidebar(false);
                pinnedRef.current = true;
                return actions.selectConversation(id);
            });
        },
        newConversation: () => guard(CONVERSATION_SCOPES, () => {
            setShowSidebar(false);
            const result = actions.newConversation();
            requestAnimationFrame(() => inputRef.current?.focus());
            return result;
        }),
        setWorkspace: (id) => guard(ALL_SCOPES, () => actions.setWorkspace(id)),
        createWorkspace: async (name) => {
            const result = await guard(ALL_SCOPES, () => actions.createWorkspace(name));
            if (result === GUARD_CANCELLED) throw new Error(t('Simpan atau tinggalkan perubahan yang belum disimpan sebelum berpindah workspace.'));
            return result;
        },
    }), [actions, guard, t]);
    const navState = useMemo(() => ({
        workspaces: state.workspaces, workspaceId: state.workspaceId, conversations: state.conversations, nextCursor: state.nextCursor,
        search: state.search, conversationId: state.conversationId,
        loading: { history: state.loading.history, workspaces: state.loading.workspaces, creating: state.loading.creating },
        errors: { history: state.errors.history, workspace: state.errors.workspace },
    }), [state.workspaces, state.workspaceId, state.conversations, state.nextCursor, state.search, state.conversationId,
        state.loading.history, state.loading.workspaces, state.loading.creating, state.errors.history, state.errors.workspace]);

    // Current model category for theming
    const currentModel = selectedChatModel;
    const currentCategory = currentModel?.category || 'chat';
    const catCfg = CATEGORY_CONFIG[currentCategory] || CATEGORY_CONFIG.chat;

    // Model count per category
    const modelCounts = useMemo(() => {
        const counts = {};
        models.forEach(m => {
            counts[m.category] = (counts[m.category] || 0) + 1;
        });
        return counts;
    }, [models]);

    // Mode-card authorization mirrors real route gates in app.jsx:
    // /generate-image has no permission prop and DashboardLayout exposes it to every
    // authenticated user by default — same default-enabled pattern as hasChat
    // (isAdmin || perms.x !== false); an explicit false still hides it.
    // /video requires the video_generator grant (admin bypasses).
    const isAdmin = user?.role === 'admin';
    const perms = user?.permissions || {};
    const canImage = isAdmin || perms.image_generator !== false;
    const canVideo = isAdmin || perms.video_generator === true;

    // Prompt suggestions — chat-domain prompts; they prefill the composer for the
    // currently selected model (media generation lives in the dedicated studios).
    const suggestions = [
        { icon: Icon.spark, text: t('Tulis prompt kreatif untuk kampanye produk kopi') },
        { icon: Icon.message, text: t('Rancang rencana konten media sosial seminggu') },
        { icon: Icon.chat, text: t('Jelaskan konsep API REST dengan analogi sederhana') },
        { icon: Icon.arrow, text: t('Buat draf email follow-up untuk calon klien') },
    ];

    const applySuggestion = (text) => {
        actions.setDraft(text);
        setTimeout(() => inputRef.current?.focus(), 50);
    };

    const pickChatMode = () => {
        const firstModel = chatModels[0];
        if (firstModel) handleSelectModel(firstModel.id);
        inputRef.current?.focus();
    };

    // ===== Derived composer / operation state =====
    const operation = state.operation;
    const uncertain = operation?.status === 'uncertain';
    const capabilities = state.capabilities;
    const capabilityInput = capabilities?.input;
    const fileTool = capabilities?.tools?.file_analysis;
    const capabilitiesReady = Boolean(capabilityInput?.text);
    const attachReason = state.loading.capabilities ? t('Memuat kapabilitas model…')
        : !capabilities ? (t(state.errors.capabilities) || t('Kapabilitas model belum tersedia.'))
            : !capabilityInput?.max_files || fileTool?.available === false ? (t(fileTool?.reason) || t('Lampiran tidak tersedia untuk model ini.')) : '';
    const canAttach = !attachReason;
    const maxFiles = Math.min(capabilityInput?.max_files || 0, state.uploadPolicy?.max_files ?? capabilityInput?.max_files ?? 0);
    const attachLimits = canAttach ? (locale === 'en'
        ? `Up to ${maxFiles} files · ${sizeLabel(capabilityInput.max_file_bytes)} per file`
        : `Maksimal ${maxFiles} file · ${sizeLabel(capabilityInput.max_file_bytes)} per file`) : attachReason;
    const acceptList = [...(capabilityInput?.accepted_mimes || []), ...(capabilityInput?.accepted_extensions || []).map((extension) => `.${String(extension).replace(/^\./, '')}`)].join(',') || undefined;
    const uploading = state.attachments.some((file) => file.status === 'uploading' || file.status === 'processing');
    const blockedFiles = state.attachments.some((file) => file.status !== 'ready');
    const readyFiles = state.attachments.filter((file) => file.status === 'ready').length;
    const sendReason = !canSendToModel ? t('Pilih model chat yang tersedia.')
        : !capabilitiesReady ? (state.loading.capabilities ? t('Memuat kapabilitas model…') : (t(state.errors.capabilities) || t('Kapabilitas model belum tersedia.')))
            : uncertain ? t('Sambungkan ulang atau abaikan permintaan sebelumnya terlebih dahulu.')
                : state.loading.conversation ? t('Memuat percakapan…')
                    : uploading ? t('Tunggu upload selesai.')
                        : blockedFiles ? t('Hapus lampiran yang gagal atau tidak tersedia.')
                            : (!state.draft.trim() && !readyFiles) ? t('Tulis pesan atau lampirkan file.') : '';
    const canSend = !sendReason && !state.isStreaming && !state.loading.creating;
    const actReason = state.isStreaming ? t('Tunggu jawaban yang berjalan selesai atau hentikan terlebih dahulu.')
        : uncertain ? t('Sambungkan ulang atau abaikan permintaan sebelumnya terlebih dahulu.')
            : !canSendToModel || !capabilitiesReady ? t('Pilih model chat yang tersedia dan tunggu kapabilitasnya dimuat.')
                : state.loading.conversation ? t('Memuat percakapan…') : '';
    const canAct = !actReason;
    const showSubmitting = operation?.status === 'sending' && operation.assistant_message_id == null;
    const showConversationError = Boolean(state.conversationId && state.errors.conversation);
    const showHero = !state.messages.length && !pendingMessage && !showSubmitting && !state.loading.conversation && !showConversationError;
    // Layout changes (context panel, viewport, composer height) move content without a scroll event:
    // a reader at the latest message stays there, and the jump control follows the real position.
    useEffect(() => {
        const element = threadRef.current;
        if (!element || typeof ResizeObserver === 'undefined') return undefined;
        const observer = new ResizeObserver(() => {
            if (pinnedRef.current) element.scrollTop = element.scrollHeight;
            updatePinned();
        });
        observer.observe(element);
        if (element.firstElementChild) observer.observe(element.firstElementChild);
        return () => observer.disconnect();
    }, [updatePinned, showHero]);
    const bannerError = state.errors.stream || pageError;
    // A known operation without a live stream is followed by polling; only a stopped poll offers a manual check.
    const pollFailed = Boolean(state.isStreaming && operation?.id && state.errors.stream && !state.polling);
    const operationMessageId = operation?.assistant_message_id == null ? null : String(operation.assistant_message_id);
    const providerState = capabilities?.provider_status?.state;
    const statusBusy = modelsLoading || state.loading.capabilities;
    const statusFailed = Boolean(modelsError || !chatModels.length || state.errors.capabilities);
    const statusLabel = statusBusy ? t('Memuat…') : statusFailed ? t('Coba lagi')
        : providerState === 'configured' ? t('Terkonfigurasi') : providerState === 'unverified' ? t('Belum diverifikasi')
            : providerState === 'unavailable' ? t('Tidak tersedia') : t('Status belum diketahui');
    const railCollapsed = sidebarCollapsed && isDesktop;
    const leaveDescription = [
        anyDirty ? t('Perubahan yang belum disimpan akan hilang.') : '',
        state.activeStreams ? t('Jawaban yang sedang berjalan akan dihentikan; teks yang sudah tersimpan tetap ada.') : '',
    ].filter(Boolean).join(' ');
    const dismissBanner = () => { actions.dismissError('stream'); setPageError(''); };

    const hasFiles = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');
    const handleDragOver = (event) => {
        if (!hasFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = canAttach ? 'copy' : 'none';
        if (!isDragging) setIsDragging(true);
    };
    const handleDragLeave = (event) => { if (!event.currentTarget.contains(event.relatedTarget)) setIsDragging(false); };
    const handleDrop = (event) => {
        if (!hasFiles(event)) return;
        setIsDragging(false);
        event.preventDefault();
        if (canAttach) actions.handleComposerDrop(event);
        else setPageError(attachReason);
    };

    const composerPlaceholder = state.attachments.length > 0
        ? t('Tambahkan pesan...')
        : t('Jelaskan ide Anda...');

    return (
        <div className={`cw-root ${isDark ? 'cw-dark' : 'cw-light'}${panelOpen && !panelIsDrawer ? ' cw-panel-docked' : ''}`}>
            <div className="cw-global-announcement">
                <AnnouncementRibbon surface="dashboard" />
            </div>
            <div className="cw-workspace-shell">
            {/* Mobile sidebar overlay */}
            {showSidebar && (
                <div className="cw-overlay lg:!hidden" onClick={() => setShowSidebar(false)} aria-hidden="true" />
            )}

            {/* ===== History Rail (Studio: searchable / collapsible) ===== */}
            <aside className={[
                'cw-rail',
                railCollapsed ? 'cw-rail-collapsed' : '',
                showSidebar ? 'cw-rail-open' : '',
            ].join(' ')} aria-label={t('Percakapan')}>
                {/* Rail header */}
                <div className="cw-rail-head">
                    {!railCollapsed && (
                        <Link to={localizedPath('/dashboard')} className="cw-brand" aria-label="XSuper.ai">
                            <UltrLockup height={26} />
                        </Link>
                    )}
                    <button
                        onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
                        className="cw-icon-btn cw-rail-collapse"
                        aria-label={railCollapsed ? t('Perluas sidebar') : t('Ciutkan sidebar')}
                        title={railCollapsed ? t('Perluas sidebar') : t('Ciutkan sidebar')}
                    >
                        <span className={`cw-collapse-icon ${railCollapsed ? 'cw-collapse-flip' : ''}`}>{Icon.collapse}</span>
                    </button>
                    <button
                        onClick={() => setShowSidebar(false)}
                        className="cw-icon-btn cw-rail-close"
                        aria-label={t('Tutup sidebar')}
                    >
                        {Icon.close}
                    </button>
                </div>

                {/* Workspaces, server search, pinned/recent history */}
                <MemoNavigation state={navState} actions={navActions} collapsed={railCollapsed} onCollapse={expandSidebar} modelLabel={modelLabel} />

                {/* Rail footer — account & controls */}
                <div className="cw-rail-foot">
                    {!railCollapsed && (
                        <div className="cw-rail-stats">
                            {/* Chat models only: the rail belongs to the chat workspace, like the model picker it summarizes. */}
                            <span><strong>{chatModels.length}</strong> {countNoun(chatModels.length, locale, 'model chat', 'chat model', 'chat models')}</span>
                            <span className="cw-rail-stats-dot">·</span>
                            <span><strong>{state.conversations.length}{state.nextCursor ? '+' : ''}</strong> {countNoun(state.nextCursor ? 2 : state.conversations.length, locale, 'percakapan', 'conversation', 'conversations')}</span>
                        </div>
                    )}
                    <div className={`cw-rail-actions ${railCollapsed ? 'cw-rail-actions-col' : ''}`}>
                        <Link to={localizedPath('/dashboard')} className="cw-rail-link" title={t('Kembali ke Dashboard')}>
                            {Icon.grid}
                            {!railCollapsed && <span>{t('Dashboard')}</span>}
                        </Link>
                        <button onClick={toggleTheme} className="cw-rail-link" title={isDark ? t('Mode Terang') : t('Mode Gelap')}>
                            {isDark ? Icon.sun : Icon.moon}
                            {!railCollapsed && <span>{isDark ? t('Terang') : t('Gelap')}</span>}
                        </button>
                    </div>
                    {!railCollapsed && (
                        <div className="cw-account">
                            <span className="cw-account-avatar" aria-hidden="true">{user?.name?.[0]?.toUpperCase() || 'U'}</span>
                            <div className="cw-account-text">
                                <div className="cw-account-name">{user?.name || 'User'}</div>
                                <div className="cw-account-role">{user?.role || 'member'}</div>
                            </div>
                        </div>
                    )}
                </div>
            </aside>

            {/* ===== Main column ===== */}
            <div className="cw-main" onDragOver={handleDragOver} onDragLeave={handleDragLeave} onDrop={handleDrop}>
                {/* Topbar follows the global viewport-wide announcement. */}

                {/* Topbar: breadcrumb + model trigger + status */}
                <header className="cw-topbar">
                    <div className="cw-topbar-left">
                        <button
                            onClick={() => setShowSidebar(!showSidebar)}
                            className="cw-icon-btn cw-menu-btn"
                            aria-label={t('Buka riwayat')}
                        >
                            {Icon.menu}
                        </button>
                        <nav className="cw-breadcrumb" aria-label="Breadcrumb">
                            <Link to={localizedPath('/dashboard')} className="cw-crumb">{t('Dashboard')}</Link>
                            <span className="cw-crumb-sep" aria-hidden="true">/</span>
                            <span className="cw-crumb cw-crumb-current">{t('Ruangan Chat')}</span>
                        </nav>
                        <ModelSelector
                            models={chatModels}
                            selectedModel={selectedModel}
                            onSelect={handleSelectModel}
                            disabled={state.modelLocked || modelsLoading || !chatModels.length}
                            loading={modelsLoading}
                            lockReason={state.modelLocked ? t('Model terkunci sampai jawaban selesai, dihentikan, atau dipulihkan.') : ''}
                            t={t}
                        />
                    </div>
                    <div className="cw-topbar-right">
                        <span className="cw-cat-pill" style={{ '--pill-accent': catCfg.accent }}>
                            {catCfg.icon}
                            {catCfg.label}
                        </span>
                        <button type="button" className={`cw-status${statusBusy || statusFailed || providerState !== 'configured' ? ' cw-status-unavailable' : ''}`} onClick={refreshModelStatus} disabled={statusBusy || state.isStreaming} aria-label={`${t('Status penyedia')}: ${statusLabel}. ${t('Perbarui model')}`} title={t(capabilities?.provider_status?.reason) || t('Perbarui model')}>
                            {!statusBusy && !statusFailed && providerState === 'configured' && <span className="cw-status-dot" aria-hidden="true" />}
                            <span className="cw-status-text">{statusLabel}</span>
                        </button>
                        <button type="button" className={`cw-icon-btn cw-panel-toggle${panelOpen ? ' cw-panel-toggle-open' : ''}`} onClick={togglePanel} aria-expanded={panelOpen} aria-label={panelOpen ? t('Tutup panel konteks') : t('Buka panel konteks')} title={panelOpen ? t('Tutup panel konteks') : t('Buka panel konteks')}>
                            {Icon.panel}
                        </button>
                    </div>
                </header>

                {(modelsLoading || modelsError || !chatModels.length) ? (
                    <div className="cw-model-feedback" role={modelsError ? 'alert' : 'status'}>
                        <span>{modelsLoading ? t('Memuat model chat…') : modelsError ? t('Daftar model tidak dapat diperbarui. Coba muat ulang; draf Anda tetap tersimpan.') : t('Tidak ada model chat yang tersedia untuk akun Anda. Model gambar dan video ada di ruang masing-masing.')}</span>
                        {!modelsLoading && <button type="button" onClick={loadModels}>{t('Coba lagi')}</button>}
                    </div>
                ) : state.errors.capabilities ? (
                    <div className="cw-model-feedback" role="alert">
                        <span>{t('Kapabilitas model tidak dapat dimuat:')} {state.errors.capabilities}</span>
                        <button type="button" onClick={() => actions.refreshCapabilities().catch(() => {})}>{t('Coba lagi')}</button>
                    </div>
                ) : null}

                {/* ===== Message thread / empty hero ===== */}
                <div className="cw-thread scrollbar-thin" ref={threadRef} onScroll={updatePinned}>
                    {showHero ? (
                        <div className="cw-hero">
                            <div className="cw-hero-sigil-wrap" aria-hidden="true">
                                <span className="cw-hero-glow" />
                                <Sigil className="cw-hero-sigil" />
                            </div>
                            <h1 className="cw-hero-title">
                                {t('Halo')}{user?.name ? `, ${user.name.split(' ')[0]}` : ''}.<br />
                                <span className="cw-hero-accent">{t('Apa yang ingin Anda buat hari ini?')}</span>
                            </h1>
                            <p className="cw-hero-sub">{t('Mulai dari prompt atau pilih mode di bawah.')}</p>

                            <ModeCards
                                modelCounts={modelCounts}
                                canImage={canImage}
                                canVideo={canVideo}
                                onPickChat={pickChatMode}
                                onGoImage={() => navigate(localizedPath('/generate-image'))}
                                onGoVideo={() => navigate(localizedPath('/video'))}
                                t={t}
                            />

                            <div className="cw-suggest-grid">
                                {suggestions.map((s, i) => (
                                    <button key={i} onClick={() => applySuggestion(s.text)} className="cw-suggest">
                                        <span className="cw-suggest-icon">{s.icon}</span>
                                        <span className="cw-suggest-text">{s.text}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div className="cw-thread-inner">
                            {state.loading.conversation && !state.messages.length && (
                                <p className="cw-thread-status" role="status">{t('Memuat percakapan…')}</p>
                            )}
                            {showConversationError && (
                                <div className="cw-thread-alert" role="alert">
                                    <p>{t(state.errors.conversation)}</p>
                                    <div className="cw-thread-alert-actions">
                                        <button type="button" onClick={() => actions.selectConversation(state.conversationId).catch(() => {})} disabled={state.loading.conversation}>{t('Coba lagi')}</button>
                                        <button type="button" onClick={() => navActions.newConversation()}>{t('Chat Baru')}</button>
                                    </div>
                                </div>
                            )}
                            {state.messages.map((message, index) => (
                                <ChatMessage
                                    key={message.id}
                                    message={message}
                                    userName={user?.name}
                                    isDark={isDark}
                                    categoryColor={currentCategory}
                                    t={t}
                                    modelName={modelLabel(message.model)}
                                    startedAt={message.id === operationMessageId ? (operation?.started_at || message.created_at) : message.created_at}
                                    error={message.id === operationMessageId ? operation?.error || undefined : undefined}
                                    conversationReady={Boolean(state.conversationId)}
                                    canAct={canAct}
                                    actReason={actReason}
                                    // Only the latest answer can be retried or continued; an older one would reorder the saved context.
                                    onRetry={index === state.messages.length - 1 ? handleRetry : undefined}
                                    onContinue={index === state.messages.length - 1 ? handleContinue : undefined}
                                    onSave={handleSaveMessage}
                                    onSaveCode={handleSaveCode}
                                />
                            ))}
                            {pendingMessage && (
                                <ChatMessage
                                    key={pendingMessage.id}
                                    message={pendingMessage}
                                    pending
                                    userName={user?.name}
                                    isDark={isDark}
                                    categoryColor={currentCategory}
                                    t={t}
                                />
                            )}
                            {showSubmitting && (
                                <div className="cw-msg cw-msg-assistant">
                                    <div className="cw-avatar cw-avatar-ai" style={{ background: `linear-gradient(135deg, ${catCfg.accent}, var(--red-600))` }}><XGlyph className="cw-sigil-icon" /></div>
                                    <div className="cw-msg-body"><div className="cw-msg-meta"><span className="cw-msg-author">XSuper.ai</span></div><GenerationProgress kind="chat" stage="submitting" model={modelLabel(operation.model)} startedAt={operation.started_at} compact /></div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* ===== Composer ===== */}
                <div className="cw-composer-wrap">
                    {showJump && !showHero && (
                        <button type="button" className="cw-jump-latest" onClick={() => scrollToLatest(true)}>
                            {Icon.arrowDown}
                            <span>{t('Ke pesan terbaru')}</span>
                        </button>
                    )}
                    {uncertain && (
                        <div className="cw-op-banner" role="alert">
                            <span className="cw-op-banner-icon">{Icon.alert}</span>
                            <div className="cw-op-banner-text">
                                <strong>{t('Status permintaan belum pasti')}</strong>
                                <span>{t('Server belum mengonfirmasi permintaan terakhir. Sambungkan ulang untuk memeriksa hasilnya dengan kunci permintaan yang sama; tidak ada pesan baru yang dikirim.')}</span>
                                {state.errors.stream && <span className="cw-op-banner-detail">{t(state.errors.stream)}</span>}
                            </div>
                            <div className="cw-op-banner-actions">
                                <button type="button" onClick={handleReconnect}>{t('Sambungkan ulang')}</button>
                                <button type="button" onClick={actions.discardUncertain}>{t('Abaikan')}</button>
                            </div>
                        </div>
                    )}
                    {!uncertain && bannerError && (
                        <div className="cw-op-banner cw-op-banner-error" role="alert">
                            <span className="cw-op-banner-icon">{Icon.alert}</span>
                            <div className="cw-op-banner-text">
                                <span>{t(bannerError)}</span>
                                {state.polling && <span className="cw-op-banner-detail">{t('Memeriksa status tersimpan di server…')}</span>}
                            </div>
                            <div className="cw-op-banner-actions">
                                {pollFailed && <button type="button" onClick={() => actions.refreshContext().catch(() => {})}>{t('Periksa status')}</button>}
                                <button type="button" className="cw-op-banner-dismiss" onClick={dismissBanner} aria-label={t('Tutup pesan')} title={t('Tutup pesan')}>{Icon.close}</button>
                            </div>
                        </div>
                    )}
                    <div className={`cw-composer ${isDragging ? 'cw-composer-drag' : ''} ${state.isStreaming ? 'cw-composer-busy' : ''}`}>
                        {/* Attachment chips */}
                        {state.attachments.length > 0 && (
                            <div className="cw-chips">
                                {state.attachments.map((file) => (
                                    <AttachmentChip
                                        key={file.local_id || file.id}
                                        file={file}
                                        t={t}
                                        onRemove={() => actions.removeAttachment(file.id ?? file.local_id).catch(() => {})}
                                        onRetry={() => actions.retryUpload(file.local_id).catch(() => {})}
                                    />
                                ))}
                            </div>
                        )}
                        {/* Files belong to the conversation context: every send includes each ready file until it is removed. */}
                        {readyFiles > 0 && (
                            <p className="cw-chips-note">{t('File siap ikut dikirim bersama setiap pesan di percakapan ini. Hapus file yang tidak diperlukan lagi.')}</p>
                        )}
                        {state.errors.upload && (
                            <div className="cw-composer-alert" role="alert">
                                <span>{t(state.errors.upload)}</span>
                                <button type="button" onClick={() => actions.dismissError('upload')} aria-label={t('Tutup pesan')} title={t('Tutup pesan')}>{Icon.close}</button>
                            </div>
                        )}

                        {/* Input row */}
                        <div className="cw-input-row">
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                disabled={!canAttach}
                                className="cw-icon-btn cw-attach-btn"
                                title={canAttach ? t('Lampirkan file') : attachReason}
                                aria-label={t('Lampirkan file')}
                                aria-describedby="cw-attach-reason"
                            >
                                {Icon.attach}
                            </button>
                            <span id="cw-attach-reason" className="cw-sr-only">{canAttach ? attachLimits : attachReason}</span>
                            <textarea
                                ref={inputRef}
                                value={state.draft}
                                onChange={(event) => actions.setDraft(event.target.value)}
                                onKeyDown={handleComposerKeyDown}
                                onPaste={actions.handleComposerPaste}
                                placeholder={composerPlaceholder}
                                rows={1}
                                dir="auto"
                                className="cw-textarea"
                                aria-label={composerPlaceholder}
                            />
                            <VoiceInput onTranscript={appendTranscript} />
                            {state.isStreaming ? (
                                <button
                                    type="button"
                                    onClick={handleStop}
                                    disabled={Boolean(operation?.stopping)}
                                    className="cw-send cw-stop"
                                    title={operation?.stopping ? t('Menghentikan…') : (capabilities?.streaming?.stop_reason || t('Berhenti'))}
                                    aria-label={t('Berhenti')}
                                >
                                    {Icon.stop}
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={handleSend}
                                    disabled={!canSend}
                                    className="cw-send"
                                    title={canSend ? t('Kirim') : sendReason}
                                    aria-label={t('Kirim')}
                                >
                                    {Icon.send}
                                </button>
                            )}
                        </div>

                        <input ref={fileInputRef} type="file" multiple accept={acceptList} onChange={handleFileSelect} className="hidden" />
                    </div>
                    <p className="cw-hint">
                        <kbd>Enter</kbd> {t('untuk kirim')}, <kbd>Shift+Enter</kbd> {t('untuk baris baru')}. {t('Anda juga bisa menempel atau menjatuhkan file.')}
                    </p>
                    {state.errors.persistence && <p className="cw-composer-note" role="status">{state.errors.persistence}</p>}
                    <p className="cw-disclaimer">{t('XSuper.ai dapat membuat kesalahan. Periksa informasi penting.')}</p>
                </div>

                {/* Drag overlay */}
                {isDragging && (
                    <div className="cw-drag-overlay" aria-hidden="true">
                        <div className="cw-drag-card">
                            <span className="cw-drag-icon">{Icon.attach}</span>
                            <div className="cw-drag-title">{canAttach ? t('Tarik & lepas file ke sini') : t('Lampiran tidak tersedia')}</div>
                            <div className="cw-drag-sub">{attachLimits}</div>
                        </div>
                    </div>
                )}
            </div>

            {/* ===== Context / Tools / Artifacts (closed by default) ===== */}
            {panelOpen && (
                <MemoContextPanel
                    tab={panelTab}
                    onTabChange={setPanelTab}
                    conversation={state.conversation}
                    workspace={state.workspace}
                    capabilities={capabilities}
                    attachments={state.attachments}
                    tools={state.tools}
                    onToolsChange={actions.setTools}
                    onAttachmentsChange={attachmentHandlers}
                    onWorkspaceChange={saveNotes}
                    onWorkspaceReload={actions.refreshWorkspaces}
                    onDirtyChange={setNotesDirty}
                    onClose={closePanel}
                    artifactsProps={artifactsProps}
                />
            )}
            </div>

            {saveSource && state.conversationId && (
                <SaveArtifactDialog
                    conversationId={state.conversationId}
                    source={saveSource}
                    onSaved={handleArtifactSaved}
                    onClose={() => setSaveSource(null)}
                    onDirtyChange={setSaveDirty}
                />
            )}
            {discardRequest && (
                <MediaActionDialog
                    title={t('Tinggalkan perubahan yang belum disimpan?')}
                    description={`${t('Belum disimpan:')} ${discardRequest.scopes.map((scope) => ({ notes: t('catatan workspace'), artifact: t('editan artefak'), save: t('artefak baru di dialog simpan') })[scope]).join(', ')}. ${t('Simpan terlebih dahulu jika ingin menyimpannya.')}`}
                    closeLabel={t('Kembali')}
                    confirmLabel={t('Tinggalkan tanpa menyimpan')}
                    onClose={() => discardRequest.resolve(GUARD_CANCELLED)}
                    onConfirm={discardRequest.proceed}
                />
            )}
            {inDataRouter && <RouteLeaveGuard when={anyDirty || state.activeStreams > 0} description={leaveDescription} onDiscard={discardArtifactDraft} t={t} />}
        </div>
    );
}
