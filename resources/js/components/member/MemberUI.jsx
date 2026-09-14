import React from "react";

export function MemberPage({ children, className = "" }) {
    return (
        <main className={`mx-auto w-full max-w-7xl space-y-5 p-4 text-[13px] sm:p-5 lg:p-6 ${className}`}>
            {children}
        </main>
    );
}

export function PageHeader({ eyebrow, title, description, actions }) {
    return (
        <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                {eyebrow && (
                    <p className="mb-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-red-500">
                        {eyebrow}
                    </p>
                )}
                <h1 className="text-[22px] font-bold leading-tight tracking-[-0.02em] text-slate-950 dark:text-white">
                    {title}
                </h1>
                {description && (
                    <p className="mt-1 max-w-2xl text-[13px] leading-5 text-slate-500 dark:text-slate-400">
                        {description}
                    </p>
                )}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
        </header>
    );
}

export function Panel({ children, className = "", as: Component = "section" }) {
    return (
        <Component
            className={`rounded-xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/[0.08] dark:bg-slate-900/70 dark:shadow-none ${className}`}
        >
            {children}
        </Component>
    );
}

export function SectionHeader({ title, description, action }) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div>
                <h2 className="text-sm font-semibold text-slate-950 dark:text-white">{title}</h2>
                {description && (
                    <p className="mt-0.5 text-[11px] leading-4 text-slate-500 dark:text-slate-400">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}

export function Spinner({ label = "Memuat" }) {
    return (
        <span className="inline-flex items-center gap-2 text-[12px] text-slate-500 dark:text-slate-400">
            <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-red-500 dark:border-slate-700 dark:border-t-red-400" />
            {label}
        </span>
    );
}

export function StatePanel({ type = "empty", title, description, action, compact = false }) {
    const tones = {
        loading: "border-slate-200 bg-slate-50 dark:border-white/[0.07] dark:bg-white/[0.025]",
        empty: "border-slate-200 bg-slate-50 dark:border-white/[0.07] dark:bg-white/[0.025]",
        error: "border-red-200 bg-red-50/70 dark:border-red-500/20 dark:bg-red-500/[0.07]",
    };

    return (
        <div className={`rounded-lg border ${tones[type] || tones.empty} ${compact ? "p-3" : "p-5 text-center"}`} role={type === "error" ? "alert" : undefined}>
            <div className={compact ? "flex items-start justify-between gap-3" : "space-y-3"}>
                <div className={compact ? "min-w-0" : ""}>
                    <p className={`font-semibold ${type === "error" ? "text-red-700 dark:text-red-300" : "text-slate-800 dark:text-slate-200"}`}>
                        {title}
                    </p>
                    {description && (
                        <p className="mt-1 text-[12px] leading-5 text-slate-500 dark:text-slate-400">
                            {description}
                        </p>
                    )}
                </div>
                {action && <div className={compact ? "shrink-0" : "flex justify-center"}>{action}</div>}
            </div>
        </div>
    );
}

export function InlineAlert({ tone = "info", children, action }) {
    const tones = {
        info: "border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-400/20 dark:bg-blue-400/10 dark:text-blue-200",
        success: "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200",
        warning: "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200",
        error: "border-red-200 bg-red-50 text-red-800 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-200",
    };
    return (
        <div className={`flex items-start justify-between gap-3 rounded-lg border px-3 py-2.5 text-[12px] leading-5 ${tones[tone] || tones.info}`} role={tone === "error" ? "alert" : "status"}>
            <div className="min-w-0">{children}</div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

export function StatusBadge({ value, label }) {
    const status = String(value || "unknown").toLowerCase();
    const positive = ["active", "approved", "available", "completed", "online", "qualified", "resolved", "success"];
    const negative = ["blocked", "disabled", "error", "failed", "offline", "rejected"];
    const pending = ["attributed", "in_progress", "pending", "processing", "queued", "waiting_on_member"];
    const className = positive.includes(status)
        ? "bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300"
        : negative.includes(status)
          ? "bg-red-500/10 text-red-700 ring-red-500/20 dark:text-red-300"
          : pending.includes(status)
            ? "bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300"
            : "bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300";

    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${className}`}>
            {label || status.replaceAll("_", " ")}
        </span>
    );
}

export function FormField({ label, error, hint, required, children }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-[12px] font-medium text-slate-700 dark:text-slate-300">
                {label}{required && <span className="ml-0.5 text-red-500">*</span>}
            </span>
            {children}
            {error ? (
                <span className="mt-1 block text-[11px] text-red-600 dark:text-red-400">{Array.isArray(error) ? error[0] : error}</span>
            ) : hint ? (
                <span className="mt-1 block text-[11px] text-slate-500 dark:text-slate-400">{hint}</span>
            ) : null}
        </label>
    );
}

export const controlClass = "h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-[13px] text-slate-900 outline-none transition focus:border-red-400 focus:ring-2 focus:ring-red-500/15 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/10 dark:bg-white/[0.04] dark:text-white dark:placeholder:text-slate-500 dark:focus:border-red-400";
export const textAreaClass = `${controlClass} h-auto min-h-24 resize-y py-2.5 leading-5`;

export function Button({ children, variant = "primary", className = "", type = "button", ...props }) {
    const variants = {
        primary: "bg-red-600 text-white hover:bg-red-700 focus:ring-red-500/30 dark:bg-red-500 dark:hover:bg-red-400",
        secondary: "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus:ring-slate-400/20 dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-200 dark:hover:bg-white/[0.09]",
        danger: "border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus:ring-red-500/20 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300 dark:hover:bg-red-500/20",
        ghost: "text-slate-600 hover:bg-slate-100 focus:ring-slate-400/20 dark:text-slate-300 dark:hover:bg-white/[0.07]",
    };
    return (
        <button
            type={type}
            className={`inline-flex h-10 items-center justify-center gap-2 rounded-lg px-3.5 text-[12px] font-semibold transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant] || variants.primary} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

export function Metric({ label, value, detail }) {
    return (
        <div className="min-w-0 rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-white/[0.07] dark:bg-white/[0.025]">
            <p className="text-[11px] font-medium text-slate-500 dark:text-slate-400">{label}</p>
            <p className="mt-1 truncate text-lg font-bold tracking-[-0.02em] text-slate-950 dark:text-white">{value}</p>
            {detail && <p className="mt-0.5 truncate text-[10px] text-slate-500 dark:text-slate-500">{detail}</p>}
        </div>
    );
}

export function errorMessage(error, fallback = "Permintaan tidak dapat diproses.") {
    if (!error) return fallback;
    if (error.status === 401) return "Sesi Anda telah berakhir. Masuk kembali untuk melanjutkan.";
    if (error.status === 403) return error.message || "Akun Anda tidak memiliki izin untuk tindakan ini.";
    if (error.status === 402) return error.message || "Saldo tidak mencukupi untuk tindakan ini.";
    if (error.status === 422) return error.message || "Periksa kembali data yang Anda masukkan.";
    return error.message || fallback;
}

export function validationErrors(error) {
    const details = error?.details;
    const validation = details?.errors && typeof details.errors === "object" ? details.errors : details;
    if (!validation || typeof validation !== "object" || Array.isArray(validation)) return {};
    return validation;
}

export function formatCount(value) {
    const number = Number(value);
    return Number.isFinite(number) ? new Intl.NumberFormat("id-ID").format(number) : "—";
}

export function formatUsdMicros(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return "—";
    return new Intl.NumberFormat("id-ID", { style: "currency", currency: "USD", minimumFractionDigits: 2, maximumFractionDigits: 6 }).format(number / 1_000_000);
}

export function formatLocalDate(value, options = {}) {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        ...options,
    }).format(date);
}
