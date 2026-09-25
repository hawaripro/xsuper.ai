import { describe, it, expect } from "vitest";
import { publicUrlError, schemaDefault, schemaErrors, schemaVariants } from "../schema.js";
import { batchView, billedSeconds, jobCandidates, realtimeOutcomeUnknown, submissionUnresolved, workspaceQuote } from "../workspaceMedia.js";

const uuid = "8c0d6f2e-1b5a-4c3d-9e8f-0a1b2c3d4e5f";
const leaf = { type: "string", minLength: 1, maxLength: 2048, "x-workspace-asset": { kind: "image", role: "image_ref", accepts_url: true } };

describe("publicUrlError (URLs the provider fetches)", () => {
    it("accepts public https URLs", () => {
        expect(publicUrlError("https://cdn.example.com/a.png?size=2")).toBeNull();
        expect(publicUrlError("https://[2606:4700::1111]/a.png")).toBeNull();
    });

    it.each([
        "http://example.com/a.png", "https://user:secret@example.com/a.png", "/relative/a.png", "https://example.com/a b.png",
        "https://localhost/a", "https://api.localhost/a", "https://printer.local/a", "https://svc.internal/a", "https://localhost./a",
        "https://127.0.0.1/a", "https://2130706433/a", "https://0x7f.1/a", "https://10.1.2.3/a", "https://172.16.0.1/a",
        "https://192.168.1.1/a", "https://169.254.169.254/latest", "https://100.64.0.1/a", "https://0.0.0.0/a", "https://224.0.0.1/a",
        "https://[::1]/a", "https://[fe80::1]/a", "https://[fc00::1]/a", "https://[::ffff:127.0.0.1]/a", "https://[2001:db8::1]/a",
        `https://example.com/${"a".repeat(2040)}`,
    ])("rejects %s", (value) => {
        expect(publicUrlError(value)).toBeTruthy();
    });
});

describe("owned file leaves", () => {
    const schema = { type: "object", required: ["image_url"], properties: { image_url: leaf, extra_urls: { type: "array", items: leaf } } };

    it("accept an owned upload UUID or a public link, mixed in lists", () => {
        expect(schemaErrors(schema, { image_url: uuid })).toEqual({});
        expect(schemaErrors(schema, { image_url: "https://cdn.example.com/cat.png", extra_urls: [uuid, "https://cdn.example.com/b.png"] })).toEqual({});
    });

    it("apply the public URL policy to links, per entry", () => {
        expect(schemaErrors(schema, { image_url: "https://192.168.0.2/cat.png" }).image_url).toBe(publicUrlError("https://192.168.0.2/cat.png"));
        const errors = schemaErrors(schema, { image_url: uuid, extra_urls: [uuid, "http://cdn.example.com/b.png"] });
        expect(Object.keys(errors)).toEqual(["extra_urls.1"]);
        expect(schemaErrors(schema, { image_url: "" }).image_url).toBeTruthy();
    });

    it("reject links when the model only accepts uploads", () => {
        const uploadsOnly = { ...schema, properties: { image_url: { ...leaf, "x-workspace-asset": { kind: "image", role: "image_ref" } } } };
        expect(schemaErrors(uploadsOnly, { image_url: uuid })).toEqual({});
        expect(schemaErrors(uploadsOnly, { image_url: "https://cdn.example.com/cat.png" }).image_url).toBeTruthy();
    });

    it("start file lists empty instead of blank placeholder entries", () => {
        expect(schemaDefault({ type: "object", required: ["refs"], properties: { refs: { type: "array", minItems: 2, items: leaf } } })).toEqual({ refs: [] });
    });

    it("explain a nullable file through its file branch", () => {
        const nullable = { type: "object", required: ["mask"], properties: { mask: { anyOf: [leaf, { type: "null" }] } } };
        expect(schemaErrors(nullable, { mask: null })).toEqual({});
        expect(schemaErrors(nullable, { mask: "https://10.0.0.1/m.png" }).mask).toBe(publicUrlError("https://10.0.0.1/m.png"));
    });

    it("keep ordinary uri parameters under the same policy", () => {
        const webhook = { type: "object", properties: { callback: { type: "string", format: "uri" } } };
        expect(schemaErrors(webhook, { callback: "https://hooks.example.com/x" })).toEqual({});
        expect(schemaErrors(webhook, { callback: "https://metadata.internal/x" }).callback).toBeTruthy();
    });
});

