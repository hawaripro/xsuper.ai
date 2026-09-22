import { test, expect } from '@playwright/test';

const password = 'E2e-Dashboard-Only!';

async function login(page, role = 'admin') {
    await page.goto('/en/login');
    await page.locator('input[type="email"]').fill(`${role}@dashboard-e2e.test`);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/dashboard$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

function capturePageErrors(page) {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    return errors;
}

test('System Activity renders real analytics and audit data without blanking', async ({ page }) => {
    const errors = capturePageErrors(page);
    await login(page);
    await page.goto('/en/admin/system');
    await expect(page.getByRole('heading', { name: 'System activity', exact: true })).toBeVisible();
    await expect(page.getByText('app.opened', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Audit log', exact: true }).click();
    await expect(page.getByText('qa.account.created', { exact: true })).toBeVisible();
    await page.getByLabel('Action contains').fill('qa.account.created');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page.getByText('qa.account.created', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('CMS confirmation opens, cancels, publishes and persists the actual draft', async ({ page, context }) => {
    const errors = capturePageErrors(page);
    await login(page);
    await page.goto('/en/admin/content');
    await page.getByRole('tab', { name: 'CMS', exact: true }).click();
    await expect(page.getByText('system.announcement', { exact: true }).last()).toBeVisible();
    const row = page.getByRole('row').filter({ hasText: 'system.announcement' }).filter({ has: page.getByText('EN', { exact: true }) });
    await row.getByRole('button', { name: 'Publish', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Publish this draft?' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await row.getByRole('button', { name: 'Publish', exact: true }).click();
    const response = page.waitForResponse(response => /\/api\/admin\/content\/\d+\/publish$/.test(response.url()) && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Publish draft', exact: true }).click();
    expect((await response).status()).toBe(200);
    await expect(row.getByRole('button', { name: 'Unpublish', exact: true })).toBeVisible();
    const blocks = await context.request.get('/api/admin/content?key=system.announcement&locale=en');
    expect(blocks.status()).toBe(200);
    const saved = (await blocks.json()).data[0];
    expect(saved.is_published).toBe(true);
    expect(saved.published.message).toBe('QA announcement draft');
    await page.reload();
    await page.getByRole('tab', { name: 'CMS', exact: true }).click();
    await expect(row.getByRole('button', { name: 'Unpublish', exact: true })).toBeVisible();
    await page.goto('/en/pricing');
    await expect(page.locator('.site-announcement').getByText('QA announcement draft', { exact: true }).first()).toBeVisible();
    await page.goto('/en/models');
    await expect(page.locator('.site-announcement')).toHaveCount(0);
    expect(errors).toEqual([]);
});
