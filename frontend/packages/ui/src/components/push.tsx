import { Bell } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { Button } from './button'

function urlBase64ToUint8Array(base64: string) {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}

const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/**
 * "Turn on notifications" for this device. On iPhone this only works once the app
 * is installed to the home screen (an iOS rule).
 */
export function PushButton({ label = 'Turn on notifications', className }: { label?: string; className?: string }) {
  const [state, setState] = useState<'unknown' | 'on' | 'off' | 'denied' | 'unsupported' | 'busy'>('unknown')

  useEffect(() => {
    if (!supported()) return setState('unsupported')
    if (Notification.permission === 'denied') return setState('denied')
    navigator.serviceWorker.ready
      .then((reg) => reg.pushManager.getSubscription())
      .then((sub) => setState(sub ? 'on' : 'off'))
      .catch(() => setState('off'))
  }, [])

  const enable = async () => {
    setState('busy')
    try {
      const { public_key } = await api.get<{ public_key: string | null }>('/api/push/key')
      if (!public_key) return setState('unsupported')
      if ((await Notification.requestPermission()) !== 'granted') return setState('denied')
      const reg = await navigator.serviceWorker.ready
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(public_key) })
      await api.post('/api/push-subscriptions', sub.toJSON())
      setState('on')
    } catch {
      setState('off')
    }
  }

  if (state !== 'off' && state !== 'busy') return null
  return (
    <Button type="button" variant="outline" size="sm" className={className} onClick={enable} loading={state === 'busy'}>
      <Bell /> {label}
    </Button>
  )
}