describe("schemaVariants (input modes)", () => {
    it("keep a nested member's declared fields when a branch only constrains it", () => {
        // Runware: "reference images or frame images" constrains members inside the declared `inputs` object.
        const inputs = { type: "object", title: "Inputs", properties: { referenceImages: { type: "array", items: leaf }, frameImages: { type: "array", items: leaf } } };
        const schema = { type: "object", properties: { inputs }, anyOf: [
            { required: ["inputs"], properties: { inputs: { required: ["referenceImages"] } } },
            { required: ["inputs"], properties: { inputs: { properties: { frameImages: { minItems: 1 } } } } },
        ] };
        const [reference, frames] = schemaVariants(schema);
        expect(reference.properties.inputs).toMatchObject({ title: "Inputs", required: ["referenceImages"] });
        expect(reference.properties.inputs.properties.referenceImages.items).toEqual(leaf);
        expect(frames.properties.inputs.properties.frameImages).toMatchObject({ type: "array", items: leaf, minItems: 1 });
    });
});

describe("workspaceQuote (server billing envelope)", () => {
    it("admits one native job at base × Pro and multiplies only the total by count", () => {
        const native = { contract_version: 1, price_tokens: 10, billing: { mode: "per_output", count_field: "count", max_count: 4, pro_field: "pro", pro_multiplier: 2 } };
        expect(workspaceQuote(native, {}, { count: 3, pro: true })).toMatchObject({ admission: 20, total: 60, count: 3 });
        expect(workspaceQuote(native, {}, { count: 5 }).total).toBeNull();
    });

    it("bills per second from the parsed duration and never applies Pro there", () => {
        const avatar = { contract_version: 1, price_tokens: 7, billing: { mode: "per_second", duration_field: "duration", durations: [], pro_field: "pro", pro_multiplier: 2 } };
        expect(workspaceQuote(avatar, { duration: 5 }, { pro: true })).toMatchObject({ admission: 35, total: 35, seconds: 5 });
        const v2 = { contract_version: 2, price_tokens: 3, billing: { mode: "per_second", duration_field: "duration", durations: [5, 10] },
            input_schema: { type: "object", properties: { duration: { type: "string", enum: ["5", "10", "auto"], default: "auto" } } } };
        expect(workspaceQuote(v2, {}, {})).toMatchObject({ admission: null, seconds: null });
        expect(workspaceQuote(v2, {}, {}).reason).toBeTruthy();
        expect(workspaceQuote(v2, { duration: "10" }, {})).toMatchObject({ admission: 30, total: 30, seconds: 10 });
        expect(workspaceQuote(v2, { duration: "8s" }, {}).reason).toBeTruthy();
    });

    it("requires a reviewed billing_seconds choice when the schema has no duration", () => {
        const v2 = { contract_version: 2, price_tokens: 4, billing: { mode: "per_second", duration_field: "billing_seconds", durations: [6] }, input_schema: { type: "object", properties: {} } };
        expect(workspaceQuote(v2, {}, {}).admission).toBeNull();
        expect(workspaceQuote(v2, {}, { billing_seconds: 6 })).toMatchObject({ admission: 24, total: 24 });
        expect(workspaceQuote({ ...v2, billing: { ...v2.billing, durations: [] } }, {}, { billing_seconds: 6 }).reason).toBeTruthy();
    });

    it("prices a schema quantity input into the one job's admission", () => {
        const schema = { type: "object", properties: { numberResults: { type: "integer", minimum: 1, maximum: 4, default: 2 }, duration: { type: "integer", default: 5 } } };
        const perImage = { contract_version: 2, price_tokens: 6, billing: { mode: "per_output", quantity_input: "numberResults", max_quantity: 4 }, input_schema: schema };
        expect(workspaceQuote(perImage, { numberResults: 3 })).toMatchObject({ admission: 18, total: 18, quantity: 3, count: 1 });
        expect(workspaceQuote(perImage, {})).toMatchObject({ admission: 12, quantity: 2 });
        expect(workspaceQuote(perImage, { numberResults: "" })).toMatchObject({ admission: 6, quantity: 1 });
        expect(workspaceQuote(perImage, { numberResults: 5 }).admission).toBeNull();
        const perSecond = { ...perImage, billing: { mode: "per_second", duration_field: "duration", durations: [], quantity_input: "numberResults", max_quantity: 4 } };
        expect(workspaceQuote(perSecond, { numberResults: 2, duration: 5 })).toMatchObject({ admission: 60, seconds: 5, quantity: 2 });
        expect(workspaceQuote({ ...perImage, contract_version: 1 }, { numberResults: 3 })).toMatchObject({ admission: 6, quantity: 1 });
    });

    it("parses whole billed seconds like the server", () => {
        expect([billedSeconds(5), billedSeconds("5"), billedSeconds("8s")]).toEqual([5, 5, 8]);
        expect([billedSeconds("auto"), billedSeconds(2.5), billedSeconds("0"), billedSeconds(null)]).toEqual([null, null, null, null]);
    });
});

