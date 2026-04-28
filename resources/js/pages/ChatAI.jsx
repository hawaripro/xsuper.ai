import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';

// ============================================
// Markdown-like renderer (lightweight)
// ============================================
function formatContent(text) {
    if (!text) return '';
    let html = text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    // Code blocks with language
    html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (_, lang, code) =>
        `<pre class="chat-code-block"><div class="chat-code-header">${lang || 'code'}</div><code>${code.trim()}</code></pre>`
    );
    html = html.replace(/```([\s\S]*?)```/g, '<pre class="chat-code-block"><code>$1</code></pre>');
    // Inline code
    html = html.replace(/`([^`]+)`/g, '<code class="chat-inline-code">$1</code>');
    // Bold
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong class="text-white font-semibold">$1</strong>');
    // Italic
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    // Line breaks (not inside pre)
    html = html.replace(/\n/g, '<br>');
    return html;
}

// ============================================
// Message Component
// ============================================
function ChatMessage({ message, userName }) {
    const isUser = message.role === 'user';

    return (
        <div className={`flex gap-3 max-w-4xl mx-auto w-full animate-msg-in ${isUser ? '' : ''}`}>
            {/* Avatar */}
            <div className={`w-8 h-8 rounded-xl flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5 ${
                isUser
                    ? 'bg-white/[0.1] text-gray-300'
                    : 'bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/20'
            }`}>
                {isUser ? (userName?.[0]?.toUpperCase() || 'U') : 'AI'}
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
                <div className="text-[11px] font-bold uppercase tracking-[0.08em] text-gray-500 mb-1.5">
                    {isUser ? (userName || 'You') : 'UltrAI'}
                </div>
                <div
                    className="text-[15px] leading-7 text-gray-300 chat-content"
                    dangerouslySetInnerHTML={{ __html: formatContent(message.content) }}
                />
            </div>
        </div>
    );
}

// ============================================
// Typing Indicator
// ============================================
function TypingIndicator() {
    return (
        <div className="flex gap-3 max-w-4xl mx-auto w-full animate-msg-in">
            <div className="w-8 h-8 rounded-xl bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/20 flex items-center justify-center text-xs font-bold flex-shrink-0">
                AI
            </div>
            <div className="flex-1">
                <div className="text-[11px] font-bold uppercase tracking-[0.08em] text-gray-500 mb-1.5">UltrAI</div>
                <div className="flex gap-1.5 py-2">
                    <span className="w-2 h-2 rounded-full bg-gray-500 animate-bounce" style={{ animationDelay: '0ms' }} />
                    <span className="w-2 h-2 rounded-full bg-gray-500 animate-bounce" style={{ animationDelay: '150ms' }} />
                    <span className="w-2 h-2 rounded-full bg-gray-500 animate-bounce" style={{ animationDelay: '300ms' }} />
                </div>
            </div>
        </div>
    );
}

// ============================================
// Conversation Item
// ============================================
function ConversationItem({ conv, isActive, onClick, onDelete }) {
    return (
        <div
            onClick={onClick}
            className={`group flex items-center gap-2 px-3 py-2.5 rounded-xl cursor-pointer transition-all duration-150 ${
                isActive
                    ? 'bg-red-500/10 text-red-400 border border-red-500/15'
                    : 'text-gray-400 hover:bg-white/[0.04] hover:text-gray-300'
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
// Main Chat Component
// ============================================
export default function ChatAI() {
    const { user } = useAuth();
    const [conversations, setConversations] = useState([]);
    const [currentConvId, setCurrentConvId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [models, setModels] = useState([]);
    const [selectedModel, setSelectedModel] = useState('auto');
    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [showSidebar, setShowSidebar] = useState(false);
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);
    const streamingContentRef = useRef('');

    const getCsrfToken = () => {
        return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');
    };

    // Auto-scroll
    const scrollToBottom = useCallback(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, []);

    useEffect(() => {
        scrollToBottom();
    }, [messages, scrollToBottom]);

    // Load models
    useEffect(() => {
        const loadModels = async () => {
            try {
                const res = await fetch('/api/chat/models', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    setModels(data.models || []);
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
            const res = await fetch('/api/chat/history', {
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
        setShowSidebar(false);
        inputRef.current?.focus();
    };

    // Load conversation
    const loadConversation = async (convId) => {
        setCurrentConvId(convId);
        setShowSidebar(false);
        try {
            const res = await fetch(`/api/chat/history/${convId}`, {
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
            await fetch(`/api/chat/history/${convId}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
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
        streamingContentRef.current = '';

        try {
            const res = await fetch('/api/chat/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    model: selectedModel,
                    messages: newMessages,
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

            // Add empty assistant message
            setMessages(prev => [...prev, { role: 'assistant', content: '' }]);

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                const chunk = decoder.decode(value, { stream: true });
                const lines = chunk.split('\n');

                for (const line of lines) {
                    if (line.startsWith('data: ') && line !== 'data: [DONE]') {
                        try {
                            const json = JSON.parse(line.slice(6));
                            const content = json.choices?.[0]?.delta?.content;
                            if (content) {
                                fullText += content;
                                // Update last message
                                setMessages(prev => {
                                    const updated = [...prev];
                                    updated[updated.length - 1] = { role: 'assistant', content: fullText };
                                    return updated;
                                });
                            }
                        } catch {}
                    }
                }
            }

            loadConversations();
        } catch (err) {
            setMessages(prev => [
                ...prev,
                { role: 'assistant', content: `⚠️ Error: ${err.message}` }
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

    // Auto-resize textarea
    const handleInputChange = (e) => {
        setInput(e.target.value);
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 200) + 'px';
    };

    return (
        <div className="flex h-[calc(100vh-4rem)] relative">
            {/* Mobile sidebar overlay */}
            {showSidebar && (
                <div
                    className="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 lg:hidden"
                    onClick={() => setShowSidebar(false)}
                />
            )}

            {/* ===== Chat Sidebar ===== */}
            <div className={`
                fixed inset-y-0 left-0 z-40 w-[280px] flex flex-col
                bg-gray-900/95 backdrop-blur-2xl border-r border-white/[0.06]
                transform transition-transform duration-300
                lg:static lg:translate-x-0 lg:z-auto
                ${showSidebar ? 'translate-x-0' : '-translate-x-full'}
            `}>
                {/* Sidebar Header */}
                <div className="p-3 border-b border-white/[0.06]">
                    <button
                        onClick={newConversation}
                        className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-red-500/15 to-red-500/5 border border-red-500/15 text-red-400 text-sm font-semibold hover:from-red-500/20 hover:to-red-500/10 transition-all"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                        Chat Baru
                    </button>
                </div>

                {/* Conversations List */}
                <div className="flex-1 overflow-y-auto p-2 space-y-0.5 scrollbar-thin">
                    {conversations.length === 0 ? (
                        <div className="text-center py-8 text-gray-600 text-sm">
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
                            />
                        ))
                    )}
                </div>

                {/* Model info */}
                <div className="p-3 border-t border-white/[0.06]">
                    <div className="px-3 py-2 rounded-xl bg-white/[0.03] text-xs text-gray-500">
                        <span className="text-gray-400 font-medium">{models.length || '183+'}</span> AI models tersedia
                    </div>
                </div>
            </div>

            {/* ===== Main Chat Area ===== */}
            <div className="flex-1 flex flex-col min-w-0">
                {/* Chat Header */}
                <div className="flex items-center justify-between px-4 py-3 border-b border-white/[0.06] bg-gray-950/50 backdrop-blur-xl">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setShowSidebar(!showSidebar)}
                            className="lg:hidden p-2 rounded-xl text-gray-400 hover:text-white hover:bg-white/10 transition-colors"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" /></svg>
                        </button>
                        <select
                            value={selectedModel}
                            onChange={(e) => setSelectedModel(e.target.value)}
                            className="px-3 py-2 rounded-xl bg-white/[0.05] border border-white/[0.08] text-sm text-gray-300 font-medium focus:outline-none focus:border-red-500/50 transition-all min-w-[180px] cursor-pointer"
                        >
                            <option value="auto" className="bg-gray-900">auto (recommended)</option>
                            {models.map((m) => (
                                <option key={m.id || m} value={m.id || m} className="bg-gray-900">
                                    {m.id || m}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-[11px] font-medium text-emerald-400">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                            Connected
                        </span>
                    </div>
                </div>

                {/* Messages Area */}
                <div className="flex-1 overflow-y-auto px-4 py-6 space-y-6 scrollbar-thin">
                    {messages.length === 0 ? (
                        /* Welcome Screen */
                        <div className="flex flex-col items-center justify-center h-full text-center px-4">
                            <div className="mb-6">
                                <div className="text-4xl font-black tracking-tight mb-2">
                                    <span className="text-white">Ultr</span>
                                    <span className="bg-gradient-to-r from-red-500 to-red-400 bg-clip-text text-transparent">AI</span>
                                </div>
                                <div className="text-xl font-bold text-white mb-2">Halo! Ada yang bisa saya bantu?</div>
                                <p className="text-gray-500 text-sm max-w-md">
                                    Pilih model AI dan mulai percakapan. Saya bisa membantu coding, analisis, penulisan, dan banyak lagi.
                                </p>
                            </div>

                            {/* Quick prompts */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-w-lg w-full">
                                {[
                                    { icon: '💻', text: 'Bantu saya menulis kode' },
                                    { icon: '📝', text: 'Buatkan artikel blog' },
                                    { icon: '🔍', text: 'Analisis data ini' },
                                    { icon: '🎨', text: 'Ide desain UI/UX' },
                                ].map((prompt, i) => (
                                    <button
                                        key={i}
                                        onClick={() => { setInput(prompt.text); inputRef.current?.focus(); }}
                                        className="flex items-center gap-3 px-4 py-3 rounded-xl bg-white/[0.03] border border-white/[0.06] text-sm text-gray-400 hover:text-white hover:bg-white/[0.06] hover:border-white/[0.1] transition-all text-left"
                                    >
                                        <span className="text-lg">{prompt.icon}</span>
                                        {prompt.text}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <>
                            {messages.map((msg, i) => (
                                <ChatMessage key={i} message={msg} userName={user?.name} />
                            ))}
                            {isStreaming && messages[messages.length - 1]?.role !== 'assistant' && (
                                <TypingIndicator />
                            )}
                        </>
                    )}
                    <div ref={messagesEndRef} />
                </div>

                {/* Input Area */}
                <div className="px-4 pb-4 pt-2 bg-gradient-to-t from-gray-950 to-transparent">
                    <div className="max-w-4xl mx-auto relative">
                        <textarea
                            ref={inputRef}
                            value={input}
                            onChange={handleInputChange}
                            onKeyDown={handleKeyDown}
                            placeholder="Ketik pesan..."
                            rows={1}
                            disabled={isStreaming}
                            className="w-full px-5 py-3.5 pr-14 rounded-2xl bg-gray-900/80 border border-white/[0.08] text-white placeholder-gray-600 text-[15px] resize-none focus:outline-none focus:border-red-500/40 focus:ring-2 focus:ring-red-500/10 transition-all backdrop-blur-xl disabled:opacity-50"
                            style={{ minHeight: '52px', maxHeight: '200px' }}
                        />
                        <button
                            onClick={sendMessage}
                            disabled={!input.trim() || isStreaming}
                            className="absolute right-2.5 bottom-2.5 w-10 h-10 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white flex items-center justify-center hover:brightness-110 disabled:opacity-30 disabled:cursor-not-allowed transition-all shadow-lg shadow-red-500/25"
                        >
                            {isStreaming ? (
                                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                            ) : (
                                <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" />
                                </svg>
                            )}
                        </button>
                    </div>
                    <p className="text-center text-[11px] text-gray-600 mt-2">
                        UltrAI dapat membuat kesalahan. Periksa informasi penting.
                    </p>
                </div>
            </div>
        </div>
    );
}
