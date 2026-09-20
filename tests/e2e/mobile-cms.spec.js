import { test, expect } from '@playwright/test';
import { login, databaseRows, captureErrors } from './helpers.js';

test('mobile CMS announcement editor scrolls to a reachable save action', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const errors = captureErrors(page);
    await login(page);
    const before = databaseRows('content_blocks', { key: 'system.announcement', locale: 'en' })[0];
    await page.goto('/en/admin/content');
    await page.getByRole('tab', { name: 'CMS', exact: true }).click();
    const row = page.getByRole('row').filter({ hasText: 'system.announcement' }).filter({ has: page.getByText('EN', { exact: true }) });
    await row.getByRole('button', { name: 'Edit draft', exact: true }).click();
    const editor = page.locator('form').filter({ has: page.getByRole('button', { name: 'Save draft', exact: true }) });
    await editor.getByRole('textbox', { name: 'Message' }).fill('Mobile draft persists without publication.');
    const saved = page.waitForResponse(response => /\/api\/admin\/content\/\d+$/.test(response.url()) && response.request().method() === 'PUT');
    await editor.getByRole('button', { name: 'Save draft', exact: true }).click();
    expect((await saved).status()).toBe(200);
    const block = databaseRows('content_blocks', { key: 'system.announcement', locale: 'en' })[0];
    expect(JSON.parse(block.draft).message).toBe('Mobile draft persists without publication.');
    expect(block.published).toBe(before.published);
    expect(block.is_published).toBe(before.is_published);
    expect(errors).toEqual([]);
});
