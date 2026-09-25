import { describe, it, expect } from "vitest";
import { groupJobs, jobDraft, jobMatchesKind, jobParameters, jobPrompt, jobSeed, jobStatusGroup, timeAgo } from "../studioJobs.js";

describe("jobDraft (Muat ke form)", () => {
    it("restores a schema job's own normalized request", () => {
        const job = { id: "w1", model: "runware/bfl-flux-1-dev", operation: "text_to_image", output_kind: "image",
            request: { model_id: "runware/bfl-flux-1-dev", operation: "text_to_image", inputs: { positivePrompt: "Kucing", numberResults: 2 } } };
        const draft = jobDraft(job);
        expect(draft).toEqual({ model: "runware/bfl-flux-1-dev", operation: "text_to_image", values: { positivePrompt: "Kucing", numberResults: 2 } });
        draft.values.positivePrompt = "changed";
        expect(job.request.inputs.positivePrompt).toBe("Kucing");
    });

    it("rebuilds native drafts from recorded settings; video prompts return to the prompt or image tab", () => {
        expect(jobDraft({ model: "qa-image", operation: "text_to_image", output_kind: "image", request: null, details: { prompt: "Apel", size: "1024x1024", tokens_reserved: 15 } }))
            .toEqual({ model: "qa-image", operation: "text_to_image", values: { prompt: "Apel", size: "1024x1024" } });
        expect(jobDraft({ model: "veo", operation: "image_to_video", output_kind: "video", details: { prompt: "Ombak", aspect_ratio: "16:9", duration: 8, reference_url: "/api/v/x/reference" } }))
            .toEqual({ model: "veo", operation: "image_to_video", values: { prompt: "Ombak", aspect_ratio: "16:9", duration: 8 }, authoring: { tab: "reference", prompt: "Ombak" } });
        expect(jobDraft({ id: "x" })).toBeNull();
    });

    it("lists readable parameters without prompts or nested files", () => {
        expect(jobParameters({ request: { inputs: { positivePrompt: "x", width: 1024, inputs: { seedImage: "uuid" }, safety: null } } })).toEqual([["width", "1024"]]);
        expect(jobParameters({ output_kind: "video", details: { aspect_ratio: "9:16", duration: 5, pro_mode: true } })).toEqual([["aspect_ratio", "9:16"], ["duration", "5"], ["pro", "Pro"]]);
    });
});

describe("jobSeed (Salin seed)", () => {
    it("reads the seed of the result item matching the selected output", () => {
        const job = { result_data: [{ download_url: "/d/0", seed: 11 }, { download_url: "/d/1", seed: 22 }] };
        expect(jobSeed(job, { download_url: "/d/1" })).toBe("22");
        expect(jobSeed(job)).toBe("11");
        expect(jobSeed({ result_data: [{ download_url: "/d/0" }] }, { download_url: "/d/0" })).toBeNull();
    });

    it("reads one seed for a whole schema result and none for native jobs", () => {
        expect(jobSeed({ result_data: { seed: 42, images: [] } })).toBe("42");
        expect(jobSeed({ result_data: { seed: "not-a-seed" } })).toBeNull();
        expect(jobSeed({ result_data: null })).toBeNull();
    });
});

describe("history views", () => {
    it("shows several images of one request as one set", () => {
        const members = ["image:1", "image:2"];
        const rows = groupJobs([{ id: "image:2", batch: { jobs: members, index: 1 } }, { id: "video:9" }, { id: "image:1", batch: { jobs: members, index: 0 } }]);
        expect(rows.map((row) => [row.key, row.job.id, row.members])).toEqual([["batch:image:1", "image:2", members], ["video:9", "video:9", ["video:9"]]]);
    });

    it("filters by status group and studio kind", () => {
        expect([{ status: "processing" }, { status: "completed" }, { status: "save_failed" }, { status: "cancelled" }].map(jobStatusGroup)).toEqual(["running", "done", "failed", "failed"]);
        const avatar = { output_kind: "video", operation: "talking_avatar" };
        expect([jobMatchesKind(avatar, "avatar"), jobMatchesKind(avatar, "video"), jobMatchesKind({ output_kind: "data" }, "other"), jobMatchesKind({ output_kind: "image" }, "")]).toEqual([true, false, true, true]);
    });

    it("titles schema jobs by their own prompt field", () => {
        expect(jobPrompt({ details: { prompt: null }, request: { inputs: { positivePrompt: "  ", negativePrompt: "blur" } } })).toBeNull();
        expect(jobPrompt({ details: { prompt: null }, request: { inputs: { width: 512, positivePrompt: "a red fox" } } })).toBe("a red fox");
        expect(jobPrompt({ details: { prompt: "native prompt" }, request: { inputs: { positivePrompt: "ignored" } } })).toBe("native prompt");
    });

    it("reads card times in whole units and falls back to dates after four weeks", () => {
        const now = Date.parse("2026-09-25T12:00:00Z");
        expect(timeAgo("2026-09-25T11:59:30Z", now)).toEqual({ value: 0, unit: "second" });
        expect(timeAgo("2026-09-25T11:55:00Z", now)).toEqual({ value: 5, unit: "minute" });
        expect(timeAgo("2026-09-24T09:00:00Z", now)).toEqual({ value: 1, unit: "day" });
        expect(timeAgo("2026-09-10T12:00:00Z", now)).toEqual({ value: 2, unit: "week" });
        expect(timeAgo("2026-08-01T12:00:00Z", now)).toBeNull();
        expect(timeAgo("not a date", now)).toBeNull();
    });
});
