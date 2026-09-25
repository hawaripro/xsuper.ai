import { describe, it, expect } from "vitest";
import { legacyStudioHref, studioHref } from "../studioLinks.js";

describe("studioHref", () => {
    it("puts kind first, then model, operation, job and track", () => {
        expect(studioHref({ track: 2, job: "abc", operation: "text_to_image", model: "fal-ai/flux/dev", kind: "image" }))
            .toBe("/studio?kind=image&model=fal-ai%2Fflux%2Fdev&operation=text_to_image&job=abc&track=2");
    });

    it("omits empty values but keeps track 0", () => {
        expect(studioHref({ kind: "audio", model: "", operation: null, job: "j-1", track: 0 })).toBe("/studio?kind=audio&job=j-1&track=0");
    });

    it("drops an unknown kind", () => {
        expect(studioHref({ kind: "music", job: "abc" })).toBe("/studio?job=abc");
        expect(studioHref({ kind: "Image", model: "m" })).toBe("/studio?model=m");
    });

    it("links to the bare studio without parameters", () => {
        expect(studioHref()).toBe("/studio");
        expect(studioHref({ kind: "", job: "" })).toBe("/studio");
    });
});

describe("legacyStudioHref", () => {
    it.each([
        ["/generate-image", "image"], ["/video", "video"], ["/audio", "audio"], ["/avatar", "avatar"], ["/3d", "model3d"],
    ])("%s opens the %s studio and keeps every other parameter in order", (pathname, kind) => {
        expect(legacyStudioHref(pathname, "?job=j&track=2&model=m&operation=o&conversation_id=c"))
            .toBe(`/studio?kind=${kind}&job=j&track=2&model=m&operation=o&conversation_id=c`);
    });

    it("replaces any kind in the query with the legacy page's own kind", () => {
        expect(legacyStudioHref("/video", "?kind=audio&job=v")).toBe("/studio?kind=video&job=v");
    });

    it("keeps a valid /media kind and drops an invalid one", () => {
        expect(legacyStudioHref("/media", "?kind=audio&job=y")).toBe("/studio?kind=audio&job=y");
        expect(legacyStudioHref("/media", "?job=y&kind=music&track=1")).toBe("/studio?job=y&track=1");
    });

    it("opens the bare studio for an empty query", () => {
        expect(legacyStudioHref("/media", "")).toBe("/studio");
        expect(legacyStudioHref("/media")).toBe("/studio");
        expect(legacyStudioHref("/generate-image", "")).toBe("/studio?kind=image");
    });
});
