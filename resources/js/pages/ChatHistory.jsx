import React, { useCallback, useEffect, useMemo, useState } from "react";
import { apiRequest } from "../lib/api";
import {
    Button,
    MemberPage,
    PageHeader,
    Panel,
    SectionHeader,
    Spinner,
    StatePanel,
    StatusBadge,
    controlClass,
    errorMessage,
    formatLocalDate,
} from "../components/member/MemberUI";

function ConversationRow({ conversation, active, onOpen, onDelete, deleting }) {
    return (
        <article
            className={`group flex items-start gap-2 border-b border-slate-100 p-3 last:border-0 dark:border-white/[0.06] ${active ? "bg-red-50/70 dark:bg-red-500/[0.08]" : "hover:bg-slate-50 dark:hover:bg-white/[0.025]"}`}
        >
            <button type="button" onClick={onOpen} className="min-w-0 flex-1 text-left focus:outline-none">
                <div className="flex items-start justify-between gap-2">
                    <h3 className="truncate text-[13px] font-semibold text-slate-900 dark:text-white">
                        {conversation.title || "Percakapan tanpa judul"}
                    </h3>
                    {active && <StatusBadge value="active" label="Dibuka" />}
                </div>
                <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    {Number(conversation.message_count || 0).toLocaleString("id-ID")} pesan · {formatLocalDate(conversation.last_message)}
                </p>
            </button>
            <button
                type="button"
                onClick={onDelete}
                disabled={deleting}
                aria-label={`Hapus ${conversation.title || "percakapan"}`}
                className="rounded-md p-1.5 text-red-500/70 transition hover:bg-red-50 hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/20 disabled:opacity-50 dark:text-red-300/70 dark:hover:bg-red-500/10 dark:hover:text-red-200"
            >
                {deleting ? (
                    <span className="block h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-red-500" />
                ) : (
                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9 14.4 19m-4.8 0L9.26 9m9.97-3.44c.34.05.67.1 1 .16M19.23 5.56 18.16 20.1A2.25 2.25 0 0 1 15.92 22H8.08a2.25 2.25 0 0 1-2.24-1.9L4.77 5.56m14.46 0a48 48 0 0 0-3.48-.4m-10.98.4c.33-.05.66-.11 1-.16m0 0a48 48 0 0 1 3.48-.4m6.5.16V4.15c0-1.18-.91-2.16-2.09-2.2a52 52 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2V5m7.5.16a49 49 0 0 0-7.5 0" />
                    </svg>
                )}
            </button>
        </article>
    );
}

