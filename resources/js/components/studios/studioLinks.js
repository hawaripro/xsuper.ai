// Deep links into the full-page media studio. `kind` is only a studio filter; job, track, model and
// operation keep the meaning the legacy studio pages gave them, which redirect here with their query.
export const STUDIO_KINDS = ["image", "video", "audio", "avatar", "model3d"];
export const LEGACY_STUDIO_PATHS = { "/media": "", "/generate-image": "image", "/video": "video", "/audio": "audio", "/avatar": "avatar", "/3d": "model3d" };

export const studioKind = (value) => STUDIO_KINDS.includes(value) ? value : "";

export function studioHref({ kind, model, operation, job, track } = {}) {
    const query = new URLSearchParams();
    if (studioKind(kind)) query.set("kind", kind);
    for (const [name, value] of Object.entries({ model, operation, job, track })) {
        if (value !== undefined && value !== null && value !== "") query.set(name, String(value));
    }
    const search = query.toString();
    return search ? `/studio?${search}` : "/studio";
}

// A legacy page's own kind replaces any kind in its query; every other parameter is kept in order.
export function legacyStudioHref(pathname, search = "") {
    const kind = LEGACY_STUDIO_PATHS[pathname] ?? "";
    const current = new URLSearchParams(search);
    const query = new URLSearchParams();
    const chosen = kind || studioKind(current.get("kind"));
    if (chosen) query.set("kind", chosen);
    for (const [name, value] of current) if (name !== "kind") query.append(name, value);
    const text = query.toString();
    return text ? `/studio?${text}` : "/studio";
}
