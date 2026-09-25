import { useEffect, useId, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioButton, StudioIcon } from "./StudioUI";
import { FieldInfo } from "./FieldControls";
import { displaySchema, schemaDefault, schemaPath } from "./schema";
import { ASPECT_RATIOS, RESOLUTIONS, availableResolutions, axisBounds, parseRatio, ratioLabel, resolutionLabel, sizeFor } from "./studioSizing";
import "./studio-form.css";

const standardChoices = ASPECT_RATIOS.map((value) => ({ value, label: value, ratio: parseRatio(value) }));
const numberValue = (value) => typeof value === "number" && Number.isFinite(value) ? value : "";

// Radio tiles behave like native radios: one Tab stop, arrows select, Home/End jump.
function radioKeys(event) {
    const direction = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
    if (!direction && event.key !== "Home" && event.key !== "End") return;
    const radios = [...event.currentTarget.querySelectorAll('[role="radio"]:not(:disabled)')];
    const current = radios.indexOf(document.activeElement);
    if (current < 0 || !radios.length) return;
    event.preventDefault();
    const next = event.key === "Home" ? 0 : event.key === "End" ? radios.length - 1 : (current + direction + radios.length) % radios.length;
    radios[next].focus();
    radios[next].click();
}

function RatioShape({ ratio }) {
    if (!ratio) return <StudioIcon name="settings" />;
    const scale = 28 / Math.max(...ratio);
    return <span className="sw-dimension-shape" aria-hidden="true" style={{ width: ratio[0] * scale, height: ratio[1] * scale }} />;
}

