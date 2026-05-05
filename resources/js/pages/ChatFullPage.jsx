import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useTheme } from '../contexts/ThemeContext';

// ============================================
// Constants & Config
// ============================================
const CATEGORY_CONFIG = {
    chat: {
        label: 'Chat',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
        ),
        color: 'blue',
        gradient: 'from-blue-500 to-cyan-400',
        bg: 'bg-blue-500/10',
        border: 'border-blue-500/20',
        text: 'text-blue-400',
        ring: 'ring-blue-500/30',
        glow: 'shadow-blue-500/20',
    },
    image: {
        label: 'Image',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                <circle cx="8.5" cy="8.5" r="1.5" />
                <polyline points="21 15 16 10 5 21" />
            </svg>
        ),
        color: 'purple',
        gradient: 'from-purple-500 to-pink-400',
        bg: 'bg-purple-500/10',
        border: 'border-purple-500/20',
        text: 'text-purple-400',
        ring: 'ring-purple-500/30',
        glow: 'shadow-purple-500/20',
    },
    video: {
        label: 'Video',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                <rect x="2" y="2" width="20" height="20" rx="2" />
                <polygon points="10 8 16 12 10 16 10 8" />
            </svg>
        ),
        color: 'red',
        gradient: 'from-red-500 to-orange-400',
        bg: 'bg-red-500/10',
        border: 'border-red-500/20',
        text: 'text-red-400',
        ring: 'ring-red-500/30',
        glow: 'shadow-red-500/20',
    },
    audio: {
        label: 'Audio',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                <path d="M9 18V5l12-2v13" />
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="16" r="3" />
            </svg>
        ),
        color: 'emerald',
        gradient: 'from-emerald-500 to-teal-400',
        bg: 'bg-emerald-500/10',
        border: 'border-emerald-500/20',
        text: 'text-emerald-400',
        ring: 'ring-emerald-500/30',
        glow: 'shadow-emerald-500/20',
    },
};

const TIER_CONFIG = {
    Original: { label: 'Original', color: 'text-blue-400', bg: 'bg-blue-500/10', border: 'border-blue-500/20' },
    Authentic: { label: 'Authentic', color: 'text-amber-400', bg: 'bg-amber-500/10', border: 'border-amber-500/20' },
    Codex: { label: 'Codex', color: 'text-emerald-400', bg: 'bg-emerald-500/10', border: 'border-emerald-500/20' },
    Wavespeed: { label: 'Wavespeed', color: 'text-violet-400', bg: 'bg-violet-500/10', border: 'border-violet-500/20' },
    YepAPI: { label: 'YepAPI', color: 'text-cyan-400', bg: 'bg-cyan-500/10', border: 'border-cyan-500/20' },
    Canva: { label: 'Canva', color: 'text-pink-400', bg: 'bg-pink-500/10', border: 'border-pink-500/20' },
};

// ============================================
// Scrub branded text
// ============================================
function rebrandText(text) {
    if (!text) return text;
    var p = [101,110,111,119,120].map(c => String.fromCharCode(c)).join('');
    return text
        .replace(new RegExp(p + 'ai', 'gi'), 'UltrAI')
        .replace(new RegExp(p + '\\s*labs', 'gi'), 'UltrAI')
        .replace(new RegExp(p, 'gi'), 'UltrAI')
        .replace(/UltrAI\s*Labs/gi, 'UltrAI');
}

