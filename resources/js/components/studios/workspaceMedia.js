import { displaySchema } from "./schema";

export const workspaceDraftKey = (model, operation) => JSON.stringify([model, operation]);
export const mediaJobPending = (job) => !["completed", "failed", "cancelled", "rejected", "save_failed", "uncertain"].includes(job?.status)
    && (["pending", "queued", "submitting", "processing", "generating", "saving", "cancel_requested"].includes(job?.status)
        || ["queued", "preparing", "submitting", "processing", "rendering", "saving", "cancel_requested"].includes(job?.stage));

// Several native image jobs of one request read as one set of variations: every stored image in request
// order (ids stay distinct per job), with how many finished, ended without an image, run or are not loaded.
export const batchView = (job, jobs) => {
    const ids = job?.batch?.jobs;
    if (!Array.isArray(ids) || ids.length < 2) return null;
    const byId = new Map(jobs.map((entry) => [entry.id, entry]));
    byId.set(job.id, job);
    const loaded = ids.map((id) => byId.get(id)).filter(Boolean);
    return {
        size: ids.length,
        outputs: loaded.flatMap((member) => (member.outputs || []).map((output) => ({ ...output, id: `${member.id}#${output.id}`, job_id: member.id }))),
        done: loaded.filter((member) => member.status === "completed").length,
        failed: loaded.filter((member) => ["failed", "cancelled", "rejected", "uncertain", "save_failed"].includes(member.status)).length,
        pending: ids.filter((id) => !byId.has(id) || mediaJobPending(byId.get(id))),
        missing: ids.filter((id) => !byId.has(id)),
    };
};

// Refusals before admission (session, CSRF, account or permission middleware, throttle, storage gate) say
// nothing about an earlier attempt whose response was lost: once a key is uncertain, only the server's answer
// for that key (its job, a changed-input conflict or a validation of the same body) may release it.
const preAdmission = new Set([401, 403, 413, 419, 429]);
export const submissionUnresolved = (failure, resume = false) => !failure?.status || failure.status >= 500
    || failure.details?.uncertain === true || (resume && preAdmission.has(failure.status));

// A realtime start settles only when the server reports the session's outcome: any other server error may
// follow an offer the provider already accepted, so the same key is replayed or reported as unknown.
export const realtimeOutcomeUnknown = (error) => !error?.status || (error.status >= 500 && !error.details?.session);

// Native studio links carry a bare native job id; the common API types it by native kind (avatar
// videos are native video jobs). /media links already carry the common id, which is used verbatim.
// A bare id on a studio page may also be a v2 job selected there, so it is the fallback candidate.
const nativeKinds = { image: "image", video: "video", avatar: "video", audio: "audio", model3d: "model3d" };
export function jobCandidates(id, kind) {
    if (typeof id !== "string" || !/^[A-Za-z0-9:_-]{1,100}$/.test(id)) return [];
    return id.includes(":") || !nativeKinds[kind] ? [id] : [`${nativeKinds[kind]}:${id}`, id];
}

// Whole billed seconds exactly as the server parses them: integers, digit strings and "8s".
export function billedSeconds(value) {
    if (typeof value === "number") return Number.isSafeInteger(value) && value >= 1 ? value : null;
    if (typeof value === "string" && /^\d{1,9}s?$/.test(value)) {
        const seconds = Number.parseInt(value, 10);
        return seconds >= 1 ? seconds : null;
    }
    return null;
}

function schemaDuration(capability) {
    const root = capability?.input_schema;
    const property = displaySchema(root, root)?.properties?.duration;
    return property === undefined ? undefined : displaySchema(property, root)?.default;
}

// The admission price is what one admitted job reserves (expected_price_tokens); count multiplies
// only the total. `reason` explains why a per-second tariff cannot be quoted yet.
export function workspaceQuote(capability, values = {}, controls = {}) {
    const base = Number(capability?.price_tokens);
    const billing = capability?.billing || {};
    const native = capability?.contract_version !== 2;
    const count = native && billing.count_field ? Number(controls.count ?? 1) : 1;
    const pro = native && Boolean(billing.pro_field) && controls.pro === true;
    const perSecond = billing.mode === "per_second";
    const durations = Array.isArray(billing.durations) ? billing.durations : [];
    let seconds = null;
    let reason = null;
    if (perSecond) {
        seconds = billing.duration_field === "billing_seconds"
            ? billedSeconds(controls.billing_seconds)
            : billedSeconds(values?.duration !== undefined ? values.duration : schemaDuration(capability));
        if (billing.duration_field === "billing_seconds" && !durations.length) reason = "Durasi tagihan per detik belum ditinjau pengelola. Operasi ini belum dapat dipakai.";
        else if (seconds == null) reason = billing.duration_field === "billing_seconds" ? "Pilih durasi yang ditagihkan." : "Tarif per detik memerlukan durasi eksplisit dalam detik bulat.";
        else if (durations.length && !durations.includes(seconds)) reason = "Durasi ini belum ditinjau untuk tarif per detik. Pilih durasi lain.";
    }
    const multiplier = !perSecond && pro ? Number(billing.pro_multiplier) : 1;
    const empty = { unit: Number.isSafeInteger(base) && base > 0 ? base : null, admission: null, total: null, count, pro, seconds, reason };
    if (reason || !Number.isSafeInteger(base) || base <= 0 || !Number.isSafeInteger(count) || count < 1 || count > (billing.max_count || 1)
        || !Number.isSafeInteger(multiplier) || multiplier < 1) return empty;
    const admission = base * (perSecond ? seconds : 1) * multiplier;
    const total = admission * count;
    return admission <= 2147483647 && total <= 2147483647 ? { ...empty, admission, total } : empty;
}

export function workspaceValidation(error) {
    return Object.fromEntries(Object.entries(error?.details?.errors || {}).map(([key, value]) => [key.replace(/^inputs\.?/, ""), value]));
}

export const operationLabel = (operation) => ({
    text_to_image: "Teks ke gambar", image_edit: "Edit gambar", image_to_image: "Gambar ke gambar", text_to_video: "Teks ke video", image_to_video: "Gambar ke video",
    audio_to_video: "Audio ke video", talking_avatar: "Avatar berbicara", text_to_speech: "Teks ke suara", music: "Musik",
    text_to_3d: "Teks ke 3D", image_to_3d: "Gambar ke 3D", model3d_to_model3d: "Transformasi 3D", video_to_video: "Video ke video",
    video_to_audio: "Video ke audio", video_to_text: "Video ke teks", audio_to_audio: "Audio ke audio", audio_to_text: "Audio ke teks",
    speech_to_text: "Ucapan ke teks", speech_to_speech: "Ucapan ke ucapan", text_to_audio: "Teks ke audio", image_to_text: "Gambar ke teks",
    image_to_json: "Gambar ke data JSON", text_to_json: "Teks ke data JSON", text_to_text: "Teks ke teks", vision: "Analisis visual",
    language_model: "Model bahasa", structured_data: "Data terstruktur", training: "Pelatihan model", workflow: "Alur kerja",
    inference: "Inferensi", realtime_video: "Video realtime",
}[operation] || String(operation || "").replace(/_/g, " "));

export const outputKindLabel = (kind) => ({ image: "Gambar", video: "Video", audio: "Audio", model3d: "3D", data: "Data", file: "Berkas", document: "Dokumen" }[kind] || kind || "");
