import { useEffect, useState } from 'react'
import { useRegisterSW } from 'virtual:pwa-register/react'
import { Button } from './button'

/** "Update available" bar so nobody is stuck on an old build. */
export function UpdatePrompt() {
  const {
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegisteredSW(_url, registration) {
      // Check for a new build every 30 minutes while the app is open.
      if (registration) setInterval(() => registration.update(), 30 * 60 * 1000)
    },
  })

  if (!needRefresh) return null

  return (
    <div className="fixed inset-x-3 bottom-3 z-50 mx-auto flex max-w-md items-center justify-between gap-3 rounded-xl border bg-elevated p-3 pl-4 shadow-pop animate-fade-up">
      <span className="text-sm">A new version is available.</span>
      <div className="flex gap-2">
        <Button size="sm" variant="ghost" onClick={() => setNeedRefresh(false)}>Later</Button>
        <Button size="sm" onClick={() => updateServiceWorker(true)}>Update</Button>
      </div>
    </div>
  )
}

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/**
 * Android/Chrome: custom "Install app" button. iPhone: a short Add-to-Home-Screen hint,
 * since iOS has no install prompt (and push only works once installed).
 */
export function InstallPrompt({ appName }: { appName: string }) {
  const [deferred, setDeferred] = useState<BeforeInstallPromptEvent | null>(null)
  const [dismissed, setDismissed] = useState(() => {
    try {
      return localStorage.getItem('install-dismissed') === '1'
    } catch {
      return false
    }
  })

  const standalone = typeof window !== 'undefined'
    && (window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone)
  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent)

  useEffect(() => {
    const handler = (e: Event) => {
      e.preventDefault()
      setDeferred(e as BeforeInstallPromptEvent)
    }
    window.addEventListener('beforeinstallprompt', handler)
    return () => window.removeEventListener('beforeinstallprompt', handler)
  }, [])

  const dismiss = () => {
    setDismissed(true)
    try {
      localStorage.setItem('install-dismissed', '1')
    } catch {
      /* ignore */
    }
  }

  if (standalone || dismissed || (!deferred && !isIos)) return null

  return (
    <div className="flex items-center justify-between gap-3 rounded-xl border bg-card p-3 text-sm shadow-card">
      {deferred ? (
        <>
          <span>Install {appName} on this phone for quick access.</span>
          <div className="flex gap-2">
            <Button size="sm" variant="ghost" onClick={dismiss}>Not now</Button>
            <Button size="sm" onClick={async () => { await deferred.prompt(); setDeferred(null) }}>Install</Button>
          </div>
        </>
      ) : (
        <>
          <span>To install: tap <b>Share</b> then <b>Add to Home Screen</b>.</span>
          <Button size="sm" variant="ghost" onClick={dismiss}>OK</Button>
        </>
      )}
    </div>
  )
}
