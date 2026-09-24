// JSON Schema stays authoritative on the server. These helpers preserve typed drafts and
// provide immediate feedback without translating schemas into provider-specific forms.
const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const object = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
const clone = (value) => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
export const schemaPath = (parent, key) => parent ? `${parent}.${key}` : String(key);
export const schemaType = (value) => value === null ? "null" : Array.isArray(value) ? "array" : typeof value;
export const schemaEqual = (a, b) => {
    if (a === b) return true;
    if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((value, index) => schemaEqual(value, b[index]));
    if (object(a) && object(b)) return Object.keys(a).length === Object.keys(b).length && Object.keys(a).every((key) => own(b, key) && schemaEqual(a[key], b[key]));
    return false;
};
export const uuidPattern = /^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/i;

// Mirrors the server policy for provider-fetched URL parameters. The browser only parses the
// text; it never requests the URL. WHATWG parsing already normalizes IPv4 forms such as
// 0x7f.1 or 2130706433 to dotted decimal and IPv6 literals to bracketed hexadecimal.
function privateIpv4(host) {
    const match = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(host);
    if (!match) return false;
    const [a, b, c] = match.slice(1).map(Number);
    return a === 0 || a === 10 || a === 127 || a >= 224
        || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31)
        || (a === 192 && ((b === 0 && (c === 0 || c === 2)) || b === 168 || (b === 88 && c === 99)))
        || (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100))) || (a === 203 && b === 0 && c === 113);
}
function nonGlobalIpv6(host) {
    if (!host.startsWith("[")) return false;
    const halves = host.slice(1, -1).split("::");
    if (halves.length > 2) return true;
    const head = halves[0] ? halves[0].split(":") : [];
    const tail = halves.length === 2 && halves[1] ? halves[1].split(":") : [];
    const words = [...head, ...Array(halves.length === 2 ? Math.max(0, 8 - head.length - tail.length) : 0).fill("0"), ...tail]
        .map((part) => /^[\da-f]{1,4}$/i.test(part) ? parseInt(part, 16) : NaN);
    if (words.length !== 8 || words.some(Number.isNaN)) return true;
    const [first, second] = words;
    // Only global unicast 2000::/3 is public, minus IETF/Teredo, documentation, 6to4 and 3fff::/20.
    return (first & 0xe000) !== 0x2000 || (first === 0x2001 && (second < 0x200 || second === 0xdb8)) || first === 0x2002 || (first === 0x3fff && second < 0x1000);
}
export function publicUrlError(value) {
    if (typeof value !== "string" || value === "" || /[\s\u0000-\u001f\u007f]/.test(value)) return "Masukkan URL lengkap yang valid, diawali https://.";
    if ([...value].length > 2048) return "URL terlalu panjang. Maksimal 2048 karakter.";
    let url;
    try { url = new URL(value); } catch { return "Masukkan URL lengkap yang valid, diawali https://."; }
    if (url.protocol !== "https:" || url.username || url.password) return "Gunakan URL HTTPS publik tanpa nama pengguna atau kata sandi.";
    const host = url.hostname.toLowerCase().replace(/\.$/, "");
    if (!host || host === "localhost" || /\.(?:localhost|local|internal)$/.test(host) || privateIpv4(host) || nonGlobalIpv6(host)) {
        return "URL harus mengarah ke alamat publik, bukan jaringan lokal atau privat.";
    }
    return null;
}

export function resolveSchema(schema, root = schema, visited = new Set()) {
    if (!object(schema) || !schema.$ref?.startsWith("#/")) return schema;
    if (visited.has(schema.$ref)) return schema;
    const target = schema.$ref.slice(2).split("/").reduce((value, key) => value?.[key.replace(/~1/g, "/").replace(/~0/g, "~")], root);
    if (target === undefined) return schema;
    const { $ref, ...siblings } = schema;
    return { ...resolveSchema(target, root, new Set([...visited, $ref])), ...siblings };
}

export function displaySchema(source, root = source) {
    const schema = resolveSchema(source, root);
    if (!object(schema)) return schema;
    if (!schema.allOf) return schema;
    const { allOf, ...base } = schema;
    return allOf.reduce((result, entry) => {
        const next = displaySchema(entry, root);
        if (!object(next)) return result;
        return {
            ...result, ...next,
            ...(result.properties || next.properties ? { properties: { ...result.properties, ...next.properties } } : {}),
            ...(result.required || next.required ? { required: [...new Set([...(result.required || []), ...(next.required || [])])] } : {}),
        };
    }, base);
}

export function schemaVariants(schema) {
    if (!object(schema)) return [];
    const { oneOf, anyOf, type, ...base } = schema;
    const branches = oneOf || anyOf;
    if (branches) return branches.map((branch) => ({ ...base, ...(type ? { type } : {}), ...branch,
        ...(base.properties || branch.properties ? { properties: { ...base.properties, ...branch.properties } } : {}),
        ...(base.required || branch.required ? { required: [...new Set([...(base.required || []), ...(branch.required || [])])] } : {}),
    }));
    if (Array.isArray(type)) return type.map((entry) => ({ ...base, type: entry }));
    if (schema.nullable && type !== "null") return [{ ...schema, nullable: false }, { type: "null", title: "null" }];
    return [];
}

