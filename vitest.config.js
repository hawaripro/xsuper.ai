import { defineConfig } from "vitest/config";

// Dedicated unit-test config: intentionally omits the Laravel Vite plugin (build-only, and it
// refuses to run outside a dev/build context) so pure-JS studio logic can be tested in Node.
export default defineConfig({
    test: {
        include: ["resources/js/**/*.test.js"],
        environment: "node",
    },
});
