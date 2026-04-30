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
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');
}

// ============================================
// Chat Message Component
// ============================================
function ChatMessage({ message, userName, isDark, categoryColor }) {
    const isUser = message.role === 'user';
    const catCfg = CATEGORY_CONFIG[categoryColor] || CATEGORY_CONFIG.chat;

    const avatarClass = isUser
        ? (isDark ? 'bg-white/[0.08] text-gray-300 border border-white/[0.06]' : 'bg-gray-100 text-gray-600 border border-gray-200')
        : `bg-gradient-to-br ${catCfg.gradient} text-white shadow-lg ${catCfg.glow}`;

    return (
        <div className="flex gap-2.5 sm:gap-3.5 max-w-4xl mx-auto w-full animate-msg-in">
            <div className={`w-7 h-7 sm:w-8 sm:h-8 rounded-lg sm:rounded-xl flex items-center justify-center text-[10px] sm:text-xs font-bold flex-shrink-0 mt-0.5 ${avatarClass} [&>svg]:w-3.5 [&>svg]:h-3.5 sm:[&>svg]:w-4 sm:[&>svg]:h-4`}>
                {isUser ? (userName?.[0]?.toUpperCase() || 'U') : catCfg.icon}
            </div>
            <div className="flex-1 min-w-0">
                <div className={`text-[10px] sm:text-[11px] font-bold uppercase tracking-[0.08em] mb-1 sm:mb-1.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                    {isUser ? (userName || 'You') : 'UltrAI'}
                </div>
                <div
                    className={`text-[13px] sm:text-[15px] leading-6 sm:leading-7 chat-content ${isDark ? 'text-gray-300' : 'text-gray-700'}`}
                    dangerouslySetInnerHTML={{ __html: formatContent(message.content, isDark) }}
                />
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

    return (
        <div className="relative" ref={dropdownRef}>
            {/* Trigger Button — compact on mobile */}
            <button
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

            {/* Dropdown — same width as trigger, left-aligned */}
            {open && (
                <>
                    {/* Invisible backdrop to catch outside clicks */}
                    <div className="fixed inset-0 z-[998]" onClick={() => setOpen(false)} />
                    <div className={`absolute top-full left-0 mt-1 w-[280px] sm:w-[340px] max-h-[min(420px,65vh)] rounded-lg sm:rounded-xl border shadow-xl z-[999] flex flex-col ${
                        isDark
                            ? 'bg-gray-900 border-white/[0.1] shadow-black/60'
                            : 'bg-white border-gray-200 shadow-gray-300/40'
                    }`}>
                        {/* Search */}
                        <div className={`px-2.5 pt-2.5 pb-2 ${isDark ? '' : ''}`}>
                            <div className="relative">
                                <svg className={`absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                    <circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Cari model..."
                                    autoFocus
                                    className={`w-full pl-8 pr-3 py-1.5 rounded-lg text-xs border focus:outline-none focus:ring-1 ${
                                        isDark
                                            ? 'bg-white/[0.04] border-white/[0.08] text-white placeholder-gray-500 focus:ring-red-500/30 focus:border-red-500/30'
                                            : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-400 focus:ring-red-500/30 focus:border-red-500/30'
                                    }`}
                                />
                            </div>
                        </div>

                        {/* Category Tabs */}
                        <div className={`flex gap-0.5 px-2.5 pb-2 overflow-x-auto scrollbar-thin`}>
                            <button
                                onClick={() => onCategoryChange('all')}
                                className={`px-2 py-1 rounded-md text-[10px] sm:text-[11px] font-semibold whitespace-nowrap transition-all ${
                                    selectedCategory === 'all'
                                        ? 'bg-red-500/15 text-red-400'
                                        : isDark
                                            ? 'text-gray-400 hover:bg-white/[0.06] hover:text-gray-300'
                                            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'
                                }`}
                            >
                                All
                            </button>
                            {categories.map(cat => {
                                const cfg = CATEGORY_CONFIG[cat] || CATEGORY_CONFIG.chat;
                                const count = models.filter(m => m.category === cat).length;
                                return (
                                    <button
                                        key={cat}
                                        onClick={() => onCategoryChange(cat)}
                                        className={`flex items-center gap-1 px-2 py-1 rounded-md text-[10px] sm:text-[11px] font-semibold whitespace-nowrap transition-all ${
                                            selectedCategory === cat
                                                ? `${cfg.bg} ${cfg.text}`
                                                : isDark
                                                    ? 'text-gray-400 hover:bg-white/[0.06] hover:text-gray-300'
                                                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'
                                        }`}
                                    >
                                        <span className="[&>svg]:w-3 [&>svg]:h-3">{cfg.icon}</span>
                                        {cfg.label}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Divider */}
                        <div className={`mx-2.5 border-t ${isDark ? 'border-white/[0.06]' : 'border-gray-100'}`} />

                        {/* Model List — scrollable */}
                        <div className="overflow-y-auto flex-1 p-1.5 scrollbar-thin" onWheel={(e) => e.stopPropagation()} onTouchMove={(e) => e.stopPropagation()}>
                            {Object.keys(groupedModels).length === 0 ? (
                                <div className={`text-center py-6 text-xs ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                    Tidak ada model ditemukan
                                </div>
                            ) : (
                                Object.entries(groupedModels).map(([tier, tierModels]) => (
                                    <div key={tier} className="mb-1 last:mb-0">
                                        <div className={`flex items-center gap-1.5 px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                                            <span className={`w-1 h-1 rounded-full ${TIER_CONFIG[tier]?.bg?.replace('/10', '/40') || 'bg-gray-400'}`} />
                                            {TIER_CONFIG[tier]?.label || tier}
                                            <span className={`ml-auto text-[9px] ${isDark ? 'text-gray-600' : 'text-gray-300'}`}>{tierModels.length}</span>
                                        </div>
                                        {tierModels.map(model => {
                                            const catCfg = CATEGORY_CONFIG[model.category] || CATEGORY_CONFIG.chat;
                                            const isSelected = model.id === selectedModel;
                                            return (
                                                <button
                                                    key={model.id}
                                                    onClick={() => { onSelect(model.id); setOpen(false); setSearch(''); }}
                                                    className={`w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-left transition-all duration-100 ${
                                                        isSelected
                                                            ? isDark
                                                                ? 'bg-red-500/10 text-white'
                                                                : 'bg-red-50 text-gray-900'
                                                            : isDark
                                                                ? 'text-gray-300 hover:bg-white/[0.05]'
                                                                : 'text-gray-700 hover:bg-gray-50'
                                                    }`}
                                                >
                                                    <span className={`w-5 h-5 rounded-md flex items-center justify-center text-white bg-gradient-to-br ${catCfg.gradient} flex-shrink-0 [&>svg]:w-2.5 [&>svg]:h-2.5`}>
                                                        {catCfg.icon}
                                                    </span>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="text-[11px] sm:text-xs font-medium truncate">{rebrandText(model.name || model.id)}</div>
                                                    </div>
                                                    {isSelected && (
                                                        <svg className="w-3.5 h-3.5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
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
    const [showSidebar, setShowSidebar] = useState(false); // hidden by default on mobile
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);

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
    const sendMessage = async () => {
        const text = input.trim();
        if (!text || isStreaming) return;

        const userMsg = { role: 'user', content: text };
        const newMessages = [...messages, userMsg];
        setMessages(newMessages);
        setInput('');
        setIsStreaming(true);

        // Reset textarea height
        if (inputRef.current) inputRef.current.style.height = 'auto';

        const apiMessages = newMessages.filter(m => m.content && m.content.trim().length > 0);

        try {
            const res = await fetch('/api/c/s', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    model: selectedModel,
                    messages: apiMessages,
                    conversation_id: currentConvId,
                }),
            });

            if (!res.ok) {
                const err = await res.json().catch(() => ({ message: 'Request failed' }));
                throw new Error(err.message || 'Chat gagal');
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let fullText = '';

            setMessages(prev => [...prev, { role: 'assistant', content: '' }]);

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                const chunk = decoder.decode(value, { stream: true });
                const lines = chunk.split('\n');

                for (const line of lines) {
                    const trimmed = line.trim();
                    // Skip empty lines and empty data lines
                    if (!trimmed || trimmed === 'data:' || trimmed === 'data: ') continue;
                    if (trimmed.startsWith('data: ') && trimmed !== 'data: [DONE]') {
                        try {
                            const json = JSON.parse(trimmed.slice(6));
                            const content = json.choices?.[0]?.delta?.content;
                            if (content) {
                                fullText += content;
                                setMessages(prev => {
                                    const updated = [...prev];
                                    updated[updated.length - 1] = { role: 'assistant', content: rebrandText(fullText) };
                                    return updated;
                                });
                            }
                        } catch {
                            // Skip malformed JSON
                        }
                    }
                }
            }

            loadConversations();
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
            <div className="flex-1 flex flex-col min-w-0">
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

                {/* Input Area — compact on mobile */}
                <div className={`px-2.5 sm:px-4 pb-3 sm:pb-4 pt-1.5 sm:pt-2 ${isDark ? 'bg-gradient-to-t from-gray-950 via-gray-950/80 to-transparent' : 'bg-gradient-to-t from-white via-white/80 to-transparent'}`}>
                    <div className="max-w-4xl mx-auto relative">
                        <div className={`relative rounded-xl sm:rounded-2xl border overflow-hidden transition-all ${
                            isDark
                                ? 'bg-gray-900/80 border-white/[0.08] focus-within:border-red-500/30 focus-within:ring-2 focus-within:ring-red-500/10'
                                : 'bg-white border-gray-300 shadow-sm focus-within:border-red-500/40 focus-within:ring-2 focus-within:ring-red-500/10'
                        }`}>
                            <textarea
                                ref={inputRef}
                                value={input}
                                onChange={handleInputChange}
                                onKeyDown={handleKeyDown}
                                placeholder={`Ketik pesan ke ${rebrandText(currentModel?.name || 'AI')}...`}
                                rows={1}
                                disabled={isStreaming}
                                className={`w-full px-3.5 sm:px-5 py-3 sm:py-3.5 pr-12 sm:pr-14 text-[13px] sm:text-[15px] resize-none focus:outline-none bg-transparent disabled:opacity-50 ${
                                    isDark ? 'text-white placeholder-gray-600' : 'text-gray-900 placeholder-gray-400'
                                }`}
                                style={{ minHeight: '46px', maxHeight: '200px' }}
                            />
                            <button
                                onClick={sendMessage}
                                disabled={!input.trim() || isStreaming}
                                className={`absolute right-2 sm:right-2.5 bottom-2 sm:bottom-2.5 w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-gradient-to-r ${catCfg.gradient} text-white flex items-center justify-center hover:brightness-110 disabled:opacity-30 disabled:cursor-not-allowed transition-all shadow-lg ${catCfg.glow}`}
                            >
                                {isStreaming ? (
                                    <svg className="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                ) : (
                                    <svg className="w-3.5 h-3.5 sm:w-4 sm:h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                        <line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" />
                                    </svg>
                                )}
                            </button>
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
