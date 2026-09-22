import { test, expect } from '@playwright/test';
import { login, databaseRows, captureErrors } from './helpers.js';

async function createProvider(page, { name, protocol, baseUrl, key }) {
    await page.getByRole('button', { name: 'Add provider', exact: true }).click();
    const editor = page.locator('[data-provider-editor]');
    await editor.getByLabel('Provider name', { exact: true }).fill(name);
    await editor.getByLabel('Protocol', { exact: true }).selectOption(protocol);
    await editor.getByLabel('HTTPS base URL', { exact: true }).fill(baseUrl);
    await editor.getByLabel('Provider API key', { exact: true }).fill(key);
    if (protocol === 'anthropic') {
        await editor.getByLabel('Anthropic API version', { exact: true }).fill('2023-06-01');
    }
    const created = page.waitForResponse(response => response.url().endsWith('/api/admin/ai/providers') && response.request().method() === 'POST');
    await editor.getByRole('button', { name: 'Save provider', exact: true }).click();
    const response = await created;
    expect(response.status()).toBe(201);
    const payload = await response.json();
    expect({ name: payload.provider.name, protocol: payload.provider.protocol, has_api_key: payload.provider.has_api_key, configuration_source: payload.provider.configuration_source }).toEqual({ name, protocol, has_api_key: true, configuration_source: 'admin' });
    expect(Object.hasOwn(payload.provider, 'api_key')).toBe(false);
    expect(JSON.stringify(payload).includes(key)).toBe(false);
    await expect(editor).toHaveCount(0);
    await expect(page.getByRole('list', { name: 'AI provider cards' }).getByRole('button').filter({ hasText: name })).toBeVisible();
    return payload.provider;
}

async function openConnection(page, id, locale = 'en') {
    await page.goto(`${locale === 'en' ? '/en' : ''}/admin/ai/${id}`);
    await page.locator('#provider-tab-connection').click();
}

async function saveProviderEdit(page, providerId) {
    const saved = page.waitForResponse(response => response.url().endsWith(`/api/admin/ai/providers/${providerId}`) && response.request().method() === 'PATCH');
    await page.locator('[data-provider-editor]').getByRole('button', { name: 'Save provider', exact: true }).click();
    expect((await saved).status()).toBe(200);
    await expect(page.locator('[data-provider-editor]')).toHaveCount(0);
}

