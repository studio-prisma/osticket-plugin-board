// Due and priority tags, sorting, swimlanes, column limits, live refresh.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { env, requireSeed, watchErrors, staffLogin, sql, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const t = seed.tickets;
const shot = (name) => path.join(LOCAL, 'screens', `extras-${name}.png`);

async function openBoard(page, query) {
  await page.goto(`/scp/ajax.php/board?${query}`);
  await page.locator('.spb-board').waitFor();
}

async function cardOrder(page, status) {
  return page.$$eval(`.spb-col[data-status-name="${status}"] .spb-cards > .spb-card`, (els) => els.map((e) => e.dataset.number));
}

// A change made outside this browser, straight in the database. A second
// session of the same agent made the test flaky on CI runners: both
// sessions renew their session ID ten seconds after login, at the same
// moment, and the first one's requests stalled.
function changeElsewhere(ticketId, statusId) {
  sql(`UPDATE ost_ticket SET status_id=${Number(statusId)}, updated=NOW() WHERE ticket_id=${Number(ticketId)}`);
}

function moveVia(page, ticketId, statusId) {
  return page.evaluate(async ({ ticketId, statusId }) => {
    const token = document.querySelector('meta[name="csrf_token"]').content;
    const res = await fetch('/scp/ajax.php/board/move', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRFToken': token },
      body: `ticket_id=${ticketId}&status_id=${statusId}`,
    });
    return res.status;
  }, { ticketId, statusId });
}

