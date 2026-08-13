const { defineConfig, devices } = require('@playwright/test');

const fixtures = [
  ['short', 41731],
  ['long', 41732],
  ['complex', 41733],
  ['empty', 41734],
  ['rtl', 41735],
];
const useDocker = process.env.KUAIZ_BROWSER_DOCKER === '1';

function fixtureCommand(seed, port) {
  const serve = `php tests/browser/fixture.php /tmp/kuaiz-cms-browser-${seed} ${seed} http://127.0.0.1:${port} && KUAIZ_CMS_DATA_DIR=/tmp/kuaiz-cms-browser-${seed} php -S 0.0.0.0:${port} public/index.php`;
  if (!useDocker) return serve;
  const name = `kuaiz-cms-browser-${seed}`;
  return `docker rm --force ${name} >/dev/null 2>&1 || true; trap 'docker stop --time 1 ${name} >/dev/null 2>&1 || true' EXIT INT TERM; docker run --rm --name ${name} --publish 127.0.0.1:${port}:${port} --mount type=bind,src=${process.cwd()},dst=/cms --workdir /cms --entrypoint sh kuaiz/php-cms-host-test:apache -c "${serve}"`;
}

module.exports = defineConfig({
  testDir: './tests/browser',
  outputDir: 'artifacts/playwright-results',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['line'], ['html', {
    outputFolder: 'artifacts/playwright-report',
    open: 'never',
  }]] : 'line',
  use: {
    locale: 'zh-CN',
    timezoneId: 'Asia/Shanghai',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'desktop-chromium',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 1000 },
      },
    },
    {
      name: 'mobile-chromium',
      use: {
        ...devices['iPhone 13'],
        browserName: 'chromium',
      },
    },
  ],
  webServer: fixtures.map(([seed, port]) => ({
    command: fixtureCommand(seed, port),
    url: `http://127.0.0.1:${port}/`,
    timeout: 30_000,
    reuseExistingServer: false,
    stdout: 'pipe',
    stderr: 'pipe',
  })),
});
