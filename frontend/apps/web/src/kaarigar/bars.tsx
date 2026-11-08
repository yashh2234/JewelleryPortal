import { CloudOff, WifiOff } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useI18n } from './i18n'
import { onQueueChange, queued } from './offline'

export function useOnline() {
  const [online, setOnline] = useState(navigator.onLine)
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => {
      window.removeEventListener('online', on)
      window.removeEventListener('offline', off)
    }
  }, [])
  return online
}


export function OfflineBar() {
  const { t } = useI18n()
  const online = useOnline()
  const [waiting, setWaiting] = useState(0)

  useEffect(() => {
    const load = () => queued().then((q) => setWaiting(q.length))
    load()
    return onQueueChange(load)
  }, [])

  if (online && !waiting) return null
  return (
    <div className="flex items-center gap-2 bg-warning/15 px-4 py-2 text-sm text-warning">
      {online ? <CloudOff className="size-4" /> : <WifiOff className="size-4" />}
      {!online && t('offline')}
      {waiting > 0 && <span className="ml-auto font-medium">{t('queued')}: {waiting}</span>}
    </div>
  )
}

