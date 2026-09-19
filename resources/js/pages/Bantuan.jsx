import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { apiRequest } from "../lib/api";
import {
    Button,
    FormField,
    InlineAlert,
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
    textAreaClass,
    validationErrors,
} from "../components/member/MemberUI";
import { useLocale } from "../contexts/LocaleContext";

const ARTICLES = [
    { id: "chat", category: "Chat", title: "Memulai percakapan AI", body: "Buka Chat AI, pilih model yang tersedia untuk akun Anda, lalu tulis kebutuhan dengan konteks dan hasil yang diharapkan. Riwayat tersimpan dapat dibuka kembali melalui menu Riwayat Chat." },
    { id: "usage", category: "Billing", title: "Memahami saldo dan pemakaian", body: "Halaman Token & Pemakaian memisahkan saldo wallet terukur, token media, dan penggunaan API. Pilih periode untuk melihat ringkasan dan rincian per model." },
    { id: "access", category: "Akun", title: "Akses, masa aktif, dan perangkat", body: "Akses fitur mengikuti status akun, masa aktif, izin, dan persetujuan perangkat. Jika akses ditolak, periksa ringkasan akun di Dashboard atau buat tiket dukungan." },
    { id: "media", category: "Media", title: "Menghasilkan gambar", body: "Pilih model yang benar-benar tersedia, tulis prompt, tentukan ukuran dan jumlah gambar. Biaya mengikuti harga model; kegagalan upstream ditampilkan sebagai status operasional, bukan hasil palsu." },
];

const EMPTY_TICKET = { subject: "", category: "account", priority: "normal", body: "" };
const EMPTY_FEEDBACK = { rating: "5", category: "general", message: "" };

