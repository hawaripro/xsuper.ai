import { describe, it, expect } from "vitest";
import { availableResolutions, axisBounds, parseRatio, ratioChoices, ratioLabel, resolutionLabel, sizeFor, snapToBounds } from "../studioSizing.js";

const flux = axisBounds({ type: "integer", minimum: 128, maximum: 2048, multipleOf: 64 });

describe("snapToBounds (width/height constraints)", () => {
    it("rounds to the multipleOf grid inside minimum and maximum", () => {
        expect(snapToBounds(1365.3, flux)).toBe(1344);
        expect(snapToBounds(20, flux)).toBe(128);
        expect(snapToBounds(5000, flux)).toBe(2048);
    });

    it("keeps inclusive bounds on the grid even when the bounds are not multiples", () => {
        expect(snapToBounds(90, { min: 100, max: 1000, step: 64 })).toBe(128);
        expect(snapToBounds(999, { min: 100, max: 1000, step: 64 })).toBe(960);
    });

    it("reads exclusive bounds as one step inside", () => {
        expect(axisBounds({ exclusiveMinimum: 0, exclusiveMaximum: 1024, multipleOf: 8 })).toEqual({ min: 8, max: 1016, step: 8 });
    });

    it("falls back to the clamped value when no multiple fits", () => {
        expect(snapToBounds(300, { min: 100, max: 110, step: 64 })).toBe(110);
    });
});

describe("sizeFor (ratio × resolution presets)", () => {
    it("targets the preset's pixel budget on the model grid", () => {
        expect(sizeFor("1:1", 1024, flux, flux)).toEqual({ width: 1024, height: 1024 });
        expect(sizeFor("16:9", 1024, flux, flux)).toEqual({ width: 1344, height: 768 });
        expect(sizeFor("21:9", 1024, flux, flux)).toEqual({ width: 1536, height: 640 });
        expect(sizeFor("3:4", 512, flux, flux)).toEqual({ width: 448, height: 576 });
    });

    it("shrinks tall ratios to the maximum and keeps every side legal", () => {
        const size = sizeFor("9:21", 2048, flux, flux);
        expect(size.height).toBe(2048);
        expect(size.width % 64).toBe(0);
        expect(size.width).toBeLessThan(size.height);
    });

    it("grows below-minimum sizes and never leaves the bounds", () => {
        const tight = { min: 512, max: 1024, step: 16 };
        const size = sizeFor("16:9", 512, tight, tight);
        expect(size.height).toBeGreaterThanOrEqual(512);
        expect(size.width).toBeLessThanOrEqual(1024);
    });

    it("offers only presets inside the model limits", () => {
        expect(availableResolutions(flux, flux).map((preset) => preset.id)).toEqual(["0.5K", "1K", "2K"]);
        expect(availableResolutions({ min: 512, max: 1440 }, { min: 512, max: 1440 }).map((preset) => preset.id)).toEqual(["0.5K", "1K"]);
        expect(availableResolutions({}, {}).map((preset) => preset.id)).toEqual(["0.5K", "1K", "2K"]);
        expect(availableResolutions({ max: 4096 }, { max: 4096 }).map((preset) => preset.id)).toContain("4K");
    });

    it("labels the current size by its nearest ratio and resolution", () => {
        expect(ratioLabel(1344, 768)).toBe("16:9");
        expect(ratioLabel(1000, 300)).toBe("10:3");
        expect(resolutionLabel(1024, 1024)).toBe("1K");
        expect(resolutionLabel(100, 100)).toBeNull();
        expect(parseRatio("16:9")).toEqual([16, 9]);
        expect(parseRatio("wide")).toBeNull();
    });
});

describe("ratioChoices (aspect_ratio and image_size enums)", () => {
    it("reads colon ratios, pixel sizes, named sizes and automatic values", () => {
        expect(ratioChoices(["auto", "16:9", "9:16"]).map((choice) => [choice.label, choice.ratio])).toEqual([["auto", null], ["16:9", [16, 9]], ["9:16", [9, 16]]]);
        expect(ratioChoices(["1024x1024", "1536x1024"]).map((choice) => choice.ratio)).toEqual([[1024, 1024], [1536, 1024]]);
        expect(ratioChoices(["square_hd", "portrait_4_3", "landscape_16_9"]).map((choice) => choice.label)).toEqual(["1:1 HD", "3:4", "16:9"]);
    });

    it("refuses enums that are not ratios", () => {
        expect(ratioChoices(["low", "high"])).toBeNull();
        expect(ratioChoices(["16:9", "cinematic"])).toBeNull();
        expect(ratioChoices(["auto", "match_input"])).toBeNull();
        expect(ratioChoices(["16:9"])).toBeNull();
    });
});
