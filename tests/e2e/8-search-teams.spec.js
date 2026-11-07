// Board search (live filter), team filter, saved views.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { env, requireSeed, watchErrors, staffLogin, clientLogin, sql, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const t = seed.tickets;
const shot = (name) => path.join(LOCAL, 'screens', `search-${name}.png`);

// A task number no ticket number contains, so a hit can only come from the task.
const TASK_NUMBER = '904173';
let secondTeam = 0;

test.beforeAll(() => {
  sql("INSERT INTO ost_team (lead_id, flags, name, notes, created, updated) VALUES (0, 1, 'Second Line', '', NOW(), NOW())");
  secondTeam = Number(sql("SELECT team_id FROM ost_team WHERE name='Second Line'").trim().split(/\s+/).pop());
  // X1 and S1 to "Level I Support" (1), G1 to "Second Line"; the rest stays without a team.
  sql(`UPDATE ost_ticket SET team_id=1 WHERE ticket_id IN (${t.X1}, ${t.S1})`);
  sql(`UPDATE ost_ticket SET team_id=${secondTeam} WHERE ticket_id=${t.G1}`);
  sql(`UPDATE ost_task SET number='${TASK_NUMBER}' WHERE id=${seed.tasks.T2}`);
});

test.afterAll(() => {
  sql('UPDATE ost_ticket SET team_id=0');
  sql(`UPDATE ost_task SET number='${seed.tasks.T2}' WHERE id=${seed.tasks.T2}`);
  sql(`DELETE FROM ost_team_member WHERE team_id=${secondTeam}`);
  sql("DELETE FROM ost_team WHERE name='Second Line'");
  sql("DELETE FROM ost_config WHERE namespace LIKE 'studio-prisma.board.staff.%'");
});

async function openBoard(page, query) {
  await page.goto(`/scp/ajax.php/board?${query}`);
  await page.locator('.spb-board').waitFor();
}

async function visible(page) {
  return page.$$eval('.spb-cards > .spb-card:not([hidden])', (els) => els.map((e) => e.dataset.number).sort());
}

async function onBoard(page) {
  await page.locator('.spb-board').waitFor({ state: 'attached' });
  return page.$$eval('.spb-cards > .spb-card', (els) => els.map((e) => e.dataset.number).sort());
}

async function search(page, text) {
  await page.fill('#spb-search-input', text);
  // The filter runs on the next animation frame.
  await page.waitForFunction(() => new Promise((r) => requestAnimationFrame(() => r(true))));
}

