/* Imported by the generated service worker: shows Web Push notifications and opens the app on tap. */
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'Update', body: event.data && event.data.text() }
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'Update', {
      body: data.body || '',
      tag: data.tag,
      icon: '/app/pwa-192.png',
      badge: '/app/pwa-192.png',
      data: { url: data.url || '/app/' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || '/app/'
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if ('focus' in client) {
          client.navigate(url)
          return client.focus()
        }
      }
      return self.clients.openWindow(url)
    }),
  )
})