test('independent provider secrets survive reload and rotation, model routing and enablement persist', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = captureErrors(page);
    const firstKey = 'qa-openai-provider-not-a-real-key';
    const rotatedKey = 'qa-openai-rotated-not-a-real-key';
    const anthropicKey = 'qa-anthropic-provider-not-a-real-key';
    await login(page);
    await page.goto('/en/admin/ai');
    const openai = await createProvider(page, {
        name: 'QA OpenAI connection', protocol: 'openai', baseUrl: 'https://openai-provider.invalid/v1', key: firstKey,
    });
    const anthropic = await createProvider(page, {
        name: 'QA Anthropic connection', protocol: 'anthropic', baseUrl: 'https://anthropic-provider.invalid/v1', key: anthropicKey,
    });
    const originalSecret = databaseRows('ai_provider_profiles', { id: openai.id })[0].api_key;
    expect(typeof originalSecret === 'string' && originalSecret !== firstKey).toBe(true);
    await openConnection(page, openai.id);
    const openaiRow = page.locator(`[data-provider-id="${openai.id}"]`);
    const anthropicRow = page.locator(`[data-provider-id="${anthropic.id}"]`);
    await expect(openaiRow).toBeVisible();
    const catalog = await page.request.get('/api/admin/ai/catalog/summary');
    expect(catalog.status()).toBe(200);
    const catalogText = await catalog.text();
    expect(catalogText.includes(firstKey) || catalogText.includes(anthropicKey)).toBe(false);
    await openaiRow.getByRole('button', { name: 'Edit', exact: true }).click();
    const providerEditor = page.locator('[data-provider-editor]');
    const keyInput = providerEditor.getByLabel('Provider API key', { exact: true });
    await expect(keyInput).toHaveAttribute('type', 'password');
    await expect.poll(async () => (await keyInput.inputValue()) === '').toBe(true);
    await providerEditor.getByLabel('Provider name', { exact: true }).fill('QA OpenAI renamed');
    await saveProviderEdit(page, openai.id);
    expect(databaseRows('ai_provider_profiles', { id: openai.id })[0].api_key === originalSecret).toBe(true);
    await openaiRow.getByRole('button', { name: 'Edit', exact: true }).click();
    await providerEditor.getByLabel('HTTPS base URL', { exact: true }).fill('https://rotated-provider.invalid/v1');
    await providerEditor.getByRole('button', { name: 'Save provider', exact: true }).click();
    await expect(providerEditor).toBeVisible();
    expect(databaseRows('ai_provider_profiles', { id: openai.id })[0].base_url).toBe('https://openai-provider.invalid/v1');
    expect(databaseRows('ai_provider_profiles', { id: openai.id })[0].api_key === originalSecret).toBe(true);
    await keyInput.fill(rotatedKey);
    await saveProviderEdit(page, openai.id);
    const rotated = databaseRows('ai_provider_profiles', { id: openai.id })[0];
    expect(rotated.base_url).toBe('https://rotated-provider.invalid/v1');
    expect(rotated.api_key !== originalSecret).toBe(true);
    expect(typeof rotated.api_key === 'string' && !rotated.api_key.includes(rotatedKey)).toBe(true);
    await page.reload();
    await page.locator('#provider-tab-connection').click();
    await openaiRow.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect.poll(async () => (await keyInput.inputValue()) === '').toBe(true);
    await providerEditor.getByLabel('Provider name', { exact: true }).fill('QA OpenAI rotated');
    await saveProviderEdit(page, openai.id);
    expect(databaseRows('ai_provider_profiles', { id: openai.id })[0].api_key === rotated.api_key).toBe(true);

    await page.locator('#provider-tab-models').click();
    await page.getByRole('button', { name: 'New model', exact: true }).click();
    const modelEditor = page.locator('[data-model-editor]');
    await modelEditor.getByLabel('Model ID', { exact: true }).fill('qa-provider-routed-model');
    await modelEditor.getByLabel('Display name', { exact: true }).fill('QA routed model');
    await modelEditor.getByLabel('Providers', { exact: true }).fill('Editorial provider label');
    await modelEditor.getByLabel('Provider connection', { exact: true }).selectOption(openai.slug);
    await modelEditor.getByLabel('Upstream model ID', { exact: true }).fill('shared-upstream-model');
    await modelEditor.getByLabel('Active', { exact: true }).check();
    const modelCreated = page.waitForResponse(response => response.url().endsWith('/api/admin/ai/models') && response.request().method() === 'POST');
    await modelEditor.getByRole('button', { name: 'Save model', exact: true }).click();
    expect((await modelCreated).status()).toBe(201);
    await expect(modelEditor).toHaveCount(0);
    const model = databaseRows('ai_model_profiles', { model_id: 'qa-provider-routed-model' })[0];
    expect(model).toMatchObject({ provider_id: openai.id, upstream_model_id: 'shared-upstream-model', provider_name: 'Editorial provider label' });
    const modelRow = page.getByRole('row').filter({ hasText: 'qa-provider-routed-model' });
    await modelRow.getByRole('button', { name: 'Edit model', exact: true }).click();
    await modelEditor.getByLabel('Provider connection', { exact: true }).selectOption(anthropic.slug);
    await expect(modelEditor.getByLabel('Upstream model ID', { exact: true })).toHaveValue('');
    await modelEditor.getByLabel('Upstream model ID', { exact: true }).fill('claude-provider-model');
    const modelSaved = page.waitForResponse(response => response.url().endsWith(`/api/admin/ai/models/${model.id}`) && response.request().method() === 'PATCH');
    await modelEditor.getByRole('button', { name: 'Save model', exact: true }).click();
    expect((await modelSaved).status()).toBe(200);
    await expect(modelEditor).toHaveCount(0);
    expect(databaseRows('ai_model_profiles', { id: model.id })[0]).toMatchObject({ model_id: 'qa-provider-routed-model', provider_id: anthropic.id, upstream_model_id: 'claude-provider-model', provider_name: 'Editorial provider label', is_available: 0 });
    await page.goto('/en/models');
    await page.locator('[data-model-search]').fill('qa-provider-routed-model');
    await expect(page.locator('[data-model-card]:visible')).toHaveCount(1);
    await openConnection(page, anthropic.id);
    const disabled = page.waitForResponse(response => response.url().endsWith(`/api/admin/ai/providers/${anthropic.id}`) && response.request().method() === 'PATCH');
    await anthropicRow.getByRole('button', { name: 'Disable connection', exact: true }).click();
    expect((await disabled).status()).toBe(200);
    await expect(anthropicRow.getByRole('button', { name: 'Enable connection', exact: true })).toBeVisible();
    expect(databaseRows('ai_provider_profiles', { id: anthropic.id })[0].is_enabled).toBe(0);
    expect(databaseRows('ai_provider_profiles', { id: openai.id })[0].is_enabled).toBe(1);
    await page.goto('/en/models');
    await expect(page.locator('[data-model-card]').filter({ hasText: 'qa-provider-routed-model' })).toHaveCount(0);
    await openConnection(page, anthropic.id);
    const enabled = page.waitForResponse(response => response.url().endsWith(`/api/admin/ai/providers/${anthropic.id}`) && response.request().method() === 'PATCH');
    await anthropicRow.getByRole('button', { name: 'Enable connection', exact: true }).click();
    expect((await enabled).status()).toBe(200);
    await expect(anthropicRow.getByRole('button', { name: 'Disable connection', exact: true })).toBeVisible();
    expect(databaseRows('ai_provider_profiles', { id: anthropic.id })[0].is_enabled).toBe(1);
    expect(errors).toEqual([]);
});

