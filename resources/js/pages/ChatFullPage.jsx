import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { useNavigate, Link, useLocation } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';
import { useLocale } from '../contexts/LocaleContext';
import AnnouncementRibbon from '../components/AnnouncementRibbon';
import { UltrLockup } from '../components/UltrLogo';
import { apiRequest } from '../lib/api';
import GenerationProgress from '../components/GenerationProgress';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
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
    chevron: <svg {...stroke} viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></svg>,
    plus: <svg {...stroke} viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" /></svg>,
    send: <svg {...stroke} viewBox="0 0 24 24"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z" /></svg>,
    stop: <svg {...stroke} viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2" /></svg>,
    trash: <svg {...stroke} viewBox="0 0 24 24"><path d="M4 6h16M9 3h6m-9 3 1 15h10l1-15M10 10v7m4-7v7" /></svg>,
    attach: <svg {...stroke} viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" /></svg>,
    file: <svg {...stroke} viewBox="0 0 24 24"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10Zm0 0v7h7M8 14h8m-8 3h5" /></svg>,
    copy: <svg {...stroke} viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M15 8V4H4v11h4" /></svg>,
    check: <svg {...stroke} viewBox="0 0 24 24"><path d="m4 12 5 5L20 7" /></svg>,
    sun: <svg {...stroke} viewBox="0 0 24 24"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" /></svg>,
    moon: <svg {...stroke} viewBox="0 0 24 24"><path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z" /></svg>,
    grid: <svg {...stroke} viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg>,
    spark: <svg {...stroke} viewBox="0 0 24 24"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5ZM20 2v4m-2-2h4" /></svg>,
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

const TIER_CONFIG = {
    Original: { label: 'Original' },
    Authentic: { label: 'Authentic' },
    Codex: { label: 'Codex' },
    Wavespeed: { label: 'Wavespeed' },
    YepAPI: { label: 'YepAPI' },
    Canva: { label: 'Canva' },
};

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

// ============================================
// Markdown renderer (GFM, no raw HTML)
// ============================================
const markdownComponents = {
    a: ({ node: _node, ...props }) => <a {...props} target="_blank" rel="noopener noreferrer" />,
    pre: ({ node: _node, children }) => <>{children}</>,
    code: ({ node: _node, className, children, ...props }) => {
        const match = /language-([\w-]+)/.exec(className || '');
        const text = String(children);
        if (!match && !text.includes('\n')) return <code className="chat-inline-code" {...props}>{children}</code>;
        return (
            <pre className="chat-code-block">
                <div className="chat-code-header">{match ? match[1] : 'code'}</div>
                <code className={className}>{text.replace(/\n$/, '')}</code>
            </pre>
        );
    },
};

function MessageMarkdown({ text }) {
    return <ReactMarkdown remarkPlugins={[remarkGfm]} components={markdownComponents}>{rebrandText(text)}</ReactMarkdown>;
}

// Typing pace: characters per second. The reveal follows the model's actual
// stream rate (with a small lead so the caret never sits idle), clamped to a
// range that still reads as typing; reasoning-tier models start slower.
const TYPING = { minCps: 28, maxCps: 480, lead: 1.15, drainSeconds: 1.1 };
function baseTypingSpeed(model) {
    const id = String(model?.id || '').toLowerCase();
    const reasoning = /(^|[^a-z])o[134](?:-|$)|gpt-5|reason|think|deepseek-r|opus/.test(id) || model?.tier === 'Authentic';
    return reasoning ? 55 : 90;
}

const CONTINUE_PROMPT = 'Lanjutkan jawaban Anda tepat dari kata terakhir yang terpotong, tanpa mengulang bagian yang sudah ditulis dan tanpa pengantar.';