// ============================================
// Markdown renderer
// ============================================
function formatContent(text, isDark) {
    if (!text) return '';
    text = rebrandText(text);
    let html = text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (_, lang, code) => {
        const header = '<div class="chat-code-header">' + (lang || 'code') + '</div>';
        return '<pre class="chat-code-block">' + header + '<code>' + code.trim() + '</code></pre>';
    });
    html = html.replace(/```([\s\S]*?)```/g, '<pre class="chat-code-block"><code>$1</code></pre>');
    html = html.replace(/`([^`]+)`/g, '<code class="chat-inline-code">$1</code>');
    const boldClass = isDark ? 'text-white font-semibold' : 'text-gray-900 font-semibold';
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong class="' + boldClass + '">$1</strong>');
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    html = html.replace(/\n/g, '<br>');
    return html;
}

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
function ChatMessage({ message, userName, isDark, categoryColor }) {
    const isUser = message.role === 'user';
    const catCfg = CATEGORY_CONFIG[categoryColor] || CATEGORY_CONFIG.chat;
    const [copied, setCopied] = useState(false);

    const avatarClass = isUser
        ? (isDark ? 'bg-white/[0.08] text-gray-300 border border-white/[0.06]' : 'bg-gray-100 text-gray-600 border border-gray-200')
        : `bg-gradient-to-br ${catCfg.gradient} text-white shadow-lg ${catCfg.glow}`;

    // Get display text (plain string for rendering)
    const displayText = typeof message.content === 'string' ? message.content : (message._display || '');
    // Get user attachment display info
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
        <div className="group flex gap-2.5 sm:gap-3.5 max-w-4xl mx-auto w-full animate-msg-in">
            <div className={`w-7 h-7 sm:w-8 sm:h-8 rounded-lg sm:rounded-xl flex items-center justify-center text-[10px] sm:text-xs font-bold flex-shrink-0 mt-0.5 ${avatarClass} [&>svg]:w-3.5 [&>svg]:h-3.5 sm:[&>svg]:w-4 sm:[&>svg]:h-4`}>
                {isUser ? (userName?.[0]?.toUpperCase() || 'U') : catCfg.icon}
            </div>
            <div className="flex-1 min-w-0">
                <div className={`flex items-center gap-2 mb-1 sm:mb-1.5`}>
                    <span className={`text-[10px] sm:text-[11px] font-bold uppercase tracking-[0.08em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                        {isUser ? (userName || 'You') : 'UltrAI'}
                    </span>
                    {/* Copy button — only for assistant messages with content */}
                    {!isUser && displayText && (
                        <button
                            onClick={handleCopy}
                            className={`opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded-md ${
                                copied
                                    ? 'text-emerald-400'
                                    : isDark ? 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.06]' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'
                            }`}
                            title={copied ? 'Tersalin!' : 'Salin'}
                        >
                            {copied ? (
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            ) : (
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            )}
                        </button>
                    )}
                </div>

                {/* User image attachment indicators */}
                {imgs.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 mb-2">
                        {imgs.map(a => (
                            <span key={a.id} className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-medium ${isDark ? 'bg-purple-500/10 text-purple-300 border border-purple-500/20' : 'bg-purple-50 text-purple-600 border border-purple-200'}`}>
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                {a.name}
                            </span>
                        ))}
                    </div>
                )}

                {/* User doc attachment indicators */}
                {docs.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 mb-2">
                        {docs.map(a => (
                            <span key={a.id} className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-medium ${isDark ? 'bg-white/[0.06] text-gray-300 border border-white/[0.06]' : 'bg-gray-100 text-gray-600 border border-gray-200'}`}>
                                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                {a.name}
                            </span>
                        ))}
                    </div>
                )}

                {/* Text content */}
                {displayText && (
                    <div
                        className={`text-[13px] sm:text-[15px] leading-6 sm:leading-7 chat-content ${isDark ? 'text-gray-300' : 'text-gray-700'}`}
                        dangerouslySetInnerHTML={{ __html: formatContent(displayText, isDark) }}
                    />
                )}
            </div>
        </div>
    );
}

