import { test, expect } from '@playwright/test';
import { login, captureErrors, databaseRows } from './helpers.js';

// Legacy studio paths (/video, /audio, /generate-image, /media, /avatar, /3d) redirect into /studio.
const memberRoutes = [
    '/dashboard', '/profile', '/chat', '/studio', '/video', '/audio', '/downloads', '/converter', '/templates', '/library',
    '/generate-image', '/media', '/token-usage', '/deposit', '/paket', '/referral', '/bantuan', '/notifications',
    '/avatar', '/3d', '/remove-background', '/history',
];
const adminRoutes = [
    '/admin/overview', '/admin/users', '/admin/operations', '/admin/token-usage',
    '/admin/ai', '/admin/content', '/admin/system', '/admin/settings',
    '/admin/ai/queue', '/admin/api-keys', '/admin/security',
];

for (const locale of ['id', 'en']) {
    test(`all authorized dashboard routes render without runtime crashes (${locale})`, async ({ page }) => {
        test.setTimeout(150_000);
        const errors = captureErrors(page);
        await login(page, 'admin', locale);
        const prefix = locale === 'en' ? '/en' : '';
        const provider = databaseRows('ai_provider_profiles', { slug: 'qa-local' })[0];
        for (const route of [...memberRoutes, ...adminRoutes, `/admin/ai/${provider.id}`]) {
            const response = await page.goto(`${prefix}${route}`);
            expect(response.status(), route).toBe(200);
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.getByRole('heading', { level: 1 }), route).toBeVisible();
            await expect(page.getByRole('heading', { name: /Page could not be displayed|Halaman tidak dapat ditampilkan/ })).toHaveCount(0);
            expect(errors, route).toEqual([]);
        }
    });
}

test('member navigation enforces admin access while keeping member pages usable', async ({ page }) => {
    test.setTimeout(90_000);
    const errors = captureErrors(page);
    await login(page, 'member');
    for (const route of memberRoutes) {
        await page.goto(`/en${route}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Page could not be displayed' })).toHaveCount(0);
    }
    for (const route of adminRoutes) {
        await page.goto(`/en${route}`);
        await expect(page.getByRole('heading', { name: 'Access Denied' }), route).toBeVisible();
    }
    expect((await page.request.get('/api/admin/audit')).status()).toBe(403);
    expect((await page.request.get('/api/admin/content')).status()).toBe(403);
    expect(errors).toEqual([]);
});

test('in-app language and theme switches persist without a client 404', async ({ page }) => {
    const errors = captureErrors(page);
    await login(page, 'admin', 'id');
    await page.getByRole('link', { name: 'Switch to English' }).click();
    await expect(page).toHaveURL('/en/dashboard');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await page.getByRole('button', { name: 'Dark mode', exact: true }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByRole('link', { name: 'System Activity', exact: true }).click();
    await expect(page).toHaveURL('/en/admin/system');
    await expect(page.getByRole('heading', { name: 'System activity', exact: true })).toBeVisible();
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByRole('button', { name: 'Light mode', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    expect(errors).toEqual([]);
});

test('guest cannot read member or admin data and sign-out revokes the session', async ({ page }) => {
    expect((await page.request.get('/api/admin/content')).status()).toBe(401);
    expect((await page.request.get('/api/dashboard')).status()).toBe(401);
    await login(page, 'member');
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL('/en/login');
    expect((await page.request.get('/api/dashboard')).status()).toBe(401);
    expect(databaseRows('users', { email: 'member@dashboard-e2e.test' })).toHaveLength(1);
});
