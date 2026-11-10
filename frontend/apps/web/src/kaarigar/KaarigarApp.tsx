import { ThemeToggle, useTenant } from '@b2b/ui'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useEffect, type ReactNode } from 'react'
import { Link, Navigate, Route, Routes } from 'react-router'
import { useSession } from '../session'
import Home from './Home'
import { I18nProvider, LangToggle, useI18n, type Key } from './i18n'
import { OfflineBar } from './bars'
import { flush } from './offline'
import { EarningsScreen, IssuesScreen, JobsScreen, KhataScreen, RequestsScreen, ReturnScreen } from './screens'

/** Kaarigar app, mounted at /kaarigar/*. Hindi / English, big touch targets, works offline for reading. */
export default function KaarigarApp() {
  const { data } = useSession()
  if (data?.type !== 'kaarigar') return null

  return (
    <I18nProvider initial={data.user.language}>
      <Sync />
      <Routes>
        <Route index element={<Home me={data.user} />} />
        <Route path="issues" element={<Screen title="newIssues"><IssuesScreen /></Screen>} />
        <Route path="jobs" element={<Screen title="myJobs"><JobsScreen /></Screen>} />
        <Route path="jobs/:id/return" element={<Screen title="returnWork" back="/kaarigar/jobs"><ReturnScreen /></Screen>} />
        <Route path="khata" element={<Screen title="khata"><KhataScreen /></Screen>} />
        <Route path="earnings" element={<Screen title="earnings"><EarningsScreen /></Screen>} />
        <Route path="requests" element={<Screen title="requests"><RequestsScreen /></Screen>} />
        <Route path="*" element={<Navigate to="/kaarigar" replace />} />
      </Routes>
    </I18nProvider>
  )
}

/** Sends queued actions whenever the phone is back online. */
function Sync() {
  const qc = useQueryClient()
  useEffect(() => {
    const run = () => flush().then(() => qc.invalidateQueries())
    run()
    window.addEventListener('online', run)
    return () => window.removeEventListener('online', run)
  }, [qc])
  return null
}

function Screen({ title, back = '/kaarigar', children }: { title: Key; back?: string; children: ReactNode }) {
  const { t } = useI18n()
  const tenant = useTenant()
  return (
    <div className="mx-auto min-h-dvh max-w-md pb-10">
      <header className="bg-brand-sheen sticky top-0 z-10 flex items-center gap-3 px-4 pb-4 pt-[max(1rem,env(safe-area-inset-top))] text-brand-foreground shadow-raised">
        <Link to={back} aria-label={t('back')} className="-ml-1 grid size-10 place-items-center rounded-full transition-colors hover:bg-white/15 active:bg-white/25"><ArrowLeft className="size-6" /></Link>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium opacity-75">{tenant.name}</p>
          <h1 className="truncate text-xl font-semibold">{t(title)}</h1>
        </div>
        <ThemeToggle onBrand />
        <LangToggle />
      </header>
      <OfflineBar />
      <div className="p-4">{children}</div>
    </div>
  )
}
