import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/Browser',
  testMatch: 'document-editor.spec.ts',
  outputDir: './output/playwright/e2e-results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 30_000,
  expect: { timeout: 8_000 },
  reporter: 'line',
  use: {
    ...devices['Desktop Chrome'],
    channel: 'chrome',
    baseURL: process.env.SENA_E2E_BASE_URL || 'http://127.0.0.1:8765',
    viewport: { width: 1440, height: 1000 },
    headless: true,
    acceptDownloads: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
})