function ConversationDetail({ conversation, messages, loading, error, onRetry }) {
    if (!conversation) {
        return (
            <div className="flex min-h-72 items-center justify-center p-5">
                <StatePanel
                    title="Pilih percakapan"
                    description="Buka salah satu riwayat untuk membaca pesan lengkapnya."
                    compact
                />
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="border-b border-slate-200 p-4 dark:border-white/[0.08]">
                <h2 className="truncate text-sm font-semibold text-slate-950 dark:text-white">
                    {conversation.title || "Percakapan tanpa judul"}
                </h2>
                <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                    Dimulai {formatLocalDate(conversation.started_at)}
                </p>
            </div>
            <div className="max-h-[620px] flex-1 space-y-3 overflow-y-auto p-4">
                {loading ? (
                    <div className="flex min-h-48 items-center justify-center"><Spinner label="Memuat pesan" /></div>
                ) : error ? (
                    <StatePanel
                        type="error"
                        title="Percakapan tidak dapat dibuka"
                        description={errorMessage(error, "Pesan percakapan gagal dimuat.")}
                        action={<Button variant="secondary" onClick={onRetry}>Coba lagi</Button>}
                    />
                ) : messages.length === 0 ? (
                    <StatePanel title="Percakapan kosong" description="Tidak ada pesan yang tersimpan pada percakapan ini." />
                ) : (
                    messages.map((message, index) => {
                        const member = message.role === "user";
                        return (
                            <article key={`${message.created_at || "message"}-${index}`} className={`flex ${member ? "justify-end" : "justify-start"}`}>
                                <div className={`max-w-[88%] rounded-xl px-3 py-2.5 ${member ? "bg-red-600 text-white dark:bg-red-500" : "border border-slate-200 bg-slate-50 text-slate-800 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-slate-200"}`}>
                                    <div className="mb-1 flex flex-wrap items-center gap-2 text-[10px] font-semibold uppercase tracking-wide opacity-70">
                                        <span>{member ? "Anda" : "Asisten"}</span>
                                        {message.model && <span className="normal-case tracking-normal">{message.model}</span>}
                                    </div>
                                    <p className="whitespace-pre-wrap break-words text-[13px] leading-5">{message.content}</p>
                                    <p className={`mt-1.5 text-right text-[10px] ${member ? "text-white/70" : "text-slate-400"}`}>
                                        {formatLocalDate(message.created_at)}
                                    </p>
                                </div>
                            </article>
                        );
                    })
                )}
            </div>
        </div>
    );
}

export default function ChatHistory() {
    const [conversations, setConversations] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [query, setQuery] = useState("");
    const [loading, setLoading] = useState(true);
    const [listError, setListError] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [actionError, setActionError] = useState(null);

    const loadConversations = useCallback(async () => {
        setLoading(true);
        setListError(null);
        try {
            const data = await apiRequest("/api/c/h");
            setConversations(Array.isArray(data?.conversations) ? data.conversations : []);
        } catch (error) {
            setListError(error);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadConversations();
    }, [loadConversations]);

    const openConversation = useCallback(async (conversationId) => {
        setSelectedId(conversationId);
        setDetailLoading(true);
        setDetailError(null);
        setMessages([]);
        try {
            const data = await apiRequest(`/api/c/h/${encodeURIComponent(conversationId)}`);
            setMessages(Array.isArray(data?.messages) ? data.messages : []);
        } catch (error) {
            setDetailError(error);
        } finally {
            setDetailLoading(false);
        }
    }, []);

    const deleteConversation = async (conversation) => {
        if (!window.confirm(`Hapus riwayat “${conversation.title || "Percakapan tanpa judul"}”? Tindakan ini tidak dapat dibatalkan.`)) return;
        setDeletingId(conversation.conversation_id);
        setActionError(null);
        try {
            await apiRequest(`/api/c/h/${encodeURIComponent(conversation.conversation_id)}`, { method: "DELETE" });
            setConversations((current) => current.filter((item) => item.conversation_id !== conversation.conversation_id));
            if (selectedId === conversation.conversation_id) {
                setSelectedId(null);
                setMessages([]);
                setDetailError(null);
            }
        } catch (error) {
            setActionError(error);
        } finally {
            setDeletingId(null);
        }
    };

    const filtered = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase("id-ID");
        if (!needle) return conversations;
        return conversations.filter((conversation) => String(conversation.title || "").toLocaleLowerCase("id-ID").includes(needle));
    }, [conversations, query]);

    const selected = conversations.find((conversation) => conversation.conversation_id === selectedId) || null;

    return (
        <MemberPage>
            <PageHeader
                eyebrow="Workspace"
                title="Riwayat chat"
                description="Cari, buka, dan hapus percakapan yang tersimpan pada akun Anda."
                actions={<Button variant="secondary" onClick={loadConversations} disabled={loading}>{loading ? "Memuat…" : "Muat ulang"}</Button>}
            />

            {actionError && (
                <StatePanel
                    type="error"
                    compact
                    title="Percakapan belum terhapus"
                    description={errorMessage(actionError)}
                    action={<Button variant="ghost" onClick={() => setActionError(null)}>Tutup</Button>}
                />
            )}

            <Panel className="overflow-hidden">
                <div className="grid min-h-[520px] lg:grid-cols-[340px_minmax(0,1fr)]">
                    <section className="border-b border-slate-200 lg:border-b-0 lg:border-r dark:border-white/[0.08]">
                        <div className="space-y-3 border-b border-slate-200 p-4 dark:border-white/[0.08]">
                            <SectionHeader title="Percakapan" description={`${conversations.length.toLocaleString("id-ID")} riwayat tersimpan`} />
                            <label className="relative block">
                                <span className="sr-only">Cari percakapan</span>
                                <svg className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.7-3.7" /></svg>
                                <input className={`${controlClass} pl-9`} value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari judul chat…" type="search" />
                            </label>
                        </div>
                        <div className="max-h-[620px] overflow-y-auto">
                            {loading ? (
                                <div className="flex min-h-48 items-center justify-center"><Spinner label="Memuat riwayat" /></div>
                            ) : listError ? (
                                <div className="p-4"><StatePanel type="error" title="Riwayat tidak dapat dimuat" description={errorMessage(listError)} action={<Button variant="secondary" onClick={loadConversations}>Coba lagi</Button>} /></div>
                            ) : conversations.length === 0 ? (
                                <div className="p-4"><StatePanel title="Belum ada riwayat" description="Percakapan baru akan tampil di sini setelah Anda menggunakan Chat AI." /></div>
                            ) : filtered.length === 0 ? (
                                <div className="p-4"><StatePanel title="Tidak ada hasil" description={`Tidak ada judul yang cocok dengan “${query.trim()}”.`} compact /></div>
                            ) : (
                                filtered.map((conversation) => (
                                    <ConversationRow
                                        key={conversation.conversation_id}
                                        conversation={conversation}
                                        active={selectedId === conversation.conversation_id}
                                        deleting={deletingId === conversation.conversation_id}
                                        onOpen={() => openConversation(conversation.conversation_id)}
                                        onDelete={() => deleteConversation(conversation)}
                                    />
                                ))
                            )}
                        </div>
                    </section>
                    <ConversationDetail
                        conversation={selected}
                        messages={messages}
                        loading={detailLoading}
                        error={detailError}
                        onRetry={() => selectedId && openConversation(selectedId)}
                    />
                </div>
            </Panel>
        </MemberPage>
    );
}
