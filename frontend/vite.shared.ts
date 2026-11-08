import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'node:url'
import { defineConfig, loadEnv, type UserConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

/**
 * One config shape for all three apps.
 *
 *  - Built into Laravel's public folder (public/app),
 *    so cPanel only ever receives static files; Node never runs on the server.
 *  - One app (staff, kaarigar and retailer areas behind one login), scope /app/.
 *    The manifest is served per tenant by Laravel (/manifest/{app}.webmanifest),
 *    so the plugin generates only the service worker.
 *  - In dev, /api, /sanctum, /storage and /manifest are proxied to `php artisan serve`,
 *    and VITE_TENANT picks the workspace (no subdomains needed locally).
 */
export function defineApp(opts: { app: 'app'; port: number }) {
  return defineConfig(({ mode }): UserConfig => {
    const env = loadEnv(mode, fileURLToPath(new URL('.', import.meta.url)), '')
    const backend = env.VITE_BACKEND_URL || 'http://127.0.0.1:8000'
    const tenant = env.VITE_TENANT || 'acme'
    const base = `/${opts.app}/`

    const proxy = Object.fromEntries(
      ['/api', '/sanctum', '/storage', '/manifest'].map((p) => [p, { target: backend, headers: { 'X-Tenant': tenant } }]),
    )

    return {
      base,
      envDir: fileURLToPath(new URL('.', import.meta.url)),
      plugins: [
        react(),
        tailwindcss(),
        VitePWA({
          registerType: 'prompt',
          injectRegister: false,
          manifest: false,
          includeAssets: ['pwa-192.png', 'pwa-512.png', 'favicon.svg', 'push-sw.js'],
          workbox: {
            // Web Push handler (public/push-sw.js) runs inside the generated worker.
            importScripts: ['push-sw.js'],
            navigateFallback: `${base}index.html`,
            navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/storage\//, /^\/manifest\//],
            globPatterns: ['**/*.{js,css,html,svg,png,webp,woff2}'],
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
                urlPattern: ({ url }) => url.pathname.startsWith('/manifest/'),
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
        outDir: fileURLToPath(new URL(`../backend/public/${opts.app}`, import.meta.url)),
        emptyOutDir: true,
        sourcemap: false,
      },
    }
  })
}
