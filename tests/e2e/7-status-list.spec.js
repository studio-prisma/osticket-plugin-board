// Status list: disabled statuses and the "Status abbreviations" setting.
//
// The seed has "Parked", an open status that is disabled in the status list,
// and a status property "kuerzel" with values for Open, In Progress and
// Parked. Every test puts back what it changes; tickets go back to the
// status they had, whatever the earlier specs left them in.
const { test, expect } = require('@playwright/test');
const { env, requireSeed, watchErrors, staffLogin, clientLogin, sql } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const t = seed.tickets;
const S = seed.status;
const NS = `plugin.${seed.plugin.id}.instance.${seed.plugin.instance}`;

function statusOf(ticketId) {
  const out = sql(`SELECT status_id FROM ost_ticket WHERE ticket_id=${Number(ticketId)}`).trim().split(/\r?\n/);
  return Number(out[out.length - 1]);
}

function setStatus(ticketId, statusId) {
  sql(`UPDATE ost_ticket SET status_id=${Number(statusId)}, closed=NULL WHERE ticket_id=${Number(ticketId)}`);
}

function setAbbrevVar(value) {
  sql(`DELETE FROM ost_config WHERE namespace='${NS}' AND \`key\`='status_abbrev_var'`);
  if (value !== null) {
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('${NS}', 'status_abbrev_var', '${value}', NOW())`);
  }
}

async function openBoard(page) {
  await page.goto('/scp/ajax.php/board?queue=all&group=&sort=updated');
  await page.locator('.spb-board').waitFor();
}

function moveVia(page, ticketId, statusId) {
  return page.evaluate(async ({ ticketId, statusId }) => {
    const token = document.querySelector('meta[name="csrf_token"]').content;
    const res = await fetch('/scp/ajax.php/board/move', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
      body: `ticket_id=${ticketId}&status_id=${statusId}`,
    });
    return { status: res.status, body: await res.json() };
  }, { ticketId, statusId });
}

// A top-level card in the Open column without sub-tickets.
async function plainOpenCard(page) {
  return page.$eval('.spb-col[data-status-name="Open"]', (col) => {
    const card = Array.from(col.querySelectorAll('.spb-cards > .spb-card')).find((c) => !c.querySelector('.spb-children'));
    return card ? { id: card.dataset.ticketId, number: card.dataset.number } : null;
  });
}

test.describe('disabled status', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
  });

  test('without tickets: no column, no quick-view entry, the move endpoint refuses it', async ({ page }) => {
    const errors = watchErrors(page);
    await openBoard(page);
    await expect(page.locator('.spb-col[data-status-name="Parked"]')).toHaveCount(0);
    await expect(page.locator('.spb-col[data-status-name="In Progress"]')).toHaveCount(1);

    const card = await plainOpenCard(page);
    expect(card).not.toBeNull();
    await page.locator(`.spb-card[data-number="${card.number}"] .spb-subject`).click();
    await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', card.number);
    const statusButtons = page.locator('.spb-action-group[data-group="status"] .spb-action');
    await expect(statusButtons.filter({ hasText: 'In Progress' })).toHaveCount(1);
    await expect(statusButtons.filter({ hasText: 'Parked' })).toHaveCount(0);
    await page.keyboard.press('Escape');
    // Checked here: the refused request below logs its 422 to the console.
    expect(errors).toEqual([]);

    const before = statusOf(card.id);
    const res = await moveVia(page, card.id, S.parked);
    expect(res.status).toBe(422);
    expect(res.body.error).toBe('invalid_status');
    expect(statusOf(card.id)).toBe(before);
  });

  test('with a ticket still in it: column stays, marked, cards move out but not in', async ({ page }) => {
    const errors = watchErrors(page);
    const original = statusOf(t.A5);
    setStatus(t.A5, S.parked);
    try {
      await openBoard(page);
      const parked = page.locator('.spb-col[data-status-name="Parked"]');
      await expect(parked).toHaveCount(1);
      await expect(parked).toHaveAttribute('data-disabled', '1');
      await expect(parked.locator('.spb-disabled-note')).toBeVisible();
      await expect(parked.locator(`.spb-card[data-number="${n.A5}"]`)).toHaveCount(1);
      // Column order: Open, Parked (sort 1, disabled), In Progress.
      const order = await page.$$eval('.spb-board > .spb-col[data-state="open"]', (cols) => cols.map((c) => c.dataset.statusName));
      expect(order).toEqual(['Open', 'Parked', 'In Progress']);

      // Not a drop target: dragging an Open card onto it changes nothing.
      const card = await plainOpenCard(page);
      await page.dragAndDrop(`.spb-card[data-number="${card.number}"]`, '.spb-col[data-status-name="Parked"] .spb-cards');
      await page.waitForTimeout(300);
      await expect(page.locator(`.spb-col[data-status-name="Open"] .spb-card[data-number="${card.number}"]`)).toHaveCount(1);

      // The keyboard move passes over it in both directions.
      const moved = page.waitForResponse((r) => r.url().includes('/board/move'));
      await page.locator(`.spb-card[data-number="${card.number}"]`).focus();
      await page.keyboard.press('Alt+ArrowRight');
      expect((await moved).status()).toBe(200);
      await expect(page.locator(`.spb-col[data-status-name="In Progress"] .spb-card[data-number="${card.number}"]`)).toHaveCount(1);
      const back = page.waitForResponse((r) => r.url().includes('/board/move'));
      await page.locator(`.spb-card[data-number="${card.number}"]`).focus();
      await page.keyboard.press('Alt+ArrowLeft');
      expect((await back).status()).toBe(200);
      await expect(page.locator(`.spb-col[data-status-name="Open"] .spb-card[data-number="${card.number}"]`)).toHaveCount(1);
      expect(statusOf(card.id)).toBe(S.open);

      // Quick view of the parked ticket offers the way out, not the way in.
      await page.locator(`.spb-card[data-number="${n.A5}"] .spb-subject`).click();
      await expect(page.locator('.spb-panel-body')).toHaveAttribute('data-number', n.A5);
      const statusButtons = page.locator('.spb-action-group[data-group="status"] .spb-action');
      await expect(statusButtons.filter({ hasText: 'Open' })).toHaveCount(1);
      await expect(statusButtons.filter({ hasText: 'Parked' })).toHaveCount(0);
      await page.keyboard.press('Escape');

      // Moving the last card out works; after a reload the column is gone.
      const out = page.waitForResponse((r) => r.url().includes('/board/move'));
      await page.locator(`.spb-card[data-number="${n.A5}"]`).focus();
      await page.keyboard.press('Alt+ArrowLeft');
      expect((await out).status()).toBe(200);
      expect(statusOf(t.A5)).toBe(S.open);
      await page.reload();
      await page.locator('.spb-board').waitFor();
      await expect(page.locator('.spb-col[data-status-name="Parked"]')).toHaveCount(0);
      expect(errors).toEqual([]);
    } finally {
      setStatus(t.A5, original);
    }
  });

  test('grouped view: the column holds in every row and stays out of reach', async ({ page }) => {
    const original = statusOf(t.A5);
    setStatus(t.A5, S.parked);
    try {
      await page.goto('/scp/ajax.php/board?queue=all&group=org&sort=updated');
      await page.locator('.spb-board').waitFor();
      const lanes = await page.locator('.spb-lane').count();
      expect(lanes).toBeGreaterThan(1);
      await expect(page.locator('.spb-cell[data-status-name="Parked"][data-disabled="1"]')).toHaveCount(lanes);
      await expect(page.locator(`.spb-cell[data-status-name="Parked"] .spb-card[data-number="${n.A5}"]`)).toHaveCount(1);
    } finally {
      setStatus(t.A5, original);
      await page.goto('/scp/ajax.php/board?group=');
    }
  });

  test('the client still sees a ticket in a disabled status, without the admin note', async ({ page }) => {
    const original = statusOf(t.A5);
    setStatus(t.A5, S.parked);
    try {
      await clientLogin(page, 'bob@acme.example');
      await page.goto('/ajax.php/board');
      await page.locator('.spb-board').waitFor();
      await expect(page.locator(`.spb-col[data-status-name="Parked"] .spb-card[data-number="${n.A5}"]`)).toHaveCount(1);
      await expect(page.locator('.spb-disabled-note')).toHaveCount(0);
      await expect(page.locator('[data-disabled]')).toHaveCount(0);
    } finally {
      setStatus(t.A5, original);
    }
  });

  test('plugin settings: the status pickers leave it out', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto(`/scp/plugins.php?id=${seed.plugin.id}&xid=${seed.plugin.instance}`);
    await expect(page.getByText(/Status abbreviations:/).first()).toBeVisible();
    const options = page.locator('select option');
    await expect(options.filter({ hasText: 'In Progress' }).first()).toBeAttached();
    await expect(options.filter({ hasText: /^Parked$/ })).toHaveCount(0);
    expect(errors).toEqual([]);
  });
});

test.describe('status abbreviations', () => {
  test.afterEach(() => setAbbrevVar(null));

  test('off by default: column heads show status names', async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await openBoard(page);
    await expect(page.locator('.spb-abbrev')).toHaveCount(0);
    await expect(page.locator('.spb-col[data-status-name="Open"] .spb-col-head h3')).toHaveText('Open');
  });

  test('on: agent heads show the abbreviation, the full name as tooltip and accessible name', async ({ page }) => {
    const errors = watchErrors(page);
    setAbbrevVar('kuerzel');
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await openBoard(page);

    const open = page.locator('.spb-board > .spb-col[data-status-name="Open"] .spb-col-head h3');
    await expect(open).toHaveAttribute('title', 'Open');
    await expect(open.locator('.spb-abbrev')).toHaveText('OF');
    await expect(open.locator('.spb-abbrev')).toBeVisible();
    await expect(open.locator('.spb-abbrev')).toHaveAttribute('aria-hidden', 'true');
    // The heading's accessible name is the full status name.
    await expect(page.getByRole('heading', { name: 'Open', exact: true })).toHaveCount(1);
    await expect(page.getByRole('heading', { name: 'In Progress', exact: true })).toHaveCount(1);
    // Visually it reads as the abbreviation only.
    const box = await open.locator('.spb-sr').boundingBox();
    expect(box === null || (box.width <= 1 && box.height <= 1)).toBe(true);

    await expect(page.locator('.spb-col[data-status-name="In Progress"] .spb-abbrev')).toHaveText('IB');
    // No value for Closed: name as before.
    await expect(page.locator('.spb-col[data-status-name="Closed"] .spb-col-head h3')).toHaveText('Closed');
    await expect(page.locator('.spb-col[data-status-name="Closed"] .spb-abbrev')).toHaveCount(0);

    // Grouped: the shared head row carries it too.
    await page.goto('/scp/ajax.php/board?queue=all&group=org&sort=updated');
    await page.locator('.spb-board').waitFor();
    await expect(page.locator('.spb-head-row .spb-col-total[data-status-id="' + S.open + '"] .spb-abbrev')).toHaveText('OF');
    await page.goto('/scp/ajax.php/board?group=');
    expect(errors).toEqual([]);
  });

  test('on: the client board keeps the full names', async ({ page }) => {
    setAbbrevVar('kuerzel');
    await clientLogin(page, 'alice@acme.example');
    await page.goto('/ajax.php/board');
    await page.locator('.spb-board').waitFor();
    await expect(page.locator('.spb-abbrev')).toHaveCount(0);
    await expect(page.locator('.spb-col[data-status-name="Open"] .spb-col-head h3')).toHaveText('Open');
  });

  test('a variable name no property has, or junk, changes nothing', async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    for (const value of ['nosuchproperty', 'kuer zel']) {
      setAbbrevVar(value);
      await openBoard(page);
      await expect(page.locator('.spb-abbrev')).toHaveCount(0);
      await expect(page.locator('.spb-col[data-status-name="Open"] .spb-col-head h3')).toHaveText('Open');
    }
  });
});