test.describe('search, as admin', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
    await page.evaluate(() => sessionStorage.clear());
  });

  test('filters live by number, task, sender, sub-ticket, several words', async ({ page }) => {
    const errors = watchErrors(page);
    await openBoard(page, 'queue=all&group=&team_set=1');
    const all = await onBoard(page);
    await expect(page.locator('.spb-search')).toBeVisible();

    await search(page, `#${n.G1}`);
    expect(await visible(page)).toEqual([n.G1]);
    await expect(page.locator('.spb-search-count')).toHaveText(`1 of ${all.length} tickets`);

    await search(page, TASK_NUMBER);
    expect(await visible(page)).toEqual([n.A1]);

    // Requester mail, any case.
    await search(page, 'CAROL@globex');
    expect(await visible(page)).toContain(n.G1);
    expect(await visible(page)).not.toContain(n.A5);

    // A sub-ticket's number shows the parent card it is nested under.
    await search(page, n.A2);
    expect(await visible(page)).toEqual([n.A1]);

    // Every word has to match; markup in a subject is plain text.
    await search(page, 'markup <img');
    const hits = await visible(page);
    expect(hits).toContain(n.X1);
    await search(page, 'markup nosuchword');
    expect(await visible(page)).toEqual([]);
    await expect(page.locator('.spb-search-count')).toHaveText('No ticket on the board matches.');
    const more = page.locator('.spb-search-more');
    await expect(more).toBeVisible();
    expect(await more.getAttribute('href')).toBe('/scp/tickets.php?a=search&search-type=&query=markup+nosuchword');
    await page.screenshot({ path: shot('none'), fullPage: true });

    // Escape clears.
    await page.press('#spb-search-input', 'Escape');
    expect(await visible(page)).toEqual(all);
    await expect(more).toBeHidden();
    expect(errors).toEqual([]);
  });

  test('"/" focuses the field; lanes without hits fold away', async ({ page }) => {
    await openBoard(page, 'queue=all&group=org&team_set=1');
    await page.locator('body').click({ position: { x: 5, y: 5 } });
    await page.keyboard.press('/');
    await expect(page.locator('#spb-search-input')).toBeFocused();
    await page.keyboard.type(n.G1);
    await page.waitForFunction(() => new Promise((r) => requestAnimationFrame(() => r(true))));
    await expect(page.locator('.spb-lane:not([hidden])')).toHaveCount(1);
    await expect(page.locator('.spb-lane:not([hidden]) .spb-lane-head h3')).toHaveText('Globex');
    await page.screenshot({ path: shot('lanes'), fullPage: true });
  });

  test('the filter survives a reload and a live refresh', async ({ page }) => {
    await openBoard(page, 'queue=all&group=&team_set=1');
    await search(page, n.G1);
    await page.reload();
    await page.locator('.spb-board').waitFor();
    await expect(page.locator('#spb-search-input')).toHaveValue(n.G1);
    expect(await visible(page)).toEqual([n.G1]);

    // Board swapped by the update button: the filter is applied to the new cards.
    sql(`UPDATE ost_ticket SET updated=NOW() WHERE ticket_id=${t.A5}`);
    await page.evaluate(() => { document.querySelector('.spb-updates').hidden = false; });
    await page.click('.spb-updates');
    await page.waitForFunction(() => document.querySelector('.spb-updates').hidden);
    expect(await visible(page)).toEqual([n.G1]);
  });
});

