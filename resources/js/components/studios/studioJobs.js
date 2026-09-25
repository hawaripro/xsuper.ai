import { ownedMediaUrl } from "./mediaOutput";
import { mediaJobPending } from "./workspaceMedia";

// Workbench views of member jobs: filters, sets of variations, reusable drafts and seeds. Only the
// member's own request data is read; provider costs never reach these payloads.
export const HISTORY_KINDS = [
    { id: "", label: "Semua" }, { id: "image", label: "Gambar" }, { id: "video", label: "Video" }, { id: "audio", label: "Audio" },
    { id: "avatar", label: "Avatar" }, { id: "model3d", label: "3D" }, { id: "other", label: "Lainnya" },
];
export const STATUS_FILTERS = [
    { id: "", label: "Semua status" }, { id: "running", label: "Berjalan" }, { id: "done", label: "Selesai" }, { id: "failed", label: "Gagal" },
];
const STUDIO = ["image", "video", "audio", "model3d"];

export function jobStatusGroup(job) {
    if (mediaJobPending(job)) return "running";
    return job?.status === "completed" ? "done" : "failed";
}

// Client-side reading of the server's kind filter for jobs created in this visit, until the history
// reloads. Avatar-category schema models are only known to the server, which then corrects the list.
export function jobMatchesKind(job, kind) {
    if (!kind) return true;
    if (kind === "avatar") return job?.operation === "talking_avatar";
    if (kind === "video") return job?.output_kind === "video" && job?.operation !== "talking_avatar";
    if (kind === "other") return !STUDIO.includes(job?.output_kind);
    return job?.output_kind === kind;
}

// Several native image jobs of one request are shown as one card; the first loaded member stands for the set.
export function groupJobs(jobs) {
    const seen = new Set();
    return jobs.flatMap((job) => {
        const members = Array.isArray(job?.batch?.jobs) && job.batch.jobs.length > 1 ? job.batch.jobs : null;
        const key = members ? `batch:${members[0]}` : job.id;
        if (seen.has(key)) return [];
        seen.add(key);
        return [{ key, job, members: members || [job.id] }];
    });
}

export function jobThumbnail(job) {
    const image = (job?.outputs || []).find((output) => output.kind === "image" && output.previewable && /^image\/(png|jpeg|webp|gif|avif)$/.test(output.mime || "") && ownedMediaUrl(output.url));
    return image ? ownedMediaUrl(image.url) : null;
}

// A playable owned video for a card's first frame; audio, 3D and files show their kind instead.
export function jobVideoPreview(job) {
    const video = (job?.outputs || []).find((output) => output.kind === "video" && output.previewable && /^video\//.test(output.mime || "") && ownedMediaUrl(output.url));
    return video ? ownedMediaUrl(video.url) : null;
}

// Time since a job was created in the largest whole unit, for "5 menit lalu"; older than four weeks
// (or unreadable) returns null so callers show the date instead.
const UNITS = [["week", 604800], ["day", 86400], ["hour", 3600], ["minute", 60]];
export function timeAgo(iso, now = Date.now()) {
    const time = Date.parse(iso);
    if (!Number.isFinite(time)) return null;
    const seconds = Math.max(0, Math.floor((now - time) / 1000));
    if (seconds < 60) return { value: 0, unit: "second" };
    if (seconds >= 4 * 604800) return null;
    const [unit, size] = UNITS.find(([, length]) => seconds >= length);
    return { value: Math.floor(seconds / size), unit };
}

// Tokens this job reserved or will be billed; legacy USD-billed rows have no token figure.
export function jobTokens(job) {
    const details = job?.details || {};
    const reserved = details.billing_mode === "tokens" && details.tokens_reserved != null ? Number(details.tokens_reserved) : null;
    const price = job?.price_tokens != null ? Number(job.price_tokens) : null;
    const value = reserved ?? price;
    return Number.isFinite(value) ? value : null;
}

const seedValue = (value) => (typeof value === "number" && Number.isSafeInteger(value)) || (typeof value === "string" && /^\d{1,20}$/.test(value)) ? String(value) : null;

// The seed a result used: schema jobs report it per result item (matched to the stored output by its
// download link) or once for the whole result.
export function jobSeed(job, output = null) {
    const data = job?.result_data;
    if (Array.isArray(data)) {
        const items = data.filter((item) => item && typeof item === "object");
        const matched = output?.download_url ? items.find((item) => item.download_url === output.download_url) : null;
        return seedValue(matched?.seed) ?? (matched ? null : seedValue(items.find((item) => seedValue(item.seed))?.seed));
    }
    return data && typeof data === "object" ? seedValue(data.seed) : null;
}

const clone = (value) => JSON.parse(JSON.stringify(value));
const nativeFields = {
    image: ["size"], video: ["aspect_ratio", "duration"], audio: ["voice", "speed", "duration", "tempo", "instrumental", "custom"],
};

// What "Muat ke form" restores: a schema job's own normalized request (text, settings and references
// to the member's own uploads), or a native job's recorded settings. Outputs and billing never are.
export function jobDraft(job) {
    const request = job?.request;
    if (request && typeof request === "object" && typeof request.model_id === "string" && typeof request.operation === "string"
        && request.inputs && typeof request.inputs === "object" && !Array.isArray(request.inputs)) {
        return { model: request.model_id, operation: request.operation, values: clone(request.inputs) };
    }
    if (typeof job?.model !== "string" || !job.model || typeof job.operation !== "string") return null;
    const details = job.details || {};
    const values = {};
    if (typeof details.prompt === "string" && details.prompt.trim()) values.prompt = details.prompt;
    for (const name of nativeFields[job.output_kind] || []) {
        if (details[name] !== undefined && details[name] !== null && details[name] !== "") values[name] = details[name];
    }
    const video = job.output_kind === "video" && ["text_to_video", "image_to_video"].includes(job.operation);
    return { model: job.model, operation: job.operation, values,
        ...(video ? { authoring: { tab: job.operation === "image_to_video" ? "reference" : "prompt", prompt: values.prompt || "" } } : {}) };
}

// Schema jobs record their prompt under the model's own field (e.g. positivePrompt).
const promptKeys = /^(prompt|positive_?prompt|text|lyrics|script|caption)$/i;
export function jobPrompt(job) {
    const prompt = job?.details?.prompt;
    if (typeof prompt === "string" && prompt.trim()) return prompt;
    const inputs = job?.request?.inputs;
    if (!inputs || typeof inputs !== "object" || Array.isArray(inputs)) return null;
    const found = Object.entries(inputs).find(([key, value]) => promptKeys.test(key) && typeof value === "string" && value.trim());
    return found ? found[1] : null;
}

export const BILLING_LABELS = { reserved: "Dicadangkan", settled: "Dibebankan", released: "Dikembalikan" };

// Request parameters shown in the detail view, without the prompt and nested file references.
export function jobParameters(job) {
    const inputs = job?.request?.inputs;
    if (inputs && typeof inputs === "object" && !Array.isArray(inputs)) {
        return Object.entries(inputs).filter(([key, value]) => !/prompt/i.test(key) && value !== null && value !== undefined && value !== "" && typeof value !== "object")
            .map(([key, value]) => [key, String(value)]);
    }
    const details = job?.details || {};
    return (nativeFields[job?.output_kind] || []).filter((name) => details[name] !== undefined && details[name] !== null && details[name] !== "")
        .map((name) => [name, String(details[name])]).concat(details.pro_mode ? [["pro", "Pro"]] : []);
}
