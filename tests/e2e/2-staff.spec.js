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
