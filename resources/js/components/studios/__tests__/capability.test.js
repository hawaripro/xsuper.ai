import { describe, it, expect } from "vitest";
import {
    capabilityErrors,
    capabilitySubmission,
    capabilityValues,
    evaluateRule,
    fieldRequired,
} from "../capability.js";

const editCapability = {
    operation: "image_edit",
    inputs: [
        { key: "prompt", role: "prompt", type: "string", single: true, max: 1, required: true, required_when: null },
        { key: "reference_image", role: "image_ref", type: "asset", single: true, max: 1, required: true, required_when: null },
    ],
    params: [
        { name: "size", type: "enum", default: "1024x1024", options: ["1024x1024", "1024x1792", "1792x1024"], required: false, required_when: null },
    ],
    ui: { order: ["input:prompt", "input:reference_image", "param:size"] },
};

describe("evaluateRule + fieldRequired", () => {
    it("resolves param_equals and has_input declarative rules", () => {
        expect(evaluateRule({ param_equals: { name: "mode", value: "edit" } }, { mode: "edit" })).toBe(true);
        expect(evaluateRule({ param_equals: { name: "mode", value: "edit" } }, { mode: "text" })).toBe(false);
        expect(evaluateRule({ has_input: "reference_image" }, { reference_image: "asset-1" })).toBe(true);
        expect(evaluateRule({ has_input: "reference_image" }, { reference_image: "" })).toBe(false);
        expect(evaluateRule(null, {})).toBe(false);
    });

    it("treats required and satisfied required_when as required", () => {
        const conditional = { key: "reference_image", type: "asset", required: false, required_when: { has_input: "prompt" } };
        expect(fieldRequired({ required: true }, {})).toBe(true);
        expect(fieldRequired(conditional, { prompt: "hi" })).toBe(true);
        expect(fieldRequired(conditional, { prompt: "" })).toBe(false);
    });
});

describe("capabilityErrors (backend rule mirror)", () => {
    it("flags a missing required reference before submit", () => {
        const errors = capabilityErrors(editCapability, { prompt: "make it pop", reference_image: "" });
        expect(errors.reference_image).toBeTruthy();
        expect(errors.prompt).toBeUndefined();
    });

    it("passes when prompt and reference are present and size is valid", () => {
        const errors = capabilityErrors(editCapability, { prompt: "make it pop", reference_image: "asset-1", size: "1024x1024" });
        expect(Object.keys(errors)).toHaveLength(0);
    });

    it("rejects an out-of-contract size option", () => {
        const errors = capabilityErrors(editCapability, { prompt: "x", reference_image: "asset-1", size: "4096x4096" });
        expect(errors.size).toBeTruthy();
    });
});

describe("capabilityValues reconciliation", () => {
    it("omits an unset optional seed instead of forcing deterministic output", () => {
        const capability = { inputs: [], params: [{ name: "seed", type: "integer", min: 0, default: null, required: false }] };
        expect(capabilitySubmission(capability, capabilityValues(capability))).toEqual({});
        expect(capabilitySubmission(capability, capabilityValues(capability, { seed: 0 }))).toEqual({ seed: 0 });
        expect(capabilityErrors(capability, { seed: 1.5 }).seed).toBeTruthy();
    });

    it("keeps a compatible size but drops an incompatible one to the default, and empties an asset", () => {
        const kept = capabilityValues(editCapability, { size: "1792x1024", reference_image: "asset-1", prompt: "keep" });
        expect(kept.size).toBe("1792x1024");
        expect(kept.prompt).toBe("keep");

        const reset = capabilityValues(editCapability, { size: "999x999" });
        expect(reset.size).toBe("1024x1024");
        expect(reset.reference_image).toBe("");
    });
});

describe("capabilitySubmission", () => {
    it("emits trimmed prompt, the stable asset id, and size; omits empties", () => {
        const body = capabilitySubmission(editCapability, { prompt: "  edit please  ", reference_image: "asset-42", size: "1024x1024" });
        expect(body).toEqual({ prompt: "edit please", reference_image: "asset-42", size: "1024x1024" });

        const noRef = capabilitySubmission(editCapability, { prompt: "edit", reference_image: "", size: "1024x1024" });
        expect(noRef.reference_image).toBeUndefined();
    });
});