export function schemaDefault(source, previous, required = true, root = source, depth = 0) {
    if (previous !== undefined) return clone(previous);
    if (depth > 32 || source === false) return undefined;
    const schema = displaySchema(source, root) || {};
    if (own(schema, "default")) return clone(schema.default);
    if (own(schema, "const")) return clone(schema.const);
    if (!required) return undefined;
    const variants = schemaVariants(schema);
    if (variants.length) return schemaDefault(variants.find((branch) => branch.type !== "null") || variants[0], undefined, true, root, depth + 1);
    if (schema.enum?.length) return clone(schema.enum[0]);
    const type = schema.type || (schema.properties || schema.additionalProperties ? "object" : schema.items || schema.prefixItems ? "array" : "string");
    if (type === "object") {
        return Object.fromEntries(Object.entries(schema.properties || {}).flatMap(([key, child]) => {
            const value = schemaDefault(child, undefined, schema.required?.includes(key), root, depth + 1);
            return value === undefined ? [] : [[key, value]];
        }));
    }
    if (type === "array") {
        const tuple = schema.prefixItems || (Array.isArray(schema.items) ? schema.items : []);
        // File lists start empty: every entry must be a real upload or link, never a blank placeholder.
        if (!tuple.length && displaySchema(schema.items || {}, root)?.["x-workspace-asset"]) return [];
        return Array.from({ length: schema.minItems || 0 }, (_, index) => schemaDefault(tuple[index] ?? (Array.isArray(schema.items) ? schema.additionalItems : schema.items) ?? {}, undefined, true, root, depth + 1));
    }
    if (type === "boolean") return false;
    if (type === "null") return null;
    // An empty required numeric control is incomplete, not an invented paid parameter.
    if (type === "integer" || type === "number") return "";
    return "";
}

