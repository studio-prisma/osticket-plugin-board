// Quick view: panel, actions run from the panel, pickers, undo, internal note.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { env, requireSeed, watchErrors, staffLogin, clientLogin, sql, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const t = seed.tickets;
const NOTE_MARK = 'board-note-4c1e';

async function openPanel(page, number) {
  await page.locator(`.spb-card[data-number="${number}"] .spb-subject`).click();
  await expect(page.locator('.spb-drawer')).toBeVisible();
  await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', number);
}

const ACT = '/scp/ajax.php/board/act';
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;

// Runs a quick-view action and waits until the panel shows the ticket again.
async function act(page, button, number) {
  const done = page.waitForResponse((r) => r.url().includes('/board/act'));
  await button.click();
  expect((await done).status()).toBe(200);
  await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', number);
}

// An open card without sub-tickets that has another open card after it.
function closablePair(page) {
  return page.evaluate(() => {
    const cards = [...document.querySelectorAll('.spb-col[data-state="open"] .spb-cards > .spb-card')];
    const i = cards.findIndex((c, k) => !c.querySelector('.spb-children') && cards[k + 1]);
    return i < 0 ? null : { id: cards[i].dataset.ticketId, number: cards[i].dataset.number, next: cards[i + 1].dataset.number };
  });
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

  test('assign from the picker: no core dialog, panel stays on the ticket', async ({ page }) => {
    const errors = watchErrors(page);
    await openPanel(page, n.A1);
    const toggle = page.locator('[data-spb-menu="agents"]');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    const menu = page.locator('.spb-menu[data-menu="agents"]');
    await expect(menu).toBeVisible();
    await act(page, menu.locator('.spb-option', { hasText: 'Board Admin' }), n.A1);
    await expect(page.locator('#popup')).toBeHidden();
    await expect(page.locator('.spb-status')).toContainText('Done.');
    await expect(page.locator('.spb-action', { hasText: 'Release' })).toBeVisible();
    await expect(page.locator(`.spb-card[data-number="${n.A1}"] .spb-meta`)).toContainText('Board Admin');
    expect(errors).toEqual([]);
  });

  test('status in one click, with the note written beforehand, and undo', async ({ page }) => {
    const mark = 'board-act-note-7d2a';
    await openPanel(page, n.X1);
    await page.fill('#spb-note-text', mark);
    await act(page, page.locator('.spb-action-group[data-group="status"] .spb-action', { hasText: 'In Progress' }), n.X1);
    await expect(page.locator('#popup')).toBeHidden();
    await expect(page.locator('.spb-status')).toContainText('Internal note saved with it.');
    await expect(page.locator(`.spb-col[data-status-name="In Progress"] .spb-card[data-number="${n.X1}"]`)).toHaveCount(1);
    await expect(page.locator('#spb-note-text')).toHaveValue('');

    // Undo puts the old status back and doesn't post the note again.
    const undone = page.waitForResponse((r) => r.url().includes('/board/act'));
    await page.locator('.spb-toast-action', { hasText: 'Undo' }).click();
    expect((await undone).status()).toBe(200);
    await expect(page.locator('.spb-status')).toContainText('Change undone.');
    await expect(page.locator(`.spb-col[data-status-name="Open"] .spb-card[data-number="${n.X1}"]`)).toHaveCount(1);

    await page.goto(`/scp/tickets.php?id=${t.X1}`);
    await expect(page.locator('.thread-body', { hasText: mark })).toHaveCount(1);

    // Later specs expect X1 in progress.
    const again = await post(page, '/scp/ajax.php/board/move', `ticket_id=${t.X1}&status_id=${seed.status.in_progress}`);
    expect(again.status).toBe(200);
  });

  test('closing from the quick view moves on to the next open card', async ({ page }) => {
    const pair = await closablePair(page);
    expect(pair).not.toBeNull();

    await openPanel(page, pair.number);
    await act(page, page.locator('.spb-action-group[data-group="status"] .spb-action', { hasText: 'Resolved' }), pair.next);
    await expect(page.locator('#popup')).toBeHidden();
    await expect(page.locator(`.spb-col[data-status-name="Resolved"] .spb-card[data-number="${pair.number}"]`)).toHaveCount(1);

    // Put it back so later specs see the seeded state.
    const back = await post(page, '/scp/ajax.php/board/move', `ticket_id=${pair.id}&status_id=${seed.status.open}`);
    expect(back.status).toBe(200);
  });

  test('with "Next ticket after closing" off, the closed ticket stays open in the panel', async ({ page }) => {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', 'next_after_close', '0', NOW())`);
    try {
      await page.reload();
      await expect(page.locator('.spb-board')).toHaveAttribute('data-next-after-close', '0');
      const pair = await closablePair(page);
      await openPanel(page, pair.number);
      await act(page, page.locator('.spb-action-group[data-group="status"] .spb-action', { hasText: 'Resolved' }), pair.number);
      const back = await post(page, '/scp/ajax.php/board/move', `ticket_id=${pair.id}&status_id=${seed.status.open}`);
      expect(back.status).toBe(200);
    } finally {
      sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='next_after_close'`);
    }
  });

  test('transfer and priority from their pickers; the current value is not offered', async ({ page }) => {
    await openPanel(page, n.A5);
    await page.locator('[data-spb-menu="depts"]').click();
    await act(page, page.locator('.spb-menu[data-menu="depts"] .spb-option', { hasText: 'Sales' }), n.A5);
    await page.locator('[data-spb-menu="depts"]').click();
    await expect(page.locator('.spb-menu[data-menu="depts"] .spb-option', { hasText: 'Sales' })).toHaveCount(0);
    // Back where it was, so the Sales agent's view stays as seeded.
    await act(page, page.locator('.spb-menu[data-menu="depts"] .spb-option', { hasText: 'Support' }), n.A5);

    await page.locator('[data-spb-menu="priority"]').click();
    await act(page, page.locator('.spb-menu[data-menu="priority"] .spb-option', { hasText: 'Low' }), n.A5);
    await page.locator('[data-spb-menu="priority"]').click();
    await expect(page.locator('.spb-menu[data-menu="priority"] .spb-option', { hasText: 'Low' })).toHaveCount(0);
    await expect(page.locator('.spb-menu[data-menu="priority"] .spb-option', { hasText: 'Normal' })).toHaveCount(1);
    await act(page, page.locator('.spb-menu[data-menu="priority"] .spb-option', { hasText: 'Normal' }), n.A5);
  });

  test('refer still opens the core dialog', async ({ page }) => {
    await openPanel(page, n.A5);
    await page.locator('.spb-action', { hasText: 'Refer' }).click();
    await expect(page.locator('#popup')).toBeVisible();
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

  test('act endpoint: CSRF, unknown action or value, unknown ticket', async ({ page }) => {
    expect((await post(page, ACT, `ticket_id=${t.A5}&act=claim`, false)).status).toBe(400);
    expect((await post(page, ACT, `ticket_id=${t.A5}&act=delete`)).status).toBe(422);
    expect((await post(page, ACT, `ticket_id=${t.A5}&act=assign_agent&value=s999999`)).status).toBe(422);
    expect((await post(page, ACT, `ticket_id=${t.A5}&act=assign_agent&value=t1`)).status).toBe(422);
    expect((await post(page, ACT, `ticket_id=${t.A5}&act=status&value=1%20OR%201`)).status).toBe(422);
    expect((await post(page, ACT, `ticket_id=${t.A5}&act=status&value=${seed.status.open}&note=${'x'.repeat(10001)}`)).status).toBe(422);
    expect((await post(page, ACT, 'ticket_id=999999&act=claim')).status).toBe(404);
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

  test('actions the role does not allow are refused on the endpoint too', async ({ page }) => {
    const r = await post(page, ACT, `ticket_id=${t.S1}&act=status&value=${seed.status.closed}`);
    expect(r.status).toBe(403);
    const claim = await post(page, ACT, `ticket_id=${t.S1}&act=claim`);
    expect(claim.status).toBe(403);
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

    const hiddenAct = await post(page, ACT, `ticket_id=${t.A1}&act=claim`);
    const missingAct = await post(page, ACT, 'ticket_id=999999&act=claim');
    expect(hiddenAct.status).toBe(404);
    expect(hiddenAct.body).toEqual(missingAct.body);
  });
});

test('a client session gets no panel, note or action', async ({ page }) => {
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
    const act = await fetch('/scp/ajax.php/board/act', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
      body: `ticket_id=${id}&act=claim`,
    });
    return [panel.status, note.status, act.status];
  }, t.A1);
  expect(r).toEqual([403, 403, 403]);
});
