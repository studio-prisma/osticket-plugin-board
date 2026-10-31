// Agent panel: board content, status changes, permission and CSRF checks.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { env, requireSeed, watchErrors, staffLogin, boardNumbers, columnOf, sql, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const MAILPIT = `http://127.0.0.1:${env.SPB_MAIL_PORT || '8026'}/api/v1/messages`;

async function mailTo(request, address) {
  const res = await request.get(MAILPIT);
  const data = await res.json();
  return (data.messages || []).filter((m) => (m.To || []).some((t) => t.Address === address)).length;
}

/** POST to the move endpoint from inside the page, with or without the token. */
function move(page, ticketId, statusId, withToken = true) {
  return page.evaluate(async ({ ticketId, statusId, withToken }) => {
    const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (withToken) headers['X-CSRFToken'] = document.querySelector('meta[name="csrf_token"]').content;
    const res = await fetch('/scp/ajax.php/board/move', {
      method: 'POST', headers, body: `ticket_id=${ticketId}&status_id=${statusId}`,
    });
    let body = null;
    try { body = await res.json(); } catch (e) { body = null; }
    return { status: res.status, body };
  }, { ticketId, statusId, withToken });
}

test.describe('admin', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
  });

  test('board is listed under Applications and shows every ticket', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/scp/index.php');
    await expect(page.locator('a[href$="/scp/ajax.php/board"]').first()).toBeAttached();

    await page.goto('/scp/ajax.php/board');
    const numbers = await boardNumbers(page);
    expect(numbers).toEqual(Object.values(n).sort());

    const parent = page.locator(`.spb-card[data-number="${n.A1}"]`);
    await expect(parent.locator('.spb-children summary')).toHaveText('4 sub-tickets');
    await expect(parent.locator('.spb-meta')).toContainText('Alice Acme');
    // Top level: A1 (carrying A2, A3, A4, G2), A5, X1, G1, S1.
    await expect(page.locator('.spb-card[draggable="true"]')).toHaveCount(5);
    expect(await page.content()).not.toContain('INTERNAL-NOTE-MARKER-7f3a');
    expect(await page.evaluate(() => window.__spbXss)).toBeUndefined();

    await page.screenshot({ path: path.join(LOCAL, 'screens', 'staff-admin.png'), fullPage: true });
    expect(errors).toEqual([]);
  });

  test('drag and drop changes the status and survives a reload', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/scp/ajax.php/board');
    expect(await columnOf(page, n.A5)).toBe('Open');

    const target = page.locator('.spb-col[data-status-name="In Progress"]');
    await page.dragAndDrop(`.spb-card[data-number="${n.A5}"]`, '.spb-col[data-status-name="In Progress"] .spb-cards');
    await expect(target.locator(`.spb-card[data-number="${n.A5}"]`)).toHaveCount(1);
    await expect(page.locator('.spb-status')).toContainText(`#${n.A5}`);

    await page.reload();
    expect(await columnOf(page, n.A5)).toBe('In Progress');
    expect(errors).toEqual([]);
  });

  test('keyboard move: Alt+ArrowLeft goes one column back', async ({ page }) => {
    await page.goto('/scp/ajax.php/board');
    const card = page.locator(`.spb-card[data-number="${n.A5}"]`);
    await card.focus();
    await page.keyboard.press('Alt+ArrowLeft');
    await expect(page.locator(`.spb-col[data-status-name="Open"] .spb-card[data-number="${n.A5}"]`)).toHaveCount(1);
    await page.reload();
    expect(await columnOf(page, n.A5)).toBe('Open');
  });

  test('closing asks first; cancel keeps the ticket open', async ({ page }) => {
    await page.goto('/scp/ajax.php/board');
    page.once('dialog', (d) => d.dismiss());
    await page.dragAndDrop(`.spb-card[data-number="${n.A5}"]`, '.spb-col[data-status-name="Closed"] .spb-cards');
    await page.waitForTimeout(300);
    await page.reload();
    expect(await columnOf(page, n.A5)).toBe('Open');
  });

  test('closing after confirmation; customer mail is recorded', async ({ page, request }) => {
    const before = await mailTo(request, 'bob@acme.example');
    await page.goto('/scp/ajax.php/board');
    let message = '';
    page.once('dialog', (d) => { message = d.message(); d.accept(); });
    await page.dragAndDrop(`.spb-card[data-number="${n.A5}"]`, '.spb-col[data-status-name="Closed"] .spb-cards');
    await expect(page.locator(`.spb-col[data-status-name="Closed"] .spb-card[data-number="${n.A5}"]`)).toHaveCount(1);
    expect(message).toContain(`#${n.A5}`);

    await page.reload();
    expect(await columnOf(page, n.A5)).toBe('Closed');

    await page.waitForTimeout(1500);
    const after = await mailTo(request, 'bob@acme.example');
    fs.writeFileSync(path.join(LOCAL, 'mail-check.json'),
      JSON.stringify({ ticket: n.A5, mails_to_requester_before: before, after }, null, 2));
  });

  test('a POST without CSRF token is rejected and changes nothing', async ({ page }) => {
    await page.goto('/scp/ajax.php/board');
    const r = await move(page, seed.tickets.A3, seed.status.in_progress, false);
    expect(r.status).toBe(400);
    await page.reload();
    expect(await page.locator(`.spb-child:has(a:text("#${n.A3}")) .spb-badge`).textContent()).toBe('Open');
  });

  test('archived/deleted states and unknown statuses are refused', async ({ page }) => {
    await page.goto('/scp/ajax.php/board');
    expect((await move(page, seed.tickets.A3, 4)).status).toBe(422); // Archived
    expect((await move(page, seed.tickets.A3, 5)).status).toBe(422); // Deleted
    expect((await move(page, seed.tickets.A3, 999999)).status).toBe(422);
    expect((await move(page, 999999, seed.status.open)).status).toBe(404);
  });
});

