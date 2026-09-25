// Aspect-ratio tiles and resolution presets for width/height pairs and ratio-like enum values.
// Computed sizes stay inside the schema's bounds and on its multipleOf grid; the server re-validates.
export const ASPECT_RATIOS = ["1:1", "4:3", "3:4", "3:2", "2:3", "16:9", "9:16", "21:9", "9:21"];
// A preset targets edge² pixels (1K ≈ 1 megapixel), so every ratio keeps a comparable pixel budget.
export const RESOLUTIONS = [{ id: "0.5K", edge: 512 }, { id: "1K", edge: 1024 }, { id: "2K", edge: 2048 }, { id: "4K", edge: 4096 }];

const finite = (value) => typeof value === "number" && Number.isFinite(value);
const named = { square_hd: [1, 1], square: [1, 1] };
const special = /^(auto|match_input|match_input_image|default|original|none)$/i;

export function parseRatio(value) {
    const match = /^\s*(\d+(?:\.\d+)?)\s*[:/]\s*(\d+(?:\.\d+)?)\s*$/.exec(typeof value === "string" ? value : "");
    if (!match) return null;
    const ratio = [Number(match[1]), Number(match[2])];
    return ratio[0] > 0 && ratio[1] > 0 ? ratio : null;
}

// Inclusive bounds of one numeric schema and the step every value is a multiple of.
export function axisBounds(schema = {}, fallbackStep = 1) {
    const step = finite(schema?.multipleOf) && schema.multipleOf > 0 ? schema.multipleOf : fallbackStep;
    let min = finite(schema?.minimum) ? schema.minimum : null;
    let max = finite(schema?.maximum) ? schema.maximum : null;
    if (finite(schema?.exclusiveMinimum)) min = Math.max(min ?? -Infinity, schema.exclusiveMinimum + step);
    if (finite(schema?.exclusiveMaximum)) max = Math.min(max ?? Infinity, schema.exclusiveMaximum - step);
    return { min, max, step };
}

export function snapToBounds(value, { min = null, max = null, step = 1 } = {}) {
    const low = min == null ? step : Math.ceil(min / step) * step;
    const high = max == null ? Infinity : Math.floor(max / step) * step;
    // No multiple of the step fits the range: keep the clamped value and let the server explain.
    if (low > high) return Math.round(Math.min(Math.max(value, min ?? value), max ?? value));
    return Math.min(Math.max(Math.round(value / step) * step, low), high);
}

export function sizeFor(ratio, edge, width = {}, height = {}) {
    const [across, down] = parseRatio(ratio) || [1, 1];
    let w = Math.sqrt(edge * edge * across / down);
    let h = w * down / across;
    const shrink = Math.min(1, width.max != null ? width.max / w : 1, height.max != null ? height.max / h : 1);
    w *= shrink;
    h *= shrink;
    const grow = Math.max(1, width.min != null ? width.min / w : 1, height.min != null ? height.min / h : 1);
    w *= grow;
    const snapped = snapToBounds(w, width);
    return { width: snapped, height: snapToBounds(snapped * down / across, height) };
}

// Presets whose pixel budget the model accepts; unbounded models are offered up to 2K.
export function availableResolutions(width = {}, height = {}) {
    const highest = Math.min(width.max ?? Infinity, height.max ?? Infinity);
    const lowest = Math.max(width.min ?? 0, height.min ?? 0);
    return RESOLUTIONS.filter((preset) => preset.edge >= lowest && preset.edge <= (highest === Infinity ? 2048 : highest));
}

const divisor = (a, b) => b ? divisor(b, a % b) : a;
export function ratioLabel(width, height) {
    if (!(width > 0 && height > 0)) return "";
    const actual = width / height;
    const near = ASPECT_RATIOS.find((entry) => {
        const [across, down] = parseRatio(entry);
        return Math.abs(actual - across / down) / (across / down) < 0.03;
    });
    if (near) return near;
    const common = divisor(Math.round(width), Math.round(height));
    const reduced = [Math.round(width) / common, Math.round(height) / common];
    return reduced[0] <= 64 && reduced[1] <= 64 ? reduced.join(":") : `${actual.toFixed(2)}:1`;
}

export function resolutionLabel(width, height) {
    if (!(width > 0 && height > 0)) return null;
    const edge = Math.sqrt(width * height);
    const near = RESOLUTIONS.find((preset) => Math.abs(edge - preset.edge) / preset.edge < 0.2);
    return near ? near.id : null;
}

// One enum value as a tile: "16:9", "1024x768", Fal named sizes and automatic choices.
export function ratioChoice(value) {
    if (typeof value !== "string") return null;
    const ratio = parseRatio(value);
    if (ratio) return { value, ratio, label: value.replace(/\s+/g, "") };
    const size = /^\s*(\d{2,5})\s*[x×*]\s*(\d{2,5})\s*$/i.exec(value);
    if (size) return { value, ratio: [Number(size[1]), Number(size[2])], label: `${size[1]}×${size[2]}` };
    if (named[value]) return { value, ratio: named[value], label: value === "square_hd" ? "1:1 HD" : "1:1" };
    const oriented = /^(landscape|portrait)_(\d+)_(\d+)$/.exec(value);
    if (oriented) {
        const [large, small] = [Number(oriented[2]), Number(oriented[3])].sort((a, b) => b - a);
        const ratio = oriented[1] === "landscape" ? [large, small] : [small, large];
        return { value, ratio, label: ratio.join(":") };
    }
    return special.test(value) ? { value, ratio: null, label: value, automatic: true } : null;
}

// Tiles only when every option reads as a ratio or an automatic choice and at least one is a ratio.
export function ratioChoices(values) {
    if (!Array.isArray(values) || values.length < 2) return null;
    const choices = values.map(ratioChoice);
    return choices.every(Boolean) && choices.some((choice) => choice.ratio) ? choices : null;
}
