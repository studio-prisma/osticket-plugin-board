// Shared helpers. Credentials and ticket IDs come from the files dev/up.sh
// writes into dev/local/ (git-ignored). Nothing secret lives in the repo.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { expect } = require('@playwright/test');

const LOCAL = path.resolve(__dirname, '../../dev/local');
const COMPOSE = path.resolve(__dirname, '../../dev/docker-compose.yml');

function readEnv(file) {
  const out = {};
  if (!fs.existsSync(file)) return out;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}

const env = Object.assign(readEnv(path.join(LOCAL, 'credentials.env')), process.env);
const seedFile = path.join(LOCAL, 'seed.json');
const seed = fs.existsSync(seedFile) ? JSON.parse(fs.readFileSync(seedFile, 'utf8')) : null;

function requireSeed() {
  if (!seed) throw new Error('dev/local/seed.json missing, run dev/up.sh first');
  return seed;
}

/** Fails the test on any uncaught page error or console error. */
function watchErrors(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error') errors.push(`console: ${m.text()}`);
  });
  return errors;
}

async function clientLogin(page, email) {
  await page.goto('/login.php');
  await page.fill('input[name="luser"]', email);
  await page.fill('input[name="lpasswd"]', env.SPB_CLIENT_PASS);
  await Promise.all([page.waitForNavigation(), page.click('#clientLogin input[type="submit"]')]);
  await expect(page.locator('a[href*="logout.php"]').first()).toBeVisible();
}

async function staffLogin(page, user, pass) {
  await page.goto('/scp/login.php');
  await page.fill('input[name="userid"]', user);
  await page.fill('input[name="passwd"]', pass);
  await page.click('#login button[type="submit"]');
  // The board is the start page after login unless a spec switches it off.
  await page.waitForURL(/\/scp\/(index|tickets)\.php|\/scp\/$|\/scp\/ajax\.php\/board/);
}

/** Ticket numbers on the board: top-level cards and nested sub-tickets. */
async function boardNumbers(page) {
  // After a click, the URL changes before the new document is parsed.
  await page.locator('.spb-board').waitFor({ state: 'attached' });
  return page.$$eval('.spb-card .spb-num, .spb-child a', (els) =>
    els.map((e) => e.textContent.replace('#', '').trim()).sort());
}

async function columnOf(page, number) {
  await page.locator('.spb-board').waitFor({ state: 'attached' });
  const col = page.locator('.spb-col', { has: page.locator(`.spb-card[data-number="${number}"]`) });
  return col.getAttribute('data-status-name');
}

/**
 * Runs one SQL statement in the test database. Only for state the UI can't
 * set up quickly, e.g. forcing a password change on an agent.
 */
function sql(statement) {
  return execFileSync('docker', [
    'compose', '--env-file', path.join(LOCAL, 'credentials.env'), '-f', COMPOSE,
    'exec', '-T', 'db', 'mariadb', '-uosticket', `-p${env.SPB_DB_PASSWORD}`, 'osticket', '-e', statement,
  ], { encoding: 'utf8' });
}

module.exports = { env, seed, requireSeed, watchErrors, clientLogin, staffLogin, boardNumbers, columnOf, sql, LOCAL };
