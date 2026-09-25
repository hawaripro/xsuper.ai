import { useEffect, useId, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioButton, StudioIcon, mediaError } from "./StudioUI";
import { axisBounds } from "./studioSizing";
import "./studio-form.css";

const SECTION_ICONS = { input: "upload", core: "spark", features: "layers", advanced: "settings" };

export function FieldInfo({ id, label, description }) {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    const wrapper = useRef(null);
    const trigger = useRef(null);
    useEffect(() => {
        if (!open) return;
        const outside = (event) => { if (!wrapper.current?.contains(event.target)) setOpen(false); };
        const escape = (event) => {
            if (event.key !== "Escape") return;
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
            trigger.current?.focus({ preventScroll: true });
        };
        document.addEventListener("pointerdown", outside);
        document.addEventListener("keydown", escape);
        return () => {
            document.removeEventListener("pointerdown", outside);
            document.removeEventListener("keydown", escape);
        };
    }, [open]);
    return <span className="sw-field-info" ref={wrapper} onKeyDown={(event) => {
        if (open && event.key === "Escape") { event.preventDefault(); event.stopPropagation(); setOpen(false); trigger.current?.focus({ preventScroll: true }); }
    }}>
        <button ref={trigger} type="button" className="sw-field-info-trigger" aria-label={`${t("Info")}: ${t(label)}`} aria-expanded={open} aria-controls={id} onClick={() => setOpen(!open)}>
            <StudioIcon name="info" />
        </button>
        <span className="sw-field-info-bubble" id={id} hidden={!open}>{t(description)}</span>
    </span>;
}

// Grouped forms share label, help and error semantics; existing ungrouped StudioField stays intact.
export function FormField({ id, label, required = false, description, hint, error, onRemove, disabled = false, composite = false, children }) {
    const { t } = useLocale();
    const message = Array.isArray(error) ? error[0] : error;
    return <div className="studio-field sw-form-field">
        <div className="sw-field-row">
            {composite ? <span id={`${id}-label`} className="sw-field-label">{t(label)}{required && <span className="schema-required" aria-hidden="true"> *</span>}</span>
                : <label htmlFor={id} className="sw-field-label">{t(label)}{required && <span className="schema-required" aria-hidden="true"> *</span>}</label>}
            {description && <FieldInfo id={`${id}-description`} label={label} description={description} />}
            {onRemove && <StudioButton className="sw-field-remove" icon="close" aria-label={`${t("Hapus")} ${t(label)}`} disabled={disabled} onClick={onRemove} />}
        </div>
        <div data-sw-field-control="" id={composite ? id : undefined} role={composite ? "group" : undefined} aria-labelledby={composite ? `${id}-label` : undefined}
            aria-describedby={composite ? [description && `${id}-description`, hint && `${id}-hint`, message && `${id}-error`].filter(Boolean).join(" ") || undefined : undefined}>{children}</div>
        {hint && <p id={`${id}-hint`} className="studio-help sw-field-hint">{hint}</p>}
        {message && <p id={`${id}-error`} className="studio-field-error" role="alert">{t(mediaError(message))}</p>}
    </div>;
}

export function FieldSection({ group, count, flagged = false, children }) {
    const { t } = useLocale();
    const id = useId();
    const [expanded, setExpanded] = useState(false);
    const heading = <><h3 id={id}><StudioIcon name={SECTION_ICONS[group.id] || "settings"} />{t(group.label)}</h3><span className="sw-form-count">{count}<span className="studio-visually-hidden"> {t("bidang")}</span></span></>;
    return <section className={`sw-form-section sw-form-section-${group.id}`} aria-labelledby={id}>
        {group.id === "advanced" ? <details open={expanded || flagged}>
            <summary className="sw-form-heading" onClick={(event) => { event.preventDefault(); if (!flagged) setExpanded(!expanded); }}>{heading}<span className="sw-form-chevron" aria-hidden="true" /></summary>
            <div className="sw-form-section-fields">{children}</div>
        </details> : <><div className="sw-form-heading">{heading}</div><div className="sw-form-section-fields">{children}</div></>}
    </section>;
}

export function SeedControl({ id, schema = {}, value, onChange, disabled = false, label = "Seed", describedBy, invalid = false, required = false }) {
    const { t } = useLocale();
    const [status, setStatus] = useState("");
    const timer = useRef(null);
    const mounted = useRef(false);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; clearTimeout(timer.current); }; }, []);
    const bounds = axisBounds(schema, 1);
    const step = Number.isInteger(bounds.step) && bounds.step > 0 ? bounds.step : 1;
    const low = Math.ceil((bounds.min ?? 0) / step);
    const high = Math.floor(Math.min(bounds.max ?? 4294967295, 4294967295) / step);
    const change = (next) => { clearTimeout(timer.current); setStatus(""); onChange(next); };
    const randomize = () => {
        // 53 random bits also cover schemas permitting the -1 sentinel plus the full uint32 range.
        const words = crypto.getRandomValues(new Uint32Array(2));
        const fraction = (words[0] * 2097152 + (words[1] >>> 11)) / 9007199254740992;
        change((low + Math.floor(fraction * (high - low + 1))) * step);
    };
    const copy = async () => {
        clearTimeout(timer.current);
        try {
            await navigator.clipboard.writeText(String(value));
            if (mounted.current) setStatus("Tersalin");
        } catch {
            if (mounted.current) setStatus("Tidak dapat menyalin. Salin nilai secara manual.");
        }
        if (mounted.current) timer.current = setTimeout(() => setStatus(""), 2200);
    };
    return <div className="sw-seed-control">
        <div className="sw-seed-row">
            <input id={id} type="number" aria-label={t(label)} aria-describedby={describedBy} aria-invalid={invalid} required={required} value={typeof value === "number" ? value : ""}
                min={bounds.min ?? undefined} max={bounds.max ?? undefined} step={schema.multipleOf || (schema.type === "integer" ? 1 : "any")} disabled={disabled}
                onChange={(event) => change(event.target.value === "" ? "" : Number(event.target.value))} />
            <StudioButton icon="dice" disabled={disabled || low > high || !Number.isSafeInteger(low) || !Number.isSafeInteger(high)} onClick={randomize}>{t("Acak")}</StudioButton>
            <StudioButton icon={status === "Tersalin" ? "check" : "copy"} disabled={disabled || typeof value !== "number" || !Number.isFinite(value)} onClick={() => { void copy(); }}>{t("Salin")}</StudioButton>
        </div>
        <span className="sw-seed-status" role="status" aria-live="polite">{status && t(status)}</span>
    </div>;
}
