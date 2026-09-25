import { useCallback, useRef } from "react";
import { StudioField } from "./StudioUI";
import AssetUploadField, { UploadActivityContext } from "./AssetUploadField";
import SchemaFields from "./SchemaFields";
import { useLocale } from "../../contexts/LocaleContext";
import DimensionControl from "./DimensionControl";
import { FieldSection, FormField, SeedControl } from "./FieldControls";
import { capabilitySchema, FIELD_GROUPS, fieldGroup } from "./studioForm";
import { fieldRequired } from "./capability";
import { ratioChoices } from "./studioSizing";

export default function CapabilityForm({ capability, values, errors = {}, disabled = false, idPrefix = "cap", hiddenInputs = [], hiddenParams = [], onChange, onUploadStateChange, grouped = false, quantityInput = null }) {
    const uploads = useRef(new Map());
    const report = useRef(onUploadStateChange);
    report.current = onUploadStateChange;
    const activity = useCallback((key, state) => {
        if (state) uploads.current.set(key, state);
        else uploads.current.delete(key);
        report.current?.({
            busy: [...uploads.current.values()].some((entry) => entry.busy),
            failed: [...uploads.current.values()].some((entry) => entry.failed),
        });
    }, []);
    if (!capability) return null;
    return <UploadActivityContext.Provider value={activity}>
        {capability.contract_version === 2 && capability.input_schema
            ? <SchemaFields schema={capability.input_schema} values={values} errors={errors} disabled={disabled} onChange={onChange} grouped={grouped} quantityInput={quantityInput} />
            : <LegacyCapabilityForm {...{ capability, values, errors, disabled, idPrefix, hiddenInputs, hiddenParams, onChange, grouped, quantityInput }} />}
    </UploadActivityContext.Provider>;
}

