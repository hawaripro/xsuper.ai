import { displaySchema, schemaVariants } from "./schema";

// Fixed-form studio behaviour for native (contract v1) operations inside the unified workspace.
// Texts and prompt compositions are the ones the dedicated studios sent before the unification.
export const VIDEO_ANGLES = [
    { id: "closeup", label: "Close-up Detail", prompt: "Extreme close-up shot focusing on product details, texture, and craftsmanship. Macro lens feel, shallow depth of field." },
    { id: "lifestyle", label: "Lifestyle / In-Use", prompt: "Product being used naturally in an everyday setting. Authentic, relatable, warm lighting." },
    { id: "spin", label: "360° Product Spin", prompt: "Product rotating on a clean surface, showing all angles. Smooth turntable rotation, studio lighting." },
    { id: "cinematic", label: "Cinematic Hero Shot", prompt: "Cinematic hero shot of the product, dramatic lighting, slow reveal, premium feel." },
    { id: "beforeafter", label: "Before & After", prompt: "A clear visual comparison showing the product before and after use, with a natural transition." },
];
export const VIDEO_STORIES = [
    { id: "problem", label: "Problem → Solution", prompt: "Talent shows a common problem, then introduces the product as the solution. Natural setting and delivery." },
    { id: "impression", label: "First Impression Jujur", prompt: "Talent opens and tries the product for the first time on camera. First-impression presentation, selfie-style camera." },
    { id: "beforeafter", label: "Before & After Rutinitas", prompt: "Talent shows their routine before the product, then after. Day-in-life style." },
    { id: "routine", label: "Bagian dari Hari-hari", prompt: "Product integrated into a daily routine. Morning or evening ritual, cozy setting." },
    { id: "friend", label: "Rekomendasi ke Teman", prompt: "Talent talking directly to camera about the product. Casual, conversational tone." },
];
export const VIDEO_TABS = [
    { id: "prompt", label: "Teks ke video", icon: "prompt" },
    { id: "reference", label: "Gambar ke video", icon: "video" },
    { id: "product", label: "Produk", icon: "product" },
    { id: "ugc", label: "UGC", icon: "ugc" },
];
export const VIDEO_DEFAULTS = { tab: "prompt", prompt: "", product: "", features: "", angles: "closeup,lifestyle", story: "problem", cta: "", variations: false, withReference: false };
export const VIDEO_PROMPT_LIMIT = 4000;

const composed = (draft) => draft.tab === "product" || draft.tab === "ugc";

export function nativeStudio(capability) {
    if (!capability || capability.contract_version !== 1) return null;
    if (["text_to_video", "image_to_video"].includes(capability.operation)) return "video";
    if (capability.operation === "talking_avatar") return "avatar";
    if (["text_to_speech", "music"].includes(capability.operation)) return "audio";
    return ["image", "model3d"].includes(capability.output_kind) ? capability.output_kind : null;
}

// Only text/settings are restored, typed like the defaults; never files, outputs or credentials.
export function restoreDraft(stored, defaults) {
    if (!stored || typeof stored !== "object" || Array.isArray(stored)) return { ...defaults };
    return Object.fromEntries(Object.entries(defaults).map(([name, value]) => [name,
        typeof stored[name] === typeof value && (typeof value !== "string" || stored[name].length <= 8000) ? stored[name] : value]));
}

export function composeVideoPrompt(draft) {
    if (!composed(draft)) return draft.prompt.trim();
    const parts = [`Create a ${draft.tab === "ugc" ? "UGC-style" : "product"} video for "${draft.product.trim()}".`];
    if (draft.features.trim()) parts.push(`Key features: ${draft.features.trim()}.`);
    if (draft.tab === "product") {
        const chosen = draft.angles.split(",");
        VIDEO_ANGLES.forEach((angle) => { if (chosen.includes(angle.id)) parts.push(angle.prompt); });
    } else {
        const story = VIDEO_STORIES.find((item) => item.id === draft.story);
        if (story) parts.push(story.prompt);
    }
    return parts.join(" ");
}

