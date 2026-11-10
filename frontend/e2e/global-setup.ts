import { execSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/** Fresh e2e database with the demo workspace before every run. */
export default function globalSetup() {
  const backend = fileURLToPath(new URL('../../backend', import.meta.url))
  const db = `${backend}/database/e2e.sqlite`
  writeFileSync(db, '')
  execSync('php artisan migrate:fresh --seed --force', {
    cwd: backend,
    stdio: 'inherit',
    env: { ...process.env, DB_DATABASE: db, QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log' },
  })
}
