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
            lib: { entry: path.resolve('tests/e2e/artifact-client.jsx'), formats: ['es'], fileName: 'artifact-client' },
            rolldownOptions: { output: { codeSplitting: false } },
        },
        define: { 'process.env.NODE_ENV': '"test"' },
    });
    const bundles = Array.isArray(result) ? result : [result];
    clientModule = bundles.flatMap(bundle => bundle.output).find(item => item.type === 'chunk').code;
});

test.beforeEach(async ({ page }) => {
    await page.route('**/__qa/artifact-client.js', route => route.fulfill({ contentType: 'text/javascript', body: clientModule }));
    await page.goto('/en/login');
    await page.evaluate(() => {
        document.body.innerHTML = '<main id="artifact-test"></main>';
        window.__artifactExecuted = false;
    });
    await page.addScriptTag({ url: '/__qa/artifact-client.js', type: 'module' });
    await page.waitForFunction(() => typeof window.renderArtifactPreview === 'function');
});

test('hostile HTML cannot execute, access the parent, submit forms or load external resources', async ({ page }) => {
    const outbound = [];
    await page.route('https://artifact-attacker.invalid/**', route => { outbound.push(route.request().url()); return route.abort(); });
    await page.evaluate(() => window.renderArtifactPreview({
        kind: 'html', filename: 'hostile.html', editable: true,
        content: `<meta http-equiv="refresh" content="0;url=https://artifact-attacker.invalid/refresh">
            <script>parent.__artifactExecuted = true</script>
            <style>body { background-image: url(https://artifact-attacker.invalid/style); }</style>
            <img src="https://artifact-attacker.invalid/image" onerror="parent.__artifactExecuted = true">
            <iframe src="https://artifact-attacker.invalid/frame"></iframe>
            <p id="safe">The document still renders.</p>
            <a href="javascript:parent.__artifactExecuted=true">Unsafe link</a>
            <form action="https://artifact-attacker.invalid/submit"><button type="submit">Submit</button></form>`,
    }));
    const frame = page.frameLocator('iframe.artifact-html-preview');
    await expect(frame.locator('#safe')).toHaveText('The document still renders.');
    const frameHandle = await page.locator('iframe.artifact-html-preview').elementHandle();
    const inner = await frameHandle.contentFrame();
    expect(await inner.evaluate(() => {
        try { return Boolean(window.parent.document.body); } catch { return false; }
    })).toBe(false);
    await frame.getByRole('button', { name: 'Submit' }).click();
    await frame.getByText('Unsafe link').click();
    expect(await page.evaluate(() => window.__artifactExecuted)).toBe(false);
    expect(outbound).toEqual([]);
    await expect(page).toHaveURL('/en/login');
});

test('SVG stays an image and cannot execute its script or event handlers in the application', async ({ page }) => {
    await page.evaluate(() => window.renderArtifactPreview({
        kind: 'svg', filename: 'hostile.svg', editable: true,
        content: `<svg xmlns="http://www.w3.org/2000/svg" id="untrusted-svg" width="40" height="40" onload="parent.__artifactExecuted=true"><script>parent.__artifactExecuted=true</script><rect width="40" height="40" fill="red"/></svg>`,
    }));
    const image = page.getByRole('img', { name: 'hostile.svg' });
    await expect(image).toBeVisible();
    await expect.poll(() => image.evaluate(element => element.complete && element.naturalWidth)).toBe(40);
    expect(await page.evaluate(() => window.__artifactExecuted)).toBe(false);
    await expect(page.locator('svg#untrusted-svg')).toHaveCount(0);
});

test('Markdown raw HTML and executable links never become active content', async ({ page }) => {
    await page.evaluate(() => window.renderArtifactPreview({
        kind: 'markdown', filename: 'hostile.md', editable: true,
        content: '# Safe heading\n\n<script>window.__artifactExecuted=true</script>\n\n<img src=x onerror="window.__artifactExecuted=true">\n\n[Unsafe](javascript:window.__artifactExecuted=true)',
    }));
    await expect(page.getByRole('heading', { name: 'Safe heading' })).toBeVisible();
    await expect(page.locator('#artifact-test img')).toHaveCount(0);
    await expect(page.locator('#artifact-test a[href^="javascript:"]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__artifactExecuted)).toBe(false);
});

test('conflicting saves retain the draft and require an explicit new revision after comparison', async ({ page }) => {
    const id = 'c511dc3c-25cd-477f-a039-cba7e65d8888';
    const firstRevision = '609e2831-6eb4-49f8-a989-663250342222';
    let artifact = {
        id, conversation_id: 'artifact-conflict', title: 'Notes', filename: 'notes.md', mime: 'text/markdown',
        kind: 'markdown', version: 1, latest_version: 1, revision_id: firstRevision, editable: true,
        content: 'Original note', download_url: `/api/c/artifacts/${id}/download?revision=${firstRevision}`,
        revisions: [{ id: firstRevision, version: 1, created_at: '2026-09-23T10:00:00Z' }],
    };
    await page.route('**/api/user', route => route.fulfill({ json: { id: 73, name: 'Artifact owner' } }));
    await page.route('**/api/c/h/artifact-conflict/artifacts', route => route.fulfill({ json: { artifacts: [artifact] } }));
    await page.route(`**/api/c/artifacts/${id}`, route => route.fulfill({ json: { artifact } }));
    const writes = [];
    await page.route(`**/api/c/artifacts/${id}/revisions`, route => {
        const request = route.request().postDataJSON();
        writes.push(request);
        if (writes.length === 1) {
            artifact = { ...artifact, version: 2, latest_version: 2, content: 'Saved by another editor' };
            return route.fulfill({ status: 409, json: { message: 'A newer revision exists.', current_version: 2 } });
        }
        artifact = { ...artifact, version: 3, latest_version: 3, content: request.content };
        return route.fulfill({ status: 201, json: { artifact } });
    });
    await page.evaluate(props => window.renderArtifactPanel(props), { conversationId: 'artifact-conflict', artifactId: id });
    await expect(page.getByText('Original note', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Edit', exact: true }).click();
    await page.getByLabel('Content', { exact: true }).fill('My unsaved revision');
    await page.getByRole('button', { name: 'Save revision', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('A newer revision exists.');
    await expect(page.getByLabel('Content', { exact: true })).toHaveValue('My unsaved revision');
    expect(await page.evaluate(() => window.__artifactDirty)).toBe(true);
    await page.getByRole('button', { name: 'Compare latest', exact: true }).click();
    await expect(page.getByText('Saved by another editor', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Save my draft as a new revision', exact: true }).click();
    await expect(page.getByText('Saved version 3', { exact: true })).toBeVisible();
    await expect(page.getByText('My unsaved revision', { exact: true })).toBeVisible();
    expect(writes).toEqual([
        { base_version: 1, content: 'My unsaved revision', filename: 'notes.md' },
        { base_version: 2, content: 'My unsaved revision', filename: 'notes.md' },
    ]);
    expect(await page.evaluate(() => window.__artifactDirty)).toBe(false);
});
