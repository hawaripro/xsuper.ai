// Model palette: categories, local "recent" and "favorite" lists and their filtering. The lists are
// display hints kept per member in this browser; the server re-checks every selected model.
export const PALETTE_CATEGORIES = [
    { id: "recent", label: "Terakhir dipakai", icon: "history" },
    { id: "favorites", label: "Favorit", icon: "star" },
    { id: "all", label: "Semua", kind: "", icon: "spark" },
    { id: "image", label: "Gambar", kind: "image", icon: "image" },
    { id: "video", label: "Video", kind: "video", icon: "video" },
    { id: "audio", label: "Audio", kind: "audio", icon: "audio" },
    { id: "avatar", label: "Avatar", kind: "avatar", icon: "ugc" },
    { id: "model3d", label: "3D", kind: "model3d", icon: "model3d" },
    { id: "other", label: "Lainnya", kind: "other", icon: "code" },
];
export const LOCAL_CATEGORIES = ["recent", "favorites"];
export const RECENT_LIMIT = 12;
export const FAVORITE_LIMIT = 50;
const STUDIO = ["image", "video", "audio", "model3d"];

// Studio kinds of a model exactly as the server filters them: avatar-category models belong to the
// avatar studio only; data and file outputs are "other".
export function modelKinds(model) {
    if (!model) return [];
    if (model.category === "avatar") return ["avatar"];
    return [...new Set((model.operations || []).map((entry) => STUDIO.includes(entry?.output_kind) ? entry.output_kind : "other"))];
}

// The URL kind after choosing a model: the chosen operation's studio, else the current kind when the
// model belongs to it, else the model's first studio ("" for data- or file-only models).
export function selectionKind(model, operation = "", current = "") {
    if (model?.category === "avatar") return "avatar";
    const chosen = (model?.operations || []).find((entry) => entry?.operation === operation);
    if (chosen && STUDIO.includes(chosen.output_kind)) return chosen.output_kind;
    const kinds = modelKinds(model).filter((entry) => entry !== "other");
    return kinds.includes(current) ? current : kinds[0] || "";
}

// The studio a loaded capability runs in, as the server files its jobs.
export function capabilityKind(model, capability) {
    if (!capability) return "";
    if (model?.category === "avatar") return "avatar";
    return STUDIO.includes(capability.output_kind) ? capability.output_kind : "";
}

export function startingPrice(model) {
    const prices = (model?.operations || []).map((entry) => Number(entry?.price_tokens)).filter((price) => Number.isSafeInteger(price) && price > 0);
    return prices.length ? Math.min(...prices) : null;
}

export function safeLogoUrl(value) {
    if (typeof value !== "string" || value.length > 2048) return null;
    try {
        const url = new URL(value);
        return url.protocol === "https:" && !url.username && !url.password ? url.href : null;
    } catch { return null; }
}

const text = (value, limit) => typeof value === "string" && value.trim() ? value.slice(0, limit) : null;
export function sanitizeModel(entry) {
    const id = text(entry?.model_id, 200);
    if (!id) return null;
    return {
        model_id: id, name: text(entry.name, 200) || id, provider_name: text(entry.provider_name, 120), category: text(entry.category, 40),
        description: text(entry.description, 240), logo_url: safeLogoUrl(entry.logo_url),
        operations: (Array.isArray(entry.operations) ? entry.operations : []).slice(0, 20).flatMap((operation) => text(operation?.operation, 60) ? [{
            operation: operation.operation, output_kind: text(operation.output_kind, 30),
            price_tokens: Number.isSafeInteger(operation.price_tokens) ? operation.price_tokens : null, price_unit: text(operation.price_unit, 30),
        }] : []),
    };
}

export function readModels(value) {
    try {
        const list = JSON.parse(value || "[]");
        return Array.isArray(list) ? list.map(sanitizeModel).filter(Boolean) : [];
    } catch { return []; }
}

export function rememberModel(list, model, limit = RECENT_LIMIT) {
    const clean = sanitizeModel(model);
    return clean ? [clean, ...list.filter((entry) => entry.model_id !== clean.model_id)].slice(0, limit) : list;
}

export function toggleFavorite(list, model, limit = FAVORITE_LIMIT) {
    if (list.some((entry) => entry.model_id === model?.model_id)) return list.filter((entry) => entry.model_id !== model.model_id);
    const clean = sanitizeModel(model);
    return clean ? [clean, ...list].slice(0, limit) : list;
}

export function matchesQuery(model, query) {
    const needle = String(query || "").trim().toLowerCase();
    if (!needle) return true;
    const haystack = [model?.name, model?.provider_name, model?.model_id, model?.description].filter(Boolean).join(" ").toLowerCase();
    return needle.split(/\s+/).every((part) => haystack.includes(part));
}

// Recent and favorite lists are searched locally; the other categories ask the server.
export function localModels(list, query) {
    return list.filter((model) => matchesQuery(model, query));
}

export const categoryKind = (category) => PALETTE_CATEGORIES.find((entry) => entry.id === category)?.kind ?? "";
export const defaultCategory = (kind, recent = []) => PALETTE_CATEGORIES.some((entry) => entry.id === kind && entry.kind) ? kind : recent.length ? "recent" : "all";
