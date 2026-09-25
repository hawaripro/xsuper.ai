import { test, expect } from '@playwright/test';
import { api, captureErrors, databaseRows, login, newSession } from './helpers.js';

async function catalog(page) {
    const response = await page.request.get('/api/deposits/catalog');
    expect(response.status()).toBe(200);
    return response.json();
}

function memberBalances(userId) {
    return {
        tokens: Number(databaseRows('user_tokens', { user_id: userId })[0]?.balance || 0),
        wallet: Number(databaseRows('wallets', { user_id: userId })[0]?.balance_microusd || 0),
    };
}

test('QRIS token deposit survives reload, waits for real admin approval, and credits tokens only once', async ({ page, browser }) => {
    test.setTimeout(90_000);
    const errors = captureErrors(page);
    await login(page, 'member');
    const member = databaseRows('users', { email: 'member@dashboard-e2e.test' })[0];
    const before = memberBalances(member.id);
    const packages = (await catalog(page)).token_packages;
    const selected = packages.find(item => item.code === 'tokens_320');
    expect(selected).toMatchObject({ base_tokens: 300, bonus_tokens: 20, total_tokens: 320, price_idr: 29000 });
    await page.goto('/en/deposit');
    const created = page.waitForResponse(response => response.url().endsWith('/api/deposits/checkout') && response.request().method() === 'POST');
    const bundle = page.getByRole('button', { name: 'Buy 320 tokens', exact: true });
    await expect(bundle).toContainText('300');
    await expect(bundle).toContainText('20');
    await expect(bundle).toContainText(/IDR\s*90[.,]63/);
    await bundle.click();
    const checkoutResponse = await created;
    expect(checkoutResponse.status()).toBe(201);
    const order = (await checkoutResponse.json()).checkout;
    expect(databaseRows('deposit_orders', { id: order.id })[0]).toMatchObject({ status: 'checkout', confirmed_at: null });
    expect(memberBalances(member.id)).toEqual(before);
    await expect(page.getByRole('img', { name: 'QRIS payment code' })).toBeVisible();
    await page.reload();
    await expect(page.locator('.deposit-payment').getByText(order.payment_reference, { exact: true })).toBeVisible();
    expect(databaseRows('deposit_orders', { id: order.id })[0].status).toBe('checkout');
    const confirmation = page.waitForResponse(response => response.url().endsWith('/api/deposits') && response.request().method() === 'POST');
    const paid = page.getByRole('button', { name: 'I have paid', exact: true });
    await expect(paid).toBeEnabled();
    await paid.focus();
    await page.keyboard.press('Enter');
    expect((await confirmation).status()).toBe(201);
    await expect(page.getByRole('heading', { name: 'Waiting for admin approval', exact: true })).toBeVisible();
    expect(databaseRows('deposit_orders', { id: order.id })[0].status).toBe('pending');
    expect(memberBalances(member.id)).toEqual(before);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Waiting for admin approval', exact: true })).toBeVisible();

    const adminSession = await newSession(browser, 'admin');
    try {
        const approved = await api(adminSession.page, `/api/admin/deposits/${order.id}/approve`, { method: 'POST', data: { note: 'E2E verified token deposit' } });
        expect(approved.status()).toBe(200);
        await expect(page.locator('.deposit-payment').getByText('Deposit approved', { exact: true })).toBeVisible();
        expect(memberBalances(member.id)).toEqual({ tokens: before.tokens + 320, wallet: before.wallet });
        await expect(page.locator('.deposit-balances > div').first()).toContainText(new Intl.NumberFormat('en-US').format(before.tokens + 320));
        expect((await api(adminSession.page, `/api/admin/deposits/${order.id}/approve`, { method: 'POST' })).status()).toBe(200);
        expect(memberBalances(member.id)).toEqual({ tokens: before.tokens + 320, wallet: before.wallet });
        expect(databaseRows('token_transactions', { user_id: member.id, type: 'topup', reference_id: `deposit-order:${order.id}` })).toHaveLength(1);
        const memberStatus = page.locator('.deposit-history-filters select').nth(1);
        await memberStatus.selectOption('approved');
        await page.reload();
        await expect(memberStatus).toHaveValue('approved');
        await expect(page.getByRole('row').filter({ hasText: order.payment_reference })).toContainText('Approved');
    } finally {
        await adminSession.context.close();
    }
    expect(errors).toEqual([]);
});