// Execution envelope: product/UGC are A/B creative modes; the CTA and per-video variation are
// creative directions the server appends, so a prompt tab never carries a CTA typed elsewhere.
export function videoExecution(draft, count) {
    const cta = composed(draft) ? draft.cta.trim() : "";
    return { mode: composed(draft) ? "ab_testing" : "prompt", ...(cta ? { cta } : {}), ugc_variation: count > 1 && draft.variations === true };
}

export function videoAuthoringErrors(draft) {
    if (composed(draft) ? !draft.product.trim() : !draft.prompt.trim()) return composed(draft) ? { product: "Isi nama produk." } : { prompt: "Tulis prompt video." };
    return composeVideoPrompt(draft).length > VIDEO_PROMPT_LIMIT ? { prompt: "Prompt terlalu panjang. Kurangi detail hingga 4000 karakter." } : {};
}

// The image tab always needs image-to-video; product/UGC use it only when a reference is chosen.
// A model that requires a reference (as its native facts declare) never sends a text-only request.
export function videoOperation(tab, withReference, operations, referenceRequired = false) {
    const text = operations.includes("text_to_video");
    const image = operations.includes("image_to_video");
    if (tab === "reference" || (referenceRequired && image)) return image ? "image_to_video" : null;
    if (tab !== "prompt" && withReference && image) return "image_to_video";
    return text ? "text_to_video" : image ? "image_to_video" : null;
}

export function voiceOptions(param, voices = []) {
    return (Array.isArray(param?.options) ? param.options : []).map((id) => {
        const known = voices.find((voice) => voice.id === id);
        return { id, label: known?.label || id, language: known?.language || null };
    });
}

export const englishOnly = (voices) => voices.length > 0 && voices.every((voice) => /^en(?:-|$)/i.test(voice.language || ""));

export function promptLimit(native) {
    const limit = Number(native?.audio?.max_characters);
    return limit > 0 ? Math.min(VIDEO_PROMPT_LIMIT, limit) : VIDEO_PROMPT_LIMIT;
}

function collectAssets(source, value, root, found, depth) {
    if (depth > 32 || value === undefined || value === null) return;
    const schema = displaySchema(source, root);
    if (!schema || typeof schema !== "object") return;
    if (schema["x-workspace-asset"]) { found.push(value); return; }
    const variants = schemaVariants(schema);
    if (variants.length) { variants.forEach((variant) => collectAssets(variant, value, root, found, depth + 1)); return; }
    if (Array.isArray(value)) {
        const tuple = schema.prefixItems || (Array.isArray(schema.items) ? schema.items : null);
        value.forEach((entry, index) => {
            const child = tuple ? tuple[index] ?? (typeof schema.additionalItems === "object" ? schema.additionalItems : null) : schema.items;
            if (child) collectAssets(child, entry, root, found, depth + 1);
        });
    } else if (typeof value === "object") {
        Object.keys(value).sort().forEach((key) => {
            const child = schema.properties?.[key] ?? (typeof schema.additionalProperties === "object" ? schema.additionalProperties : null);
            if (child) collectAssets(child, value[key], root, found, depth + 1);
        });
    }
}

// Identity of the files a request would use: permission to use a face or voice covers these files only.
export function assetSignature(capability, values = {}) {
    if (capability?.contract_version === 2 && capability.input_schema) {
        const found = [];
        collectAssets(capability.input_schema, values, capability.input_schema, found, 0);
        return JSON.stringify(found);
    }
    return JSON.stringify((capability?.inputs || []).filter((input) => input.type === "asset").map((input) => values?.[input.key] ?? null));
}

export const jobTitle = (job) => job?.details?.prompt || job?.details?.model_label || job?.model || "";

// Model and recorded settings; a duration is returned as { seconds } so the caller can localise its unit.
export function jobSummary(job) {
    const details = job?.details || {};
    const shown = (value) => value && value !== "auto" ? value : null;
    return [job?.model, shown(details.size), shown(details.aspect_ratio), details.duration ? { seconds: details.duration } : null, details.pro_mode ? "Pro" : null].filter(Boolean);
}