test.describe('admin', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
  });

  test('due and priority tags on agent cards', async ({ page }) => {
    const errors = watchErrors(page);
    await openBoard(page, 'queue=all&sort=updated&group=');
    await expect(page.locator(`.spb-card[data-number="${n.G1}"] .spb-due-overdue`)).toHaveText('Overdue');
    await expect(page.locator(`.spb-card[data-number="${n.X1}"] .spb-due-tomorrow`)).toHaveText('Due tomorrow');
    await expect(page.locator(`.spb-card[data-number="${n.X1}"] .spb-prio`)).toHaveText('High');
    await expect(page.locator(`.spb-card[data-number="${n.S1}"] .spb-prio`)).toHaveText('Emergency');
    // Normal priority gets no tag.
    await expect(page.locator(`.spb-card[data-number="${n.G1}"] .spb-prio`)).toHaveCount(0);
    await page.screenshot({ path: shot('tags'), fullPage: true });
    expect(errors).toEqual([]);
  });

  test('sort by priority and by due date, remembered', async ({ page }) => {
    await openBoard(page, 'queue=all&group=&sort=priority');
    let open = await cardOrder(page, 'Open');
    expect(open.indexOf(n.S1)).toBeLessThan(open.indexOf(n.G1));

    await Promise.all([page.waitForURL(/sort=due/), page.selectOption('#spb-sort', 'due')]);
    open = await cardOrder(page, 'Open');
    expect(open.indexOf(n.G1)).toBeLessThan(open.indexOf(n.S1));

    await openBoard(page, '');
    await expect(page.locator('#spb-sort')).toHaveValue('due');
    await openBoard(page, 'sort=updated');
  });

  test('swimlanes by organisation: rows, totals, moves stay in their row', async ({ page }) => {
    const errors = watchErrors(page);
    await openBoard(page, 'queue=all&sort=updated&group=org');
    const lanes = await page.$$eval('.spb-lane-head h3', (els) => els.map((e) => e.textContent));
    expect(lanes).toEqual(['Acme Corp', 'Globex']);
    await expect(page.locator(`.spb-lane[data-lane="Globex"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);
    await expect(page.locator(`.spb-lane[data-lane="Acme Corp"] .spb-card[data-number="${n.A1}"]`)).toHaveCount(1);
    await page.screenshot({ path: shot('lanes'), fullPage: true });

    // Keyboard move inside the Globex row, and back.
    const g1 = page.locator(`.spb-card[data-number="${n.G1}"]`);
    await g1.focus();
    await page.keyboard.press('Alt+ArrowRight');
    await expect(page.locator(`.spb-lane[data-lane="Globex"] .spb-col[data-status-name="In Progress"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);
    // The shared head row counts across rows and shows the limit.
    await expect(page.locator('.spb-col-total[data-status-id] .spb-col-head.spb-over')).toHaveCount(1);
    await g1.focus();
    await page.keyboard.press('Alt+ArrowLeft');
    await expect(page.locator(`.spb-lane[data-lane="Globex"] .spb-col[data-status-name="Open"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);

    // Dropping into another organisation's row does nothing.
    await page.dragAndDrop(`.spb-card[data-number="${n.G1}"]`,
      '.spb-lane[data-lane="Acme Corp"] .spb-col[data-status-name="Open"] .spb-cards');
    await page.waitForTimeout(400);
    await expect(page.locator(`.spb-lane[data-lane="Globex"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);
    await openBoard(page, 'group=org');
    await expect(page.locator(`.spb-lane[data-lane="Globex"] .spb-col[data-status-name="Open"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);

    for (const g of ['agent', 'dept']) {
      await openBoard(page, `group=${g}`);
      expect(await page.locator('.spb-lane').count()).toBeGreaterThan(0);
      await page.screenshot({ path: shot(`lanes-${g}`), fullPage: true });
    }
    await openBoard(page, 'group=');
    expect(errors).toEqual([]);
  });

  test('column limit: marked when exceeded, cleared when back under', async ({ page }) => {
    await openBoard(page, 'queue=all&group=');
    const head = page.locator('.spb-col[data-status-name="In Progress"] > .spb-col-head');
    await expect(head.locator('.spb-count')).toHaveText(/ of 1$/);
    await expect(head).not.toHaveClass(/spb-over/);

    await page.locator(`.spb-card[data-number="${n.G1}"]`).focus();
    await page.keyboard.press('Alt+ArrowRight');
    await expect(head).toHaveClass(/spb-over/);
    await expect(head.locator('.spb-count')).toHaveText('2 of 1');
    await page.screenshot({ path: shot('limit'), fullPage: false });

    await page.locator(`.spb-card[data-number="${n.G1}"]`).focus();
    await page.keyboard.press('Alt+ArrowLeft');
    await expect(head).not.toHaveClass(/spb-over/);
  });
});

test.describe('live refresh', () => {
  test.setTimeout(150000);

  test('changes made elsewhere: swapped in when idle, offered when busy', async ({ page: viewer }) => {
    const errors = watchErrors(viewer);
    await staffLogin(viewer, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await openBoard(viewer, 'queue=all&group=&sort=updated');

    try {
      // Idle viewer: the board updates by itself.
      changeElsewhere(t.G1, seed.status.in_progress);
      await expect(viewer.locator(`.spb-col[data-status-name="In Progress"] .spb-card[data-number="${n.G1}"]`))
        .toHaveCount(1, { timeout: 40000 });
      await expect(viewer.locator('.spb-status')).toContainText('Board updated');

      // Busy viewer (keeps pressing a key): the update is offered, not forced.
      changeElsewhere(t.G1, seed.status.open);
      const button = viewer.locator('.spb-updates');
      const deadline = Date.now() + 40000;
      while (Date.now() < deadline && !(await button.isVisible())) {
        await viewer.keyboard.press('Shift');
        await viewer.waitForTimeout(1500);
      }
      await expect(button).toBeVisible();
      // Still the old state underneath.
      await expect(viewer.locator(`.spb-col[data-status-name="In Progress"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);
      await viewer.screenshot({ path: shot('updates-offered') });
      await button.click();
      // The swap fetches the whole page again; give it time under load.
      await expect(viewer.locator(`.spb-col[data-status-name="Open"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1, { timeout: 15000 });
      await expect(button).toBeHidden();

      // After a swap the board still works: quick view opens.
      await viewer.locator(`.spb-card[data-number="${n.G1}"] .spb-subject`).click();
      await expect(viewer.locator('.spb-panel-body')).toHaveAttribute('data-number', n.G1);

      expect(errors).toEqual([]);
    } finally {
      // A failure must not leave G1 in progress for the specs after this one.
      changeElsewhere(t.G1, seed.status.open);
    }
  });

  test('state endpoint: agent only, no store, reflects changes', async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await openBoard(page, 'queue=all');
    const read = () => page.evaluate(async () => {
      const res = await fetch('/scp/ajax.php/board/state');
      return { status: res.status, cache: res.headers.get('cache-control'), body: await res.json() };
    });
    const a = await read();
    expect(a.status).toBe(200);
    expect(a.cache).toContain('no-store');
    expect(a.body.signature).toMatch(/^[0-9a-f]{40}$/);
    try {
      expect(await moveVia(page, t.G1, seed.status.in_progress)).toBe(200);
      const b = await read();
      expect(b.body.signature).not.toBe(a.body.signature);
    } finally {
      changeElsewhere(t.G1, seed.status.open);
    }
  });
});
