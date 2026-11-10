import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'

const backend = fileURLToPath(new URL('../backend', import.meta.url))

/**
 * End-to-end checks of every sign-in, screen and sign-out, in a real browser.
 * Uses its own SQLite database (backend/database/e2e.sqlite), reseeded on each run,
 * so local data is never touched.  Run: npm run e2e
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: 'http://localhost:5175',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    serviceWorkers: 'block',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: 'php artisan serve --port=8001',
      cwd: backend,
      url: 'http://127.0.0.1:8001/up',
      env: { DB_DATABASE: `${backend}/database/e2e.sqlite`, KAARIGAR_EXPOSE_OTP: 'true', APP_ENV: 'local', MAIL_MAILER: 'log', QUEUE_CONNECTION: 'sync' },
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: 'npm run dev',
      url: 'http://localhost:5175',
      env: { VITE_BACKEND_URL: 'http://127.0.0.1:8001', VITE_TENANT: 'acme' },
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
})
