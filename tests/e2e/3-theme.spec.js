// Optional: runs when a theme is mounted through dev/local/compose.override.yml
// (see dev/README.md). Checks that the board renders inside the themed chrome
// and keeps its own layout, and saves screenshots for a visual check.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { env, watchErrors, clientLogin, staffLogin, LOCAL } = require('./support');

const themed = fs.existsSync(path.join(LOCAL, 'compose.override.yml'));
test.skip(!themed, 'no theme mounted (dev/local/compose.override.yml missing)');

test('client board inside the themed portal', async ({ page }) => {
  const errors = watchErrors(page);
  await clientLogin(page, 'alice@acme.example');
  await page.goto('/ajax.php/board');
  await expect(page.locator('link[href*="assets/custom/css/"]').first()).toBeAttached();
  const cols = page.locator('.spb-col');
  expect(await cols.count()).toBeGreaterThan(1);
  const [a, b] = [await cols.nth(0).boundingBox(), await cols.nth(1).boundingBox()];
  expect(b.x).toBeGreaterThan(a.x); // columns side by side, not collapsed by theme CSS
  await page.screenshot({ path: path.join(LOCAL, 'screens', 'theme-client.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: path.join(LOCAL, 'screens', 'theme-client-mobile.png'), fullPage: true });
  expect(errors).toEqual([]);
});

test('agent board inside the themed panel', async ({ page }) => {
  const errors = watchErrors(page);
  await staffLogin(page, env.SPB_ADMIN_USER, env.SPB_ADMIN_PASS);
  await page.goto('/scp/ajax.php/board');
  await expect(page.locator('.spb-board')).toBeVisible();
  await page.screenshot({ path: path.join(LOCAL, 'screens', 'theme-staff.png'), fullPage: true });
  expect(errors).toEqual([]);
});