test.describe('team filter and saved views, as admin', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
  });

  test('multi-select narrows the board on the server, independent of lanes', async ({ page }) => {
    const errors = watchErrors(page);
    await openBoard(page, 'queue=all&group=agent&sort=number&team_set=1');
    const all = await onBoard(page);

    await page.click('.spb-teams > summary');
    await page.check('.spb-teams input[value="1"]');
    await page.check(`.spb-teams input[value="${secondTeam}"]`);
    // Closing the fold-out applies the change.
    await Promise.all([page.waitForNavigation(), page.click('.spb-title')]);
    expect(await onBoard(page)).toEqual([n.G1, n.S1, n.X1].sort());
    await expect(page.locator('.spb-teams > summary')).toHaveText('2 teams');
    await expect(page.locator('.spb-lane')).not.toHaveCount(0);

    // Grouping changes nothing about which tickets are there.
    await openBoard(page, 'group=dept');
    expect(await onBoard(page)).toEqual([n.G1, n.S1, n.X1].sort());

    // "Without team" plus one team.
    await openBoard(page, `team_set=1&team[]=0&team[]=${secondTeam}`);
    const expected = all.filter((x) => x !== n.X1 && x !== n.S1);
    expect(await onBoard(page)).toEqual(expected);
    await page.screenshot({ path: shot('teams'), fullPage: true });
    expect(errors).toEqual([]);
  });

  test('the last selection is kept per agent, across sessions', async ({ page, browser }) => {
    await openBoard(page, `team_set=1&team[]=${secondTeam}`);
    expect(await onBoard(page)).toEqual([n.G1]);

    // A fresh browser context: new session, same agent.
    const other = await browser.newContext();
    const p2 = await other.newPage();
    await staffLogin(p2, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS, { keepPrefs: true });
    await openBoard(p2, 'queue=all');
    expect(await onBoard(p2)).toEqual([n.G1]);
    await expect(p2.locator('.spb-teams > summary')).toHaveText('Second Line');
    await other.close();

    // Unchecking everything means all teams again.
    await openBoard(page, 'team_set=1');
    await expect(page.locator('.spb-teams > summary')).toHaveText('All teams');
    expect((await onBoard(page)).length).toBeGreaterThan(1);
  });

  test('queue, sort and grouping are kept per agent too', async ({ page, browser }) => {
    await openBoard(page, `queue=${seed.searches.acme}&sort=number&group=org`);

    const other = await browser.newContext();
    const p2 = await other.newPage();
    await staffLogin(p2, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS, { keepPrefs: true });
    await p2.goto('/scp/ajax.php/board');
    await p2.locator('.spb-board').waitFor();
    await expect(p2.locator('#spb-queue')).toHaveValue(String(seed.searches.acme));
    await expect(p2.locator('#spb-sort')).toHaveValue('number');
    await expect(p2.locator('#spb-group')).toHaveValue('org');
    await other.close();

    // The live-refresh check reads them but stores nothing.
    await page.evaluate(() => fetch('/scp/ajax.php/board/state?queue=all'));
    await page.goto('/scp/ajax.php/board');
    await page.locator('.spb-board').waitFor();
    await expect(page.locator('#spb-queue')).toHaveValue(String(seed.searches.acme));
    await openBoard(page, 'queue=all&sort=updated&group=');
  });

  test('a team also brings in tickets assigned to its members personally', async ({ page }) => {
    const admin = Number(sql(`SELECT staff_id FROM ost_staff WHERE username='${env.SPB_ADMIN_USER}'`).trim().split(/\s+/).pop());
    await openBoard(page, 'queue=all&group=&team_set=1');
    const all = await onBoard(page);
    sql(`INSERT INTO ost_team_member (team_id, staff_id, flags) VALUES (${secondTeam}, ${admin}, 0)`);
    try {
      await openBoard(page, `team_set=1&team[]=${secondTeam}`);
      // G1 by its team; A1, A5 and S1 because they are assigned to the admin, now a member.
      const expected = all.filter((x) => [n.G1, n.A1, n.A5, n.S1].includes(x));
      expect(expected).toEqual(expect.arrayContaining([n.G1, n.A5]));
      expect(await onBoard(page)).toEqual(expected);
    } finally {
      sql(`DELETE FROM ost_team_member WHERE team_id=${secondTeam}`);
    }
    await openBoard(page, 'team_set=1');
  });

  test('works on top of a queue or saved search', async ({ page }) => {
    await openBoard(page, `queue=${seed.searches.acme}&team_set=1&team[]=1`);
    // "Acme tickets" with team Level I Support: only X1.
    expect(await onBoard(page)).toEqual([n.X1]);
    await openBoard(page, 'queue=all&team_set=1');
  });

  test('named views: save, pick, delete', async ({ page }) => {
    const errors = watchErrors(page);
    await openBoard(page, `queue=all&sort=number&group=org&team_set=1&team[]=${secondTeam}`);
    await page.click('.spb-view-save > summary');
    await page.fill('#spb-view-name', 'Globex <b>line</b>');
    await Promise.all([page.waitForNavigation(), page.click('.spb-view-save button[type="submit"]')]);

    const select = page.locator('#spb-queue');
    await expect(select.locator('optgroup[label="My views"] option')).toHaveText(['Globex <b>line</b>']);
    expect(await select.inputValue()).toBe('v0');

    // Somewhere else, then back through the dropdown.
    await openBoard(page, 'queue=all&sort=updated&group=&team_set=1');
    expect(await select.inputValue()).toBe('all');
    await Promise.all([page.waitForNavigation(), select.selectOption('v0')]);
    await page.locator('.spb-board').waitFor();
    expect(await onBoard(page)).toEqual([n.G1]);
    await expect(page.locator('#spb-group')).toHaveValue('org');
    await expect(page.locator('#spb-sort')).toHaveValue('number');
    expect(await select.inputValue()).toBe('v0');
    await page.screenshot({ path: shot('view'), fullPage: true });

    page.once('dialog', (d) => d.accept());
    await Promise.all([page.waitForNavigation(), page.click('.spb-view-delete button')]);
    await expect(select.locator('optgroup[label="My views"]')).toHaveCount(0);
    // The board itself stays.
    expect(await onBoard(page)).toEqual([n.G1]);
    await openBoard(page, 'queue=all&team_set=1&sort=updated&group=');
    expect(errors).toEqual([]);
  });

  test('view endpoint: CSRF required, invalid names refused, no foreign data', async ({ page }) => {
    await openBoard(page, 'queue=all&team_set=1');
    const noToken = await page.evaluate(async () => {
      const res = await fetch('/scp/ajax.php/board/view', {
        method: 'POST', redirect: 'manual',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'op=save&name=x',
      });
      return res.status;
    });
    expect(noToken).toBe(400);

    await page.click('.spb-view-save > summary');
    await page.evaluate(() => { document.querySelector('#spb-view-name').removeAttribute('maxlength'); });
    await page.fill('#spb-view-name', 'x'.repeat(61));
    await Promise.all([page.waitForNavigation(), page.click('.spb-view-save button[type="submit"]')]);
    await expect(page.locator('.spb-warn', { hasText: 'The view was not saved' })).toBeVisible();
    await expect(page.locator('#spb-queue optgroup[label="My views"]')).toHaveCount(0);

    // An unknown view index is the default view, not an error.
    await openBoard(page, 'queue=v99');
    expect(await page.locator('#spb-queue').inputValue()).toBe('all');
  });
});

