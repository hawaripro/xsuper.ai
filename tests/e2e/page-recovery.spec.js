import { test, expect } from '@playwright/test';
import { build } from 'vite';
import path from 'node:path';

let clientModule;
test.beforeAll(async () => {
    const result = await build({
        configFile: false,
        logLevel: 'silent',
        build: {
            write: false,
            minify: false,
            lib: { entry: path.resolve('tests/e2e/recovery-client.jsx'), formats: ['es'], fileName: 'recovery' },
            rolldownOptions: { output: { codeSplitting: false } },
        },
        define: { 'process.env.NODE_ENV': '"test"' },
    });
    const bundles = Array.isArray(result) ? result : [result];
    clientModule = bundles.flatMap(bundle => bundle.output).find(item => item.type === 'chunk').code;
});

test('page boundary retains recoverable UI and retries the child render', async ({ page }) => {
    await page.goto('/en/login');
    await page.evaluate(() => { document.body.innerHTML = '<div id="recovery-test"></div>'; });
    await page.addScriptTag({ content: clientModule, type: 'module' });
    await expect(page.getByRole('heading', { name: 'Page could not be displayed' })).toBeVisible();
    await expect(page.getByText('private error details')).toHaveCount(0);
    await page.getByRole('button', { name: 'Try again', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Recovered content' })).toBeVisible();
});

test('dashboard recovery link retries a failure on the dashboard itself', async ({ page }) => {
    await page.goto('/en/login');
    await page.evaluate(() => { document.body.innerHTML = '<div id="recovery-test"></div>'; });
    await page.addScriptTag({ content: clientModule, type: 'module' });
    await expect(page.getByRole('heading', { name: 'Page could not be displayed' })).toBeVisible();
    await page.getByRole('link', { name: 'Back to dashboard', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Recovered content' })).toBeVisible();
});
