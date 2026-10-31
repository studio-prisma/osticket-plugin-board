// Client portal: what each customer sees, and what they must not.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { requireSeed, watchErrors, clientLogin, boardNumbers, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;

test('alice sees own and organisation tickets, nested, nothing from Globex', async ({ page }) => {
  const errors = watchErrors(page);
  await clientLogin(page, 'alice@acme.example');

  const nav = page.locator('#nav a.spb-nav');
  await expect(nav).toHaveCount(1);
  await nav.click();
  await expect(page).toHaveURL(/\/ajax\.php\/board\/?$/);
  await expect(page.locator('#nav a.spb-nav')).toHaveClass(/active/);

  const numbers = await boardNumbers(page);
  expect(numbers).toEqual([n.A1, n.A2, n.A3, n.A4, n.A5, n.X1].sort());
  for (const k of ['G1', 'G2', 'S1']) expect(numbers).not.toContain(n[k]);

  // Sub-tickets sit under the parent card, each with its own status.
  const parent = page.locator(`.spb-card[data-number="${n.A1}"]`);
  await expect(parent.locator('.spb-children summary')).toHaveText('3 sub-tickets');
  await expect(parent.locator(`.spb-child:has(a:text("#${n.A2}")) .spb-badge`)).toHaveText('In Progress');
  await expect(parent.locator(`.spb-child:has(a:text("#${n.A4}")) .spb-badge`)).toHaveText('Closed');

  // Read-only: no drag handles, no agent-only fields, links into the portal.
  await expect(page.locator('[draggable="true"]')).toHaveCount(0);
  await expect(page.locator('.spb-board[data-move-url]')).toHaveCount(0);
  await expect(page.locator('.spb-meta dt', { hasText: 'Assigned' })).toHaveCount(0);
  await expect(page.locator('.spb-meta dt', { hasText: 'Requester' })).toHaveCount(0);
  expect(await parent.locator('a.spb-num').getAttribute('href')).toMatch(/^\/tickets\.php\?id=\d+$/);

  // Markup in a subject is text, never markup.
  await expect(page.locator(`.spb-card[data-number="${n.X1}"] .spb-subject`)).toContainText('<img src=x');
  expect(await page.evaluate(() => window.__spbXss)).toBeUndefined();

  const html = await page.content();
  expect(html).not.toContain('INTERNAL-NOTE-MARKER-7f3a');
  expect(html).not.toContain('Globex');

  await page.screenshot({ path: path.join(LOCAL, 'screens', 'client-alice.png'), fullPage: true });
  expect(errors).toEqual([]);
});

test('carol sees only Globex tickets; the child of a foreign parent stands alone', async ({ page }) => {
  await clientLogin(page, 'carol@globex.example');
  await page.goto('/ajax.php/board');
  const numbers = await boardNumbers(page);
  expect(numbers).toEqual([n.G1, n.G2, n.S1].sort());
  await expect(page.locator(`.spb-card[data-number="${n.G2}"]`)).toHaveCount(1);
  const html = await page.content();
  for (const subject of ['Website relaunch', 'Design mockups', 'Content migration', 'Invoice question']) {
    expect(html).not.toContain(subject);
  }
});

test('anonymous visitors are sent to the login page', async ({ page }) => {
  await page.goto('/ajax.php/board');
  await expect(page).toHaveURL(/\/login\.php/);
  await expect(page.locator('.spb-board')).toHaveCount(0);
});

test('anonymous portal pages carry no board link', async ({ page }) => {
  await page.goto('/index.php');
  await expect(page.locator('#nav')).toHaveCount(1);
  await expect(page.locator('a.spb-nav')).toHaveCount(0);
});

test('a client session cannot reach the agent board or move tickets', async ({ page }) => {
  await clientLogin(page, 'alice@acme.example');

  // scp/ajax.php answers 403 without an agent session; a portal session is not one.
  const res = await page.goto('/scp/ajax.php/board');
  expect(res.status()).toBe(403);
  await expect(page.locator('.spb-board')).toHaveCount(0);

  await page.goto('/ajax.php/board');
  const result = await page.evaluate(async ({ id, status }) => {
    const token = document.querySelector('meta[name="csrf_token"]').content;
    const res = await fetch('/scp/ajax.php/board/move', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
      body: `ticket_id=${id}&status_id=${status}`,
      redirect: 'manual',
    });
    return { status: res.status, type: res.type };
  }, { id: seed.tickets.A1, status: seed.status.closed });
  expect(result.status).toBe(403);

  await page.reload();
  const col = page.locator('.spb-col', { has: page.locator(`.spb-card[data-number="${n.A1}"]`) });
  await expect(col).toHaveAttribute('data-status-name', 'Open');
});

test('the client route does not answer POST', async ({ page }) => {
  await clientLogin(page, 'alice@acme.example');
  await page.goto('/ajax.php/board');
  const status = await page.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf_token"]').content;
    const res = await fetch('/ajax.php/board', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
      body: 'ticket_id=1&status_id=3',
    });
    return res.status;
  });
  expect(status).toBe(400);
});
