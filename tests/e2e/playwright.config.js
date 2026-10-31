// @ts-check
const { defineConfig } = require('@playwright/test');
const { env } = require('./support');

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js$/,
  // The specs share one seeded database and change ticket statuses, so they
  // run in file order, one worker.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: '../../dev/local/e2e-report' }]],
  outputDir: '../../dev/local/e2e-results',
  use: {
    baseURL: `http://127.0.0.1:${env.SPB_HTTP_PORT || '8088'}`,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    viewport: { width: 1400, height: 900 },
  },
});
