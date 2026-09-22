// Frontend mirror of the backend MediaCapability contract. The studio renders inputs/params
// and previews validation from the SAME declarative rules the server enforces (required,
// required_when: param_equals | has_input, enum options, numeric range). The backend remains
// the single authority; this only drives rendering and pre-submit feedback — never a second
// hardcoded per-model ruleset.

export const nonEmpty = (value) =>
    value !== null && value !== undefined && value !== "" && !(Array.isArray(value) && value.length === 0);

export function evaluateRule(rule, values) {
    if (!rule || typeof rule !== "object") return false;
    if (rule.param_equals && typeof rule.param_equals === "object") {
        return (values[rule.param_equals.name] ?? null) === (rule.param_equals.value ?? null);
    }
    if (rule.has_input !== undefined) return nonEmpty(values[String(rule.has_input)]);
    return false;
}

export const fieldRequired = (field, values) => Boolean(field?.required) || evaluateRule(field?.required_when, values);

// Reconcile a draft when the capability changes: keep a still-valid previous value, else fall
// back to the param default (or first option) / an empty input.
export function capabilityValues(capability, previous = {}) {
    const values = {};
    for (const input of capability?.inputs ?? []) {
        const prev = previous[input.key];
        values[input.key] = typeof prev === "string" ? prev : input.type === "asset" && !input.single ? [] : "";
    }
    for (const param of capability?.params ?? []) {
        const prev = previous[param.name];
        const options = Array.isArray(param.options) ? param.options : null;
        if (options) {
            values[param.name] = options.includes(prev) ? prev : param.default ?? options[0] ?? "";
        } else if (param.type === "number" || param.type === "integer") {
            const n = Number(prev);
            values[param.name] = Number.isFinite(n) && nonEmpty(prev) ? n
                : nonEmpty(param.default) ? Number(param.default) : param.required ? Number(param.min ?? 0) : "";
        } else if (param.type === "boolean") {
            values[param.name] = typeof prev === "boolean" ? prev : Boolean(param.default);
        } else {
            values[param.name] = typeof prev === "string" ? prev : param.default ?? "";
        }
    }
    return values;
}

// Client-side preview of the backend validation. Returns a { key: rawMessage } map; callers
// pass the message through StudioField, which localizes it (same path as server errors).
export function capabilityErrors(capability, values) {
    const errors = {};
    for (const param of capability?.params ?? []) {
        const value = values[param.name];
        if (fieldRequired(param, values) && !nonEmpty(value)) {
            errors[param.name] = "Parameter ini wajib diisi.";
            continue;
        }
        if (!nonEmpty(value)) continue;
        if (Array.isArray(param.options) && !param.options.includes(value)) {
            errors[param.name] = "Pilihan tidak didukung oleh model ini.";
        } else if (param.type === "number" || param.type === "integer") {
            const n = Number(value);
            if (!Number.isFinite(n)) errors[param.name] = "Masukkan angka yang valid.";
            else if (param.type === "integer" && !Number.isInteger(n)) errors[param.name] = "Masukkan bilangan bulat.";
            else if (param.min != null && n < Number(param.min)) errors[param.name] = "Nilai di bawah batas minimum model ini.";
            else if (param.max != null && n > Number(param.max)) errors[param.name] = "Nilai melebihi batas maksimum model ini.";
        }
    }
    for (const input of capability?.inputs ?? []) {
        const value = values[input.key];
        const filled = input.type === "string" ? typeof value === "string" && value.trim() !== "" : nonEmpty(value);
        if (fieldRequired(input, values) && !filled) {
            errors[input.key] = "Input ini wajib disediakan.";
        } else if (input.type === "string" && typeof value === "string" && value.length > 4000) {
            errors[input.key] = "Teks terlalu panjang.";
        }
    }
    return errors;
}

// Build the request field map from capability values. Keys align with the backend field names
// (prompt, size, …), so the result spreads straight into the flat request body.
export function capabilitySubmission(capability, values) {
    const body = {};
    for (const input of capability?.inputs ?? []) {
        const value = values[input.key];
        if (input.type === "string") {
            const trimmed = typeof value === "string" ? value.trim() : "";
            if (trimmed !== "") body[input.key] = trimmed;
        } else if (nonEmpty(value)) {
            body[input.key] = value;
        }
    }
    for (const param of capability?.params ?? []) {
        if (nonEmpty(values[param.name])) body[param.name] = values[param.name];
    }
    return body;
}
