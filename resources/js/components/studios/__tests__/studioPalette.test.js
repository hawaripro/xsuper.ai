import { describe, it, expect } from "vitest";
import { capabilityKind, defaultCategory, localModels, matchesQuery, modelKinds, readModels, rememberModel, safeLogoUrl, sanitizeModel, selectionKind, startingPrice, toggleFavorite } from "../studioPalette.js";

const model = (id, extra = {}) => ({ model_id: id, name: `Model ${id}`, provider_name: "Runware", category: "image",
    operations: [{ operation: "text_to_image", output_kind: "image", price_tokens: 12, price_unit: "generation" }], ...extra });

describe("recent and favorite models", () => {
    it("keeps the newest use first, once per model, within the limit", () => {
        let recent = [];
        for (const id of ["a", "b", "c", "a"]) recent = rememberModel(recent, model(id), 3);
        expect(recent.map((entry) => entry.model_id)).toEqual(["a", "c", "b"]);
        recent = rememberModel(recent, model("d"), 3);
        expect(recent.map((entry) => entry.model_id)).toEqual(["d", "a", "c"]);
    });

    it("toggles a favorite on and off", () => {
        const on = toggleFavorite([], model("flux"));
        expect(on.map((entry) => entry.model_id)).toEqual(["flux"]);
        expect(toggleFavorite(on, { model_id: "flux" })).toEqual([]);
    });

    it("reads stored lists defensively: bad JSON, missing ids and unsafe logos are dropped", () => {
        expect(readModels("not json")).toEqual([]);
        expect(readModels(JSON.stringify({ model_id: "x" }))).toEqual([]);
        const [entry] = readModels(JSON.stringify([{ model_id: "x", logo_url: "javascript:alert(1)", operations: [{ operation: "text_to_image", price_tokens: "9" }] }, { name: "no id" }]));
        expect(entry).toMatchObject({ model_id: "x", name: "x", logo_url: null, operations: [{ operation: "text_to_image", price_tokens: null }] });
        expect(sanitizeModel(null)).toBeNull();
        expect(safeLogoUrl("https://content.runware.ai/logo.png")).toBe("https://content.runware.ai/logo.png");
        expect(safeLogoUrl("http://example.com/logo.png")).toBeNull();
    });
});

describe("filtering", () => {
    it("matches every word of the query across name, provider, id and description", () => {
        const flux = model("runware/bfl-flux-1-dev", { name: "FLUX.1 [dev]", description: "Open-weight text-to-image" });
        expect(matchesQuery(flux, "flux runware")).toBe(true);
        expect(matchesQuery(flux, "open weight")).toBe(true);
        expect(matchesQuery(flux, "flux video")).toBe(false);
        expect(matchesQuery(flux, "  ")).toBe(true);
        expect(localModels([flux, model("other")], "dev").map((entry) => entry.model_id)).toEqual(["runware/bfl-flux-1-dev"]);
    });

    it("reads studio kinds like the server filter", () => {
        expect(modelKinds(model("avatar", { category: "avatar", operations: [{ operation: "audio_to_video", output_kind: "video" }] }))).toEqual(["avatar"]);
        const mixed = model("vision", { category: "other", operations: [{ operation: "image_edit", output_kind: "image" }, { operation: "image_to_text", output_kind: "data" }] });
        expect(modelKinds(mixed)).toEqual(["image", "other"]);
    });

    it("moves the studio kind to the chosen model without leaving a kind it still belongs to", () => {
        const multi = model("multi", { operations: [{ operation: "text_to_image", output_kind: "image" }, { operation: "image_to_video", output_kind: "video" }] });
        expect(selectionKind(multi, "image_to_video", "image")).toBe("video");
        expect(selectionKind(multi, "", "video")).toBe("video");
        expect(selectionKind(multi, "", "audio")).toBe("image");
        expect(selectionKind(model("caption", { operations: [{ operation: "image_to_text", output_kind: "data" }] }), "", "image")).toBe("");
        expect(selectionKind(model("face", { category: "avatar", operations: [{ operation: "image_to_video", output_kind: "video" }] }), "image_to_video", "video")).toBe("avatar");
        expect(capabilityKind({ category: "avatar" }, { output_kind: "video" })).toBe("avatar");
        expect(capabilityKind({ category: "image" }, { output_kind: "data" })).toBe("");
        expect(capabilityKind({ category: "image" }, null)).toBe("");
    });

    it("starts prices at the cheapest operation and opens the palette on the studio kind", () => {
        expect(startingPrice(model("x", { operations: [{ price_tokens: 30 }, { price_tokens: 8 }, { price_tokens: null }] }))).toBe(8);
        expect(startingPrice(model("x", { operations: [] }))).toBeNull();
        expect(defaultCategory("video", [])).toBe("video");
        expect(defaultCategory("", [model("a")])).toBe("recent");
        expect(defaultCategory("", [])).toBe("all");
    });
});
