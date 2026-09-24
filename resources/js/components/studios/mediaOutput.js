const ownedPaths = [
    /^\/api\/media\/workspace\/jobs\/[^/]+\/outputs\/[^/]+\/(?:preview|download)$/,
    /^\/api\/(?:images|audio)\/[^/]+\/assets\/\d+$/,
    /^\/api\/(?:3d|avatar|v)\/[^/]+\/asset$/,
    /^\/api\/v\/[^/]+\/reference$/,
    /^\/api\/c\/artifacts\/[^/]+\/(?:revisions\/[^/]+\/)?(?:preview|download)$/,
    /^\/api\/media\/assets\/[^/]+(?:\/download)?$/,
];

export function ownedMediaUrl(value, origin = globalThis.location?.origin) {
    if (typeof value !== "string" || !value || !origin) return null;
    try {
        const url = new URL(value, origin);
        if (url.origin !== origin || !["http:", "https:"].includes(url.protocol) || url.username || url.password || /%(?:2f|5c|2e)/i.test(url.pathname)) return null;
        if (!ownedPaths.some((pattern) => pattern.test(url.pathname))) return null;
        return `${url.pathname}${url.search}`;
    } catch { return null; }
}

// model-viewer is only given an internally created blob of a self-contained GLB.
// Arbitrary URLs hidden in meshes, textures or extension metadata never load.
export function selfContainedGlb(buffer) {
    if (!(buffer instanceof ArrayBuffer) || buffer.byteLength < 20) return false;
    const view = new DataView(buffer);
    if (view.getUint32(0, true) !== 0x46546c67 || view.getUint32(4, true) !== 2 || view.getUint32(8, true) !== buffer.byteLength) return false;
    const length = view.getUint32(12, true);
    if (view.getUint32(16, true) !== 0x4e4f534a || length > buffer.byteLength - 20) return false;
    let document;
    try { document = JSON.parse(new TextDecoder().decode(new Uint8Array(buffer, 20, length)).trim()); } catch { return false; }
    const safe = (value, depth = 0) => {
        if (depth > 64) return false;
        if (!value || typeof value !== "object") return true;
        return Object.entries(value).every(([key, child]) => key === "uri"
            ? typeof child === "string" && /^data:(?:image\/(?:png|jpeg|webp)|application\/(?:octet-stream|gltf-buffer));base64,[A-Za-z0-9+/=\s]+$/i.test(child)
            : safe(child, depth + 1));
    };
    return safe(document);
}

export async function readOwnedMedia(url, maxBytes, signal) {
    const safe = ownedMediaUrl(url);
    if (!safe) throw new Error("Only owned media can be previewed.");
    const response = await fetch(safe, { credentials: "same-origin", redirect: "error", signal });
    if (!response.ok || Number(response.headers.get("content-length")) > maxBytes || !response.body) throw new Error("Preview unavailable or too large. Download the original.");
    const reader = response.body.getReader();
    const chunks = [];
    let size = 0;
    try {
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            size += value.byteLength;
            if (size > maxBytes) throw new Error("Preview too large. Download the original.");
            chunks.push(value);
        }
    } catch (error) {
        await reader.cancel().catch(() => {});
        throw error;
    } finally { reader.releaseLock(); }
    const result = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) { result.set(chunk, offset); offset += chunk.byteLength; }
    return result.buffer;
}