// ============================================
// Typing Indicator
// ============================================
function TypingIndicator({ isDark, categoryColor }) {
    const catCfg = CATEGORY_CONFIG[categoryColor] || CATEGORY_CONFIG.chat;
    return (
        <div className="flex gap-2.5 sm:gap-3.5 max-w-4xl mx-auto w-full animate-msg-in">
            <div className={`w-7 h-7 sm:w-8 sm:h-8 rounded-lg sm:rounded-xl bg-gradient-to-br ${catCfg.gradient} text-white shadow-lg ${catCfg.glow} flex items-center justify-center text-[10px] sm:text-xs font-bold flex-shrink-0 [&>svg]:w-3.5 [&>svg]:h-3.5 sm:[&>svg]:w-4 sm:[&>svg]:h-4`}>
                {catCfg.icon}
            </div>
            <div className="flex-1">
                <div className={`text-[10px] sm:text-[11px] font-bold uppercase tracking-[0.08em] mb-1 sm:mb-1.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>UltrAI</div>
                <div className="flex gap-1.5 py-2">
                    {[0, 150, 300].map((delay) => (
                        <span key={delay} className={`w-1.5 h-1.5 sm:w-2 sm:h-2 rounded-full ${isDark ? 'bg-gray-500' : 'bg-gray-400'} animate-bounce`} style={{ animationDelay: `${delay}ms` }} />
                    ))}
                </div>
            </div>
        </div>
    );
}

// ============================================
// Conversation Item
// ============================================
function ConversationItem({ conv, isActive, onClick, onDelete, isDark }) {
    return (
        <div
            onClick={onClick}
            className={`group flex items-center gap-2 px-3 py-2.5 rounded-xl cursor-pointer transition-all duration-150 ${
                isActive
                    ? 'bg-red-500/10 text-red-400 border border-red-500/15'
                    : isDark
                        ? 'text-gray-400 hover:bg-white/[0.04] hover:text-gray-300'
                        : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'
            }`}
        >
            <svg className="w-4 h-4 flex-shrink-0 opacity-50" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
            <span className="flex-1 text-sm truncate font-medium">
                {conv.title || conv.conversation_id?.slice(0, 20) || 'New Chat'}
            </span>
            <button
                onClick={(e) => { e.stopPropagation(); onDelete(); }}
                className="opacity-0 group-hover:opacity-100 p-1 rounded-md hover:bg-red-500/15 hover:text-red-400 transition-all"
                title="Hapus"
            >
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                    <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                </svg>
            </button>
        </div>
    );
}

// ============================================
// Model Selector Dropdown
// ============================================
function ModelSelector({ models, selectedModel, onSelect, selectedCategory, onCategoryChange, isDark }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const dropdownRef = useRef(null);

    // Close on outside click
    useEffect(() => {
        const handler = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const categories = useMemo(() => {
        const cats = [...new Set(models.map(m => m.category))];
        return cats.sort((a, b) => {
            const order = ['chat', 'image', 'video', 'audio'];
            return order.indexOf(a) - order.indexOf(b);
        });
    }, [models]);

    const filteredModels = useMemo(() => {
        let filtered = models;
        if (selectedCategory !== 'all') {
            filtered = filtered.filter(m => m.category === selectedCategory);
        }
        if (search) {
            const s = search.toLowerCase();
            filtered = filtered.filter(m =>
                (m.name || m.id).toLowerCase().includes(s) ||
                m.id.toLowerCase().includes(s) ||
                (m.tier || '').toLowerCase().includes(s)
            );
        }
        return filtered;
    }, [models, selectedCategory, search]);

    // Group by tier
    const groupedModels = useMemo(() => {
        const groups = {};
        filteredModels.forEach(m => {
            const tier = m.tier || 'Original';
            if (!groups[tier]) groups[tier] = [];
            groups[tier].push(m);
        });
        return groups;
    }, [filteredModels]);

    const currentModel = models.find(m => m.id === selectedModel);
    const currentCatCfg = CATEGORY_CONFIG[currentModel?.category] || CATEGORY_CONFIG.chat;

    // Track trigger button width for dropdown alignment
    const triggerRef = useRef(null);

    return (
        <div className="relative" ref={dropdownRef}>
            {/* Trigger Button — compact on mobile */}
            <button
                ref={triggerRef}
                onClick={() => setOpen(!open)}
                className={`flex items-center gap-1.5 sm:gap-2.5 px-2.5 sm:px-3.5 py-1.5 sm:py-2 rounded-lg sm:rounded-xl border transition-all duration-200 ${
                    open
                        ? isDark
                            ? 'bg-white/[0.08] border-white/[0.15] text-white'
                            : 'bg-gray-100 border-gray-400 text-gray-900'
                        : isDark
                            ? 'bg-white/[0.04] border-white/[0.08] text-gray-300 hover:bg-white/[0.06] hover:border-white/[0.12]'
                            : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50 hover:border-gray-400'
                }`}
            >
                <span className={`w-5 h-5 sm:w-6 sm:h-6 rounded-md sm:rounded-lg bg-gradient-to-br ${currentCatCfg.gradient} flex items-center justify-center text-white [&>svg]:w-3 [&>svg]:h-3 sm:[&>svg]:w-4 sm:[&>svg]:h-4`}>
                    {currentCatCfg.icon}
                </span>
                <span className="text-xs sm:text-sm font-semibold truncate max-w-[120px] sm:max-w-[200px]">
                    {rebrandText(currentModel?.name || currentModel?.id || 'Select Model')}
                </span>
                <span className={`hidden sm:inline-block text-[10px] font-bold px-1.5 py-0.5 rounded-md ${TIER_CONFIG[currentModel?.tier]?.bg || ''} ${TIER_CONFIG[currentModel?.tier]?.color || ''}`}>
                    {TIER_CONFIG[currentModel?.tier]?.label || ''}
                </span>
                <svg className={`w-3.5 h-3.5 sm:w-4 sm:h-4 transition-transform ${open ? 'rotate-180' : ''} ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                    <polyline points="6 9 12 15 18 9" />
                </svg>
            </button>

            {/* Dropdown — exact same width as trigger button */}
            {open && (
                <>
                    <div className="fixed inset-0 z-[998]" onClick={() => setOpen(false)} />
                    <div
                        className={`absolute top-full left-0 mt-1.5 rounded-lg sm:rounded-xl border shadow-xl z-[999] flex flex-col ${
                            isDark
                                ? 'bg-gray-900 border-white/[0.1] shadow-black/60'
                                : 'bg-white border-gray-200 shadow-gray-300/40'
                        }`}
                        style={{ width: triggerRef.current ? triggerRef.current.offsetWidth + 'px' : 'auto' }}
                    >
                        {/* Search */}
                        <div className="p-3 pb-2">
                            <div className="relative">
                                <svg className={`absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                    <circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Cari model..."
                                    autoFocus
                                    className={`w-full pl-9 pr-3 py-2 rounded-lg text-sm border focus:outline-none focus:ring-2 ${
                                        isDark
                                            ? 'bg-white/[0.04] border-white/[0.08] text-white placeholder-gray-500 focus:ring-red-500/20 focus:border-red-500/30'
                                            : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400 focus:ring-red-500/20 focus:border-red-500/30'
                                    }`}
                                />
                            </div>
                        </div>

                        {/* Category Tabs — sticky, never hidden by scroll */}
                        <div className={`flex-shrink-0 flex gap-1 px-3 py-2 overflow-x-auto scrollbar-thin border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-100'}`}>
                            <button
                                onClick={() => onCategoryChange('all')}
                                className={`px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-all ${
                                    selectedCategory === 'all'
                                        ? 'bg-red-500/15 text-red-400 border border-red-500/20'
                                        : isDark
                                            ? 'text-gray-400 hover:bg-white/[0.06] hover:text-gray-300'
                                            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'
                                }`}
                            >
                                All
                            </button>
                            {categories.map(cat => {
                                const cfg = CATEGORY_CONFIG[cat] || CATEGORY_CONFIG.chat;
                                return (
                                    <button
                                        key={cat}
                                        onClick={() => onCategoryChange(cat)}
                                        className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-all ${
                                            selectedCategory === cat
                                                ? `${cfg.bg} ${cfg.text} border ${cfg.border}`
                                                : isDark
                                                    ? 'text-gray-400 hover:bg-white/[0.06] hover:text-gray-300'
                                                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'
                                        }`}
                                    >
                                        <span className="[&>svg]:w-3.5 [&>svg]:h-3.5">{cfg.icon}</span>
                                        {cfg.label}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Model List — only this part scrolls, expands downward */}
                        <div className="overflow-y-auto max-h-[min(340px,50vh)] p-2 scrollbar-thin" onWheel={(e) => e.stopPropagation()} onTouchMove={(e) => e.stopPropagation()}>
                            {Object.keys(groupedModels).length === 0 ? (
                                <div className={`text-center py-8 text-sm ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                    Tidak ada model ditemukan
                                </div>
                            ) : (
                                Object.entries(groupedModels).map(([tier, tierModels]) => (
                                    <div key={tier} className="mb-2 last:mb-0">
                                        <div className={`flex items-center gap-2 px-2 py-1.5 text-[10px] font-bold uppercase tracking-[0.1em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                            <span className={`w-1.5 h-1.5 rounded-full ${TIER_CONFIG[tier]?.bg?.replace('/10', '/40') || 'bg-gray-400'}`} />
                                            {TIER_CONFIG[tier]?.label || tier}
                                            <span className={`ml-auto text-[9px] font-medium ${isDark ? 'text-gray-600' : 'text-gray-300'}`}>{tierModels.length}</span>
                                        </div>
                                        {tierModels.map(model => {
                                            const catCfg = CATEGORY_CONFIG[model.category] || CATEGORY_CONFIG.chat;
                                            const isSelected = model.id === selectedModel;
                                            return (
                                                <button
                                                    key={model.id}
                                                    onClick={() => { onSelect(model.id); setOpen(false); setSearch(''); }}
                                                    className={`w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-left transition-all duration-100 ${
                                                        isSelected
                                                            ? isDark
                                                                ? 'bg-red-500/10 text-white'
                                                                : 'bg-red-50 text-gray-900'
                                                            : isDark
                                                                ? 'text-gray-300 hover:bg-white/[0.05]'
                                                                : 'text-gray-700 hover:bg-gray-50'
                                                    }`}
                                                >
                                                    <span className={`w-6 h-6 rounded-lg flex items-center justify-center text-white bg-gradient-to-br ${catCfg.gradient} flex-shrink-0 [&>svg]:w-3 [&>svg]:h-3`}>
                                                        {catCfg.icon}
                                                    </span>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="text-sm font-medium truncate">{rebrandText(model.name || model.id)}</div>
                                                    </div>
                                                    {isSelected && (
                                                        <svg className="w-4 h-4 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                                                            <polyline points="20 6 9 17 4 12" />
                                                        </svg>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

// ============================================
// Main Full Page Chat Component
// ============================================
export default function ChatFullPage() {
    const { user } = useAuth();
    const { theme, toggleTheme } = useTheme();
    const navigate = useNavigate();
    const isDark = theme === 'dark';

    const [conversations, setConversations] = useState([]);
    const [currentConvId, setCurrentConvId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [models, setModels] = useState([]);
    const [selectedModel, setSelectedModel] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('all');
    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [showSidebar, setShowSidebar] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [attachments, setAttachments] = useState([]); // [{id, base64, name, size, type:'image'|'doc'}]
    const [isDragging, setIsDragging] = useState(false);
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);
    const fileInputRef = useRef(null);

    // Auto-scroll — only when there are messages (not on welcome screen)
    const scrollToBottom = useCallback(() => {
        if (messages.length > 0) {
            messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
        }
    }, [messages.length]);

    useEffect(() => { scrollToBottom(); }, [messages, scrollToBottom]);

    // Load all models
    useEffect(() => {
        const loadModels = async () => {
            try {
                const res = await fetch('/api/c/am', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    const m = data.models || [];
                    setModels(m);
                    if (m.length > 0 && !selectedModel) {
                        // Default to first chat model
                        const chatModel = m.find(x => x.category === 'chat');
                        setSelectedModel(chatModel?.id || m[0].id);
                    }
                }
            } catch (err) {
                console.error('Failed to load models:', err);
            }
        };
        loadModels();
    }, []);

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
    }, []);

    // New conversation
    const newConversation = () => {
        const id = 'chat-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        setCurrentConvId(id);
        setMessages([]);
        inputRef.current?.focus();
    };

    // Load conversation
    const loadConversation = async (convId) => {
        setCurrentConvId(convId);
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

    // Send message
    // Build clean messages for API — only serializable primitives
    // For older messages with attachments: only send text (not base64 images again)
    // Only the LAST user message keeps multimodal content
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
                    // Last message: keep full multimodal (images + text)
                    const parts = m.content.map(p => {
                        if (p?.type === 'image_url') return { type: 'image_url', image_url: { url: String(p.image_url?.url || '') } };
                        return { type: 'text', text: String(p?.text || '') };
                    }).filter(p => p.type === 'image_url' || (p.text && p.text.trim()));
                    if (parts.length > 0) result.push({ role, content: parts });
                } else {
                    // Older messages: extract text only (skip heavy base64 images)
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

    // Stream chat response with proper buffer handling
    const streamChat = async (apiMessages, model, convId) => {
        // Final safety: ensure all messages are serializable
        const safeMessages = apiMessages.map(m => {
            const role = String(m.role || 'user');
            const content = m.content;
            if (typeof content === 'string') return { role, content };
            if (Array.isArray(content)) {
                // Only keep safe primitive parts
                const safeParts = content.filter(p => p && typeof p === 'object' && p.type).map(p => {
                    if (p.type === 'text') return { type: 'text', text: String(p.text || '') };
                    if (p.type === 'image_url' && p.image_url?.url) return { type: 'image_url', image_url: { url: String(p.image_url.url) } };
                    return null;
                }).filter(Boolean);
                return { role, content: safeParts.length > 0 ? safeParts : '[content]' };
            }
            return { role, content: String(content || '') };
        });

        const body = JSON.stringify({
            model: String(model),
            messages: safeMessages,
            conversation_id: String(convId || ''),
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
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({ error: res.statusText }));
            throw new Error(err.message || err.error || 'Chat gagal');
        }

        const reader = res.body?.getReader();
        if (!reader) throw new Error('No response body');

        const decoder = new TextDecoder();
        let buffer = '';
        let fullText = '';

        setMessages(prev => [...prev, { role: 'assistant', content: '' }]);

        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || ''; // Keep incomplete line in buffer

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                const data = line.slice(6);
                if (data === '[DONE]') return fullText;

                try {
                    const delta = JSON.parse(data).choices?.[0]?.delta;
                    if (delta?.content) {
                        fullText += delta.content;
                        const display = rebrandText(fullText);
                        setMessages(prev => {
                            const updated = [...prev];
                            updated[updated.length - 1] = { role: 'assistant', content: display };
                            return updated;
                        });
                    }
                } catch {
                    // Skip malformed JSON chunks
                }
            }
        }
        return fullText;
    };

    const sendMessage = async () => {
        const text = input.trim();
        if ((!text && attachments.length === 0) || isStreaming || !selectedModel) return;

        // Build user message content
        let userContent = text;
        let userDisplay = text; // plain string for display
        const currentAtts = [...attachments];

        if (currentAtts.length > 0) {
            // Multimodal: array of {type, text/image_url}
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

        // Store message — _imgs only keeps small thumbnail info for display, not full base64
        const displayImgs = currentAtts.filter(a => a.type === 'image').map(a => ({ id: a.id, name: a.name, thumb: a.base64.length < 50000 ? a.base64 : a.base64.substring(0, 100) + '...' }));
        const displayDocs = currentAtts.filter(a => a.type === 'doc').map(a => ({ id: a.id, name: a.name }));
        const userMsg = { role: 'user', content: userContent, _display: userDisplay, _imgs: displayImgs, _docs: displayDocs };
        const newMessages = [...messages, userMsg];
        setMessages(newMessages);
        setInput('');
        setAttachments([]);
        setIsStreaming(true);

        if (inputRef.current) inputRef.current.style.height = 'auto';

        try {
            const apiMessages = buildApiMessages(newMessages);
            await streamChat(apiMessages, selectedModel, currentConvId);
            loadConversations();

            // After send: flatten multimodal content to text-only in state
            // This prevents huge base64 from accumulating in memory/state
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
            setMessages(prev => [
                ...prev.filter(m => m.content !== ''),
                { role: 'assistant', content: 'Error: ' + err.message }
            ]);
        } finally {
            setIsStreaming(false);
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
    const currentModel = models.find(m => m.id === selectedModel);
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

    return (
        <div className={`h-screen flex ${isDark ? 'bg-gray-950' : 'bg-gray-50'}`} style={{ fontSize: '90%' }}>
            {/* Mobile sidebar overlay */}
            {showSidebar && (
                <div
                    className="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 lg:hidden"
                    onClick={() => setShowSidebar(false)}
                />
            )}

            {/* ===== Sidebar ===== */}
            <div className={[
                'fixed inset-y-0 left-0 z-40 flex flex-col',
                'backdrop-blur-2xl border-r',
                'transform transition-all duration-300',
                'lg:static lg:z-auto',
                sidebarCollapsed ? 'w-[60px]' : 'w-[280px]',
                isDark ? 'bg-gray-900/95 border-white/[0.06]' : 'bg-white border-gray-200',
                showSidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ].join(' ')}>
                {/* Sidebar Header */}
                <div className={`flex items-center justify-between p-3 border-b ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                    {!sidebarCollapsed && (
                        <Link to="/dashboard" className="flex items-center gap-[3px] text-lg font-extrabold tracking-tight">
                            <span className={isDark ? 'text-white' : 'text-gray-900'}>Ultr</span>
                            <span className="bg-gradient-to-r from-red-500 to-red-400 bg-clip-text text-transparent">AI</span>
                        </Link>
                    )}
                    <button
                        onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
                        className={`p-1.5 rounded-lg transition-colors hidden lg:block ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                    >
                        <svg className={`w-4 h-4 transition-transform ${sidebarCollapsed ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <polyline points="15 18 9 12 15 6" />
                        </svg>
                    </button>
                    <button
                        onClick={() => setShowSidebar(false)}
                        className={`p-1.5 rounded-lg transition-colors lg:hidden ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                {/* New Chat Button */}
                <div className={`p-2 ${sidebarCollapsed ? 'px-1.5' : ''}`}>
                    <button
                        onClick={newConversation}
                        className={`w-full flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-red-500/15 to-red-500/5 border border-red-500/15 text-red-400 font-semibold hover:from-red-500/20 hover:to-red-500/10 transition-all ${
                            sidebarCollapsed ? 'p-2.5 text-xs' : 'px-4 py-2.5 text-sm'
                        }`}
                        title="Chat Baru"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                        {!sidebarCollapsed && 'Chat Baru'}
                    </button>
                </div>

                {/* Conversations List */}
                {!sidebarCollapsed && (
                    <div className="flex-1 overflow-y-auto p-2 space-y-0.5 scrollbar-thin">
                        {conversations.length === 0 ? (
                            <div className={`text-center py-8 text-sm ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                                Belum ada percakapan
                            </div>
                        ) : (
                            conversations.map((conv) => (
                                <ConversationItem
                                    key={conv.conversation_id}
                                    conv={conv}
                                    isActive={conv.conversation_id === currentConvId}
                                    onClick={() => loadConversation(conv.conversation_id)}
                                    onDelete={() => deleteConversation(conv.conversation_id)}
                                    isDark={isDark}
                                />
                            ))
                        )}
                    </div>
                )}

                {/* Sidebar Footer */}
                <div className={`p-2 border-t space-y-1 ${isDark ? 'border-white/[0.06]' : 'border-gray-200'}`}>
                    {/* Model count */}
                    {!sidebarCollapsed && (
                        <div className={`px-3 py-2 rounded-xl text-xs ${isDark ? 'bg-white/[0.03] text-gray-500' : 'bg-gray-50 text-gray-400'}`}>
                            <div className="flex items-center justify-between">
                                <span><span className={`font-semibold ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>{modelCounts.total || 0}</span> models</span>
                                <div className="flex gap-1.5">
                                    {Object.entries(CATEGORY_CONFIG).map(([cat, cfg]) => (
                                        modelCounts[cat] ? (
                                            <span key={cat} className={`flex items-center gap-0.5 ${cfg.text}`} title={`${cfg.label}: ${modelCounts[cat]}`}>
                                                {cfg.icon}
                                                <span className="text-[9px] font-bold">{modelCounts[cat]}</span>
                                            </span>
                                        ) : null
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Nav links */}
                    <div className={`flex ${sidebarCollapsed ? 'flex-col items-center gap-1' : 'items-center gap-1'}`}>
                        <Link
                            to="/dashboard"
                            className={`flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium transition-all ${
                                isDark ? 'text-gray-400 hover:text-white hover:bg-white/[0.06]' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                            }`}
                            title="Dashboard"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24">
                                <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
                            </svg>
                            {!sidebarCollapsed && 'Dashboard'}
                        </Link>
                        <button
                            onClick={toggleTheme}
                            className={`flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium transition-all ${
                                isDark ? 'text-gray-400 hover:text-white hover:bg-white/[0.06]' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                            }`}
                            title={isDark ? 'Light Mode' : 'Dark Mode'}
                        >
                            {isDark ? (
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                            ) : (
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                            )}
                            {!sidebarCollapsed && (isDark ? 'Light' : 'Dark')}
                        </button>
                    </div>

                    {/* User */}
                    {!sidebarCollapsed && (
                        <div className={`flex items-center gap-2.5 px-3 py-2 rounded-xl ${isDark ? 'bg-white/[0.03]' : 'bg-gray-50'}`}>
                            <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-white text-xs font-bold shadow-lg shadow-red-500/20">
                                {user?.name?.[0]?.toUpperCase() || 'U'}
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className={`text-xs font-semibold truncate ${isDark ? 'text-white' : 'text-gray-900'}`}>{user?.name || 'User'}</div>
                                <div className={`text-[10px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{user?.role || 'member'}</div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ===== Main Chat Area ===== */}
            <div className="flex-1 flex flex-col min-w-0 relative" onDragOver={handleDragOver} onDragLeave={handleDragLeave} onDrop={handleDrop}>
                {/* Chat Header — sticky, compact on mobile */}
                <div className={`sticky top-0 z-30 flex items-center justify-between px-2.5 sm:px-4 py-2 sm:py-2.5 border-b backdrop-blur-xl ${
                    isDark ? 'border-white/[0.06] bg-gray-950/80' : 'border-gray-200 bg-white/90'
                }`}>
                    <div className="flex items-center gap-2 sm:gap-3 min-w-0">
                        <button
                            onClick={() => setShowSidebar(!showSidebar)}
                            className={`lg:hidden p-1.5 sm:p-2 rounded-lg sm:rounded-xl transition-colors flex-shrink-0 ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/10' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" /></svg>
                        </button>

                        <ModelSelector
                            models={models}
                            selectedModel={selectedModel}
                            onSelect={setSelectedModel}
                            selectedCategory={selectedCategory}
                            onCategoryChange={setSelectedCategory}
                            isDark={isDark}
                        />
                    </div>

                    <div className="flex items-center gap-1.5 sm:gap-2 flex-shrink-0">
                        {/* Category indicator */}
                        <span className={`hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold ${catCfg.bg} border ${catCfg.border} ${catCfg.text}`}>
                            {catCfg.icon}
                            {catCfg.label}
                        </span>

                        {/* Connected status */}
                        <span className="inline-flex items-center gap-1 sm:gap-1.5 px-2 sm:px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-[10px] sm:text-[11px] font-medium text-emerald-400">
                            <span className="relative flex h-1.5 w-1.5">
                                <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping" style={{ animationDuration: '2s' }} />
                                <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            </span>
                            <span className="hidden sm:inline">Online</span>
                        </span>
                    </div>
                </div>

                {/* Messages Area */}
                <div className={`flex-1 overflow-y-auto px-3 sm:px-4 py-4 sm:py-6 space-y-4 sm:space-y-6 scrollbar-thin ${isDark ? '' : 'bg-gray-50/50'}`}>
                    {messages.length === 0 ? (
                        /* Welcome Screen — centered on desktop, starts from top on mobile */
                        <div className="flex flex-col items-center text-center px-2 sm:px-4 pt-6 lg:pt-0 pb-4 lg:justify-center lg:min-h-full">
                            {/* Animated Logo */}
                            <div className="mb-6 sm:mb-8 relative">
                                <div className={`absolute inset-0 w-16 h-16 sm:w-20 sm:h-20 mx-auto rounded-full bg-gradient-to-br ${catCfg.gradient} opacity-20 blur-xl animate-pulse`} />
                                <div className="relative text-4xl sm:text-5xl font-black tracking-tight mb-2 sm:mb-3">
                                    <span className={isDark ? 'text-white' : 'text-gray-900'}>Ultr</span>
                                    <span className="bg-gradient-to-r from-red-500 to-red-400 bg-clip-text text-transparent">AI</span>
                                </div>
                                <div className={`text-lg sm:text-xl font-bold mb-1.5 sm:mb-2 ${isDark ? 'text-white' : 'text-gray-900'}`}>
                                    Halo, {user?.name?.split(' ')[0] || 'User'}! Ada yang bisa saya bantu?
                                </div>
                                <p className={`text-xs sm:text-sm max-w-lg ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                    Pilih model AI dari {modelCounts.total || 0}+ model yang tersedia. Chat, generate gambar, video, dan audio — semua dalam satu tempat.
                                </p>
                            </div>

                            {/* Category Cards — auto center based on available categories */}
                            {(() => {
                                const availableCats = Object.entries(CATEGORY_CONFIG).filter(([cat]) => (modelCounts[cat] || 0) > 0);
                                const count = availableCats.length;
                                return (
                                    <div className="flex flex-wrap justify-center gap-2 sm:gap-3 max-w-2xl w-full mb-5 sm:mb-6">
                                        {availableCats.map(([cat, cfg]) => (
                                            <button
                                                key={cat}
                                                onClick={() => {
                                                    setSelectedCategory(cat);
                                                    const firstModel = models.find(m => m.category === cat);
                                                    if (firstModel) setSelectedModel(firstModel.id);
                                                }}
                                                className={`flex flex-col items-center gap-1.5 sm:gap-2 p-3 sm:p-4 rounded-xl sm:rounded-2xl border transition-all duration-200 hover:scale-[1.03] ${
                                                    count === 1 ? 'w-36 sm:w-40' : count === 2 ? 'w-32 sm:w-36' : count === 3 ? 'w-28 sm:w-32' : 'w-[calc(50%-0.25rem)] sm:w-32'
                                                } ${
                                                    isDark
                                                        ? 'bg-white/[0.03] border-white/[0.06] hover:bg-white/[0.06] hover:border-white/[0.1]'
                                                        : 'bg-white border-gray-200 hover:border-gray-300 shadow-sm'
                                                }`}
                                            >
                                                <div className={`w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-gradient-to-br ${cfg.gradient} flex items-center justify-center text-white shadow-lg ${cfg.glow}`}>
                                                    {cfg.icon}
                                                </div>
                                                <div className={`text-xs sm:text-sm font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>{cfg.label}</div>
                                                <div className={`text-[10px] sm:text-[11px] font-medium ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{modelCounts[cat]} models</div>
                                            </button>
                                        ))}
                                    </div>
                                );
                            })()}

                            {/* Quick prompts */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-w-lg w-full">
                                {[
                                    { icon: '\uD83D\uDCBB', text: 'Bantu saya menulis kode' },
                                    { icon: '\uD83D\uDCDD', text: 'Buatkan artikel blog' },
                                    { icon: '\uD83C\uDFA8', text: 'Generate gambar kreatif' },
                                    { icon: '\uD83C\uDFAC', text: 'Buat video pendek' },
                                ].map((prompt, i) => (
                                    <button
                                        key={i}
                                        onClick={() => { setInput(prompt.text); inputRef.current?.focus(); }}
                                        className={`flex items-center gap-2.5 sm:gap-3 px-3 sm:px-4 py-2.5 sm:py-3 rounded-xl border text-xs sm:text-sm transition-all text-left ${
                                            isDark
                                                ? 'bg-white/[0.03] border-white/[0.06] text-gray-400 hover:text-white hover:bg-white/[0.06] hover:border-white/[0.1]'
                                                : 'bg-white border-gray-200 text-gray-500 hover:text-gray-900 hover:bg-gray-50 hover:border-gray-300'
                                        }`}
                                    >
                                        <span className="text-base sm:text-lg">{prompt.icon}</span>
                                        {prompt.text}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <>
                            {messages.map((msg, i) => (
                                <ChatMessage key={i} message={msg} userName={user?.name} isDark={isDark} categoryColor={currentCategory} />
                            ))}
                            {isStreaming && messages[messages.length - 1]?.role !== 'assistant' && (
                                <TypingIndicator isDark={isDark} categoryColor={currentCategory} />
                            )}
                        </>
                    )}
                    <div ref={messagesEndRef} />
                </div>

                {/* Drag overlay */}
                {isDragging && (
                    <div className="absolute inset-0 z-40 flex items-center justify-center bg-black/40 backdrop-blur-sm pointer-events-none">
                        <div className={`flex flex-col items-center gap-3 p-8 rounded-2xl border-2 border-dashed ${catCfg.border} ${catCfg.bg}`}>
                            <div className={`w-12 h-12 rounded-2xl bg-gradient-to-br ${catCfg.gradient} flex items-center justify-center text-white`}>
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            </div>
                            <div className="text-white font-bold text-sm">Drop file di sini</div>
                            <div className="text-white/60 text-xs">Gambar, PDF, TXT (max 20MB)</div>
                        </div>
                    </div>
                )}

                {/* Input Area */}
                <div
                    className={`px-2.5 sm:px-4 pb-3 sm:pb-4 pt-1.5 sm:pt-2 ${isDark ? 'bg-gradient-to-t from-gray-950 via-gray-950/80 to-transparent' : 'bg-gradient-to-t from-white via-white/80 to-transparent'}`}
                    onDragOver={handleDragOver} onDragLeave={handleDragLeave} onDrop={handleDrop}
                >
                    <div className="max-w-4xl mx-auto relative">
                        <div className={`relative rounded-xl sm:rounded-2xl border transition-all ${
                            isDark
                                ? 'bg-gray-900/80 border-white/[0.08] focus-within:border-red-500/30 focus-within:ring-2 focus-within:ring-red-500/10'
                                : 'bg-white border-gray-300 shadow-sm focus-within:border-red-500/40 focus-within:ring-2 focus-within:ring-red-500/10'
                        }`}>

                            {/* Attachment previews */}
                            {attachments.length > 0 && (
                                <div className="flex gap-2 px-3 sm:px-4 pt-3 pb-1 overflow-x-auto scrollbar-thin">
                                    {attachments.map(att => (
                                        <div key={att.id} className="relative group flex-shrink-0 animate-msg-in">
                                            {att.type === 'image' ? (
                                                <img src={att.base64} alt={att.name} className={`w-14 h-14 sm:w-18 sm:h-18 rounded-lg object-cover border ${isDark ? 'border-white/[0.1]' : 'border-gray-200'}`} />
                                            ) : (
                                                <div className={`flex items-center gap-1.5 px-2.5 py-2 rounded-lg border ${isDark ? 'bg-white/[0.04] border-white/[0.08] text-gray-300' : 'bg-gray-50 border-gray-200 text-gray-600'}`}>
                                                    <svg className={`w-4 h-4 ${catCfg.text}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                    <span className="text-[10px] font-medium truncate max-w-[80px]">{att.name}</span>
                                                </div>
                                            )}
                                            <button onClick={() => removeAttachment(att.id)} className="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-red-500 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 hover:bg-red-600 transition-all shadow-lg text-[10px]">
                                                <svg className="w-3 h-3" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Input row */}
                            <div className="flex items-end">
                                <button
                                    onClick={() => fileInputRef.current?.click()}
                                    disabled={isStreaming || attachments.length >= MAX_FILES}
                                    className={`flex-shrink-0 p-2 sm:p-2.5 ml-1 mb-1 rounded-lg transition-all disabled:opacity-30 ${isDark ? 'text-gray-400 hover:text-white hover:bg-white/[0.08]' : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'}`}
                                    title="Upload gambar atau dokumen"
                                >
                                    <svg className="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                </button>
                                <textarea
                                    ref={inputRef}
                                    value={input}
                                    onChange={handleInputChange}
                                    onKeyDown={handleKeyDown}
                                    onPaste={handlePaste}
                                    placeholder={attachments.length > 0 ? 'Tambahkan pesan...' : `Ketik pesan ke ${rebrandText(currentModel?.name || 'AI')}...`}
                                    rows={1}
                                    disabled={isStreaming}
                                    className={`flex-1 px-1 sm:px-2 py-3 sm:py-3.5 pr-12 sm:pr-14 text-[13px] sm:text-[15px] resize-none focus:outline-none bg-transparent disabled:opacity-50 ${isDark ? 'text-white placeholder-gray-600' : 'text-gray-900 placeholder-gray-400'}`}
                                    style={{ minHeight: '46px', maxHeight: '200px' }}
                                />
                                <button
                                    onClick={sendMessage}
                                    disabled={(!input.trim() && attachments.length === 0) || isStreaming}
                                    className={`flex-shrink-0 w-8 h-8 sm:w-10 sm:h-10 mr-2 mb-2 rounded-lg sm:rounded-xl bg-gradient-to-r ${catCfg.gradient} text-white flex items-center justify-center hover:brightness-110 disabled:opacity-30 disabled:cursor-not-allowed transition-all shadow-lg ${catCfg.glow}`}
                                >
                                    {isStreaming ? (
                                        <svg className="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    ) : (
                                        <svg className="w-3.5 h-3.5 sm:w-4 sm:h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    )}
                                </button>
                            </div>

                            <input ref={fileInputRef} type="file" multiple accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,text/plain,text/markdown,text/csv,application/json" onChange={handleFileSelect} className="hidden" />
                        </div>
                    </div>
                    <p className={`text-center text-[10px] sm:text-[11px] mt-1.5 sm:mt-2 ${isDark ? 'text-gray-600' : 'text-gray-400'}`}>
                        UltrAI dapat membuat kesalahan. Periksa informasi penting.
                    </p>
                </div>
            </div>
        </div>
    );
}
