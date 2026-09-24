import { describe, it, expect } from "vitest";
import {
    VIDEO_DEFAULTS, assetSignature, composeVideoPrompt, englishOnly, jobSummary, jobTitle, nativeStudio, promptLimit,
    restoreDraft, videoAuthoringErrors, videoExecution, videoOperation, voiceOptions,
} from "../nativeStudio.js";
import { ownedMediaUrl } from "../mediaOutput.js";

const video = (patch) => ({ ...VIDEO_DEFAULTS, ...patch });

describe("composeVideoPrompt (the prompt the video studio sends)", () => {
    it("sends the written prompt for text and image tabs", () => {
        expect(composeVideoPrompt(video({ tab: "prompt", prompt: "  A calm lake  " }))).toBe("A calm lake");
        expect(composeVideoPrompt(video({ tab: "reference", prompt: "Animate it" }))).toBe("Animate it");
    });
    it("composes product videos from the product, features and chosen angles in catalogue order", () => {
        expect(composeVideoPrompt(video({ tab: "product", product: " Kopi ", features: " Arabika ", angles: "spin,closeup" }))).toBe(
            'Create a product video for "Kopi". Key features: Arabika. '
            + "Extreme close-up shot focusing on product details, texture, and craftsmanship. Macro lens feel, shallow depth of field. "
            + "Product rotating on a clean surface, showing all angles. Smooth turntable rotation, studio lighting.");
    });
    it("composes UGC videos with the chosen story and omits empty features", () => {
        expect(composeVideoPrompt(video({ tab: "ugc", product: "Kopi", story: "friend" }))).toBe(
            'Create a UGC-style video for "Kopi". Talent talking directly to camera about the product. Casual, conversational tone.');
    });
});

describe("videoExecution (mode, CTA and variation envelope)", () => {
    it("keeps prompt tabs plain even when a CTA was typed on another tab", () => {
        expect(videoExecution(video({ tab: "prompt", cta: "Buy", variations: true }), 1)).toEqual({ mode: "prompt", ugc_variation: false });
        expect(videoExecution(video({ tab: "reference", cta: "Buy" }), 2)).toEqual({ mode: "prompt", ugc_variation: false });
    });
    it("sends product and UGC as ab_testing with a trimmed CTA and variation only for several videos", () => {
        expect(videoExecution(video({ tab: "product", cta: "  Order today ", variations: true }), 2)).toEqual({ mode: "ab_testing", cta: "Order today", ugc_variation: true });
        expect(videoExecution(video({ tab: "ugc", cta: "   ", variations: true }), 1)).toEqual({ mode: "ab_testing", ugc_variation: false });
    });
});

describe("videoAuthoringErrors", () => {
    it("requires the prompt on prompt tabs and the product name on product tabs", () => {
        expect(videoAuthoringErrors(video({ tab: "prompt", prompt: " " }))).toHaveProperty("prompt");
        expect(videoAuthoringErrors(video({ tab: "product", prompt: "", product: "" }))).toHaveProperty("product");
        expect(videoAuthoringErrors(video({ tab: "ugc", product: "Kopi" }))).toEqual({});
    });
    it("rejects a composed prompt over 4000 characters", () => {
        expect(videoAuthoringErrors(video({ tab: "product", product: "Kopi", features: "x".repeat(3990) }))).toHaveProperty("prompt");
    });
});

describe("videoOperation (tab to capability)", () => {
    const both = ["text_to_video", "image_to_video"];
    it("maps tabs to the model's operations", () => {
        expect(videoOperation("prompt", true, both)).toBe("text_to_video");
        expect(videoOperation("reference", false, both)).toBe("image_to_video");
        expect(videoOperation("product", false, both)).toBe("text_to_video");
        expect(videoOperation("ugc", true, both)).toBe("image_to_video");
    });
    it("uses the only operation of reference-only models and reports a missing one", () => {
        expect(videoOperation("prompt", false, ["image_to_video"])).toBe("image_to_video");
        expect(videoOperation("reference", false, ["text_to_video"])).toBeNull();
    });
    it("keeps models that require a reference on image-to-video in every tab", () => {
        expect(videoOperation("prompt", false, both, true)).toBe("image_to_video");
        expect(videoOperation("product", false, both, true)).toBe("image_to_video");
        expect(videoOperation("prompt", false, ["text_to_video"], true)).toBe("text_to_video");
    });
});

describe("restoreDraft", () => {
    it("keeps only known values of the stored type and bounded length", () => {
        expect(restoreDraft({ tab: "ugc", prompt: 5, cta: "x".repeat(8001), variations: true, extra: "no" }, VIDEO_DEFAULTS))
            .toEqual({ ...VIDEO_DEFAULTS, tab: "ugc", variations: true });
        expect(restoreDraft(null, VIDEO_DEFAULTS)).toEqual(VIDEO_DEFAULTS);
    });
});