test.describe('salesagent', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_AGENT_USER, env.SPB_AGENT_PASS);
    await page.evaluate(() => sessionStorage.clear());
  });

  test('team filter and search never widen what the agent sees', async ({ page }) => {
    // G1 and X1 are in teams but not in Sales.
    await openBoard(page, `queue=all&team_set=1&team[]=1&team[]=${secondTeam}`);
    expect(await onBoard(page)).toEqual([n.S1]);
    await search(page, n.G1);
    expect(await page.locator('.spb-card:not([hidden])').count()).toBe(0);
    // No card on the page carries G1, hidden or not.
    expect(await page.$$eval('.spb-card', (els) => els.map((e) => e.dataset.search).join(' '))).not.toContain(n.G1);
    // The live-refresh fingerprint with a team in the query counts the same.
    const state = await page.evaluate(async (team) => {
      const res = await fetch(`/scp/ajax.php/board/state?team_set=1&team[]=${team}`);
      return res.json();
    }, secondTeam);
    expect(state.count).toBe(0);

    // A view saved by the admin is not reachable: v0 is the agent's own (none).
    await openBoard(page, 'queue=v0');
    expect(await page.locator('#spb-queue').inputValue()).toBe('all');
    await openBoard(page, 'queue=all&team_set=1');
  });

  test("a saved search of another agent can't be smuggled in through a view", async ({ page }) => {
    // Stored by hand: the agent's own prefs pointing at the admin's private search.
    const sid = Number(sql(`SELECT staff_id FROM ost_staff WHERE username='${env.SPB_AGENT_USER}'`).trim().split(/\s+/).pop());
    const prefs = JSON.stringify({ teams: [], views: [{ name: 'Sneaky', queue: seed.searches.acme, teams: [], sort: 'updated', group: '' }] });
    sql(`INSERT INTO ost_config (namespace, \`key\`, value, updated) VALUES ('studio-prisma.board.staff.${sid}', 'prefs', '${prefs}', NOW())
      ON DUPLICATE KEY UPDATE value='${prefs}'`);
    await openBoard(page, 'queue=v0');
    await expect(page.locator('.spb-warn', { hasText: 'not available' })).toBeVisible();
    expect(await onBoard(page)).toEqual([n.S1]);
    await openBoard(page, 'queue=all');
  });
});

test.describe('client portal', () => {
  test('search over the customer\'s own cards, no agent fields', async ({ page }) => {
    await clientLogin(page, 'alice@acme.example');
    await page.evaluate(() => sessionStorage.clear());
    await page.goto('/ajax.php/board');
    await page.locator('.spb-board').waitFor();
    await expect(page.locator('.spb-search')).toBeVisible();
    await expect(page.locator('.spb-search-more')).toHaveCount(0);
    const all = await onBoard(page);

    await search(page, n.A5);
    expect(await visible(page)).toEqual([n.A5]);
    // Requester names and tasks are not searchable here.
    await search(page, 'Call the customer back');
    expect(await visible(page)).toEqual([]);
    const attrs = await page.$$eval('.spb-card', (els) => els.map((e) => e.dataset.search).join('\n'));
    expect(attrs).not.toContain('@');
    await page.screenshot({ path: shot('client'), fullPage: true });
    await search(page, '');
    expect(await visible(page)).toEqual(all);
  });
});
