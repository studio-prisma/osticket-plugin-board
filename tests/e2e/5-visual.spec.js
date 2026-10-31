// Visual walkthrough: screenshots of every board state into dev/local/screens/
// (git-ignored) plus layout invariants that a screenshot alone wouldn't
// catch reliably. Runs last; it doesn't depend on ticket statuses.
const { test, expect } = require('@playwright/test');
const path = require('path');
const { env, requireSeed, watchErrors, staffLogin, clientLogin, LOCAL } = require('./support');

const seed = requireSeed();
const n = seed.numbers;
const shot = (name) => path.join(LOCAL, 'screens', `visual-${name}.png`);

async function noPageOverflow(page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
}

test.describe('agent board', () => {
  test.beforeEach(async ({ page }) => {
    await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
  });

  test('board, hover, quick view, toast', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/scp/ajax.php/board?queue=all');
    await page.locator('.spb-board').waitFor();
    await noPageOverflow(page);

    // Columns side by side, cards inside their column.
    const cols = page.locator('.spb-col');
    const a = await cols.nth(0).boundingBox();
    const b = await cols.nth(1).boundingBox();
    expect(b.x).toBeGreaterThan(a.x + a.width - 1);
    const card = page.locator('.spb-card').first();
    const cb = await card.boundingBox();
    expect(cb.x).toBeGreaterThanOrEqual(a.x);
    expect(cb.x + cb.width).toBeLessThanOrEqual(a.x + a.width + 1);
    await page.screenshot({ path: shot('staff-board'), fullPage: true });

    await card.hover();
    await page.screenshot({ path: shot('staff-hover'), clip: { x: a.x - 8, y: a.y - 8, width: a.width + 16, height: 360 } });

    // Children expanded.
    await page.locator(`.spb-card[data-number="${n.A1}"] .spb-children summary`).click();
    await page.screenshot({ path: shot('staff-children'), clip: await page.locator(`.spb-card[data-number="${n.A1}"]`).boundingBox() });

    // Quick view slides in and stays inside the viewport.
    await page.locator(`.spb-card[data-number="${n.A1}"] .spb-subject`).click();
    const drawer = page.locator('.spb-drawer');
    await expect(drawer).toHaveClass(/spb-open/);
    await expect(page.locator('.spb-panel-body')).toBeVisible();
    await page.waitForTimeout(450); // let the slide settle for the screenshot
    const vp = page.viewportSize();
    const db = await drawer.boundingBox();
    expect(db.x).toBeGreaterThanOrEqual(0);
    expect(db.x + db.width).toBeLessThanOrEqual(vp.width + 1);
    await page.screenshot({ path: shot('staff-quickview') });

    // Closing slides out to the right, then hides.
    await page.locator('.spb-drawer-close').click();
    await expect(drawer).not.toHaveClass(/spb-open/);
    await expect(drawer).toBeHidden();

    // Toast appears after a move and goes away again.
    await page.locator(`.spb-card[data-number="${n.G1}"]`).focus();
    await page.keyboard.press('Alt+ArrowRight');
    const toast = page.locator('.spb-status');
    await expect(toast).toBeVisible();
    await expect(page.locator(`.spb-card[data-number="${n.G1}"]`)).toHaveClass(/spb-landed/);
    await page.screenshot({ path: shot('staff-moved') });
    await expect(toast).toBeHidden({ timeout: 6000 });
    // and back, so the state stays as it was
    await page.locator(`.spb-card[data-number="${n.G1}"]`).focus();
    await page.keyboard.press('Alt+ArrowLeft');
    await expect(page.locator(`.spb-col[data-status-name="Open"] .spb-card[data-number="${n.G1}"]`)).toHaveCount(1);

    expect(errors).toEqual([]);
  });

  test('queue filter and the drop target while dragging', async ({ page }) => {
    await page.goto('/scp/ajax.php/board?queue=all');
    await Promise.all([page.waitForURL(/queue=\d+/), page.selectOption('#spb-queue', { label: 'Acme tickets' })]);
    await page.screenshot({ path: shot('staff-filtered'), fullPage: true });

    // Hover state of a column while a card is dragged over it.
    await page.goto('/scp/ajax.php/board?queue=all');
    const src = page.locator('.spb-card[draggable="true"]').first();
    const target = page.locator('.spb-col[data-status-name="In Progress"]');
    const s = await src.boundingBox();
    const t = await target.boundingBox();
    await page.mouse.move(s.x + 20, s.y + 20);
    await page.mouse.down();
    await page.mouse.move(t.x + 40, t.y + 60, { steps: 8 });
    await page.screenshot({ path: shot('staff-dragging') });
    await page.keyboard.press('Escape');
    await page.mouse.up();
  });

  test('narrow window: columns stack, quick view takes the width', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/scp/ajax.php/board?queue=all');
    const cols = page.locator('.spb-col');
    const a = await cols.nth(0).boundingBox();
    const b = await cols.nth(1).boundingBox();
    expect(b.y).toBeGreaterThan(a.y);
    await page.screenshot({ path: shot('staff-mobile'), fullPage: true });
    await page.locator('.spb-card .spb-subject').first().click();
    await expect(page.locator('.spb-panel-body')).toBeVisible();
    await page.waitForTimeout(450);
    const db = await page.locator('.spb-drawer').boundingBox();
    expect(db.width).toBeLessThanOrEqual(391);
    await page.screenshot({ path: shot('staff-mobile-quickview') });
  });
});

test.describe('client board', () => {
  test('desktop and phone', async ({ page }) => {
    const errors = watchErrors(page);
    await clientLogin(page, 'alice@acme.example');
    await page.goto('/ajax.php/board');
    await noPageOverflow(page);
    await page.screenshot({ path: shot('client-board'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    // The stock client portal has a fixed-width layout, so the page itself
    // scrolls sideways on a phone whatever the board does (a responsive theme
    // fixes that). What the board owes: columns stacked, nothing wider than
    // the space the portal gives it.
    const fit = await page.evaluate(() => {
      const board = document.querySelector('.spb-board');
      const cols = [...board.querySelectorAll('.spb-col')].map((c) => c.getBoundingClientRect());
      return {
        inner: board.scrollWidth - board.clientWidth,
        stacked: cols.every((r, i) => i === 0 || r.top > cols[i - 1].top),
      };
    });
    expect(fit.inner).toBeLessThanOrEqual(1);
    expect(fit.stacked).toBe(true);
    await page.screenshot({ path: shot('client-mobile'), fullPage: true });
    expect(errors).toEqual([]);
  });
});
