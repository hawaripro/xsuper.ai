import { describe, it, expect } from "vitest";
import { capabilitySchema, fieldGroup, jsonDraftText, mergeJsonDraft, orderFields, parseJsonDraft, schemaDocs } from "../studioForm.js";

const leaf = { type: "string", "x-workspace-asset": { kind: "image", role: "image_ref", accepts_url: true } };
const runware = {
    contract_version: 2,
    input_schema: {
        type: "object", required: ["positivePrompt"],
        properties: {
            positivePrompt: { type: "string", minLength: 2, maxLength: 3000, "x-workspace-group": "core" },
            width: { type: "integer", minimum: 128, maximum: 2048, multipleOf: 64, default: 1024, "x-workspace-group": "core" },
            numberResults: { type: "integer", minimum: 1, maximum: 20, default: 1, "x-workspace-group": "core" },
            steps: { type: "integer", minimum: 1, maximum: 50 },
            inputs: { type: "object", properties: { seedImage: leaf } },
        },
    },
};
const native = {
    contract_version: 1,
    inputs: [{ key: "prompt", type: "string", required: true }, { key: "reference_image", type: "asset", single: true, role: "image_ref" }],
    params: [{ name: "size", type: "enum", options: ["1024x1024", "1536x1024"], default: "1024x1024" }, { name: "count", type: "integer", min: 1, max: 4 }, { name: "seed", type: "integer" }],
    ui: { inputs: { prompt: { label: "Prompt" } }, params: {} },
};

describe("parseJsonDraft (Form/JSON editing)", () => {
    it("never applies invalid JSON syntax", () => {
        const result = parseJsonDraft('{"positivePrompt": "a cat",}', runware);
        expect(result.ok).toBe(false);
        expect(result.syntax).not.toBeNull();
        expect(result.value).toBeNull();
    });

    it("requires an object of fields", () => {
        expect(parseJsonDraft("[1, 2]", runware)).toMatchObject({ ok: false, blocking: { "": expect.any(String) } });
        expect(parseJsonDraft("null", runware).ok).toBe(false);
    });

    it("blocks values the schema rejects and reports their paths", () => {
        const result = parseJsonDraft(JSON.stringify({ positivePrompt: "a cat", width: 1000, numberResults: 30 }), runware);
        expect(result.ok).toBe(false);
        expect(Object.keys(result.blocking).sort()).toEqual(["numberResults", "width"]);
    });

    it("lets incomplete required fields through so the form can flag them", () => {
        const result = parseJsonDraft(JSON.stringify({ positivePrompt: "", width: 1024 }), runware);
        expect(result.ok).toBe(true);
        expect(Object.keys(result.incomplete)).toEqual(["positivePrompt"]);
        expect(parseJsonDraft("{}", runware)).toMatchObject({ ok: true, incomplete: { positivePrompt: expect.any(String) } });
    });

    it("validates native fields and refuses unknown or studio-controlled keys", () => {
        expect(parseJsonDraft(JSON.stringify({ prompt: "Kucing", size: "1536x1024" }), native, { hidden: ["count"], values: { count: 2 } }).ok).toBe(true);
        expect(Object.keys(parseJsonDraft(JSON.stringify({ prompt: "Kucing", size: "9x9" }), native, { hidden: ["count"] }).blocking)).toEqual(["size"]);
        expect(Object.keys(parseJsonDraft(JSON.stringify({ prompt: "Kucing", colour: "red" }), native, { hidden: ["count"] }).blocking)).toEqual(["colour"]);
        expect(Object.keys(parseJsonDraft(JSON.stringify({ prompt: "Kucing", count: 3 }), native, { hidden: ["count"] }).blocking)).toEqual(["count"]);
    });

    it("shows only sent values and keeps studio-controlled ones when applying", () => {
        const values = { prompt: "Kucing", reference_image: "", size: "1024x1024", count: 2, seed: "" };
        expect(JSON.parse(jsonDraftText(values, native, ["count"]))).toEqual({ prompt: "Kucing", size: "1024x1024" });
        expect(mergeJsonDraft(values, { prompt: "Anjing" }, ["count"])).toEqual({ count: 2, prompt: "Anjing" });
        expect(JSON.parse(jsonDraftText({ positivePrompt: "" }, runware))).toEqual({ positivePrompt: "" });
    });
});

describe("request panel structure", () => {
    it("places fields by declared group, then files, required, prompt and size, else advanced", () => {
        const root = runware.input_schema;
        expect(fieldGroup("steps", { type: "integer", "x-workspace-group": "advanced" }, { root })).toBe("advanced");
        expect(fieldGroup("inputs", root.properties.inputs, { root })).toBe("input");
        expect(fieldGroup("negative_prompt", { type: "string" }, { root })).toBe("core");
        expect(fieldGroup("guidance", { type: "number" }, { root, required: true })).toBe("core");
        expect(fieldGroup("image_size", { type: "string", enum: ["square_hd"] }, { root })).toBe("core");
        expect(fieldGroup("num_images", { type: "integer" }, { root, quantity: "num_images" })).toBe("core");
        expect(fieldGroup("enable_safety_checker", { type: "boolean" }, { root })).toBe("advanced");
    });

    it("files native uploads under Input and leads each section with the required prompt", () => {
        const schema = capabilitySchema(native, ["count"]);
        expect(fieldGroup("reference_image", schema.properties.reference_image, { root: schema })).toBe("input");
        const ordered = orderFields([
            { key: "system_prompt", required: false }, { key: "resolution", required: false }, { key: "seed", required: true }, { key: "prompt", required: true },
        ]);
        expect(ordered.map((entry) => entry.key)).toEqual(["prompt", "seed", "system_prompt", "resolution"]);
    });

    it("describes native inputs and params as one closed object schema", () => {
        const schema = capabilitySchema(native, ["count"]);
        expect(Object.keys(schema.properties)).toEqual(["prompt", "reference_image", "size", "seed"]);
        expect(schema).toMatchObject({ required: ["prompt"], additionalProperties: false, properties: { size: { enum: ["1024x1024", "1536x1024"], default: "1024x1024" } } });
        expect(capabilitySchema(runware)).toBe(runware.input_schema);
    });

    it("documents parameters with limits, defaults and nested file inputs", () => {
        const rows = schemaDocs(runware.input_schema);
        expect(rows.find((row) => row.path === "width")).toMatchObject({ type: "integer", minimum: 128, maximum: 2048, step: 64, default: 1024, required: false });
        expect(rows.find((row) => row.path === "positivePrompt")).toMatchObject({ required: true, minimum: 2, maximum: 3000 });
        expect(rows.find((row) => row.path === "inputs.seedImage")).toMatchObject({ depth: 1, type: "file", file: "image" });
    });
});