describe("nativeStudio", () => {
    it("offers fixed-form studios only for native contracts", () => {
        expect(nativeStudio({ contract_version: 1, operation: "text_to_video", output_kind: "video" })).toBe("video");
        expect(nativeStudio({ contract_version: 1, operation: "image_to_video", output_kind: "video" })).toBe("video");
        expect(nativeStudio({ contract_version: 2, operation: "text_to_video", output_kind: "video" })).toBeNull();
        expect(nativeStudio({ contract_version: 1, operation: "talking_avatar", output_kind: "video" })).toBe("avatar");
        expect(nativeStudio({ contract_version: 1, operation: "music", output_kind: "audio" })).toBe("audio");
        expect(nativeStudio({ contract_version: 1, operation: "image_edit", output_kind: "image" })).toBe("image");
        expect(nativeStudio({ contract_version: 1, operation: "image_to_3d", output_kind: "model3d" })).toBe("model3d");
        expect(nativeStudio(null)).toBeNull();
    });
});

describe("audio helpers", () => {
    const voices = [{ id: "af_bella", label: "Bella", language: "en-US" }, { id: "af_heart", label: "Heart", language: "en-US" }];
    it("labels voices in the order the capability offers them", () => {
        expect(voiceOptions({ options: ["af_heart", "zz_new"] }, voices)).toEqual([
            { id: "af_heart", label: "Heart", language: "en-US" }, { id: "zz_new", label: "zz_new", language: null }]);
    });
    it("detects English-only voice sets", () => {
        expect(englishOnly(voices)).toBe(true);
        expect(englishOnly([...voices, { id: "id", label: "Id", language: "id-ID" }])).toBe(false);
        expect(englishOnly([])).toBe(false);
    });
    it("limits prompts to the model's character limit within 4000", () => {
        expect(promptLimit({ audio: { max_characters: 500 } })).toBe(500);
        expect(promptLimit({ audio: { max_characters: 9000 } })).toBe(4000);
        expect(promptLimit(null)).toBe(4000);
    });
});

describe("assetSignature (consent covers the chosen files)", () => {
    it("changes when a native file input changes, not when text changes", () => {
        const capability = { contract_version: 1, inputs: [{ key: "prompt", type: "string" }, { key: "avatar_photo", type: "asset" }, { key: "speech_audio", type: "asset" }] };
        const before = assetSignature(capability, { prompt: "a", avatar_photo: "p1", speech_audio: "s1" });
        expect(assetSignature(capability, { prompt: "b", avatar_photo: "p1", speech_audio: "s1" })).toBe(before);
        expect(assetSignature(capability, { prompt: "a", avatar_photo: "p2", speech_audio: "s1" })).not.toBe(before);
    });
    it("follows nested schema file fields", () => {
        const capability = { contract_version: 2, input_schema: { type: "object", properties: {
            prompt: { type: "string" },
            media: { type: "object", properties: { face: { type: "string", "x-workspace-asset": { kind: "image" } } } },
            clips: { type: "array", items: { $ref: "#/$defs/clip" } },
        }, $defs: { clip: { type: "string", "x-workspace-asset": { kind: "audio" } } } } };
        const before = assetSignature(capability, { prompt: "a", media: { face: "f1" }, clips: ["c1"] });
        expect(assetSignature(capability, { prompt: "b", media: { face: "f1" }, clips: ["c1"] })).toBe(before);
        expect(assetSignature(capability, { prompt: "a", media: { face: "f1" }, clips: ["c1", "c2"] })).not.toBe(before);
        expect(assetSignature(capability, { prompt: "a", media: { face: "f2" }, clips: ["c1"] })).not.toBe(before);
    });
});

describe("job presentation", () => {
    it("titles jobs by their prompt, then model label, then model", () => {
        expect(jobTitle({ model: "m", details: { prompt: "A cup", model_label: "Model" } })).toBe("A cup");
        expect(jobTitle({ model: "m", details: { prompt: null, model_label: "Model" } })).toBe("Model");
        expect(jobTitle({ model: "m" })).toBe("m");
    });
    it("summarises the recorded settings like the studios did", () => {
        expect(jobSummary({ model: "longcat", details: { size: "auto", aspect_ratio: "16:9", duration: 5, pro_mode: true } }))
            .toEqual(["longcat", "16:9", { seconds: 5 }, "Pro"]);
        expect(jobSummary({ model: "flux", details: { size: "1024x1024" } })).toEqual(["flux", "1024x1024"]);
    });
});

describe("ownedMediaUrl (saved video references)", () => {
    it("previews the owner-gated reference route and nothing beside it", () => {
        expect(ownedMediaUrl("/api/v/abc-123/reference", "https://app.test")).toBe("/api/v/abc-123/reference");
        expect(ownedMediaUrl("/api/v/abc/reference/extra", "https://app.test")).toBeNull();
        expect(ownedMediaUrl("https://cdn.example/api/v/abc/reference", "https://app.test")).toBeNull();
    });
});
