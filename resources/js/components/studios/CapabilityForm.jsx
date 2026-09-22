import { useState } from "react";
import { StudioField } from "./StudioUI";
import AssetUploadField from "./AssetUploadField";
import { useLocale } from "../../contexts/LocaleContext";

// Renders a resolved capability's declared inputs + params as labelled, correctly-typed
// controls, in the order the backend ui_metadata specifies. Values and errors are controlled
// by the parent studio; there is no per-model logic here — every field comes from the contract.
export default function CapabilityForm({ capability, values, errors = {}, disabled = false, idPrefix = "cap", onChange }) {
    const { t } = useLocale();
    const [touched, setTouched] = useState({ hash: null, fields: {} });
    if (!capability) return null;

    const ui = capability.ui || { inputs: {}, params: {}, order: [] };
    const inputByKey = Object.fromEntries((capability.inputs || []).map((input) => [input.key, input]));
    const paramByName = Object.fromEntries((capability.params || []).map((param) => [param.name, param]));
    const set = (key, value) => {
        setTouched((current) => ({ hash: capability.source_hash, fields: { ...(current.hash === capability.source_hash ? current.fields : {}), [key]: true } }));
        onChange({ ...values, [key]: value });
    };
    const fieldError = (key) => {
        const value = values[key];
        const edited = touched.hash === capability.source_hash && touched.fields[key];
        return edited || (value !== undefined && value !== null && value !== "") ? errors[key] : undefined;
    };
    const order = ui.order?.length
        ? ui.order
        : [...(capability.inputs || []).map((i) => `input:${i.key}`), ...(capability.params || []).map((p) => `param:${p.name}`)];

    const renderInput = (input, meta, id) => {
        const value = values[input.key] ?? "";
        if (input.type === "string") {
            const isPrompt = meta.control === "textarea" || input.key === "prompt";
            const hint = isPrompt ? `${(value || "").length}/4000 ${t("karakter")}` : meta.help ? t(meta.help) : undefined;
            const common = {
                id, value, disabled, "aria-invalid": Boolean(fieldError(input.key)), required: Boolean(input.required),
                placeholder: meta.placeholder ? t(meta.placeholder) : undefined,
                onChange: (event) => set(input.key, event.target.value),
            };
            return <StudioField key={`input:${input.key}`} id={id} label={meta.label || input.key} error={fieldError(input.key)} hint={hint}>
                {isPrompt
                    ? <textarea {...common} rows={7} maxLength={4000} />
                    : <input {...common} type="text" maxLength={4000} />}
            </StudioField>;
        }
        if (input.type === "asset") {
            return <AssetUploadField key={`input:${input.key}`} input={input} meta={meta} value={values[input.key]} error={fieldError(input.key)} disabled={disabled} idPrefix={idPrefix} onChange={(assetId) => set(input.key, assetId)} />;
        }

        return null;
    };

    const renderParam = (param, meta, id) => {
        const value = values[param.name];
        const control = meta.control || (Array.isArray(param.options) ? "select" : ["number", "integer"].includes(param.type) ? "number" : param.type === "boolean" ? "toggle" : "text");
        const help = meta.help ? t(meta.help) : undefined;
        const label = meta.label || param.name;
        if (control === "select") {
            const options = Array.isArray(param.options) ? param.options : [];
            return <StudioField key={`param:${param.name}`} id={id} label={label} error={fieldError(param.name)} hint={help}>
                <select id={id} value={value ?? ""} disabled={disabled || options.length < 2} aria-invalid={Boolean(fieldError(param.name))} onChange={(event) => set(param.name, options.find((option) => String(option) === event.target.value) ?? event.target.value)}>
                    {options.map((option) => <option key={option} value={option}>{param.unit ? `${option} ${param.unit}` : option}</option>)}
                </select>
            </StudioField>;
        }
        if (control === "slider") {
            return <StudioField key={`param:${param.name}`} id={id} label={label} error={fieldError(param.name)} hint={help}>
                <div className="studio-range-value"><output htmlFor={id}>{value}{param.unit ? ` ${param.unit}` : ""}</output><span>{param.min}–{param.max}{param.unit ? ` ${param.unit}` : ""}</span></div>
                <input id={id} type="range" min={param.min} max={param.max} step={param.step || 1} value={value ?? param.min ?? 0} disabled={disabled} aria-valuetext={`${value}${param.unit ? ` ${param.unit}` : ""}`} onChange={(event) => set(param.name, Number(event.target.value))} />
            </StudioField>;
        }
        if (control === "number") {
            return <StudioField key={`param:${param.name}`} id={id} label={label} error={fieldError(param.name)} hint={help}>
                <div className="studio-input-unit">
                    <input id={id} type="number" min={param.min ?? undefined} max={param.max ?? undefined} step={param.step || (param.type === "integer" ? 1 : "any")} value={value ?? ""} disabled={disabled} aria-invalid={Boolean(fieldError(param.name))} onChange={(event) => set(param.name, event.target.value === "" ? "" : Number(event.target.value))} />
                    {param.unit && <span>{param.unit}</span>}
                </div>
            </StudioField>;
        }
        if (control === "toggle") {
            return <StudioField key={`param:${param.name}`} id={id} label={label} error={fieldError(param.name)} hint={help}>
                <button type="button" id={id} className="studio-switch" role="switch" aria-checked={Boolean(value)} disabled={disabled} onClick={() => set(param.name, !value)}><span /></button>
            </StudioField>;
        }
        return <StudioField key={`param:${param.name}`} id={id} label={label} error={fieldError(param.name)} hint={help}>
            <input id={id} type="text" value={value ?? ""} disabled={disabled} onChange={(event) => set(param.name, event.target.value)} />
        </StudioField>;
    };

    return <>{order.map((token) => {
        const separator = token.indexOf(":");
        const kind = token.slice(0, separator);
        const key = token.slice(separator + 1);
        const id = `${idPrefix}-${key}`;
        if (kind === "input" && inputByKey[key]) return renderInput(inputByKey[key], ui.inputs?.[key] || {}, id);
        if (kind === "param" && paramByName[key]) return renderParam(paramByName[key], ui.params?.[key] || {}, id);
        return null;
    })}</>;
}
