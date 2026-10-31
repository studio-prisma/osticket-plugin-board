// Quick view: panel, core dialogs from the panel, internal note.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { env, requireSeed, watchErrors, staffLogin, clientLogin, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const t = seed.tickets;
const NOTE_MARK = 'board-note-4c1e';

async function openPanel(page, number) {
  await page.locator(`.spb-card[data-number="${number}"] .spb-subject`).click();
  await expect(page.locator('.spb-drawer')).toBeVisible();
  await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', number);
}

function post(page, url, body, withToken = true) {
  return page.evaluate(async ({ url, body, withToken }) => {
    const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (withToken) headers['X-CSRFToken'] = document.querySelector('meta[name="csrf_token"]').content;
    const res = await fetch(url, { method: 'POST', headers, body });
    let json = null;
    try { json = await res.json(); } catch (e) { json = null; }
    return { status: res.status, body: json };
  }, { url, body, withToken });
}

test.describe('admin', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await page.goto('/scp/ajax.php/board?queue=all');
  });

  test('a card opens the quick view with actions and the core preview; Escape closes it', async ({ page }) => {
    const errors = watchErrors(page);
    await openPanel(page, n.X1);
    const actions = await page.$$eval('.spb-action', (els) => els.map((e) => e.textContent.trim()));
    for (const a of ['Assign to agent', 'Assign to team', 'Transfer to department', 'Refer', 'Add task']) {
      expect(actions).toContain(a);
    }
    // Core preview is there. The panel head reads the subject through
    // Ticket::getSubject() (form answers, sanitised by osTicket on create),
    // the card reads ticket__cdata (raw markup from the seed); neither may
    // turn into an element.
    await expect(page.locator('.spb-preview')).toContainText('Ticket State');
    await expect(page.locator('.spb-panel-head h2')).toContainText('markup in subject');
    await expect(page.locator('.spb-drawer img[src="x"], .spb-board img[src="x"]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__spbXss)).toBeUndefined();
    expect(page.url()).toContain(`#spb-ticket=${t.X1}`);

    await page.screenshot({ path: path.join(LOCAL, 'screens', 'quickview.png'), fullPage: false });
    await page.keyboard.press('Escape');
    await expect(page.locator('.spb-drawer')).toBeHidden();
    expect(page.url()).not.toContain('#spb-ticket');
    expect(errors).toEqual([]);
  });

  test('assign through the core dialog, board reloads with the panel open', async ({ page }) => {
    await openPanel(page, n.A1);
    await page.locator('.spb-action', { hasText: 'Assign to agent' }).click();
    const dialog = page.locator('#popup');
    await expect(dialog).toBeVisible();
    await dialog.locator('select').first().selectOption({ label: 'Board Admin' });
    await Promise.all([
      page.waitForEvent('load'),
      dialog.locator('button[type="submit"], input[type="submit"]').last().click(),
    ]);
    await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', n.A1);
    await expect(page.locator('.spb-action', { hasText: 'Release' })).toBeVisible();
    await expect(page.locator(`.spb-card[data-number="${n.A1}"] .spb-meta`)).toContainText('Board Admin');
  });

  test('status through the core dialog with a comment', async ({ page }) => {
    await openPanel(page, n.X1);
    await page.locator('.spb-action', { hasText: 'In Progress' }).click();
    const dialog = page.locator('#popup');
    await expect(dialog).toBeVisible();
    // The comment field is osTicket's rich-text editor; the textarea under
    // it is hidden.
    const editor = dialog.locator('[contenteditable="true"]').first();
    await editor.click();
    await page.keyboard.type('Moved from the quick view.');
    await Promise.all([
      page.waitForEvent('load'),
      dialog.locator('button[type="submit"], input[type="submit"]').last().click(),
    ]);
    await expect(page.locator(`.spb-col[data-status-name="In Progress"] .spb-card[data-number="${n.X1}"]`)).toHaveCount(1);
  });

  test('internal note: stored as text, visible to agents, never to the customer', async ({ page, browser }) => {
    await openPanel(page, n.A1);
    const text = `<b>${NOTE_MARK}</b> & "quoted"`;
    await page.fill('#spb-note-text', text);
    await page.click('form.spb-note button[type="submit"]');
    await expect(page.locator('.spb-status')).toHaveText('Internal note posted.');
    await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', n.A1);

    // Agent ticket view: the note is there, as text, not as markup.
    await page.goto(`/scp/tickets.php?id=${t.A1}`);
    await expect(page.locator('.thread-body', { hasText: NOTE_MARK }).first()).toContainText(`<b>${NOTE_MARK}</b>`);
    await expect(page.locator(`b:text("${NOTE_MARK}")`)).toHaveCount(0);

    // Customer portal: nothing of it.
    const ctx = await browser.newContext();
    const client = await ctx.newPage();
    await clientLogin(client, 'alice@acme.example');
    await client.goto(`/tickets.php?id=${t.A1}`);
    expect(await client.content()).not.toContain(NOTE_MARK);
    await client.goto('/ajax.php/board');
    expect(await client.content()).not.toContain(NOTE_MARK);
    await ctx.close();
  });

  test('note endpoint: CSRF, empty text, unknown ticket', async ({ page }) => {
    const url = '/scp/ajax.php/board/note';
    expect((await post(page, url, `ticket_id=${t.A1}&note=x`, false)).status).toBe(400);
    expect((await post(page, url, `ticket_id=${t.A1}&note=%20%20`)).status).toBe(422);
    const missing = await post(page, url, 'ticket_id=999999&note=x');
    expect(missing.status).toBe(404);
  });
});

test.describe('view-only agent in Sales', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_AGENT_USER, env.SPB_AGENT_PASS);
    await page.goto('/scp/ajax.php/board?queue=all');
  });

  test('quick view without action buttons the role does not allow', async ({ page }) => {
    await openPanel(page, n.S1);
    const actions = await page.$$eval('.spb-action', (els) => els.map((e) => e.textContent.trim()));
    for (const a of ['Assign to agent', 'Claim', 'Transfer to department', 'Closed', 'In Progress']) {
      expect(actions).not.toContain(a);
    }
  });

  test('panel and note of an invisible ticket answer like a missing one', async ({ page }) => {
    const statuses = await page.evaluate(async (ids) => {
      const out = [];
      for (const id of ids) out.push((await fetch(`/scp/ajax.php/board/panel/${id}`)).status);
      return out;
    }, [t.A1, 999999]);
    expect(statuses).toEqual([404, 404]);

    const hidden = await post(page, '/scp/ajax.php/board/note', `ticket_id=${t.A1}&note=x`);
    const missing = await post(page, '/scp/ajax.php/board/note', 'ticket_id=999999&note=x');
    expect(hidden.status).toBe(404);
    expect(hidden.body).toEqual(missing.body);
  });
});

test('a client session gets neither panel nor note', async ({ page }) => {
  await clientLogin(page, 'alice@acme.example');
  await page.goto('/ajax.php/board');
  const r = await page.evaluate(async (id) => {
    const token = document.querySelector('meta[name="csrf_token"]').content;
    const panel = await fetch(`/scp/ajax.php/board/panel/${id}`);
    const note = await fetch('/scp/ajax.php/board/note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
      body: `ticket_id=${id}&note=x`,
    });
    return [panel.status, note.status];
  }, t.A1);
  expect(r).toEqual([403, 403]);
});
