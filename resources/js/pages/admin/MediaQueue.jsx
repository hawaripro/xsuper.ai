import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import { LoadingState, ErrorState } from "../../components/dashboard/AsyncState";
import StatusBadge from "../../components/dashboard/StatusBadge";
import DataTable from "../../components/dashboard/DataTable";
import { apiRequest, formatCurrency, formatDateTime } from "../../lib/api";

const queueStatuses = ["", "pending", "processing", "completed", "failed"];

function Icon({ name, className = "h-4 w-4" }) {
    const common = { className, fill: "none", stroke: "currentColor", strokeWidth: 2, viewBox: "0 0 24 24", strokeLinecap: "round", strokeLinejoin: "round", "aria-hidden": true };
    switch (name) {
        case "sync":
            return (<svg {...common}><path d="M23 4v6h-6M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>);
        case "activity":
            return (<svg {...common}><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>);
        case "back":
            return (<svg {...common}><path d="M19 12H5m6-6-6 6 6 6" /></svg>);
        default:
            return null;
    }
}

/**
 * The global media queue used to hide behind a tab on the catalogue page.
 * It is cross-provider operational tooling, so it now owns a URL; the
 * provider list stays a pure card grid.
 */
export default function MediaQueue() {
    const { t, localizedPath } = useLocale();
    const [queue, setQueue] = useState({ data: null, loading: true, error: "" });
    const [queueType, setQueueType] = useState("images");
    const [queueStatus, setQueueStatus] = useState("");
    const [cookies, setCookies] = useState({ has: false, updatedAt: null, text: "", busy: false, msg: "", error: false });

    const loadQueue = useCallback(
        async (signal) => {
            setQueue((current) => ({ ...current, loading: true, error: "" }));
            try {
                const query = new URLSearchParams({ limit: "100" });
                if (queueStatus) query.set("status", queueStatus);
                const data = await apiRequest(`/api/admin/media/queue?${query}`, { signal });
                setQueue({ data, loading: false, error: "" });
            } catch (error) {
                if (error?.name !== "AbortError")
                    setQueue((current) => ({ ...current, loading: false, error: error.message || t("Antrean media global tidak dapat dimuat.") }));
            }
        },
        [queueStatus],
    );

    useEffect(() => {
        const controller = new AbortController();
        loadQueue(controller.signal);
        return () => controller.abort();
    }, [loadQueue]);

    const loadCookies = useCallback(async () => {
        try {
            const data = await apiRequest("/api/admin/media/youtube-cookies");
            setCookies((current) => ({ ...current, has: !!data.has_cookies, updatedAt: data.updated_at || null }));
        } catch { /* non-fatal: card just shows "belum ada" */ }
    }, []);
    useEffect(() => { loadCookies(); }, [loadCookies]);

    const saveCookies = async (clear = false) => {
        setCookies((current) => ({ ...current, busy: true, msg: "", error: false }));
        try {
            const data = await apiRequest("/api/admin/media/youtube-cookies", { method: "POST", body: { cookies: clear ? "" : cookies.text } });
            setCookies((current) => ({ ...current, busy: false, has: !!data.has_cookies, updatedAt: data.updated_at || null, text: "", msg: clear ? t("Cookies dihapus.") : t("Cookies disimpan."), error: false }));
        } catch (error) {
            setCookies((current) => ({ ...current, busy: false, msg: error.message || t("Cookies tidak dapat disimpan."), error: true }));
        }
    };

    const images = queue.data?.images || [];
    const videos = queue.data?.videos || [];
    const audio = queue.data?.audio || [];
    const renderCost = (job) => {
        if (job.billing_mode === "admin") return t("Gratis admin (riwayat lama)");
        if (job.billing_mode === "tokens") return `${Number(job.tokens_reserved || 0)} ${t("token")}`;
        return job.cost_microusd == null ? "—" : formatCurrency(Number(job.cost_microusd) / 1_000_000, "USD");
    };
    const queueRows = { images, videos, audio }[queueType];

    return (
        <div className="ui-page space-y-5">
            <nav className="pd-crumbs animate-fade-in-up motion-reduce:animate-none" aria-label={t("Navigasi")}>
                <Link to={localizedPath("/admin/ai")} className="underline underline-offset-2">{t("Penyedia AI")}</Link>
                <span aria-hidden="true">/</span>
                <strong>{t("Antrean media global")}</strong>
            </nav>
            <section className="ui-card" aria-labelledby="yt-cookies-title">
                <div className="ui-card-header">
                    <div className="min-w-0">
                        <h2 id="yt-cookies-title" className="ui-section-title">{t("Cookies YouTube")}</h2>
                        <p className="mt-0.5 max-w-prose text-[11px] text-slate-500 dark:text-slate-400">{t("Tempel isi cookies.txt (format Netscape) dari akun YouTube agar unduhan YouTube lolos verifikasi bot. Disimpan terenkripsi dan tidak pernah ditampilkan kembali.")}</p>
                    </div>
                    <span className="ui-status" data-tone={cookies.has ? "good" : "warn"}>{cookies.has ? t("Terpasang") : t("Belum ada")}</span>
                </div>
                <div className="ui-card-body space-y-3">
                    {cookies.has && cookies.updatedAt && <p className="text-[11px] text-slate-500 dark:text-slate-400">{t("Diperbarui")}: {formatDateTime(cookies.updatedAt)}</p>}
                    <textarea className="ui-input min-h-32 w-full resize-y font-mono text-xs" placeholder="# Netscape HTTP Cookie File" value={cookies.text} disabled={cookies.busy} onChange={(event) => setCookies((current) => ({ ...current, text: event.target.value }))} />
                    {cookies.msg && <p className={`text-xs ${cookies.error ? "text-red-600 dark:text-red-300" : "text-emerald-600 dark:text-emerald-300"}`} role={cookies.error ? "alert" : "status"}>{cookies.msg}</p>}
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="ui-btn-primary" disabled={cookies.busy || !cookies.text.trim()} onClick={() => saveCookies(false)}>{cookies.busy ? t("Menyimpan…") : t("Simpan cookies")}</button>
                        {cookies.has && <button type="button" className="ui-btn-secondary" disabled={cookies.busy} onClick={() => saveCookies(true)}>{t("Hapus cookies")}</button>}
                    </div>
                </div>
            </section>
                <section className="ui-card" aria-labelledby="queue-title">
                    <div className="ui-card-header">
                        <div className="flex items-center gap-3">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-fuchsia-500 to-purple-600 text-white shadow-md">
                                <Icon name="activity" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 id="queue-title" className="ui-section-title">{t("Antrean media global")}</h2>
                                <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{t("Status pekerjaan lintas akun yang diizinkan, penagihan, keluaran, dan kegagalan operasional.")}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            className="ui-btn-secondary inline-flex items-center gap-2"
                            onClick={() => loadQueue()}
                            disabled={queue.loading}
                        >
                            <Icon name="sync" className={`h-4 w-4 ${queue.loading ? "animate-spin" : ""}`} />
                            {t("Muat ulang")}
                        </button>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-white/10">
                        {[
                            ["images", "Gambar", images.length],
                            ["videos", "Video", videos.length],
                            ["audio", "Audio", audio.length],
                        ].map(([type, label, total]) => <button
                            key={type}
                            type="button"
                            aria-pressed={queueType === type}
                            className={queueType === type ? "ui-btn-primary min-h-10 px-4 text-xs" : "ui-btn-secondary"}
                            onClick={() => setQueueType(type)}
                        >
                            {t(label)} ({total})
                        </button>)}
                        <select
                            className="ui-input ml-auto min-h-10 w-auto"
                            value={queueStatus}
                            onChange={(event) =>
                                setQueueStatus(event.target.value)
                            }
                        >
                            {queueStatuses.map((status) => (
                                <option key={status || "all"} value={status}>
                                    {status
                                        ? status.replaceAll("_", " ")
                                        : "All statuses"}
                                </option>
                            ))}
                        </select>
                    </div>
                    {queue.loading && !queue.data ? (
                        <div className="p-4">
                            <LoadingState label={t("Memuat pekerjaan media global…")} />
                        </div>
                    ) : queue.error && !queue.data ? (
                        <div className="p-4">
                            <ErrorState
                                message={queue.error}
                                onRetry={() => loadQueue()}
                            />
                        </div>
                    ) : (
                        <DataTable
                            rows={queueRows}
                            rowKey="job_id"
                            emptyTitle={`No ${queueType} jobs`}
                            emptyDescription={
                                queueStatus
                                    ? `No ${queueStatus} jobs match the current queue filter.`
                                    : `No global ${queueType} jobs have been recorded.`
                            }
                            columns={[
                                {
                                    key: "job",
                                    label: "Job",
                                    render: (row) => (
                                        <div>
                                            <strong className="block font-mono text-[11px] text-slate-900 dark:text-white">
                                                {row.job_id}
                                            </strong>
                                            <span className="text-[11px] text-slate-500">
                                                {row.model}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: "user",
                                    label: "Member",
                                    render: (row) => (
                                        <div>
                                            <span className="block">
                                                {row.user?.name || "Unknown"}
                                            </span>
                                            <span className="text-[11px] text-slate-500">
                                                {row.user?.email}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: "prompt",
                                    label: "Prompt",
                                    render: (row) => (
                                        <span
                                            className="block max-w-sm whitespace-normal"
                                            title={row.prompt}
                                        >
                                            {row.prompt || "—"}
                                        </span>
                                    ),
                                },
                                {
                                    key: "spec",
                                    label: "Spec",
                                    render: (row) => queueType === "images"
                                        ? `${row.size || "—"} · ${row.n || 1} ${t("Gambar")}`
                                        : queueType === "audio"
                                            ? (row.mode === "speech" ? `${row.voice || "—"} · ${row.speed ?? "—"}×` : `${t("Musik / efek suara")} · ${row.duration ?? "—"} s`)
                                            : <div>
                                                <span>{row.aspect_ratio || "—"} · {row.duration ?? "—"} s · {row.pro_mode ? "Pro" : "Standard"}</span>
                                                {row.has_reference && row.reference_url && <a className="mt-1 block underline underline-offset-2" href={row.reference_url} target="_blank" rel="noopener noreferrer">{t("Gambar referensi")}</a>}
                                            </div>,
                                },
                                {
                                    key: "status",
                                    label: "Status",
                                    render: (row) => (
                                        <div>
                                            <StatusBadge status={row.stage === "cancelled" ? "cancelled" : row.status} />
                                            {row.error && (
                                                <p className="mt-1 max-w-xs whitespace-normal text-[10px] text-red-600 dark:text-red-400">
                                                    {row.error}
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: "billing",
                                    label: "Billing",
                                    render: (row) => (
                                        <div>
                                            <span className="block">
                                                {renderCost(row)}
                                            </span>
                                            <StatusBadge
                                                status={
                                                    row.billing_status ||
                                                    "unknown"
                                                }
                                            />
                                        </div>
                                    ),
                                },
                                {
                                    key: "created",
                                    label: "Created",
                                    render: (row) =>
                                        formatDateTime(row.created_at),
                                },
                            ]}
                        />
                    )}
                </section>
        </div>
    );
}