// Renders a resolved capability's declared inputs + params as labelled, correctly-typed
// controls, in the order the backend ui_metadata specifies. Values and errors are controlled
// by the parent studio, which decides which errors are visible yet; there is no per-model logic
// here — every field comes from the contract. hiddenInputs/hiddenParams are fields the parent
// renders itself: native quantity and Pro as execution controls, a studio's own authoring fields.
function LegacyCapabilityForm({ capability, values, errors = {}, disabled = false, idPrefix = "cap", hiddenInputs = [], hiddenParams = [], onChange, grouped = false, quantityInput = null }) {
    const { t } = useLocale();
    if (!capability) return null;

    const ui = capability.ui || { inputs: {}, params: {}, order: [] };
    const inputByKey = Object.fromEntries((capability.inputs || []).map((input) => [input.key, input]));
    const paramByName = Object.fromEntries((capability.params || []).map((param) => [param.name, param]));
    const set = (key, value) => onChange({ ...values, [key]: value });
    const fieldError = (key) => errors[key];
    const declared = [...(capability.inputs || []).map((input) => `input:${input.key}`), ...(capability.params || []).map((param) => `param:${param.name}`)];
    const order = ui.order?.length ? ui.order : declared;
    const root = grouped ? capabilitySchema(capability, [...hiddenInputs, ...hiddenParams]) : null;
    const Field = grouped ? FormField : StudioField;
    const descriptionIds = (id, meta, error, hint) => grouped
        ? [meta.help && `${id}-description`, error && `${id}-error`, hint && `${id}-hint`].filter(Boolean).join(" ") || undefined
        : undefined;

    const renderInput = (input, meta, id) => {
        const value = values[input.key] ?? "";
        if (input.type === "string") {
            const isPrompt = meta.control === "textarea" || input.key === "prompt";
            const hint = isPrompt ? `${(value || "").length}/4000 ${t("karakter")}` : !grouped && meta.help ? t(meta.help) : undefined;
            const common = {
                id, value, disabled, "aria-invalid": Boolean(fieldError(input.key)), required: grouped ? fieldRequired(input, values) : Boolean(input.required),
                "aria-describedby": descriptionIds(id, meta, fieldError(input.key), hint),
                placeholder: meta.placeholder ? t(meta.placeholder) : undefined,
                onChange: (event) => set(input.key, event.target.value),
            };
            return <Field key={`input:${input.key}`} id={id} label={meta.label || input.key} required={fieldRequired(input, values)} description={meta.help} error={fieldError(input.key)} hint={hint}>
                {isPrompt
                    ? <textarea {...common} rows={7} maxLength={4000} />
                    : <input {...common} type="text" maxLength={4000} />}
            </Field>;
        }
        if (input.type === "asset") {
            const control = <AssetUploadField key={`input:${input.key}`} input={input} meta={grouped ? { ...meta, label: "Berkas", help: undefined } : meta} value={values[input.key]} error={fieldError(input.key)} disabled={disabled} idPrefix={idPrefix} onChange={(assetId) => set(input.key, assetId)} />;
            return grouped ? <FormField key={`input:${input.key}`} id={id} label={meta.label || input.key} required={fieldRequired(input, values)} description={meta.help} composite>{control}</FormField> : control;
        }
        return null;
    };

    const renderParam = (param, meta, id) => {
        const value = values[param.name];
        const control = meta.control || (Array.isArray(param.options) ? "select" : ["number", "integer"].includes(param.type) ? "number" : param.type === "boolean" ? "toggle" : "text");
        const help = grouped
            ? [param.min != null && `${t("Minimum")}: ${param.min}`, param.max != null && `${t("Maksimum")}: ${param.max}`, param.step != null && `${t("Kelipatan")}: ${param.step}`].filter(Boolean).join(" ") || undefined
            : meta.help ? t(meta.help) : undefined;
        const label = meta.label || param.name;
        const description = descriptionIds(id, meta, fieldError(param.name), help);
        const fieldProps = { id, label, error: fieldError(param.name), hint: help, description: meta.help, required: fieldRequired(param, values) };
        const choices = grouped && ratioChoices(param.options);
        if (choices) return <FormField key={`param:${param.name}`} {...fieldProps}>
            <DimensionControl id={id} label={label} value={value} choices={choices} path={param.name} errors={errors} disabled={disabled} describedBy={description} onChange={(next) => set(param.name, next)} />
        </FormField>;
        if (grouped && param.name === "seed" && ["integer", "number"].includes(param.type) && !Array.isArray(param.options)) return <FormField key={`param:${param.name}`} {...fieldProps}>
            <SeedControl id={id} label={label} schema={{ ...root.properties[param.name], multipleOf: param.step }} value={value} disabled={disabled} describedBy={description} invalid={Boolean(fieldError(param.name))}
                required={fieldRequired(param, values)} onChange={(next) => set(param.name, next)} />
        </FormField>;
        if (control === "select") {
            const options = Array.isArray(param.options) ? param.options : [];
            return <Field key={`param:${param.name}`} {...fieldProps}>
                <select id={id} value={value ?? ""} disabled={disabled || options.length < 2} aria-invalid={Boolean(fieldError(param.name))} aria-describedby={description} onChange={(event) => set(param.name, options.find((option) => String(option) === event.target.value) ?? event.target.value)}>
                    {options.map((option) => <option key={option} value={option}>{param.unit ? `${option} ${param.unit}` : option}</option>)}
                </select>
            </Field>;
        }
        if (control === "slider") {
            return <Field key={`param:${param.name}`} {...fieldProps}>
                <div className="studio-range-value"><output htmlFor={id}>{value}{param.unit ? ` ${param.unit}` : ""}</output><span>{param.min}–{param.max}{param.unit ? ` ${param.unit}` : ""}</span></div>
                <input id={id} type="range" min={param.min} max={param.max} step={param.step || 1} value={value ?? param.min ?? 0} disabled={disabled} aria-describedby={description} aria-valuetext={`${value}${param.unit ? ` ${param.unit}` : ""}`} onChange={(event) => set(param.name, Number(event.target.value))} />
            </Field>;
        }
        if (control === "number") {
            return <Field key={`param:${param.name}`} {...fieldProps}>
                <div className="studio-input-unit">
                    <input id={id} type="number" min={param.min ?? undefined} max={param.max ?? undefined} step={param.step || (param.type === "integer" ? 1 : "any")} value={value ?? ""} disabled={disabled} aria-invalid={Boolean(fieldError(param.name))} aria-describedby={description} onChange={(event) => set(param.name, event.target.value === "" ? "" : Number(event.target.value))} />
                    {param.unit && <span>{param.unit}</span>}
                </div>
            </Field>;
        }
        if (control === "toggle") {
            return <Field key={`param:${param.name}`} {...fieldProps}>
                <button type="button" id={id} className="studio-switch" role="switch" aria-checked={Boolean(value)} aria-describedby={description} disabled={disabled} onClick={() => set(param.name, !value)}><span /></button>
            </Field>;
        }
        return <Field key={`param:${param.name}`} {...fieldProps}>
            <input id={id} type="text" value={value ?? ""} disabled={disabled} aria-describedby={description} onChange={(event) => set(param.name, event.target.value)} />
        </Field>;
    };

    const renderToken = (token) => {
        const separator = token.indexOf(":");
        const kind = token.slice(0, separator);
        const key = token.slice(separator + 1);
        const id = `${idPrefix}-${key}`;
        if (kind === "input" && inputByKey[key] && !hiddenInputs.includes(key)) return renderInput(inputByKey[key], ui.inputs?.[key] || {}, id);
        if (kind === "param" && paramByName[key] && !hiddenParams.includes(key)) return renderParam(paramByName[key], ui.params?.[key] || {}, id);
        return null;
    };
    if (!grouped) return <>{order.map(renderToken)}</>;
    const entries = [...new Set([...order, ...declared])].flatMap((token) => {
        const separator = token.indexOf(":");
        const kind = token.slice(0, separator);
        const key = token.slice(separator + 1);
        if (kind === "input" ? !inputByKey[key] || hiddenInputs.includes(key) : kind !== "param" || !paramByName[key] || hiddenParams.includes(key)) return [];
        return [{ token, key, group: fieldGroup(key, root.properties[key], { root, required: root.required.includes(key), quantity: quantityInput }) }];
    });
    return <div className="sw-form-sections">{FIELD_GROUPS.map((group) => {
        const fields = entries.filter((entry) => entry.group === group.id);
        if (!fields.length) return null;
        const flagged = fields.some((entry) => Object.keys(errors).some((at) => at === entry.key || at.startsWith(`${entry.key}.`)));
        return <FieldSection key={group.id} group={group} count={fields.length} flagged={flagged}>{fields.map((entry) => renderToken(entry.token))}</FieldSection>;
    })}</div>;
}