describe("submissionUnresolved (lost responses for paid work)", () => {
    const failure = (status, details = {}) => ({ status, details });

    it("keeps the key when the outcome is unknown", () => {
        expect(submissionUnresolved({})).toBe(true);
        expect(submissionUnresolved(failure(500, { message: "Server Error" }))).toBe(true);
        expect(submissionUnresolved(failure(422, { uncertain: true }))).toBe(true);
    });

    it("releases a first attempt refused before admission", () => {
        for (const status of [401, 403, 419, 422, 429]) expect(submissionUnresolved(failure(status))).toBe(false);
    });

    it("keeps an uncertain key when a recovery attempt is refused before the server answers for it", () => {
        for (const status of [401, 403, 413, 419, 429]) expect(submissionUnresolved(failure(status), true)).toBe(true);
    });

    it("releases an uncertain key only on the server's answer for that key", () => {
        for (const status of [402, 404, 409, 422]) expect(submissionUnresolved(failure(status), true)).toBe(false);
    });
});

describe("realtimeOutcomeUnknown (realtime start responses)", () => {
    it("treats server errors without a session outcome as unknown, whatever their message", () => {
        expect(realtimeOutcomeUnknown({ status: 500, details: { message: "Server Error" } })).toBe(true);
        expect(realtimeOutcomeUnknown({ status: 503, details: {} })).toBe(true);
        expect(realtimeOutcomeUnknown(new Error("offline"))).toBe(true);
    });

    it("accepts an outcome the server reported for the session or a client refusal", () => {
        expect(realtimeOutcomeUnknown({ status: 502, details: { message: "Rejected", session: { status: "failed" } } })).toBe(false);
        expect(realtimeOutcomeUnknown({ status: 422, details: { errors: {} } })).toBe(false);
    });
});

describe("jobCandidates (deep links)", () => {
    it("reads bare studio ids as that studio's native job first and keeps typed or /media ids verbatim", () => {
        expect(jobCandidates("abc-1", "avatar")).toEqual(["video:abc-1", "abc-1"]);
        expect(jobCandidates("abc-1", "model3d")).toEqual(["model3d:abc-1", "abc-1"]);
        expect(jobCandidates("audio:abc-1", "image")).toEqual(["audio:abc-1"]);
        expect(jobCandidates("abc-1", "")).toEqual(["abc-1"]);
        expect(jobCandidates("../abc", "image")).toEqual([]);
    });
});

describe("batchView (several image jobs from one request)", () => {
    const image = (id) => ({ id: "0", kind: "image", mime: "image/png", previewable: true, url: `/api/media/workspace/jobs/${id}/outputs/0/preview` });
    const batch = { jobs: ["image:a", "image:b", "image:c"] };

    it("is absent for a single request", () => {
        expect(batchView({ id: "image:a", batch: null }, [])).toBeNull();
        expect(batchView(null, [])).toBeNull();
    });

    it("shows every variation in request order with ids that stay distinct across jobs", () => {
        const jobs = [
            { id: "image:c", status: "completed", batch, outputs: [image("c")] },
            { id: "image:a", status: "completed", batch, outputs: [image("a")] },
            { id: "image:b", status: "processing", stage: "rendering", batch, outputs: [] },
        ];
        const view = batchView(jobs[1], jobs);
        expect(view.outputs.map((output) => [output.id, output.job_id])).toEqual([["image:a#0", "image:a"], ["image:c#0", "image:c"]]);
        expect(view).toMatchObject({ size: 3, done: 2, failed: 0, pending: ["image:b"], missing: [] });
    });

    it("reports members that are not loaded yet and those that ended without an image", () => {
        const view = batchView({ id: "image:a", status: "failed", batch, outputs: [] }, [{ id: "image:b", status: "cancelled", batch, outputs: [] }]);
        expect(view).toMatchObject({ size: 3, done: 0, failed: 2, missing: ["image:c"], outputs: [] });
        expect(view.pending).toEqual(["image:c"]);
    });
});