export function schemaErrors(source, value, root = source, path = "", depth = 0) {
    const errors = {};
    const fail = (message, at = path) => { if (!errors[at]) errors[at] = message; };
    const merge = (child, data, at = path) => Object.assign(errors, schemaErrors(child, data, root, at, depth + 1));
    if (depth > 64) { fail("Struktur terlalu dalam."); return errors; }
    if (source === false) { fail("Nilai ini tidak diizinkan."); return errors; }
    if (source === true || source == null) return errors;
    const schema = resolveSchema(source, root);
    if (schema.$ref) { fail("Definisi input belum dapat dibaca. Muat ulang model."); return errors; }
    if (value === undefined) { fail("Input ini wajib disediakan."); return errors; }
    if (value === null && schema.nullable) return errors;
    if (schema.allOf) schema.allOf.forEach((child) => merge(child, value));
    for (const union of ["oneOf", "anyOf"]) {
        if (!schema[union]) continue;
        const results = schema[union].map((child) => schemaErrors(child, value, root, path, depth + 1));
        const matches = results.filter((result) => Object.keys(result).length === 0).length;
        if (matches === 0) {
            // Explain the branch that shares the value's JSON type (for example a nullable file) instead of a bare mismatch.
            const kinds = Number.isInteger(value) ? ["integer", "number"] : [schemaType(value)];
            const typed = schema[union].findIndex((child) => [resolveSchema(child, root)?.type].flat().some((type) => kinds.includes(type)));
            if (typed >= 0) Object.entries(results[typed]).forEach(([at, message]) => fail(message, at));
            else fail("Nilai harus memenuhi pilihan bentuk yang didukung.");
        } else if (union === "oneOf" && matches !== 1) fail("Nilai cocok dengan lebih dari satu bentuk. Sesuaikan agar hanya satu yang berlaku.");
    }
    if (schema.not && Object.keys(schemaErrors(schema.not, value, root, path, depth + 1)).length === 0) fail("Kombinasi nilai ini tidak diizinkan.");
    if (schema.if) {
        const branch = Object.keys(schemaErrors(schema.if, value, root, path, depth + 1)).length === 0 ? schema.then : schema.else;
        if (branch) merge(branch, value);
    }
    if (own(schema, "const") && !schemaEqual(value, schema.const)) fail("Nilai tetap ini tidak dapat diubah.");
    if (schema.enum && !schema.enum.some((option) => schemaEqual(option, value))) fail("Pilihan tidak didukung oleh model ini.");
    const types = Array.isArray(schema.type) ? schema.type : schema.type ? [schema.type] : [];
    if (value === "" && types.length && types.every((type) => type === "integer" || type === "number")) { fail("Input ini wajib disediakan."); return errors; }
    if (types.length && !types.some((type) => type === "integer" ? Number.isInteger(value) : type === "number" ? typeof value === "number" && Number.isFinite(value) : type === schemaType(value))) {
        fail("Jenis nilai tidak sesuai dengan model ini.");
        return errors;
    }
    if (typeof value === "number") {
        if (!Number.isFinite(value)) fail("Masukkan angka yang valid.");
        if (schema.minimum != null && value < schema.minimum || typeof schema.exclusiveMinimum === "number" && value <= schema.exclusiveMinimum || schema.exclusiveMinimum === true && value <= schema.minimum) fail("Nilai di bawah batas minimum model ini.");
        if (schema.maximum != null && value > schema.maximum || typeof schema.exclusiveMaximum === "number" && value >= schema.exclusiveMaximum || schema.exclusiveMaximum === true && value >= schema.maximum) fail("Nilai melebihi batas maksimum model ini.");
        if (schema.multipleOf > 0 && Math.abs(value / schema.multipleOf - Math.round(value / schema.multipleOf)) > 1e-8) fail(`Gunakan kelipatan ${schema.multipleOf}.`);
    }
    const asset = schema["x-workspace-asset"];
    if (typeof value === "string" && asset) {
        // A file leaf holds an owned upload UUID or, when the model accepts it, a public link.
        if (value === "") fail(asset.accepts_url === true ? "Unggah berkas atau masukkan tautan publik." : "Pilih atau unggah berkas milik Anda.");
        else if (!uuidPattern.test(value)) {
            const problem = asset.accepts_url === true ? publicUrlError(value) : "Pilih atau unggah berkas milik Anda.";
            if (problem) fail(problem);
        }
    } else if (typeof value === "string") {
        const length = [...value].length;
        if (schema.minLength != null && length < schema.minLength) fail("Teks terlalu pendek.");
        if (schema.maxLength != null && length > schema.maxLength) fail("Teks terlalu panjang.");
        if (schema.pattern) {
            try { if (!new RegExp(schema.pattern, "u").test(value)) fail("Teks tidak sesuai pola yang disyaratkan."); } catch { /* The server validates dialect-specific patterns. */ }
        }
        if (schema.format === "uuid" && !uuidPattern.test(value)) fail("Masukkan UUID yang valid.");
        if (schema.format === "uri" || schema.format === "url") {
            const problem = publicUrlError(value);
            if (problem) fail(problem);
        }
    }
    if (Array.isArray(value)) {
        if (schema.minItems != null && value.length < schema.minItems) fail(`Minimal ${schema.minItems} item.`);
        if (schema.maxItems != null && value.length > schema.maxItems) fail(`Maksimal ${schema.maxItems} item.`);
        if (schema.uniqueItems && value.some((entry, index) => value.slice(0, index).some((other) => schemaEqual(entry, other)))) fail("Setiap item harus berbeda.");
        const tuple = schema.prefixItems || (Array.isArray(schema.items) ? schema.items : []);
        value.forEach((entry, index) => merge(tuple[index] ?? (Array.isArray(schema.items) ? schema.additionalItems ?? true : schema.items ?? true), entry, schemaPath(path, index)));
        if (schema.contains) {
            const matches = value.filter((entry) => Object.keys(schemaErrors(schema.contains, entry, root, path, depth + 1)).length === 0).length;
            if (matches < (schema.minContains ?? 1) || schema.maxContains != null && matches > schema.maxContains) fail("Jumlah item yang memenuhi syarat tidak sesuai.");
        }
    }
    if (object(value)) {
        const keys = Object.keys(value);
        if (schema.minProperties != null && keys.length < schema.minProperties) fail(`Minimal ${schema.minProperties} bidang.`);
        if (schema.maxProperties != null && keys.length > schema.maxProperties) fail(`Maksimal ${schema.maxProperties} bidang.`);
        for (const key of schema.required || []) if (!own(value, key) || value[key] === undefined) fail("Input ini wajib disediakan.", schemaPath(path, key));
        for (const key of keys) {
            const at = schemaPath(path, key);
            let declared = own(schema.properties || {}, key);
            if (declared) merge(schema.properties[key], value[key], at);
            for (const [pattern, child] of Object.entries(schema.patternProperties || {})) {
                try { if (new RegExp(pattern, "u").test(key)) { declared = true; merge(child, value[key], at); } } catch { /* Server authority. */ }
            }
            if (!declared && schema.additionalProperties !== undefined) merge(schema.additionalProperties, value[key], at);
            if (schema.propertyNames) merge(schema.propertyNames, key, at);
            const dependency = schema.dependentRequired?.[key] || schema.dependencies?.[key];
            if (Array.isArray(dependency)) {
                for (const required of dependency) if (!own(value, required)) fail("Bidang terkait wajib disediakan.", schemaPath(path, required));
            } else if (object(dependency)) merge(dependency, value);
            if (schema.dependentSchemas?.[key]) merge(schema.dependentSchemas[key], value);
        }
    }
    return errors;
}
