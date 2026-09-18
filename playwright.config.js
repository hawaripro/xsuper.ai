import { defineConfig } from '@playwright/test';
import { root, testEnvironment } from './tests/e2e/environment.js';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.js',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    webServer: {
        command: 'node tests/e2e/prepare.js && node tests/e2e/serve.js',
        url: 'http://127.0.0.1:8017/api/health',
        cwd: root,
        env: testEnvironment,
        reuseExistingServer: false,
        timeout: 30_000,
    },
    timeout: 45_000,
    expect: { timeout: 12_000 },
    outputDir: 'storage/framework/testing/playwright-results',
    reporter: [['list'], ['json', { outputFile: 'storage/framework/testing/playwright-report.json' }]],
    use: {
        baseURL: 'http://127.0.0.1:8017',
        browserName: 'chromium',
        channel: process.env.E2E_BROWSER_CHANNEL || 'chrome',
        headless: true,
        viewport: { width: 1440, height: 1000 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