test.describe('view-only agent in Sales', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_AGENT_USER, env.SPB_AGENT_PASS);
  });

  test('sees only Sales tickets', async ({ page }) => {
    await page.goto('/scp/ajax.php/board');
    expect(await boardNumbers(page)).toEqual([n.S1]);
    expect(await page.content()).not.toContain('Website relaunch');
  });

  test('cannot change a visible ticket, gets the same 404 for an invisible one', async ({ page }) => {
    await page.goto('/scp/ajax.php/board');

    const denied = await move(page, seed.tickets.S1, seed.status.in_progress);
    expect(denied.status).toBe(403);

    const hidden = await move(page, seed.tickets.A1, seed.status.closed);
    const missing = await move(page, 999999, seed.status.closed);
    expect(hidden.status).toBe(404);
    expect(hidden.body).toEqual(missing.body);

    // The UI reports the refusal and leaves the card where it was.
    await page.dragAndDrop(`.spb-card[data-number="${n.S1}"]`, '.spb-col[data-status-name="In Progress"] .spb-cards');
    await expect(page.locator('.spb-status.spb-error')).toBeVisible();
    await page.reload();
    expect(await columnOf(page, n.S1)).toBe('Open');
  });
});

test.describe('agent with a pending password change', () => {
  // scp/ajax.php is exempt from the forced password change in staff.inc.php;
  // the board must not inherit that exemption.
  test.afterEach(() => {
    sql(`UPDATE ost_staff SET change_passwd=0 WHERE username='${env.SPB_AGENT_USER}'`);
  });

  test('gets neither the board nor the move endpoint', async ({ page }) => {
    await staffLogin(page, env.SPB_AGENT_USER, env.SPB_AGENT_PASS);
    await page.goto('/scp/ajax.php/board');
    await expect(page.locator('.spb-board')).toHaveCount(1);

    sql(`UPDATE ost_staff SET change_passwd=1 WHERE username='${env.SPB_AGENT_USER}'`);

    await page.goto('/scp/ajax.php/board');
    await expect(page).not.toHaveURL(/ajax\.php\/board/);
    await expect(page.locator('.spb-board')).toHaveCount(0);

    // The CSRF token from any agent page still works; the gate must hold anyway.
    const r = await page.evaluate(async ({ id, status }) => {
      const token = document.querySelector('meta[name="csrf_token"]').content;
      const res = await fetch('/scp/ajax.php/board/move', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
        body: `ticket_id=${id}&status_id=${status}`,
      });
      return { status: res.status, body: await res.json() };
    }, { id: seed.tickets.S1, status: seed.status.in_progress });
    expect(r.status).toBe(403);
    expect(r.body.error).toBe('account_action_required');
  });
});