// choices: ratioChoices(enum), or null for an independent width/height pair.
// sizeSchema: the pair's object schema, or the custom object branch of a size union.
export default function DimensionControl({ id: suppliedId, label = "Ukuran output", value, onChange, choices = null, sizeSchema = null, root = sizeSchema, allowAutomatic = false, path = "", errors = {}, disabled = false, describedBy }) {
    const { t } = useLocale();
    const generatedId = useId();
    const id = suppliedId || generatedId;
    const [open, setOpen] = useState(false);
    const wrapper = useRef(null);
    const trigger = useRef(null);
    const popover = useRef(null);
    const custom = value !== null && typeof value === "object" && !Array.isArray(value);
    const manual = Boolean(sizeSchema) && (!choices || custom);
    const size = custom ? value : {};
    const widthSchema = displaySchema(sizeSchema?.properties?.width || {}, root) || {};
    const heightSchema = displaySchema(sizeSchema?.properties?.height || {}, root) || {};
    const widthBounds = axisBounds(widthSchema, 1);
    const heightBounds = axisBounds(heightSchema, 1);
    const currentRatio = ratioLabel(size.width, size.height);
    const currentResolution = resolutionLabel(size.width, size.height);
    const presets = availableResolutions(widthBounds, heightBounds);
    const options = choices || standardChoices;
    const selectedIndex = choices ? choices.findIndex((choice) => choice.value === value) : standardChoices.findIndex((choice) => choice.value === currentRatio);
    const firstTab = selectedIndex >= 0 ? selectedIndex : custom && choices ? choices.length : 0;
    const errorFor = (axis) => {
        const message = errors[schemaPath(path, axis)];
        return Array.isArray(message) ? message[0] : message;
    };
    const widthError = errorFor("width");
    const heightError = errorFor("height");
    const axisError = Boolean(widthError || heightError);
    const sizeText = currentRatio ? `${currentRatio} · ${size.width}×${size.height}` : t("Pilih ukuran");
    const selectedChoice = choices?.[selectedIndex];
    const triggerText = choices && !custom
        ? selectedChoice ? t(selectedChoice.automatic ? "Otomatis" : selectedChoice.label) : typeof value === "string" && value ? value : t("Pilih ukuran")
        : custom && choices ? `${t("Kustom")} · ${sizeText}` : sizeText;
    const close = () => { setOpen(false); trigger.current?.focus({ preventScroll: true }); };
    useEffect(() => {
        if (!open) return;
        popover.current?.querySelector('[role="radio"][tabindex="0"]:not(:disabled)')?.focus({ preventScroll: true });
        // The popover opens inside a scrolling panel: bring all of it into view.
        popover.current?.scrollIntoView({ block: "nearest", behavior: window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
        const outside = (event) => {
            if (wrapper.current?.contains(event.target)) return;
            setOpen(false);
            trigger.current?.focus({ preventScroll: true });
        };
        const escape = (event) => {
            if (event.key !== "Escape" || event.defaultPrevented) return;
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
            trigger.current?.focus({ preventScroll: true });
        };
        document.addEventListener("click", outside);
        document.addEventListener("keydown", escape);
        return () => { document.removeEventListener("click", outside); document.removeEventListener("keydown", escape); };
    }, [open]);
    const pickSize = (ratio, edge) => onChange({ ...size, ...sizeFor(ratio, edge, widthBounds, heightBounds) });
    const pickRatio = (ratio) => {
        const edge = RESOLUTIONS.find((preset) => preset.id === currentResolution)?.edge || (currentRatio ? Math.sqrt(size.width * size.height) : 1024);
        pickSize(ratio, edge);
    };
    const axisMessages = <>{["width", "height"].map((axis) => errorFor(axis) && <p id={`${id}-${axis}-error`} key={axis} className="studio-field-error" role="alert">{t(axis === "width" ? "Lebar (px)" : "Tinggi (px)")}: {t(errorFor(axis))}</p>)}</>;
    return <div className="sw-dimension" ref={wrapper}>
        <button ref={trigger} id={id} type="button" className="sw-dimension-trigger" aria-label={`${t(label)}: ${triggerText}`} aria-haspopup="dialog" aria-expanded={open} aria-controls={`${id}-popover`}
            aria-invalid={Boolean(errors[path]) || axisError} aria-describedby={[describedBy, axisError ? `${id}-size-errors` : null].filter(Boolean).join(" ") || undefined} disabled={disabled} onClick={() => setOpen(!open)}>
            <span>{triggerText}</span><StudioIcon name="settings" />
        </button>
        {!open && axisError && <div id={`${id}-size-errors`}>{axisMessages}</div>}
        {open && <div id={`${id}-popover`} ref={popover} className="sw-dimension-popover" role="dialog" aria-label={t("Ukuran output")}>
            <div className="sw-dimension-heading"><strong>{t("Ukuran output")}</strong><StudioButton icon="close" className="sw-dimension-close" aria-label={t("Tutup")} onClick={close} /></div>
            <div className="sw-dimension-ratios" role="radiogroup" aria-label={t("Rasio aspek")} onKeyDown={radioKeys}>
                {options.map((choice, index) => <button type="button" key={choice.value} className="sw-dimension-tile" role="radio" aria-checked={selectedIndex === index} tabIndex={firstTab === index ? 0 : -1} disabled={disabled}
                    onClick={() => choices ? onChange(choice.value) : pickRatio(choice.value)}>
                    <span className="sw-dimension-tile-art"><RatioShape ratio={choice.ratio} /></span><span>{t(choice.automatic ? "Otomatis" : choice.label)}</span>
                </button>)}
                {choices && sizeSchema && <button type="button" className="sw-dimension-tile" role="radio" aria-checked={custom} tabIndex={firstTab === choices.length ? 0 : -1} disabled={disabled}
                    onClick={() => { if (!custom) onChange(schemaDefault(sizeSchema, undefined, true, root)); }}>
                    <span className="sw-dimension-tile-art"><StudioIcon name="settings" /></span><span>{t("Kustom")}</span>
                </button>}
            </div>
            {manual && <>
                {presets.length > 0 && <div className="sw-dimension-resolution"><span id={`${id}-resolution-label`}>{t("Resolusi")}</span>
                    <div role="radiogroup" aria-labelledby={`${id}-resolution-label`} className="sw-dimension-presets" onKeyDown={radioKeys}>
                        {presets.map((preset, index) => <button type="button" role="radio" key={preset.id} aria-checked={currentResolution === preset.id}
                            tabIndex={currentResolution === preset.id || !presets.some((entry) => entry.id === currentResolution) && index === 0 ? 0 : -1} disabled={disabled} onClick={() => pickSize(currentRatio || "1:1", preset.edge)}>{t(preset.id)}</button>)}
                    </div>
                </div>}
                <div className="sw-dimension-manual">{["width", "height"].map((axis) => {
                    const bounds = axis === "width" ? widthBounds : heightBounds;
                    const axisSchema = axis === "width" ? widthSchema : heightSchema;
                    const axisLabel = axis === "width" ? "Lebar (px)" : "Tinggi (px)";
                    const hint = [bounds.min != null && `${t("Minimum")}: ${bounds.min}`, bounds.max != null && `${t("Maksimum")}: ${bounds.max}`, `${t("Kelipatan")}: ${bounds.step}`].filter(Boolean).join(" · ");
                    return <div key={axis} className="studio-field">
                        <div className="sw-field-row"><label htmlFor={`${id}-${axis}`} className="sw-field-label">{t(axisLabel)}</label>
                            {axisSchema.description && <FieldInfo id={`${id}-${axis}-description`} label={axisLabel} description={axisSchema.description} />}
                        </div>
                        <input id={`${id}-${axis}`} type="number" min={bounds.min ?? undefined} max={bounds.max ?? undefined} step={bounds.step} value={numberValue(size[axis])} disabled={disabled}
                            required={sizeSchema.required?.includes(axis)} aria-invalid={Boolean(errorFor(axis))}
                            aria-describedby={[axisSchema.description && `${id}-${axis}-description`, `${id}-${axis}-hint`, errorFor(axis) && `${id}-${axis}-error`].filter(Boolean).join(" ")}
                            onChange={(event) => onChange({ ...size, [axis]: event.target.value === "" ? "" : Number(event.target.value) })} />
                        <p className="studio-help sw-field-hint" id={`${id}-${axis}-hint`}>{hint}</p>
                    </div>;
                })}</div>
                {axisError && <div id={`${id}-size-errors`}>{axisMessages}</div>}
                {allowAutomatic && <button type="button" className="sw-dimension-automatic" disabled={disabled} onClick={() => { onChange(undefined); close(); }}>{t("Otomatis (bawaan model)")}</button>}
            </>}
        </div>}
    </div>;
}
