// The maker of each model, recognized from its public model id and name, with the maker's official
// mark from LobeHub Icons (MIT, public/brands/ai/LICENSE.txt) served from this site. Order matters:
// a model is attributed to the first maker whose name it carries (Kling's lip-sync is Kling's).
const LOGOS = "/brands/ai/";
const brand = (id, name, file, pattern) => ({ id, name, logo: `${LOGOS}${file}.svg`, mono: !file.endsWith("-color"),
    match: new RegExp(`(?:^|[^a-z0-9])(?:${pattern})`, "i") });

export const MODEL_BRANDS = [
    brand("kling", "Kling AI", "kling-color", "kling"),
    brand("kolors", "Kolors", "kolors-color", "kolors"),
    brand("hailuo", "Hailuo AI", "hailuo-color", "hailuo"),
    brand("minimax", "MiniMax", "minimax-color", "minimax"),
    brand("bytedance", "ByteDance", "bytedance-color", "bytedance|seedance|seedream|seededit|seed-?(?:speech|audio|vc|tts)|omnihuman|dreamina|jimeng"),
    brand("gemini", "Google Gemini", "gemini-color", "gemini|nano-?banana"),
    brand("google", "Google", "google-color", "google|veo|imagen|lyria"),
    brand("flux", "Black Forest Labs", "flux", "flux|bfl|black-?forest"),
    brand("elevenlabs", "ElevenLabs", "elevenlabs", "elevenlabs|eleven-"),
    brand("stability", "Stability AI", "stability-color", "stable-?(?:diffusion|audio|video|cascade)|stability|sdxl|sd3|svd"),
    brand("qwen", "Qwen", "qwen-color", "qwen"),
    brand("alibaba", "Alibaba", "alibaba-color", "alibaba|tongyi|wanx|happy-?horse|wan(?:[-_. ]?\\d|\\/|-(?:pro|i2v|t2v|flf2v|move|animate|vace))"),
    brand("luma", "Luma AI", "luma-color", "luma|ray-?\\d|photon|dream-?machine"),
    brand("pixverse", "PixVerse", "pixverse-color", "pixverse"),
    brand("vidu", "Vidu", "vidu-color", "vidu"),
    brand("ideogram", "Ideogram", "ideogram", "ideogram"),
    brand("recraft", "Recraft", "recraft", "recraft"),
    brand("grok", "Grok", "grok", "grok"),
    brand("xai", "xAI", "xai", "xai(?:\\/|-)|x-ai"),
    brand("lightricks", "Lightricks LTX", "lightricks", "ltx|lightricks"),
    brand("suno", "Suno", "suno", "suno"),
    brand("udio", "Udio", "udio-color", "udio"),
    brand("runway", "Runway", "runway", "runway"),
    brand("pika", "Pika", "pika", "pika"),
    brand("sora", "OpenAI Sora", "sora-color", "sora"),
    brand("dalle", "DALL·E", "dalle-color", "dall-?e"),
    brand("openai", "OpenAI", "chatgpt", "openai|gpt-?image"),
    brand("midjourney", "Midjourney", "midjourney", "midjourney"),
    brand("krea", "Krea", "krea", "krea"),
    brand("hunyuan", "Tencent Hunyuan", "hunyuan-color", "hunyuan"),
    brand("tencent", "Tencent", "tencent-color", "tencent"),
    brand("meta", "Meta", "meta-color", "meta(?:\\/|-ai|-llama)|segment-anything|sam-?\\d|musicgen|audiocraft"),
    brand("microsoft", "Microsoft", "microsoft-color", "microsoft|vibevoice|trellis|florence"),
    brand("nvidia", "NVIDIA", "nvidia-color", "nvidia|sana(?![a-z])"),
    brand("hedra", "Hedra", "hedra", "hedra"),
    brand("topaz", "Topaz Labs", "topazlabs", "topaz"),
    brand("bria", "Bria AI", "briaai-color", "bria"),
    brand("tripo", "Tripo", "tripo-color", "tripo"),
    brand("meshy", "Meshy", "meshy-color", "meshy"),
    brand("longcat", "LongCat", "longcat-color", "longcat"),
    brand("decart", "Decart", "decart-color", "decart|lucy-"),
    brand("sync", "Sync Labs", "sync", "sync(?:-?labs|\\/|-lipsync)"),
    brand("fishaudio", "Fish Audio", "fishaudio", "fish-?audio|openaudio"),
    brand("indextts", "IndexTTS", "bilibiliindex", "index-?tts"),
    brand("stepfun", "StepFun", "stepfun-color", "stepfun|ace-?step|step-?(?:video|audio)"),
    brand("reve", "Reve", "reve", "reve(?![a-z])"),
    brand("cogvideo", "CogVideo", "cogvideo-color", "cogvideo"),
    brand("cogview", "CogView", "cogview-color", "cogview"),
    brand("zhipu", "Zhipu AI", "zhipu-color", "zhipu"),
    brand("vectorizer", "Vectorizer.AI", "vectorizerai", "vectoriz"),
    brand("viggle", "Viggle", "viggle", "viggle"),
];

/** The maker brand of a model or job ({ model_id | model, name | model_label }), or null. */
export function modelBrand(model) {
    if (!model) return null;
    const text = [model.model_id || model.model, model.name || model.model_label].filter((value) => typeof value === "string").join(" ").slice(0, 400);
    return text ? MODEL_BRANDS.find((entry) => entry.match.test(text)) || null : null;
}

const KINDS = ["image", "video", "audio", "avatar", "model3d"];
/** The studio kind a model or job belongs to, for its color and icon when no mark is known. */
export function modelKind(model, fallback = "") {
    if (!model) return KINDS.includes(fallback) ? fallback : "other";
    if (model.category === "avatar" || model.operation === "talking_avatar") return "avatar";
    const kind = model.output_kind || model.kind || model.operations?.find((entry) => KINDS.includes(entry?.output_kind))?.output_kind || fallback;
    return KINDS.includes(kind) ? kind : "other";
}
