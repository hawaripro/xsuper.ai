import { test, expect } from '@playwright/test';
import { api, login, captureErrors } from './helpers.js';

test('model table saves a filtered selection atomically and deletes only explicit IDs', async ({ page }) => {
    const errors = captureErrors(page);
    await login(page);
    const ids = [];
    for (const suffix of ['first', 'second', 'untouched']) {
        const created = await api(page, '/api/admin/ai/models', { method: 'POST', data: {
            model_id: `qa-bulk-table-${suffix}`, display_name: `QA bulk table ${suffix}`, category: 'image',
            provider_slug: 'qa-local',
            token_cost: 15, is_enabled: false,
        } });
        expect(created.status()).toBe(201);
        ids.push((await created.json()).model.id);
    }
    try {
        await page.goto('/en/admin/ai');
        const table = page.locator('section[aria-labelledby="models-title"]');
        await table.getByRole('searchbox', { name: 'Search models', exact: true }).fill('qa-bulk-table');
        await table.getByRole('spinbutton', { name: 'Tokens per result qa-bulk-table-first', exact: true }).fill('21');
        await table.getByRole('spinbutton', { name: 'Tokens per result qa-bulk-table-second', exact: true }).fill('39');
        await table.getByLabel('Select row IDs explicitly', { exact: true }).fill(`${ids[0]}, ${ids[1]}`);
        await table.getByRole('button', { name: 'Use these IDs', exact: true }).click();
        await table.getByRole('searchbox', { name: 'Search models', exact: true }).fill('qa-bulk-table-first');
        await table.getByRole('button', { name: 'Save selection (2)', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toContainText('qa-bulk-table-first');
        await expect(dialog).toContainText('qa-bulk-table-second');
        await expect(dialog).not.toContainText('qa-bulk-table-untouched');
        const saved = page.waitForResponse((response) => response.url().endsWith('/api/admin/ai/models/bulk') && response.request().method() === 'PATCH');
        await dialog.getByRole('button', { name: 'Save 2 rows', exact: true }).click();
        expect((await saved).status()).toBe(200);
        const models = (await (await api(page, '/api/admin/ai/catalog')).json()).models;
        expect(models.find((model) => model.id === ids[0]).token_cost).toBe(21);
        expect(models.find((model) => model.id === ids[1]).token_cost).toBe(39);
        expect(models.find((model) => model.id === ids[2]).token_cost).toBe(15);
        await table.getByRole('button', { name: 'Delete selection (2)', exact: true }).click();
        const deleted = page.waitForResponse((response) => response.url().endsWith('/api/admin/ai/models/bulk') && response.request().method() === 'DELETE');
        await page.getByRole('dialog').getByRole('button', { name: 'Delete 2 rows', exact: true }).click();
        expect((await deleted).status()).toBe(200);
        const remaining = (await (await api(page, '/api/admin/ai/catalog')).json()).models;
        expect(remaining.some((model) => model.id === ids[0] || model.id === ids[1])).toBe(false);
        expect(remaining.some((model) => model.id === ids[2])).toBe(true);
        expect(errors).toEqual([]);
    } finally {
        const existing = (await (await api(page, '/api/admin/ai/catalog')).json()).models.filter((model) => ids.includes(model.id)).map((model) => model.id);
        if (existing.length) await api(page, '/api/admin/ai/models/bulk', { method: 'DELETE', data: { ids: existing, expected_count: existing.length, delete_usage_rates: true } });
    }
});

test('pricing table keeps both row drafts after atomic API pair validation fails', async ({ page }) => {
    await login(page);
    const ids = [];
    for (const meter of ['input_tokens', 'output_tokens']) {
        const created = await api(page, '/api/pricing/rates', { method: 'POST', data: {
            service: 'api', meter, model: 'qa-bulk-price-pair', label: `QA pair ${meter}`, unit: '1M tokens',
            price_idr: null, price_usd: null, is_active: false,
        } });
        expect(created.status()).toBe(201);
        ids.push((await created.json()).usage_rate.id);
    }
    try {
        await page.goto('/en/admin/settings');
        const table = page.locator('section[aria-labelledby="usage-prices-title"]');
        await table.getByRole('searchbox', { name: 'Search rates', exact: true }).fill('qa-bulk-price-pair');
        await table.getByLabel(`IDR #${ids[0]}`, { exact: true }).fill('16000');
        await table.getByLabel(`USD #${ids[0]}`, { exact: true }).fill('1');
        await table.getByRole('checkbox', { name: `Publish rate #${ids[0]}`, exact: true }).check();
        await table.getByLabel(`USD #${ids[1]}`, { exact: true }).fill('3');
        await table.getByRole('button', { name: 'Save rates (2)', exact: true }).click();
        const failed = page.waitForResponse((response) => response.url().endsWith('/api/pricing/rates/bulk') && response.request().method() === 'PATCH');
        await page.getByRole('dialog').getByRole('button', { name: 'Save 2 rows', exact: true }).click();
        expect((await failed).status()).toBe(422);
        await expect(table.getByLabel(`USD #${ids[0]}`, { exact: true })).toHaveValue('1');
        await expect(table.getByLabel(`USD #${ids[1]}`, { exact: true })).toHaveValue('3');
        let rates = (await (await api(page, '/api/pricing/settings')).json()).usage_rates.filter((rate) => ids.includes(rate.id));
        expect(rates.every((rate) => rate.price_usd === null && !rate.is_active)).toBe(true);
        await table.getByLabel(`IDR #${ids[1]}`, { exact: true }).fill('48000');
        await table.getByRole('button', { name: 'Save rates (2)', exact: true }).click();
        const saved = page.waitForResponse((response) => response.url().endsWith('/api/pricing/rates/bulk') && response.request().method() === 'PATCH');
        await page.getByRole('dialog').getByRole('button', { name: 'Save 2 rows', exact: true }).click();
        expect((await saved).status()).toBe(200);
        rates = (await (await api(page, '/api/pricing/settings')).json()).usage_rates.filter((rate) => ids.includes(rate.id));
        expect(rates.every((rate) => rate.is_active)).toBe(true);
        expect(Number(rates.find((rate) => rate.id === ids[0]).price_usd)).toBe(1);
        expect(Number(rates.find((rate) => rate.id === ids[1]).price_usd)).toBe(3);
    } finally {
        await api(page, '/api/pricing/rates/bulk', { method: 'DELETE', data: { ids, expected_count: ids.length } });
    }
});
