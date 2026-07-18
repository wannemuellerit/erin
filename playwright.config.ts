import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './tests/Browser',
    outputDir: './storage/framework/testing/playwright',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    timeout: 30_000,
    expect: {
        timeout: 7_500,
    },
    reporter: process.env.CI
        ? [
              ['line'],
              [
                  'html',
                  {
                      outputFolder:
                          './storage/framework/testing/playwright-report',
                      open: 'never',
                  },
              ],
          ]
        : [['list']],
    use: {
        baseURL,
        testIdAttribute: 'data-test',
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        trace: process.env.CI ? 'on-first-retry' : 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: {
                    args: ['--host-resolver-rules=MAP localhost vite'],
                },
                viewport: {
                    width: 1440,
                    height: 1000,
                },
            },
        },
    ],
});