test('custom IDR deposit on mobile previews USD, prevents duplicate checkout, and credits only the API wallet', async ({ page, browser }) => {
    test.setTimeout(90_000);
    const errors = captureErrors(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'member', 'id');
    const member = databaseRows('users', { email: 'member@dashboard-e2e.test' })[0];
    const before = memberBalances(member.id);
    const settings = await catalog(page);
    await page.goto('/deposit?tab=wallet');
    const amount = page.getByLabel('Jumlah deposit (IDR)', { exact: true });
    await amount.fill(String(settings.limits.min_idr - 1));
    await expect(page.getByRole('button', { name: 'Lanjut ke QRIS', exact: true })).toBeDisabled();
    await amount.fill('16000');
    await expect(page.getByRole('status').filter({ hasText: /USD\s*1,00/ })).toBeVisible();
    let checkoutRequests = 0;
    const recordRequest = request => {
        if (request.url().endsWith('/api/deposits/checkout') && request.method() === 'POST') checkoutRequests += 1;
    };
    page.on('request', recordRequest);
    const created = page.waitForResponse(response => response.url().endsWith('/api/deposits/checkout') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Lanjut ke QRIS', exact: true }).dblclick();
    const response = await created;
    expect(response.status()).toBe(201);
    const order = (await response.json()).checkout;
    expect(order).toMatchObject({ kind: 'wallet', amount_idr: 16000, credit_microusd: 1000000 });
    await expect(page.getByRole('img', { name: 'Kode QRIS pembayaran' })).toBeVisible();
    expect(checkoutRequests).toBe(1);
    page.off('request', recordRequest);
    const confirmation = page.waitForResponse(result => result.url().endsWith('/api/deposits') && result.request().method() === 'POST');
    await page.getByRole('button', { name: 'Saya sudah membayar', exact: true }).click();
    expect((await confirmation).status()).toBe(201);
    expect(memberBalances(member.id)).toEqual(before);

    const adminSession = await newSession(browser, 'admin');
    try {
        const approved = await api(adminSession.page, `/api/admin/deposits/${order.id}/approve`, { method: 'POST', data: { note: 'E2E verified wallet deposit' } });
        expect(approved.status()).toBe(200);
        await expect(page.locator('.deposit-payment').getByText('Deposit disetujui', { exact: true })).toBeVisible();
        expect(memberBalances(member.id)).toEqual({ tokens: before.tokens, wallet: before.wallet + 1000000 });
        expect(databaseRows('wallet_transactions', { user_id: member.id, type: 'deposit', reference_id: `deposit-order:${order.id}` })).toHaveLength(1);
        await page.reload();
        await expect(page.locator('.deposit-payment').getByText('Deposit disetujui', { exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.setViewportSize({ width: 320, height: 844 });
        await page.locator('#deposit-tab-wallet').focus();
        await page.keyboard.press('End');
        const storageTab = page.getByRole('tab', { name: 'Penyimpanan', exact: true });
        await expect(storageTab).toBeFocused();
        await expect(storageTab).toBeInViewport({ ratio: 1 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally {
        await adminSession.context.close();
    }
    expect(errors).toEqual([]);
});

test('admin deposit rejection requires deliberate confirmation and preserves the member balances and history', async ({ page, browser }) => {
    test.setTimeout(90_000);
    const errors = captureErrors(page);
    await login(page, 'member');
    const member = databaseRows('users', { email: 'member@dashboard-e2e.test' })[0];
    const before = memberBalances(member.id);
    const checkout = await api(page, '/api/deposits/checkout', { method: 'POST', data: { kind: 'tokens', package_code: 'tokens_100' } });
    expect(checkout.status()).toBe(201);
    const order = (await checkout.json()).checkout;
    expect((await api(page, '/api/deposits', { method: 'POST', data: { payment_reference: order.payment_reference } })).status()).toBe(201);
    await page.goto(`/en/deposit?order=${order.id}`);
    await expect(page.getByRole('heading', { name: 'Waiting for admin approval', exact: true })).toBeVisible();
    const adminSession = await newSession(browser, 'admin');
    try {
        await adminSession.page.goto('/en/admin/operations');
        await adminSession.page.getByRole('button', { name: 'Deposit', exact: true }).click();
        const row = adminSession.page.getByRole('row').filter({ hasText: order.payment_reference });
        await row.getByRole('button', { name: 'Reject', exact: true }).click();
        const dialog = adminSession.page.getByRole('dialog', { name: 'Reject deposit?', exact: true });
        const reject = dialog.getByRole('button', { name: 'Reject deposit', exact: true });
        await expect(reject).toBeDisabled();
        await dialog.getByLabel('Note to member (optional)', { exact: true }).fill('Payment not received for this reference.');
        await dialog.getByRole('checkbox').check();
        const rejected = adminSession.page.waitForResponse(result => result.url().endsWith(`/api/admin/deposits/${order.id}/reject`) && result.request().method() === 'POST');
        await reject.click();
        expect((await rejected).status()).toBe(200);
        await expect(dialog).not.toBeVisible();
        await expect(page.locator('.deposit-payment').getByText('Deposit rejected', { exact: true })).toBeVisible();
        await expect(page.locator('.deposit-payment')).toContainText('Payment not received for this reference.');
        expect(memberBalances(member.id)).toEqual(before);
        expect(databaseRows('deposit_orders', { id: order.id })[0]).toMatchObject({ status: 'rejected', note: 'Payment not received for this reference.' });
        await adminSession.page.locator('.deposit-admin .deposit-history-filters select').first().selectOption('rejected');
        await expect(row).toContainText('Rejected');
        await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
    } finally {
        await adminSession.context.close();
    }
    expect(errors).toEqual([]);
});