function ArticleLibrary() {
    const { t } = useLocale();
    const [query, setQuery] = useState("");
    const [openId, setOpenId] = useState(null);
    const filtered = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase("id-ID");
        if (!needle) return ARTICLES;
        return ARTICLES.filter((article) => `${article.title} ${article.category} ${article.body}`.toLocaleLowerCase("id-ID").includes(needle));
    }, [query]);

    return (
        <Panel className="p-4">
            <SectionHeader title={t("Panduan singkat")} description={t("Jawaban praktis untuk alur anggota yang tersedia.")} />
            <label className="mt-3 block">
                <span className="sr-only">{t("Cari artikel bantuan")}</span>
                <input className={controlClass} type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={t("Cari panduan…")} />
            </label>
            <div className="mt-3 space-y-2">
                {filtered.length === 0 ? (
                    <StatePanel title={t("Artikel tidak ditemukan")} description={`${t("Tidak ada panduan yang cocok dengan")} “${query.trim()}”.`} compact />
                ) : filtered.map((article) => {
                    const open = openId === article.id;
                    return (
                        <article key={article.id} className="rounded-lg border border-slate-200 dark:border-white/[0.08]">
                            <button type="button" onClick={() => setOpenId(open ? null : article.id)} aria-expanded={open} className="flex w-full items-center justify-between gap-3 p-3 text-left">
                                <span className="min-w-0"><span className="block text-[10px] font-semibold uppercase tracking-wide text-red-500">{t(article.category)}</span><span className="mt-0.5 block text-[12px] font-semibold text-slate-900 dark:text-white">{t(article.title)}</span></span>
                                <svg className={`h-4 w-4 shrink-0 text-slate-400 transition ${open ? "rotate-180" : ""}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="m6 9 6 6 6-6" /></svg>
                            </button>
                            {open && <p className="border-t border-slate-200 px-3 py-3 text-[12px] leading-5 text-slate-600 dark:border-white/[0.08] dark:text-slate-300">{t(article.body)}</p>}
                        </article>
                    );
                })}
            </div>
        </Panel>
    );
}

function FeedbackForm() {
    const { t } = useLocale();
    const [form, setForm] = useState(EMPTY_FEEDBACK);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});
    const [success, setSuccess] = useState(null);

    const submit = async (event) => {
        event.preventDefault();
        setSubmitting(true); setError(null); setErrors({}); setSuccess(null);
        try {
            await apiRequest("/api/feedback", { method: "POST", body: { ...form, rating: Number(form.rating) } });
            setForm(EMPTY_FEEDBACK);
            setSuccess(t("Masukan Anda sudah tercatat dan akan ditinjau tim XSuper.ai."));
        } catch (requestError) {
            setError(requestError);
            setErrors(validationErrors(requestError));
        } finally { setSubmitting(false); }
    };

    return (
        <Panel className="p-4">
            <SectionHeader title={t("Kirim masukan")} description={t("Nilai pengalaman Anda atau laporkan bagian yang perlu diperbaiki.")} />
            {success && <div className="mt-3"><InlineAlert tone="success">{success}</InlineAlert></div>}
            {error && <div className="mt-3"><InlineAlert tone="error">{errorMessage(error)}</InlineAlert></div>}
            <form className="mt-4 space-y-3" onSubmit={submit}>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t("Penilaian")} error={errors.rating} required><select className={controlClass} value={form.rating} onChange={(event) => setForm({ ...form, rating: event.target.value })}>{[5,4,3,2,1].map((rating) => <option key={rating} value={rating}>{rating} / 5</option>)}</select></FormField>
                    <FormField label={t("Kategori")} error={errors.category} required><select className={controlClass} value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}><option value="general">{t("Umum")}</option><option value="chat">{t("Chat")}</option><option value="billing">{t("Billing")}</option><option value="media">{t("Media")}</option><option value="access">{t("Akses akun")}</option></select></FormField>
                </div>
                <FormField label={t("Masukan")} error={errors.message} hint={t("Minimal 10 karakter.")} required><textarea className={textAreaClass} value={form.message} onChange={(event) => setForm({ ...form, message: event.target.value })} maxLength={5000} placeholder={t("Ceritakan pengalaman atau saran Anda…")} /></FormField>
                <Button type="submit" disabled={submitting}>{submitting ? t("Mengirim…") : t("Kirim masukan")}</Button>
            </form>
        </Panel>
    );
}

function NewTicketForm({ onCreated }) {
    const { t } = useLocale();
    const [form, setForm] = useState(EMPTY_TICKET);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});

    const submit = async (event) => {
        event.preventDefault();
        setSubmitting(true); setError(null); setErrors({});
        try {
            const response = await apiRequest("/api/support/tickets", { method: "POST", body: form });
            setForm(EMPTY_TICKET);
            onCreated(response?.data);
        } catch (requestError) {
            setError(requestError);
            setErrors(validationErrors(requestError));
        } finally { setSubmitting(false); }
    };

    return (
        <Panel className="p-4">
            <SectionHeader title={t("Buat tiket dukungan")} description={t("Gunakan tiket untuk masalah akun atau kendala teknis yang perlu ditindaklanjuti.")} />
            {error && <div className="mt-3"><InlineAlert tone="error">{errorMessage(error)}</InlineAlert></div>}
            <form className="mt-4 space-y-3" onSubmit={submit}>
                <FormField label={t("Subjek")} error={errors.subject} required><input className={controlClass} value={form.subject} onChange={(event) => setForm({ ...form, subject: event.target.value })} maxLength={160} placeholder={t("Ringkas masalah Anda")} /></FormField>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t("Kategori")} error={errors.category} required><select className={controlClass} value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })}><option value="account">{t("Akun")}</option><option value="billing">{t("Billing")}</option><option value="chat">{t("Chat")}</option><option value="media">{t("Media")}</option><option value="api">{t("API")}</option><option value="other">{t("Lainnya")}</option></select></FormField>
                    <FormField label={t("Prioritas")} error={errors.priority}><select className={controlClass} value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })}><option value="low">{t("Rendah")}</option><option value="normal">{t("Normal")}</option><option value="high">{t("Tinggi")}</option><option value="urgent">{t("Mendesak")}</option></select></FormField>
                </div>
                <FormField label={t("Detail")} error={errors.body} required><textarea className={textAreaClass} value={form.body} onChange={(event) => setForm({ ...form, body: event.target.value })} maxLength={10000} placeholder={t("Jelaskan kendala, waktu kejadian, dan hasil yang diharapkan…")} /></FormField>
                <Button type="submit" disabled={submitting}>{submitting ? t("Membuat…") : t("Buat tiket")}</Button>
            </form>
        </Panel>
    );
}

function TicketThread({ ticket, loading, error, reply, setReply, replying, replyError, replyErrors, onReply, onRetry }) {
    const { t } = useLocale();
    if (loading) return <div className="flex min-h-48 items-center justify-center"><Spinner label={t("Memuat percakapan tiket")} /></div>;
    if (error) return <StatePanel type="error" title={t("Tiket tidak dapat dibuka")} description={errorMessage(error)} action={<Button variant="secondary" onClick={onRetry}>{t("Coba lagi")}</Button>} />;
    if (!ticket) return <StatePanel title={t("Pilih tiket")} description={t("Buka tiket untuk melihat percakapan dan membalas.")} />;
    const closed = ["resolved", "closed"].includes(ticket.status);
    return (
        <div>
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-3 dark:border-white/[0.08]">
                <div><h3 className="text-sm font-semibold text-slate-950 dark:text-white">{ticket.subject}</h3><p className="mt-1 text-[11px] text-slate-500">#{ticket.id} · {ticket.category} · {t("diperbarui")} {formatLocalDate(ticket.updated_at)}</p></div>
                <StatusBadge value={ticket.status} />
            </div>
            <div className="mt-4 max-h-80 space-y-3 overflow-y-auto pr-1">
                {(ticket.messages || []).map((message) => (
                    <article key={message.id} className={`rounded-lg border p-3 ${message.is_staff ? "border-blue-200 bg-blue-50/60 dark:border-blue-400/20 dark:bg-blue-400/[0.07]" : "border-slate-200 bg-slate-50 dark:border-white/[0.07] dark:bg-white/[0.025]"}`}>
                        <div className="flex items-center justify-between gap-3 text-[10px] text-slate-500"><span className="font-semibold uppercase tracking-wide">{message.is_staff ? t("Tim XSuper.ai") : t("Anda")}</span><span>{formatLocalDate(message.created_at)}</span></div>
                        <p className="mt-2 whitespace-pre-wrap break-words text-[12px] leading-5 text-slate-700 dark:text-slate-200">{message.body}</p>
                    </article>
                ))}
            </div>
            {closed ? (
                <div className="mt-4"><InlineAlert tone="info">{t("Tiket ini")} {ticket.status === "closed" ? t("ditutup") : t("diselesaikan")}; {t("balasan baru tidak dapat dikirim.")}</InlineAlert></div>
            ) : (
                <form className="mt-4 space-y-2" onSubmit={onReply}>
                    {replyError && <InlineAlert tone="error">{errorMessage(replyError)}</InlineAlert>}
                    <FormField label={t("Balasan")} error={replyErrors.body} required><textarea className={textAreaClass} value={reply} onChange={(event) => setReply(event.target.value)} maxLength={10000} placeholder={t("Tulis informasi tambahan…")} /></FormField>
                    <Button type="submit" disabled={replying}>{replying ? t("Mengirim…") : t("Kirim balasan")}</Button>
                </form>
            )}
        </div>
    );
}

export default function Bantuan() {
    const { t } = useLocale();
    const [searchParams] = useSearchParams();
    const requestedTicket = searchParams.get("ticket");
    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedId, setSelectedId] = useState(null);
    const [thread, setThread] = useState(null);
    const [threadLoading, setThreadLoading] = useState(false);
    const [threadError, setThreadError] = useState(null);
    const [reply, setReply] = useState("");
    const [replying, setReplying] = useState(false);
    const [replyError, setReplyError] = useState(null);
    const [replyErrors, setReplyErrors] = useState({});
    const [notice, setNotice] = useState(null);

    const loadTickets = useCallback(async () => {
        setLoading(true); setError(null);
        try { const response = await apiRequest("/api/support/tickets?per_page=50"); setTickets(Array.isArray(response?.data) ? response.data : []); }
        catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { loadTickets(); }, [loadTickets]);

    const openTicket = useCallback(async (id) => {
        setSelectedId(id); setThread(null); setThreadLoading(true); setThreadError(null); setReplyError(null);
        try { const response = await apiRequest(`/api/support/tickets/${id}`); setThread(response?.data || null); }
        catch (requestError) { setThreadError(requestError); }
        finally { setThreadLoading(false); }
    }, []);

    useEffect(() => {
        if (requestedTicket) openTicket(requestedTicket);
    }, [requestedTicket, openTicket]);

    const onCreated = (ticket) => {
        setNotice(t("Tiket berhasil dibuat. Tim dukungan dapat menindaklanjutinya melalui percakapan tiket."));
        loadTickets();
        if (ticket?.id) openTicket(ticket.id);
    };

    const submitReply = async (event) => {
        event.preventDefault(); setReplying(true); setReplyError(null); setReplyErrors({});
        try {
            const response = await apiRequest(`/api/support/tickets/${selectedId}/replies`, { method: "POST", body: { body: reply } });
            setThread(current => current?.id === response.ticket.id
                ? { ...current, ...response.ticket, messages: [...current.messages, response.data] }
                : current);
            setReply(""); setNotice(t("Balasan berhasil dikirim.")); loadTickets();
        } catch (requestError) { setReplyError(requestError); setReplyErrors(validationErrors(requestError)); }
        finally { setReplying(false); }
    };

    return (
        <MemberPage>
            <PageHeader eyebrow={t("Bantuan")} title={t("Pusat bantuan")} description={t("Baca panduan, kirim masukan, atau lanjutkan masalah melalui tiket dukungan.")} actions={<Button variant="secondary" onClick={loadTickets} disabled={loading}>{loading ? t("Memuat…") : t("Muat ulang tiket")}</Button>} />
            {notice && <InlineAlert tone="success" action={<button type="button" onClick={() => setNotice(null)} className="font-semibold">{t("Tutup")}</button>}>{notice}</InlineAlert>}
            <div className="grid gap-5 xl:grid-cols-2"><ArticleLibrary /><FeedbackForm /></div>
            <NewTicketForm onCreated={onCreated} />
            <Panel className="overflow-hidden">
                <div className="border-b border-slate-200 p-4 dark:border-white/[0.08]"><SectionHeader title={t("Tiket Anda")} description={t("Status dan percakapan dukungan terbaru.")} /></div>
                <div className="grid lg:grid-cols-[320px_minmax(0,1fr)]">
                    <div className="border-b border-slate-200 lg:border-b-0 lg:border-r dark:border-white/[0.08]">
                        {loading ? <div className="flex min-h-48 items-center justify-center"><Spinner label={t("Memuat tiket")} /></div> : error ? <div className="p-4"><StatePanel type="error" title={t("Tiket tidak dapat dimuat")} description={errorMessage(error)} action={<Button variant="secondary" onClick={loadTickets}>{t("Coba lagi")}</Button>} /></div> : tickets.length === 0 ? <div className="p-4"><StatePanel title={t("Belum ada tiket")} description={t("Tiket baru yang Anda buat akan tampil di sini.")} compact /></div> : <div className="max-h-[520px] divide-y divide-slate-100 overflow-y-auto dark:divide-white/[0.06]">{tickets.map((ticket) => <button key={ticket.id} type="button" onClick={() => openTicket(ticket.id)} className={`w-full p-3 text-left transition ${selectedId === ticket.id ? "bg-red-50/70 dark:bg-red-500/[0.08]" : "hover:bg-slate-50 dark:hover:bg-white/[0.025]"}`}><div className="flex items-start justify-between gap-2"><span className="truncate text-[12px] font-semibold text-slate-900 dark:text-white">{ticket.subject}</span><StatusBadge value={ticket.status} /></div><p className="mt-1 text-[10px] text-slate-500">#{ticket.id} · {ticket.messages_count || 0} {t("pesan")} · {formatLocalDate(ticket.updated_at)}</p></button>)}</div>}
                    </div>
                    <div className="p-4"><TicketThread ticket={thread} loading={threadLoading} error={threadError} reply={reply} setReply={setReply} replying={replying} replyError={replyError} replyErrors={replyErrors} onReply={submitReply} onRetry={() => selectedId && openTicket(selectedId)} /></div>
                </div>
            </Panel>
        </MemberPage>
    );
}
