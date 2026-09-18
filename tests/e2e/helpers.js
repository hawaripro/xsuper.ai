import { expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { root, testEnvironment } from './environment.js';

export const testPassword = 'E2e-Dashboard-Only!';

export async function login(page, role = 'admin', locale = 'en') {
    const prefix = locale === 'en' ? '/en' : '';
    await page.goto(`${prefix}/login`);
    await page.locator('input[type="email"]').fill(`${role}@dashboard-e2e.test`);
    await page.locator('input[type="password"]').fill(testPassword);
    await page.locator('form button[type="submit"]').click();
    await expect(page).toHaveURL(new RegExp(`${prefix}/dashboard$`));
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

export function captureErrors(page) {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    return errors;
}

export async function api(page, url, options = {}) {
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    return page.request.fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
            ...(options.headers || {}),
        },
    });
}

export function databaseRows(table, filters = {}) {
    const output = execFileSync('php', ['tests/e2e/database.php', table, JSON.stringify(filters)], {
        cwd: root,
        env: { ...process.env, ...testEnvironment },
        encoding: 'utf8',
    });
    return JSON.parse(output);
}

export async function newSession(browser, role, locale = 'en') {
    const context = await browser.newContext({ baseURL: 'http://127.0.0.1:8017', viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    await login(page, role, locale);
    return { context, page };
}
