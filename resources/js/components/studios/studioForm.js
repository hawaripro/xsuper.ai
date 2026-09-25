import { capabilityErrors } from "./capability";
import { displaySchema, schemaErrors, schemaVariants } from "./schema";

// Request panel structure shared by the form, the JSON editor and the parameter docs. Schema-driven
// (v2) capabilities use their input schema; native (v1) inputs and params are described as one.
export const FIELD_GROUPS = [
    { id: "input", label: "Input" },
    { id: "core", label: "Inti" },
    { id: "features", label: "Fitur" },
    { id: "advanced", label: "Lanjutan" },
];
const declaredGroups = { inputs: "input", core: "core", features: "features", advanced: "advanced" };
const promptLike = /prompt|lyrics|script|^text$|^caption$/i;
// Output size and length stay in view: the ratio and resolution control lives on them.
const primary = /^(size|image_size|aspect_ratio|width|height|resolution|duration)$/;

function containsAsset(source, root, depth = 0) {
    if (depth > 6 || !source || typeof source !== "object") return false;
    const schema = displaySchema(source, root);
    if (!schema || typeof schema !== "object") return false;
    if (schema["x-workspace-asset"] || schema["x-native-asset"] || schema["x-workspace-file-object"] === true) return true;
    const children = [...Object.values(schema.properties || {}), ...(Array.isArray(schema.items) ? schema.items : schema.items ? [schema.items] : []), ...(schema.prefixItems || []), ...schemaVariants(schema)];
    return children.some((child) => containsAsset(child, root, depth + 1));
}

// Declared x-workspace-group wins; otherwise files are inputs, required, prompt-like, size or
// quantity fields are core, and everything else is advanced.
export function fieldGroup(name, schema, { required = false, root = schema, quantity = null } = {}) {
    const declared = declaredGroups[displaySchema(schema, root)?.["x-workspace-group"]];
    if (declared) return declared;
    if (containsAsset(schema, root)) return "input";
    return required || promptLike.test(name) || primary.test(name) || (quantity && name === quantity) ? "core" : "advanced";
}

// Within a section the main prompt leads: required prompt-like fields, other required fields,
// optional prompt-like fields, then the rest, each in schema order.
export function orderFields(entries) {
    const rank = (entry) => entry.required ? (promptLike.test(entry.key) ? 0 : 1) : promptLike.test(entry.key) ? 2 : 3;
    return entries.map((entry, index) => [rank(entry), index, entry]).sort((a, b) => a[0] - b[0] || a[1] - b[1]).map(([, , entry]) => entry);
}

// Native inputs and params as one object schema, so JSON edits and docs use the same validator.
export function capabilitySchema(capability, hidden = []) {
    if (!capability) return null;
    if (capability.contract_version === 2 && capability.input_schema) return capability.input_schema;
    const properties = {};
    const required = [];
    const ui = capability.ui || {};
    for (const input of capability.inputs || []) {
        if (hidden.includes(input.key)) continue;
        const title = ui.inputs?.[input.key]?.label || undefined;
        properties[input.key] = input.type === "asset"
            ? (input.single ? { type: "string", title, "x-native-asset": input.role || true } : { type: "array", title, items: { type: "string" }, "x-native-asset": input.role || true })
            : { type: "string", title, maxLength: 4000 };
        if (input.required) required.push(input.key);
    }
    for (const param of capability.params || []) {
        if (hidden.includes(param.name)) continue;
        const type = { integer: "integer", number: "number", boolean: "boolean", string: "string" }[param.type];
        properties[param.name] = {
            ...(type && !Array.isArray(param.options) ? { type } : {}), title: ui.params?.[param.name]?.label || undefined,
            ...(Array.isArray(param.options) ? { enum: param.options } : {}),
            ...(param.min != null ? { minimum: Number(param.min) } : {}), ...(param.max != null ? { maximum: Number(param.max) } : {}),
            ...(param.default !== null && param.default !== undefined ? { default: param.default } : {}),
            ...(ui.params?.[param.name]?.help ? { description: ui.params[param.name].help } : {}), ...(param.unit ? { "x-unit": param.unit } : {}),
        };
        if (param.required) required.push(param.name);
    }
    return { type: "object", properties, required, additionalProperties: false };
}

const empty = (value) => value === undefined || value === "" || (Array.isArray(value) && value.length === 0);
const at = (value, path) => path === "" ? value : path.split(".").reduce((node, key) => node === null || typeof node !== "object" ? undefined : node[key], value);

// JSON shows what the form holds: native empty optional values are not sent, so they are left out.
export function jsonDraftText(values, capability, hidden = []) {
    const native = capability?.contract_version !== 2;
    const visible = Object.fromEntries(Object.entries(values || {}).filter(([key, value]) => !hidden.includes(key) && !(native && empty(value))));
    return JSON.stringify(visible, null, 2);
}