test('editing locks connection actions and failed checks and syncs never report health success', async ({ page }) => {
    test.setTimeout(90_000);
    const errors = captureErrors(page);
    await login(page);
    await page.goto('/en/admin/ai');
    const checked = await createProvider(page, {
        name: 'QA unreachable connection', protocol: 'openai', baseUrl: 'https://check-provider.invalid/v1', key: 'qa-check-not-a-real-key',
    });
    await openConnection(page, checked.id);
    const checkedRow = page.locator(`[data-provider-id="${checked.id}"]`);
    const checkButton = checkedRow.getByRole('button', { name: 'Check connection', exact: true });
    const syncButton = checkedRow.getByRole('button', { name: 'Sync metadata (draft)', exact: true });
    await checkedRow.getByRole('button', { name: 'Edit', exact: true }).click();
    const editor = page.locator('[data-provider-editor]');
    await editor.getByLabel('Provider name', { exact: true }).fill('Unsaved connection name');
    await editor.getByLabel('Provider API key', { exact: true }).fill('qa-unsaved-draft-key');
    await expect(checkButton).toBeDisabled();
    await expect(syncButton).toBeDisabled();
    await page.locator('[data-provider-connections]').getByRole('button', { name: 'Refresh', exact: true }).click();
    await expect(editor.getByLabel('Provider name', { exact: true })).toHaveValue('Unsaved connection name');
    await expect(editor.getByLabel('Provider API key', { exact: true })).toHaveValue('qa-unsaved-draft-key');
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    const failedCheck = page.waitForResponse(response => response.url().endsWith(`/api/admin/ai/providers/${checked.id}/check`));
    await checkButton.click();
    const checkResponse = await failedCheck;
    expect(checkResponse.status()).toBe(503);
    expect((await checkResponse.text()).includes('qa-check-not-a-real-key')).toBe(false);
    await expect(checkedRow.getByRole('alert')).toBeVisible();
    await expect(checkedRow.getByRole('status')).toHaveCount(0);
    const failedSync = page.waitForResponse(response => response.url().endsWith(`/api/admin/ai/providers/${checked.id}/sync`));
    await syncButton.click();
    expect((await failedSync).status()).toBe(503);
    await expect(checkedRow.getByRole('alert')).toBeVisible();
    await expect(checkedRow.getByRole('status')).toHaveCount(0);
    expect(databaseRows('ai_model_profiles', { provider_id: checked.id })).toEqual([]);
    expect(databaseRows('ai_provider_profiles', { id: checked.id })[0].name).toBe('QA unreachable connection');
    await checkedRow.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(editor.getByLabel('Provider API key', { exact: true })).toHaveValue('');
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    await openConnection(page, checked.id);
    await expect(page.locator(`[data-provider-id="${checked.id}"]`).getByText('Connection failed', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('mobile Indonesian dark provider form saves and reopens with a write-only key', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const errors = captureErrors(page);
    await login(page, 'admin', 'id');
    await page.getByRole('button', { name: /Dark mode|Mode gelap/i }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.goto('/admin/ai');
    await page.getByRole('button', { name: 'Tambah penyedia', exact: true }).click();
    const editor = page.locator('[data-provider-editor]');
    await editor.getByLabel('Nama provider', { exact: true }).fill('QA koneksi seluler');
    await editor.getByLabel('Protokol', { exact: true }).selectOption('anthropic');
    await editor.getByLabel('URL dasar HTTPS', { exact: true }).fill('https://mobile-provider.invalid/v1');
    await editor.getByLabel('API key provider', { exact: true }).fill('qa-mobile-not-a-real-key');
    await editor.getByLabel('Versi API Anthropic', { exact: true }).fill('2023-06-01');
    const created = page.waitForResponse(response => response.url().endsWith('/api/admin/ai/providers') && response.request().method() === 'POST');
    await editor.getByRole('button', { name: 'Simpan provider', exact: true }).click();
    expect((await created).status()).toBe(201);
    await expect(editor).toHaveCount(0);
    const provider = databaseRows('ai_provider_profiles', { name: 'QA koneksi seluler' })[0];
    expect({ protocol: provider.protocol, api_version: provider.api_version, is_enabled: provider.is_enabled }).toEqual({ protocol: 'anthropic', api_version: '2023-06-01', is_enabled: 1 });
    await openConnection(page, provider.id, 'id');
    await expect(page.locator('html')).toHaveClass(/dark/);
    const row = page.locator(`[data-provider-id="${provider.id}"]`);
    await row.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect.poll(async () => (await editor.getByLabel('API key provider', { exact: true }).inputValue()) === '').toBe(true);
    await expect(editor.getByRole('button', { name: 'Simpan provider', exact: true })).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    expect(errors).toEqual([]);
});