// ============================================
// CSRF Token
// ============================================
function getCsrfToken() {
    try {
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        if (match) return decodeURIComponent(match[1]);
    } catch {}
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

// ============================================
// Chat Message Component
// ============================================
function ChatMessage({ message, userName, isDark, categoryColor, t, onContinue, canContinue }) {
    const isUser = message.role === 'user';
    const catCfg = CATEGORY_CONFIG[categoryColor] || CATEGORY_CONFIG.chat;
    const [copied, setCopied] = useState(false);

    const displayText = typeof message.content === 'string' ? message.content : (message._display || '');
    const imgs = message._imgs || [];
    const docs = message._docs || [];

    const handleCopy = () => {
        const text = displayText || '';
        navigator.clipboard.writeText(text).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }).catch(() => {});
    };

    return (
        <div className={`cw-msg group ${isUser ? 'cw-msg-user' : 'cw-msg-assistant'}`}>
            <div className={`cw-avatar ${isUser ? 'cw-avatar-user' : 'cw-avatar-ai'}`} style={!isUser ? { background: `linear-gradient(135deg, ${catCfg.accent}, var(--red-600))` } : undefined}>
                {isUser ? (userName?.[0]?.toUpperCase() || 'U') : <XGlyph className="cw-sigil-icon" />}
            </div>
            <div className="cw-msg-body">
                <div className="cw-msg-meta">
                    <span className="cw-msg-author">{isUser ? (userName || 'You') : 'XSuper.ai'}</span>
                    {!isUser && displayText && (
                        <button onClick={handleCopy} className={`cw-copy-btn ${copied ? 'cw-copy-done' : ''}`} aria-label={t('Salin')} title={t('Salin')}>
                            {copied ? Icon.check : Icon.copy}
                            <span>{copied ? t('Disalin') : t('Salin')}</span>
                        </button>
                    )}
                </div>

                {imgs.length > 0 && (
                    <div className="cw-attach-row">
                        {imgs.map(a => (
                            <span key={a.id} className="cw-attach-chip">
                                {Icon.image}{a.name}
                            </span>
                        ))}
                    </div>
                )}
                {docs.length > 0 && (
                    <div className="cw-attach-row">
                        {docs.map(a => (
                            <span key={a.id} className="cw-attach-chip">
                                {Icon.file}{a.name}
                            </span>
                        ))}
                    </div>
                )}

                {(displayText || message._typing) && (
                    <div className={`chat-content cw-msg-content cw-markdown ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                        <MessageMarkdown text={displayText} />
                        {message._typing && <span className="cw-caret" aria-hidden="true" />}
                    </div>
                )}
                {message._truncated && !message._typing && (
                    <div className="cw-truncated" role="status">
                        <span>{t('Jawaban terhenti karena batas panjang model.')}</span>
                        {onContinue && <button type="button" className="cw-continue-btn" onClick={onContinue} disabled={!canContinue}>{Icon.arrow}{t('Lanjutkan jawaban')}</button>}
                    </div>
                )}
            </div>
        </div>
    );
}


// ============================================
// Conversation Item
// ============================================
function ConversationItem({ conv, isActive, onClick, onDelete, t }) {
    return (
        <div onClick={onClick} className={`cw-conv ${isActive ? 'cw-conv-active' : ''}`} role="button" tabIndex={0}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } }}>
            <span className="cw-conv-icon">{Icon.message}</span>
            <div className="cw-conv-text">
                <div className="cw-conv-title">{conv.title || t('Percakapan tanpa judul')}</div>
                <div className="cw-conv-sub">{rebrandText(conv.model_name || conv.model || '')}</div>
            </div>
            <button
                onClick={(e) => { e.stopPropagation(); onDelete(); }}
                className="cw-conv-delete"
                aria-label={t('Hapus percakapan')}
                title={t('Hapus percakapan')}
            >
                {Icon.trash}
            </button>
        </div>
    );
}

// ============================================
// Model Selector Dropdown
// ============================================
function ModelSelector({ models, selectedModel, onSelect, disabled, loading, t }) {
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

    const groupedModels = useMemo(() => {
        const term = search.trim().toLowerCase();
        const groups = new Map();
        models.filter(model => !term || [model.name, model.id, model.tier].some(value => String(value || '').toLowerCase().includes(term))).forEach(model => {
            const tier = model.tier || 'Original';
            if (!groups.has(tier)) groups.set(tier, []);
            groups.get(tier).push(model);
        });
        return [...groups.entries()];
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
            >
                <span className="cw-model-swatch" style={{ background: 'linear-gradient(135deg, var(--red-500), var(--red-600))' }}>{Icon.chat}</span>
                <span className="cw-model-name">{rebrandText(currentModel?.name || currentModel?.id || t(loading ? 'Memuat model…' : 'Pilih model chat'))}</span>
                {currentModel?.tier && <span className="cw-model-tier">{TIER_CONFIG[currentModel.tier]?.label || currentModel.tier}</span>}
                <span className={`cw-chevron ${expanded ? 'cw-chevron-open' : ''}`}>{Icon.chevron}</span>
            </button>
            {expanded && (
                <>
                    <div className="cw-dropdown-overlay" onClick={close} aria-hidden="true" />
                    <div className="cw-dropdown" onKeyDown={onDropdownKeyDown}>
                        <div className="cw-dropdown-search">
                            <span className="cw-dropdown-search-icon">{Icon.search}</span>
                            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('Cari model...')} aria-label={t('Cari model...')} autoFocus />
                        </div>
                        <div id="cw-chat-model-list" className="cw-dropdown-list scrollbar-thin" role="listbox" aria-label={t('Model chat')} onWheel={(event) => event.stopPropagation()} onTouchMove={(event) => event.stopPropagation()}>
                            {groupedModels.length === 0 ? <div className="cw-dropdown-empty">{t('Tidak ada model ditemukan')}</div> : groupedModels.map(([tier, tierModels]) => (
                                <div key={tier} className="cw-tier-group" role="group" aria-label={tier}>
                                    <div className="cw-tier-label" aria-hidden="true">{TIER_CONFIG[tier]?.label || tier}<span className="cw-tier-count">{tierModels.length}</span></div>
                                    {tierModels.map(model => (
                                        <button key={model.id} type="button" role="option" aria-selected={model.id === selectedModel} onClick={() => { onSelect(model.id); setSearch(''); close(); }} className={`cw-model-option ${model.id === selectedModel ? 'cw-model-option-active' : ''}`}>
                                            <span className="cw-model-swatch cw-model-swatch-sm" style={{ background: 'linear-gradient(135deg, var(--red-500), var(--red-600))' }}>{Icon.chat}</span>
                                            <span className="cw-model-option-name">{rebrandText(model.name || model.id)}</span>
                                            {model.id === selectedModel && <span className="cw-check">{Icon.check}</span>}
                                        </button>
                                    ))}
                                </div>
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
function ModeCards({ modelCounts, canImage, canVideo, onPickChat, onGoImage, onGoVideo, t }) {
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
                    {c.count > 0 && <span className="cw-mode-count">{c.count} {t('Model').toLowerCase()}</span>}
                    <span className="cw-mode-arrow">{Icon.arrow}</span>
                </button>
            ))}
        </div>
    );
}

// ============================================
// Main Full Page Chat Component
// ============================================
export default function ChatFullPage() {
    const { user } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const { t, localizedPath } = useLocale();
    const navigate = useNavigate();
    const location = useLocation();
    const isDark = theme === 'dark';

    const [conversations, setConversations] = useState([]);
    const [currentConvId, setCurrentConvId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [models, setModels] = useState([]);
    const [modelsLoading, setModelsLoading] = useState(true);
    const [modelsError, setModelsError] = useState(null);
    const [selectedModel, setSelectedModel] = useState('');
    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [waitStartedAt, setWaitStartedAt] = useState(null);
    const [waitModel, setWaitModel] = useState('');
    const [showSidebar, setShowSidebar] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [historySearch, setHistorySearch] = useState('');
    const [attachments, setAttachments] = useState([]); // [{id, base64, name, size, type:'image'|'doc'}]
    const [isDragging, setIsDragging] = useState(false);
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);
    const fileInputRef = useRef(null);
    const abortRef = useRef(null);
    const modelRequestRef = useRef(null);
    const chatModels = useMemo(() => models.filter(model => model.category === 'chat'), [models]);
    const selectedChatModel = chatModels.find(model => model.id === selectedModel);
    const canSendToModel = Boolean(selectedChatModel && !modelsLoading);

    // Prefill prompt from template navigation
    useEffect(() => {
        if (location.state?.prefillPrompt) {
            setInput(location.state.prefillPrompt);
            window.history.replaceState({}, document.title);
            setTimeout(() => inputRef.current?.focus(), 100);
        }
    }, [location.state]);

    // Auto-scroll — only when there are messages (not on welcome screen)
    const scrollToBottom = useCallback(() => {
        if (messages.length > 0) {
            messagesEndRef.current?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
        }
    }, [messages.length]);

    useEffect(() => { scrollToBottom(); }, [messages, scrollToBottom]);

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

    useEffect(() => {
        loadModels();
        const onFocus = () => { if (document.visibilityState !== 'hidden' && !abortRef.current) loadModels(); };
        window.addEventListener('focus', onFocus);
        return () => {
            window.removeEventListener('focus', onFocus);
            modelRequestRef.current?.abort();
        };
    }, [loadModels]);

    // Load conversations
    const loadConversations = useCallback(async () => {
        try {
            const res = await fetch('/api/c/h', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setConversations(data.conversations || []);
            }
        } catch (err) {
            console.error('Failed to load history:', err);
        }
    }, []);

    useEffect(() => {
        loadConversations();
        newConversation();
        return () => { abortRef.current?.abort(); };
    }, []);

    // New conversation
    const newConversation = () => {
        abortRef.current?.abort();
        abortRef.current = null;
        setIsStreaming(false);
        const id = 'chat-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        setCurrentConvId(id);
        setMessages([]);
        setShowSidebar(false);
        inputRef.current?.focus();
    };

    // Load conversation
    const loadConversation = async (convId) => {
        abortRef.current?.abort();
        abortRef.current = null;
        setIsStreaming(false);
        setCurrentConvId(convId);
        setShowSidebar(false);
        try {
            const res = await fetch('/api/c/h/' + convId, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setMessages((data.messages || []).map(m => ({ role: m.role, content: m.content })));
            }
        } catch (err) {
            console.error('Failed to load conversation:', err);
        }
    };

    // Deep link: /chat?conversation=<id> (dashboard search, library, notifications)
    const requestedConversation = new URLSearchParams(location.search).get('conversation');
    useEffect(() => {
        if (!requestedConversation || requestedConversation === currentConvId) return;
        loadConversation(requestedConversation);
    }, [requestedConversation]);

    // Delete conversation
    const deleteConversation = async (convId) => {
        try {
            await fetch('/api/c/h/' + convId, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            });
            if (currentConvId === convId) newConversation();
            loadConversations();
        } catch (err) {
            console.error('Failed to delete:', err);
        }
    };

    // Build clean messages for API — only serializable primitives
    const buildApiMessages = (msgs) => {
        const result = [];
        const lastIdx = msgs.length - 1;
        for (let i = 0; i <= lastIdx; i++) {
            const m = msgs[i];
            if (!m.role || !m.content) continue;
            const role = String(m.role);

            if (typeof m.content === 'string') {
                if (m.content.trim()) result.push({ role, content: m.content });
            } else if (Array.isArray(m.content)) {
                if (i === lastIdx) {
                    const parts = m.content.map(p => {
                        if (p?.type === 'image_url') return { type: 'image_url', image_url: { url: String(p.image_url?.url || '') } };
                        return { type: 'text', text: String(p?.text || '') };
                    }).filter(p => p.type === 'image_url' || (p.text && p.text.trim()));
                    if (parts.length > 0) result.push({ role, content: parts });
                } else {
                    const textParts = m.content
                        .filter(p => p?.type === 'text')
                        .map(p => String(p?.text || ''))
                        .join('\n').trim();
                    if (textParts) result.push({ role, content: textParts });
                }
            } else {
                const s = String(m.content || '').trim();
                if (s) result.push({ role, content: s });
            }
        }
        return result;
    };

    // Stream chat with real AbortController. `options.continuation` extends the
    // assistant message at `options.targetIndex` instead of appending a new one.
    const streamChat = async (apiMessages, model, convId, options = {}) => {
        const safeMessages = apiMessages.map(m => {
            const content = m.content;
            if (Array.isArray(content)) {
                const safeParts = content.filter(p => p && typeof p === 'object' && p.type).map(p => {
                    if (p.type === 'text') return { type: 'text', text: String(p.text || '') };
                    if (p.type === 'image_url' && p.image_url?.url) return { type: 'image_url', image_url: { url: String(p.image_url.url) } };
                    return null;
                }).filter(Boolean);
                return { role: m.role, content: safeParts.length > 0 ? safeParts : '[content]' };
            }
            return { role: m.role, content: String(content || '') };
        });

        const controller = new AbortController();
        abortRef.current = controller;

        const body = JSON.stringify({
            model: String(model),
            messages: safeMessages,
            conversation_id: String(convId || ''),
            ...(options.continuation ? { continuation: true } : {}),
        });

        const res = await fetch('/api/c/s', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body,
            signal: controller.signal,
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({ error: res.statusText }));
            throw new Error(err.message || err.error || 'Chat gagal');
        }

        const reader = res.body?.getReader();
        if (!reader) throw new Error('No response body');

        const decoder = new TextDecoder();
        const prefix = options.continuation ? String(options.prefix || '') : '';
        let buffer = '';
        let fullText = '';
        let displayedLen = 0;
        let animFrame = null;
        let firstChunkAt = 0;
        let lastFrameAt = 0;
        let streamDone = false;
        let finishReason = null;
        let carry = 0;
        const baseCps = baseTypingSpeed(selectedChatModel);

        const targetIndex = options.continuation ? options.targetIndex : null;
        const patch = (updater) => setMessages(prev => {
            const updated = [...prev];
            const index = targetIndex ?? updated.length - 1;
            if (!updated[index] || updated[index].role !== 'assistant') return prev;
            updated[index] = updater(updated[index]);
            return updated;
        });
        if (!options.continuation) setMessages(prev => [...prev, { role: 'assistant', content: '', _typing: true }]);
        else patch(message => ({ ...message, _typing: true, _truncated: false }));

        // Characters per second: follow the measured stream rate with a small lead,
        // and drain whatever is left within ~1s once the provider has finished.
        const targetCps = () => {
            const elapsed = firstChunkAt ? (performance.now() - firstChunkAt) / 1000 : 0;
            const measured = elapsed > 0.25 ? (fullText.length / elapsed) * TYPING.lead : baseCps;
            let cps = Math.min(TYPING.maxCps, Math.max(TYPING.minCps, measured));
            if (streamDone) cps = Math.max(cps, (fullText.length - displayedLen) / TYPING.drainSeconds);
            return cps;
        };

        const revealText = (now) => {
            if (displayedLen >= fullText.length) {
                animFrame = null;
                if (streamDone) patch(message => ({ ...message, content: prefix + fullText, _typing: false, _truncated: finishReason === 'length' }));
                return;
            }
            const seconds = lastFrameAt ? Math.min(0.1, (now - lastFrameAt) / 1000) : 1 / 60;
            lastFrameAt = now;
            carry += seconds * targetCps();
            const step = Math.floor(carry);
            if (step > 0) {
                carry -= step;
                displayedLen = Math.min(displayedLen + step, fullText.length);
                patch(message => ({ ...message, content: prefix + fullText.slice(0, displayedLen), _typing: true }));
            }
            animFrame = requestAnimationFrame(revealText);
        };
        const startReveal = () => { if (!animFrame) { lastFrameAt = 0; animFrame = requestAnimationFrame(revealText); } };

        // Every character the model produced ends up rendered, even when the
        // connection ends without a [DONE] marker or the user stops the stream.
        const flush = (truncated = finishReason === 'length') => {
            streamDone = true;
            if (animFrame) { cancelAnimationFrame(animFrame); animFrame = null; }
            displayedLen = fullText.length;
            patch(message => ({ ...message, content: prefix + fullText, _typing: false, _truncated: truncated }));
        };
        const finish = () => {
            streamDone = true;
            if (displayedLen >= fullText.length) flush();
            else startReveal();
        };

        try {
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const data = line.slice(6);
                    if (data === '[DONE]') {
                        finish();
                        return fullText;
                    }

                    let event;
                    try { event = JSON.parse(data); } catch { continue; }
                    if (event.error) throw new Error(event.error.message || 'The AI provider is unavailable.');
                    const choice = event.choices?.[0];
                    if (choice?.finish_reason) finishReason = choice.finish_reason;
                    const delta = choice?.delta;
                    if (delta?.content) {
                        if (!firstChunkAt) firstChunkAt = performance.now();
                        fullText += delta.content;
                        startReveal();
                    }
                }
            }
            buffer += decoder.decode();
            const tail = buffer.trim();
            if (tail.startsWith('data: ') && tail.slice(6) !== '[DONE]') {
                try {
                    const event = JSON.parse(tail.slice(6));
                    const delta = event.choices?.[0]?.delta;
                    if (delta?.content) fullText += delta.content;
                } catch { /* an incomplete trailing frame carries no renderable text */ }
            }
        } catch (err) {
            if (err?.name === 'AbortError') {
                // User stopped generation — keep partial text
                flush(false);
                return fullText;
            }
            flush(false);
            throw err;
        }

        finish();
        return fullText;
    };

    const continueAnswer = async (index) => {
        const target = messages[index];
        if (!target || target.role !== 'assistant' || isStreaming || !canSendToModel) return;
        const partial = typeof target.content === 'string' ? target.content : '';
        setIsStreaming(true);
        setWaitStartedAt(Date.now());
        setWaitModel(selectedChatModel?.name || selectedModel);
        try {
            const apiMessages = [...buildApiMessages(messages.slice(0, index + 1)), { role: 'user', content: CONTINUE_PROMPT }];
            await streamChat(apiMessages, selectedModel, currentConvId, { continuation: true, targetIndex: index, prefix: partial });
        } catch (err) {
            if (err?.name === 'AbortError') return;
            setMessages(prev => [...prev, { role: 'assistant', content: 'Error: ' + err.message }]);
        } finally {
            setIsStreaming(false);
            abortRef.current = null;
            inputRef.current?.focus();
        }
    };

    const stopStreaming = () => {
        abortRef.current?.abort();
        abortRef.current = null;
        setIsStreaming(false);
    };

    const sendMessage = async () => {
        const text = input.trim();
        if ((!text && attachments.length === 0) || isStreaming || !canSendToModel) return;

        let userContent = text;
        let userDisplay = text;
        const currentAtts = [...attachments];

        if (currentAtts.length > 0) {
            const parts = [];
            if (text) parts.push({ type: 'text', text });
            for (const att of currentAtts) {
                if (att.type === 'image') {
                    parts.push({ type: 'image_url', image_url: { url: att.base64 } });
                } else if (att.type === 'doc') {
                    parts.push({ type: 'text', text: `[File: ${att.name}]\n${att.text}` });
                }
            }
            userContent = parts;
            userDisplay = text || '';
        }

        const displayImgs = currentAtts.filter(a => a.type === 'image').map(a => ({ id: a.id, name: a.name, thumb: a.base64.length < 50000 ? a.base64 : a.base64.substring(0, 100) + '...' }));
        const displayDocs = currentAtts.filter(a => a.type === 'doc').map(a => ({ id: a.id, name: a.name }));
        const userMsg = { role: 'user', content: userContent, _display: userDisplay, _imgs: displayImgs, _docs: displayDocs };
        const newMessages = [...messages, userMsg];
        setMessages(newMessages);
        setInput('');
        setAttachments([]);
        setIsStreaming(true);
        setWaitStartedAt(Date.now());
        setWaitModel(selectedChatModel?.name || selectedModel);

        if (inputRef.current) inputRef.current.style.height = 'auto';

        try {
            const apiMessages = buildApiMessages(newMessages);
            await streamChat(apiMessages, selectedModel, currentConvId);
            loadConversations();

            setMessages(prev => prev.map(m => {
                if (Array.isArray(m.content)) {
                    const textOnly = m.content
                        .filter(p => p?.type === 'text')
                        .map(p => p.text || '')
                        .join('\n').trim() || '[Attachment]';
                    return { ...m, content: textOnly };
                }
                return m;
            }));
        } catch (err) {
            if (err?.name === 'AbortError') return;
            setMessages(prev => [
                ...prev.filter(m => m.content !== ''),
                { role: 'assistant', content: 'Error: ' + err.message }
            ]);
        } finally {
            setIsStreaming(false);
            abortRef.current = null;
            inputRef.current?.focus();
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    };

    const handleInputChange = (e) => {
        setInput(e.target.value);
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 200) + 'px';
    };

    // ===== File Upload =====
    const MAX_FILES = 5;
    const MAX_SIZE = 20 * 1024 * 1024;
    const IMG_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    const DOC_TYPES = ['application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/json'];

    const fileToBase64 = (file) => new Promise((resolve) => {
        const r = new FileReader();
        r.onload = () => resolve(r.result);
        r.onerror = () => resolve(null);
        r.readAsDataURL(file);
    });

    const processFiles = useCallback(async (fileList) => {
        const files = Array.from(fileList);
        for (const file of files) {
            if (attachments.length >= MAX_FILES) break;
            const isImg = IMG_TYPES.includes(file.type);
            const isDoc = DOC_TYPES.includes(file.type);
            if (!isImg && !isDoc) continue;
            if (file.size > MAX_SIZE) continue;

            if (isImg) {
                const base64 = await fileToBase64(file);
                if (!base64) continue;
                setAttachments(prev => prev.length >= MAX_FILES ? prev : [...prev, {
                    id: Date.now() + '-' + Math.random().toString(36).slice(2, 5),
                    base64, name: file.name, size: file.size, type: 'image',
                }]);
            } else {
                const text = await file.text().catch(() => null);
                if (!text) continue;
                setAttachments(prev => prev.length >= MAX_FILES ? prev : [...prev, {
                    id: Date.now() + '-' + Math.random().toString(36).slice(2, 5),
                    text, name: file.name, size: file.size, type: 'doc',
                }]);
            }
        }
    }, [attachments.length]);

    const removeAttachment = (id) => setAttachments(prev => prev.filter(a => a.id !== id));

    const handleDragOver = (e) => { e.preventDefault(); setIsDragging(true); };
    const handleDragLeave = (e) => { e.preventDefault(); setIsDragging(false); };
    const handleDrop = (e) => { e.preventDefault(); setIsDragging(false); if (e.dataTransfer.files?.length) processFiles(e.dataTransfer.files); };
    const handlePaste = (e) => {
        const files = [];
        for (const item of (e.clipboardData?.items || [])) {
            if (item.kind === 'file') { const f = item.getAsFile(); if (f) files.push(f); }
        }
        if (files.length > 0) { e.preventDefault(); processFiles(files); }
    };
    const handleFileSelect = (e) => { if (e.target.files?.length) { processFiles(e.target.files); e.target.value = ''; } };

    // Current model category for theming
    const currentModel = selectedChatModel;
    const currentCategory = currentModel?.category || 'chat';
    const catCfg = CATEGORY_CONFIG[currentCategory] || CATEGORY_CONFIG.chat;

    // Model count per category
    const modelCounts = useMemo(() => {
        const counts = { total: models.length };
        models.forEach(m => {
            counts[m.category] = (counts[m.category] || 0) + 1;
        });
        return counts;
    }, [models]);

    // Filtered conversations for history rail search
    const filteredConversations = useMemo(() => {
        if (!historySearch.trim()) return conversations;
        const s = historySearch.toLowerCase();
        return conversations.filter(c =>
            (c.title || '').toLowerCase().includes(s) ||
            (c.model_name || c.model || '').toLowerCase().includes(s)
        );
    }, [conversations, historySearch]);

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
        setInput(text);
        setTimeout(() => inputRef.current?.focus(), 50);
    };

    const pickChatMode = () => {
        const firstModel = chatModels[0];
        if (firstModel) setSelectedModel(firstModel.id);
        inputRef.current?.focus();
    };

    const composerPlaceholder = attachments.length > 0
        ? t('Tambahkan pesan...')
        : t('Jelaskan ide Anda...');

    return (
        <div className={`cw-root ${isDark ? 'cw-dark' : 'cw-light'}`}>
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
                sidebarCollapsed ? 'cw-rail-collapsed' : '',
                showSidebar ? 'cw-rail-open' : '',
            ].join(' ')} aria-label={t('Percakapan')}>
                {/* Rail header */}
                <div className="cw-rail-head">
                    {!sidebarCollapsed && (
                        <Link to={localizedPath('/dashboard')} className="cw-brand" aria-label="XSuper.ai">
                            <UltrLockup height={26} />
                        </Link>
                    )}
                    <button
                        onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
                        className="cw-icon-btn cw-rail-collapse"
                        aria-label={sidebarCollapsed ? t('Perluas sidebar') : t('Ciutkan sidebar')}
                        title={sidebarCollapsed ? t('Perluas sidebar') : t('Ciutkan sidebar')}
                    >
                        <span className={`cw-collapse-icon ${sidebarCollapsed ? 'cw-collapse-flip' : ''}`}>{Icon.collapse}</span>
                    </button>
                    <button
                        onClick={() => setShowSidebar(false)}
                        className="cw-icon-btn cw-rail-close"
                        aria-label={t('Tutup sidebar')}
                    >
                        {Icon.close}
                    </button>
                </div>

                {/* New chat */}
                <div className="cw-rail-new">
                    <button onClick={newConversation} className="cw-new-chat" title={t('Chat Baru')}>
                        {Icon.plus}
                        {!sidebarCollapsed && <span>{t('Chat Baru')}</span>}
                    </button>
                </div>

                {/* Search */}
                {!sidebarCollapsed && (
                    <div className="cw-rail-search">
                        <span className="cw-rail-search-icon">{Icon.search}</span>
                        <input
                            type="search"
                            value={historySearch}
                            onChange={(e) => setHistorySearch(e.target.value)}
                            placeholder={t('Cari percakapan...')}
                            aria-label={t('Cari percakapan...')}
                        />
                        {historySearch && (
                            <button className="cw-rail-search-clear" onClick={() => setHistorySearch('')} aria-label={t('Tutup sidebar')}>{Icon.close}</button>
                        )}
                    </div>
                )}

                {/* Conversation list */}
                {!sidebarCollapsed && (
                    <div className="cw-rail-list scrollbar-thin" role="list">
                        {filteredConversations.length === 0 ? (
                            <div className="cw-rail-empty">
                                {conversations.length === 0 ? t('Belum ada percakapan') : t('Tidak ada percakapan yang cocok')}
                            </div>
                        ) : (
                            filteredConversations.map((conv) => (
                                <ConversationItem
                                    key={conv.conversation_id}
                                    conv={conv}
                                    isActive={conv.conversation_id === currentConvId}
                                    onClick={() => loadConversation(conv.conversation_id)}
                                    onDelete={() => deleteConversation(conv.conversation_id)}
                                    t={t}
                                />
                            ))
                        )}
                    </div>
                )}

                {/* Rail footer — account & controls */}
                <div className="cw-rail-foot">
                    {!sidebarCollapsed && (
                        <div className="cw-rail-stats">
                            <span><strong>{modelCounts.total || 0}</strong> {t('Model').toLowerCase()}</span>
                            <span className="cw-rail-stats-dot">·</span>
                            <span><strong>{conversations.length}</strong> {t('Percakapan').toLowerCase()}</span>
                        </div>
                    )}
                    <div className={`cw-rail-actions ${sidebarCollapsed ? 'cw-rail-actions-col' : ''}`}>
                        <Link to={localizedPath('/dashboard')} className="cw-rail-link" title={t('Kembali ke Dashboard')}>
                            {Icon.grid}
                            {!sidebarCollapsed && <span>{t('Dashboard')}</span>}
                        </Link>
                        <button onClick={toggleTheme} className="cw-rail-link" title={isDark ? t('Mode Terang') : t('Mode Gelap')}>
                            {isDark ? Icon.sun : Icon.moon}
                            {!sidebarCollapsed && <span>{isDark ? t('Terang') : t('Gelap')}</span>}
                        </button>
                    </div>
                    {!sidebarCollapsed && (
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
                            onSelect={setSelectedModel}
                            disabled={isStreaming || modelsLoading || !chatModels.length}
                            loading={modelsLoading}
                            t={t}
                        />
                    </div>
                    <div className="cw-topbar-right">
                        <span className="cw-cat-pill" style={{ '--pill-accent': catCfg.accent }}>
                            {catCfg.icon}
                            {catCfg.label}
                        </span>
                        <button type="button" className={`cw-status${modelsLoading || modelsError || !chatModels.length ? ' cw-status-unavailable' : ''}`} onClick={loadModels} disabled={modelsLoading || isStreaming} aria-label={t('Perbarui model')} title={t('Perbarui model')}>
                            {!modelsLoading && !modelsError && chatModels.length > 0 && <span className="cw-status-dot" aria-hidden="true" />}
                            <span className="cw-status-text">{modelsLoading ? t('Memuat…') : modelsError || !chatModels.length ? t('Coba lagi') : t('Daring')}</span>
                        </button>
                    </div>
                </header>

                {(modelsLoading || modelsError || !chatModels.length) && (
                    <div className="cw-model-feedback" role={modelsError ? 'alert' : 'status'}>
                        <span>{modelsLoading ? t('Memuat model chat…') : modelsError ? t('Daftar model tidak dapat diperbarui. Coba muat ulang; draf Anda tetap tersimpan.') : t('Tidak ada model chat yang tersedia untuk akun Anda. Model gambar dan video ada di ruang masing-masing.')}</span>
                        {!modelsLoading && <button type="button" onClick={loadModels}>{t('Coba lagi')}</button>}
                    </div>
                )}

                {/* ===== Message thread / empty hero ===== */}
                <div className="cw-thread scrollbar-thin">
                    {messages.length === 0 ? (
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
                            {messages.map((msg, i) => (
                                !(isStreaming && msg.role === 'assistant' && !msg.content) &&
                                <ChatMessage
                                    key={i}
                                    message={msg}
                                    userName={user?.name}
                                    isDark={isDark}
                                    categoryColor={currentCategory}
                                    t={t}
                                    onContinue={msg._truncated ? () => continueAnswer(i) : undefined}
                                    canContinue={!isStreaming && canSendToModel}
                                />
                            ))}
                            {isStreaming && (messages[messages.length - 1]?.role !== 'assistant' || !messages[messages.length - 1]?.content) && (
                                <div className="cw-msg cw-msg-assistant">
                                    <div className="cw-avatar cw-avatar-ai" style={{ background: `linear-gradient(135deg, ${catCfg.accent}, var(--red-600))` }}><XGlyph className="cw-sigil-icon" /></div>
                                    <div className="cw-msg-body"><div className="cw-msg-meta"><span className="cw-msg-author">XSuper.ai</span></div><GenerationProgress kind="chat" stage="waiting" model={waitModel} startedAt={waitStartedAt} compact /></div>
                                </div>
                            )}
                            <div ref={messagesEndRef} />
                        </div>
                    )}
                </div>

                {/* ===== Composer ===== */}
                <div className="cw-composer-wrap">
                    <div className={`cw-composer ${isDragging ? 'cw-composer-drag' : ''} ${isStreaming ? 'cw-composer-busy' : ''}`}>
                        {/* Attachment chips */}
                        {attachments.length > 0 && (
                            <div className="cw-chips">
                                {attachments.map(att => (
                                    <div key={att.id} className="cw-chip group">
                                        {att.type === 'image' ? (
                                            <img src={att.base64} alt={att.name} className="cw-chip-thumb" />
                                        ) : (
                                            <span className="cw-chip-icon">{Icon.file}</span>
                                        )}
                                        <span className="cw-chip-name">{att.name}</span>
                                        <button
                                            onClick={() => removeAttachment(att.id)}
                                            className="cw-chip-remove"
                                            aria-label={`${t('Hapus lampiran')}: ${att.name}`}
                                            title={t('Hapus lampiran')}
                                        >
                                            {Icon.close}
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Input row */}
                        <div className="cw-input-row">
                            <button
                                onClick={() => fileInputRef.current?.click()}
                                disabled={isStreaming || attachments.length >= MAX_FILES}
                                className="cw-icon-btn cw-attach-btn"
                                title={t('Lampirkan file')}
                                aria-label={t('Lampirkan file')}
                            >
                                {Icon.attach}
                            </button>
                            <textarea
                                ref={inputRef}
                                value={input}
                                onChange={handleInputChange}
                                onKeyDown={handleKeyDown}
                                onPaste={handlePaste}
                                placeholder={composerPlaceholder}
                                rows={1}
                                disabled={isStreaming}
                                className="cw-textarea"
                                aria-label={composerPlaceholder}
                            />
                            {isStreaming ? (
                                <button
                                    onClick={stopStreaming}
                                    className="cw-send cw-stop"
                                    title={t('Berhenti')}
                                    aria-label={t('Berhenti')}
                                >
                                    {Icon.stop}
                                </button>
                            ) : (
                                <button
                                    onClick={sendMessage}
                                    disabled={(!input.trim() && attachments.length === 0) || !canSendToModel}
                                    className="cw-send"
                                    title={t('Kirim')}
                                    aria-label={t('Kirim')}
                                >
                                    {Icon.send}
                                </button>
                            )}
                        </div>

                        <input ref={fileInputRef} type="file" multiple
                            accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,text/plain,text/markdown,text/csv,application/json"
                            onChange={handleFileSelect} className="hidden" />
                    </div>
                    <p className="cw-hint">
                        <kbd>Enter</kbd> {t('untuk kirim')}, <kbd>Shift+Enter</kbd> {t('untuk baris baru')}. {t('Anda juga bisa menempel atau menjatuhkan file.')}
                    </p>
                    <p className="cw-disclaimer">{t('XSuper.ai dapat membuat kesalahan. Periksa informasi penting.')}</p>
                </div>

                {/* Drag overlay */}
                {isDragging && (
                    <div className="cw-drag-overlay" aria-hidden="true">
                        <div className="cw-drag-card">
                            <span className="cw-drag-icon">{Icon.attach}</span>
                            <div className="cw-drag-title">{t('Tarik & lepas file ke sini')}</div>
                            <div className="cw-drag-sub">{t('Gambar atau dokumen, maks 5 file 20MB')}</div>
                        </div>
                    </div>
                )}
            </div>
            </div>
        </div>
    );
}
