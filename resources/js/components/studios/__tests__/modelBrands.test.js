import { describe, it, expect } from "vitest";
import { modelBrand, modelKind } from "../modelBrands.js";

const makerOf = (model_id, name = "") => modelBrand({ model_id, name })?.id ?? null;

describe("modelBrand (official maker marks)", () => {
    it("recognizes makers across Fal, Runware and Kinovi ids", () => {
        expect(makerOf("fal-ai/kling-video/v3/pro/image-to-video", "Kling Video")).toBe("kling");
        expect(makerOf("fal-ai/kling-video/lipsync/audio-to-video", "Kling LipSync")).toBe("kling");
        expect(makerOf("suno-music", "Suno Music")).toBe("suno");
        expect(makerOf("nanobanana-pro", "Nano Banana Pro")).toBe("gemini");
        expect(makerOf("runware/bfl-flux-1-dev", "FLUX.1 [dev]")).toBe("flux");
        expect(makerOf("fal-ai/minimax/speech-2.8-hd", "MiniMax Speech 2.8 [HD]")).toBe("minimax");
        expect(makerOf("fal-ai/minimax/video-01-live/image-to-video", "MiniMax (Hailuo AI) Video 01")).toBe("hailuo");
        expect(makerOf("wan3.0-text-to-video", "Wan 3.0")).toBe("alibaba");
        expect(makerOf("fal-ai/wan/v2.7/image-to-video", "Wan")).toBe("alibaba");
        expect(makerOf("fal-ai/bytedance/seed-speech/tts/v2", "Bytedance Seed Speech")).toBe("bytedance");
        expect(makerOf("seedance-2-5", "seedance-2-5")).toBe("bytedance");
        expect(makerOf("xai/grok-imagine-image", "Grok Imagine Image")).toBe("grok");
        expect(makerOf("fal-ai/stable-audio-25/text-to-audio", "Stable Audio 2.5")).toBe("stability");
    });

    it("never attributes a model to a maker whose name is only part of another word", () => {
        expect(makerOf("fal-ai/mmaudio-v2/text-to-audio", "MMAudio V2 Text to Audio")).toBeNull();
        expect(makerOf("fal-ai/kokoro/american-english", "Kokoro TTS")).toBeNull();
        expect(makerOf("fal-ai/reverse-video", "Reverse")).toBeNull();
    });

    it("works on jobs as well as catalog entries", () => {
        expect(modelBrand({ model: "fal-ai/luma-dream-machine/ray-2", model_label: "Luma Ray 2" })?.id).toBe("luma");
        expect(modelBrand(null)).toBeNull();
    });

    it("serves monochrome marks as masks and colored marks as images", () => {
        expect(modelBrand({ model_id: "suno-music" })).toMatchObject({ logo: "/brands/ai/suno.svg", mono: true });
        expect(modelBrand({ model_id: "fal-ai/kling-video" })).toMatchObject({ logo: "/brands/ai/kling-color.svg", mono: false });
    });
});

describe("modelKind (fallback identity)", () => {
    it("files avatar models under avatar and other outputs under other", () => {
        expect(modelKind({ category: "avatar", operations: [{ output_kind: "video" }] })).toBe("avatar");
        expect(modelKind({ operations: [{ output_kind: "data" }, { output_kind: "audio" }] })).toBe("audio");
        expect(modelKind({ output_kind: "data" })).toBe("other");
        expect(modelKind(null, "video")).toBe("video");
    });
});
