import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { existsSync, renameSync, rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { defineConfig, loadEnv, type Plugin, type UserConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

const publicDir = fileURLToPath(new URL('../backend/public', import.meta.url))

/**
 * Build straight into Laravel's public folder (index.php lives there, so it is
 * never emptied): assets/ is replaced on each build, and the page is written as
 * app.html — Laravel serves it for every workspace URL with the tenant injected,
 * and Apache never serves a stray index.html on the bare domain.
 */
function intoLaravelPublic(): Plugin {
  return {
    name: 'into-laravel-public',
    apply: 'build',
    buildStart() {
      for (const old of ['assets', 'app', 'sw.js', 'app.html']) {
        rmSync(`${publicDir}/${old}`, { recursive: true, force: true })
      }
    },
    writeBundle() {
      if (existsSync(`${publicDir}/index.html`)) renameSync(`${publicDir}/index.html`, `${publicDir}/app.html`)
    },
  }
}

/**
 * The app lives at the root: acme.yourapp.in/ (staff, kaarigar and retailer areas
 * behind one login). In dev it runs on http://localhost:5175/ and /api, /sanctum,
 * /storage, /manifest.webmanifest and /khata are proxied to `php artisan serve`;
 * VITE_TENANT picks the workspace (no subdomains needed locally).
 */
export function defineApp(opts: { port: number }) {
  return defineConfig(({ mode }): UserConfig => {
    const env = loadEnv(mode, fileURLToPath(new URL('.', import.meta.url)), '')
    const backend = env.VITE_BACKEND_URL || 'http://127.0.0.1:8000'
    const tenant = env.VITE_TENANT || 'acme'

    const proxy = Object.fromEntries(
      ['/api', '/sanctum', '/storage', '/manifest.webmanifest', '/khata'].map((p) => [p, { target: backend, headers: { 'X-Tenant': tenant } }]),
    )

    return {
      base: '/',
      envDir: fileURLToPath(new URL('.', import.meta.url)),
      plugins: [
        react(),
        tailwindcss(),
        intoLaravelPublic(),
        VitePWA({
          registerType: 'prompt',
          injectRegister: false,
          manifest: false,   // served per tenant by Laravel at /manifest.webmanifest
          includeAssets: ['pwa-192.png', 'pwa-512.png', 'favicon.svg', 'push-sw.js'],
          workbox: {
            // Web Push handler (public/push-sw.js) runs inside the generated worker.
            importScripts: ['push-sw.js'],
            navigateFallback: '/app.html',
            navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/storage\//, /^\/manifest/, /^\/khata\//, /^\/up$/],
            // Only the app's own files: public/ also holds index.php and uploads.
            globPatterns: ['app.html', 'assets/**/*.{js,css,woff2}', '*.{png,svg}', 'push-sw.js'],
            globIgnores: ['storage/**', 'vendor/**'],
            cleanupOutdatedCaches: true,
            runtimeCaching: [
              {
                // Design photos and uploads: show cached copies offline.
                urlPattern: ({ url }) => url.pathname.startsWith('/storage/'),
                handler: 'CacheFirst',
                options: {
                  cacheName: 'uploads',
                  expiration: { maxEntries: 400, maxAgeSeconds: 60 * 60 * 24 * 30 },
                  cacheableResponse: { statuses: [0, 200] },
                },
              },
              {
                // Inter + Fraunces from Google Fonts, so the type stays right offline.
                urlPattern: ({ url }) => url.origin === 'https://fonts.googleapis.com' || url.origin === 'https://fonts.gstatic.com',
                handler: 'CacheFirst',
                options: {
                  cacheName: 'fonts',
                  expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 365 },
                  cacheableResponse: { statuses: [0, 200] },
                },
              },
              {
                urlPattern: ({ url }) => url.pathname.startsWith('/manifest'),
                handler: 'StaleWhileRevalidate',
                options: { cacheName: 'manifest' },
              },
            ],
          },
        }),
      ],
      server: { port: opts.port, strictPort: true, proxy },
      preview: { port: opts.port },
      build: {
        outDir: publicDir,
        emptyOutDir: false,
        assetsDir: 'assets',
        sourcemap: false,
      },
    }
  })
}
