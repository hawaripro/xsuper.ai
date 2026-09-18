import { test, expect } from '@playwright/test';
import { login, newSession, databaseRows, captureErrors } from './helpers.js';

test('admin blocks a real member device, revokes its session and restores access', async ({ page, browser }) => {
    const errors = captureErrors(page);
    await login(page);
    const member = await newSession(browser, 'other');
    const user = databaseRows('users', { email: 'other@dashboard-e2e.test' })[0];
    const devices = databaseRows('user_devices', { user_id: user.id });
    const device = devices.find(item => item.device_type === 'browser' && item.status === 'active');
    expect(device).toBeDefined();
    await page.goto('/en/admin/users');
    const userRow = page.getByRole('row').filter({ hasText: user.email });
    await userRow.getByRole('button', { name: 'Devices', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Block', exact: true }).first().click();
    const blocked = page.waitForResponse(response => response.url().endsWith(`/api/d/${device.id}`) && response.request().method() === 'PUT');
    await page.getByRole('button', { name: 'Block', exact: true }).last().click();
    expect((await blocked).status()).toBe(200);
    expect(databaseRows('user_devices', { id: device.id })[0].status).toBe('blocked');
    expect((await member.page.request.get('/api/dashboard')).status()).toBe(401);
    const restored = page.waitForResponse(response => response.url().endsWith(`/api/d/${device.id}`) && response.request().method() === 'PUT');
    await page.getByRole('dialog').getByRole('button', { name: 'Enable', exact: true }).click();
    expect((await restored).status()).toBe(200);
    await login(member.page, 'other');
    expect((await member.page.request.get('/api/dashboard')).status()).toBe(200);
    expect(errors).toEqual([]);
    await member.context.close();
});