test.describe('board as start page', () => {
  const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;

  async function login(page, user, pass) {
    await page.fill('input[name="userid"]', user);
    await page.fill('input[name="passwd"]', pass);
    await page.click('#login button[type="submit"]');
  }

  test('login lands on the board once; the ticket list stays reachable', async ({ page }) => {
    await page.goto('/scp/login.php');
    await login(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await page.waitForURL(/\/scp\/ajax\.php\/board/);
    await expect(page.locator('.spb-board')).toHaveCount(1);

    await page.goto('/scp/index.php');
    await expect(page).toHaveURL(/\/scp\/index\.php$/);
    await expect(page.locator('.spb-board')).toHaveCount(0);
  });

  test('a login from a ticket link opens that ticket', async ({ page }) => {
    await page.goto(`/scp/tickets.php?id=${seed.tickets.A5}`);
    await page.waitForURL(/login\.php/);
    await login(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await page.waitForURL(new RegExp(`tickets\\.php\\?id=${seed.tickets.A5}`));
    await expect(page.locator('.spb-board')).toHaveCount(0);
  });

  test('opening the panel root after the session ran out still lands on the board', async ({ page }) => {
    await page.goto('/scp/');
    await page.waitForURL(/login\.php/);
    await login(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await page.waitForURL(/\/scp\/ajax\.php\/board/);
  });

  test('a pending password change wins over the board', async ({ page }) => {
    sql(`UPDATE ost_staff SET change_passwd=1 WHERE username='${env.SPB_AGENT_USER}'`);
    try {
      await page.goto('/scp/login.php');
      await login(page, env.SPB_AGENT_USER, env.SPB_AGENT_PASS);
      await page.waitForURL((url) => !/login\.php/.test(url.pathname));
      await page.waitForLoadState('load');
      expect(page.url()).not.toMatch(/ajax\.php\/board/);
      await expect(page.locator('.spb-board')).toHaveCount(0);
    } finally {
      sql(`UPDATE ost_staff SET change_passwd=0 WHERE username='${env.SPB_AGENT_USER}'`);
    }
  });

  test('switched off: login lands on the ticket list as before', async ({ page }) => {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', 'staff_landing', '0', NOW())`);
    try {
      await page.goto('/scp/login.php');
      await login(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
      await page.waitForURL(/\/scp\/(index|tickets)\.php|\/scp\/$/);
      await page.waitForLoadState('load');
      expect(page.url()).not.toMatch(/ajax\.php\/board/);
    } finally {
      sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='staff_landing'`);
    }
  });
});

test.describe('queues and saved searches as filters', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await page.goto('/scp/ajax.php/board?queue=all');
  });

  async function pick(page, label) {
    await Promise.all([
      page.waitForURL(/queue=\d+/),
      page.selectOption('#spb-queue', { label }),
    ]);
    await page.locator('.spb-board').waitFor({ state: 'attached' });
  }

  test('lists the agent panel queues and own searches, never someone else\'s', async ({ page }) => {
    const options = await page.$$eval('#spb-queue option', (els) => els.map((e) => e.textContent.trim()));
    for (const name of ['Open', 'My Tickets', 'Closed', 'Acme tickets']) expect(options).toContain(name);
    expect(options).not.toContain('Sales private search');
    await expect(page.locator('.spb-toolbar .spb-show')).toBeHidden(); // JS replaced the button
  });

  test('a saved search filters the board and stays selected', async ({ page }) => {
    const errors = watchErrors(page);
    await pick(page, 'Acme tickets');
    await expect(page.locator('h1.spb-title')).toHaveText('Board: Acme tickets');

    const cards = await page.$$eval('.spb-card', (els) => els.map((e) => e.dataset.number).sort());
    expect(cards).toEqual([n.A1, n.A5, n.X1].sort());
    // Sub-tickets come along as context, also from outside the search.
    await expect(page.locator(`.spb-card[data-number="${n.A1}"] .spb-children summary`)).toHaveText('4 sub-tickets');

    await page.goto('/scp/ajax.php/board');
    await expect(page.locator('h1.spb-title')).toHaveText('Board: Acme tickets');
    await expect(page.locator('#spb-queue option:checked')).toHaveText('Acme tickets');

    await page.goto('/scp/ajax.php/board?queue=all');
    await expect(page.locator('h1.spb-title')).toHaveText('Board');
    expect(await boardNumbers(page)).toEqual(Object.values(n).sort());
    expect(errors).toEqual([]);
  });

  test('a queue applies its criteria: Closed shows closed tickets only', async ({ page }) => {
    await pick(page, 'Closed');
    const states = await page.$$eval('.spb-card', (els) => els.map((e) => e.closest('.spb-col').dataset.state));
    expect(states.length).toBeGreaterThan(0);
    expect(new Set(states)).toEqual(new Set(['closed']));
    await expect(page.locator(`.spb-card[data-number="${n.A1}"]`)).toHaveCount(0);
  });

  test('another agent\'s private search by URL: notice, full board, nothing leaked', async ({ page }) => {
    await page.goto(`/scp/ajax.php/board?queue=${seed.searches.sales}`);
    await expect(page.locator('.spb-toolbar + .spb-warn')).toBeVisible();
    await expect(page.locator('h1.spb-title')).toHaveText('Board');
    expect(await page.content()).not.toContain('Sales private search');
    // Same answer as for an ID that doesn't exist.
    const foreign = await page.locator('.spb-warn').first().textContent();
    await page.goto('/scp/ajax.php/board?queue=999999');
    expect(await page.locator('.spb-warn').first().textContent()).toBe(foreign);
    // Not remembered.
    await page.goto('/scp/ajax.php/board');
    await expect(page.locator('.spb-warn')).toHaveCount(0);
  });
});