function syntaxPosition(text, error) {
    const message = String(error?.message || "");
    const lineColumn = /line (\d+) column (\d+)/i.exec(message);
    if (lineColumn) return { line: Number(lineColumn[1]), column: Number(lineColumn[2]) };
    const position = /position (\d+)/i.exec(message);
    if (!position) return { line: null, column: null };
    const before = text.slice(0, Number(position[1]));
    return { line: before.split("\n").length, column: Number(position[1]) - before.lastIndexOf("\n") };
}

// Parses and validates edited JSON. Syntax errors and invalid values block applying; a field that is
// only missing or empty is "incomplete" and may be applied, because the form flags it before sending.
export function parseJsonDraft(text, capability, { hidden = [], values = {} } = {}) {
    let value;
    try {
        value = JSON.parse(text);
    } catch (error) {
        return { ok: false, value: null, syntax: syntaxPosition(text, error), blocking: {}, incomplete: {} };
    }
    if (value === null || typeof value !== "object" || Array.isArray(value)) {
        return { ok: false, value: null, syntax: null, blocking: { "": "JSON harus berupa objek dengan nama bidang sebagai kunci." }, incomplete: {} };
    }
    const blocking = {};
    const incomplete = {};
    hidden.filter((key) => Object.hasOwn(value, key)).forEach((key) => { blocking[key] = "Atur bidang ini melalui kontrol studio, bukan JSON."; });
    const native = capability?.contract_version !== 2;
    const merged = mergeJsonDraft(values, value, hidden);
    const errors = { ...schemaErrors(capabilitySchema(capability, hidden) || {}, value), ...(native ? capabilityErrors(capability, merged) : {}) };
    for (const [path, message] of Object.entries(errors)) {
        if (hidden.includes(path.split(".")[0])) continue;
        (empty(at(value, path)) ? incomplete : blocking)[path] ??= Array.isArray(message) ? message[0] : message;
    }
    return { ok: Object.keys(blocking).length === 0, value, syntax: null, blocking, incomplete };
}

// Studio-controlled keys (native quantity, Pro, composed prompts) keep their form values.
export function mergeJsonDraft(values, parsed, hidden = []) {
    return { ...Object.fromEntries(hidden.filter((key) => Object.hasOwn(values || {}, key)).map((key) => [key, values[key]])), ...parsed };
}

function typeLabel(schema, root) {
    if (schema["x-workspace-asset"] || schema["x-native-asset"]) return "file";
    if (schema.enum) return "enum";
    const variants = schemaVariants(schema).map((entry) => displaySchema(entry, root)).filter(Boolean);
    if (variants.length) return [...new Set(variants.map((entry) => typeLabel(entry, root)))].join(" | ");
    const type = Array.isArray(schema.type) ? schema.type.join(" | ") : schema.type || (schema.properties ? "object" : schema.items ? "array" : "any");
    if (type === "array" && schema.items && !Array.isArray(schema.items)) return `array<${typeLabel(displaySchema(schema.items, root) || {}, root)}>`;
    return type;
}

// Human-readable parameter reference; nested objects (such as provider `inputs`) one level deep.
export function schemaDocs(source, maxDepth = 1) {
    const rows = [];
    const visit = (input, path, depth) => {
        const schema = displaySchema(input, source) || {};
        for (const [key, child] of Object.entries(schema.properties || {})) {
            const resolved = displaySchema(child, source) || {};
            const variants = schemaVariants(resolved).map((entry) => displaySchema(entry, source)).filter(Boolean);
            const options = resolved.enum || variants.find((entry) => Array.isArray(entry.enum))?.enum || null;
            const numeric = [resolved, ...variants].find((entry) => entry.minimum != null || entry.maximum != null || entry.multipleOf != null) || {};
            const itemsOf = resolved.items && !Array.isArray(resolved.items) ? displaySchema(resolved.items, source) : null;
            rows.push({
                path: path ? `${path}.${key}` : key, name: key, depth, title: resolved.title || null, type: typeLabel(resolved, source),
                required: (schema.required || []).includes(key), default: resolved.default, options: Array.isArray(options) ? options.slice(0, 40) : null,
                minimum: numeric.minimum ?? resolved.minLength ?? resolved.minItems ?? null, maximum: numeric.maximum ?? resolved.maxLength ?? resolved.maxItems ?? null,
                step: numeric.multipleOf ?? null, unit: resolved["x-unit"] || null, description: resolved.description || null,
                file: (resolved["x-workspace-asset"] || itemsOf?.["x-workspace-asset"])?.kind || (resolved["x-native-asset"] ? "file" : null),
            });
            if (depth < maxDepth && resolved.properties) visit(resolved, rows[rows.length - 1].path, depth + 1);
        }
    };
    if (source) visit(source, "", 0);
    return rows;
}
