import { test, expect } from '@playwright/test';
import { login, databaseRows, captureErrors } from './helpers.js';

test('template opens Chat, attachments reach the real request, unavailable provider shows an error without fake answers', async ({ page }) => {
    const errors = captureErrors(page);
    await login(page, 'member');
    await page.goto('/en/templates');
    const card = page.locator('div').filter({ has: page.getByRole('heading', { name: 'QA persisted coding template', exact: true }) }).filter({ has: page.getByRole('button', { name: /Use Template/ }) }).last();
    await card.getByRole('button', { name: /Use Template/ }).click();
    await expect(page).toHaveURL('/en/chat');
    await expect(page.locator('textarea')).toHaveValue('Review this code and explain the risks.');
    await expect(page.locator('.cw-model-trigger')).toContainText('QA chat model');
    const uploaded = page.waitForResponse(response => /\/api\/c\/h\/[^/]+\/attachments$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST');
    await page.locator('input[type="file"]').setInputFiles({ name: 'qa-context.txt', mimeType: 'text/plain', buffer: Buffer.from('Isolated attachment context.') });
    expect((await uploaded).status()).toBe(201);
    await expect(page.getByRole('button', { name: 'Remove attachment: qa-context.txt', exact: true })).toBeVisible();
    const sent = page.waitForRequest(request => request.url().endsWith('/api/c/s') && request.method() === 'POST');
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    const payload = (await sent).postDataJSON();
    expect(payload.model).toBe('qa-chat');
    expect(payload.stream_protocol).toBe('workspace_v2');
    expect(payload.messages).toEqual([{ role: 'user', content: 'Review this code and explain the risks.' }]);
    expect(payload.attachment_ids).toHaveLength(1);
    await expect(page.locator('.cw-msg-assistant[data-status="failed"]')).toBeVisible();
    const messages = databaseRows('chat_history', { conversation_id: payload.conversation_id });
    expect(messages.some(message => message.role === 'user' && message.content === 'Review this code and explain the risks.' && message.attachment_ids.includes(payload.attachment_ids[0]))).toBe(true);
    expect(messages.filter(message => message.role === 'assistant').map(message => message.status)).toEqual(['failed']);
    await expect(page.getByRole('button', { name: 'Send', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('image studio submits once through the full-page studio and shows the real queued job', async ({ page }) => {
    const errors = captureErrors(page);
    await login(page, 'other');
    const user = databaseRows('users', { email: 'other@dashboard-e2e.test' })[0];
    const balance = databaseRows('user_tokens', { user_id: user.id })[0].balance;
    const lastTransactionId = Math.max(0, ...databaseRows('token_transactions', { user_id: user.id }).map(entry => Number(entry.id)));
    // The former image studio URL redirects into /studio with its kind filter.
    await page.goto('/en/generate-image');
    await expect(page).toHaveURL(/\/en\/studio\?kind=image/);
    const form = page.locator('form.sw-request');
    await expect(form.locator('.sw-model-meta code')).toHaveText('qa-image');
    await form.locator('#cap-prompt').fill('QA unified image request must reserve once.');
    await expect(form.locator('.sw-estimate-total strong')).toContainText('15');
    const submitted = page.waitForResponse(response => response.url().endsWith('/api/media/workspace/jobs') && response.request().method() === 'POST');
    // The image studio confirms model, quantity and total before reserving tokens; going back sends nothing.
    await form.locator('button[type="submit"]').click();
    await page.getByRole('dialog').getByRole('button', { name: 'Back', exact: true }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await form.locator('button[type="submit"]').click();
    await expect(page.getByRole('dialog')).toContainText('15 tokens');
    await page.getByRole('dialog').getByRole('button', { name: 'Yes, generate images', exact: true }).click();
    const response = await submitted;
    expect(response.status()).toBe(202);
    const { job } = await response.json();
    expect(job).toMatchObject({ model: 'qa-image', operation: 'text_to_image', status: 'pending', outputs: [] });
    expect(job.id).toMatch(/^image:/);
    // The media queue has no worker here: the job stays queued with one reservation and no fabricated output.
    const rows = databaseRows('image_jobs', { user_id: user.id, prompt: 'QA unified image request must reserve once.' });
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ status: 'pending', stage: 'queued', billing_status: 'reserved', result_urls: null });
    const ledger = databaseRows('token_transactions', { user_id: user.id }).filter(entry => Number(entry.id) > lastTransactionId);
    expect(ledger.map(entry => entry.type)).toEqual(['deduct']);
    expect(ledger[0].amount).toBe(15);
    expect(databaseRows('user_tokens', { user_id: user.id })[0].balance).toBe(balance - 15);
    await expect(page).toHaveURL(new RegExp(`job=${encodeURIComponent(job.id)}`));
    const card = page.locator('.sw-results .sw-card[aria-current="true"]');
    await expect(card).toContainText('QA unified image request must reserve once.');
    await expect(card.locator('.sw-card-running')).toBeVisible();
    await expect(card.locator('.sw-card-media img')).toHaveCount(0);
    // A reload keeps the ?job= link: the result opens in the detail dialog.
    await page.reload();
    const detail = page.locator('dialog.sw-detail');
    await expect(detail).toBeVisible();
    await expect(detail).toContainText('QA unified image request must reserve once.');
    await expect(detail.locator('code', { hasText: job.id })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(detail).toHaveCount(0);
    await expect(page.locator('.sw-results .sw-card[aria-current="true"]')).toContainText('QA unified image request must reserve once.');
    expect(errors).toEqual([]);
});
