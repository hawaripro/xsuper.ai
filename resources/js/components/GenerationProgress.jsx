import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import { useLocale } from "../contexts/LocaleContext";
import "./generation-progress.css";

const STAGES = {
    pending: "Menunggu antrean",
    queued: "Menunggu antrean",
    submitting: "Mengirim permintaan",
    rendering: "Merender video",
    generating: "Membuat gambar",
    saving: "Menyimpan hasil",
    waiting: "Menunggu jawaban AI",
    processing: "Sedang diproses",
    completed: "Selesai",
    rejected: "Prompt ditolak",
    failed: "Proses gagal",
};
const subscribeToVisibility = (notify) => {
    document.addEventListener("visibilitychange", notify);
    window.addEventListener("pageshow", notify);
    window.addEventListener("pagehide", notify);
    return () => {
        document.removeEventListener("visibilitychange", notify);
        window.removeEventListener("pageshow", notify);
        window.removeEventListener("pagehide", notify);
    };
};
const pageIsVisible = () => !document.hidden;
const pageIsVisibleOnServer = () => true;


export default function GenerationProgress({ stage = "processing", kind = "image", model, startedAt, compact = false, detail }) {
    const { t } = useLocale();
    const rootRef = useRef(null);
    const visible = useSyncExternalStore(subscribeToVisibility, pageIsVisible, pageIsVisibleOnServer);
    const [inView, setInView] = useState(false);
    const [now, setNow] = useState(Date.now);
    const terminal = ["completed", "rejected", "failed"].includes(stage);
    const paused = !visible || !inView || terminal;
    const started = typeof startedAt === "number" ? startedAt : Date.parse(startedAt);
    const hasElapsed = Number.isFinite(started);
    const seconds = hasElapsed ? Math.max(0, Math.floor((now - started) / 1000)) : 0;
    const minutes = Math.floor(seconds / 60);
    const elapsed = minutes >= 60
        ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`
        : `${minutes}:${String(seconds % 60).padStart(2, "0")}`;

    useEffect(() => {
        const observer = typeof IntersectionObserver === "function"
            ? new IntersectionObserver(([entry]) => setInView(entry.isIntersecting), { threshold: 0.01 })
            : null;
        if (observer && rootRef.current) observer.observe(rootRef.current);
        else setInView(true);
        return () => observer?.disconnect();
    }, []);

    useEffect(() => {
        if (paused || !hasElapsed) return;
        setNow(Date.now());
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [paused, hasElapsed, started]);

    return (
        <div
            ref={rootRef}
            className={`generation-progress${compact ? " generation-progress--compact" : ""}`}
            data-paused={paused ? "true" : "false"}
            data-stage={stage}
            data-kind={kind}
        >
            <div className="generation-progress__visual" aria-hidden="true">
                <div className="generation-progress__orbit">
                    <span className="generation-progress__ring generation-progress__ring--one" />
                    <span className="generation-progress__ring generation-progress__ring--two" />
                    <span className="generation-progress__ring generation-progress__ring--three" />
                    <span className="generation-progress__core" />
                </div>
                {!compact && <div className="generation-progress__preview"><span /><span /><span /></div>}
            </div>
            <div className="generation-progress__body">
                <div className="generation-progress__status" role="status" aria-live="polite" aria-atomic="true">
                    <strong>{t(STAGES[stage] || STAGES.processing)}</strong>
                    {model && <span className="generation-progress__model">{model}</span>}
                    {detail && <p className="generation-progress__detail">{detail}</p>}
                </div>
                {hasElapsed && (
                    <p className="generation-progress__elapsed" aria-live="off">
                        <span>{t("Waktu berjalan")}</span>
                        <time dateTime={`PT${seconds}S`}>{elapsed}</time>
                    </p>
                )}
            </div>
        </div>
    );
}
