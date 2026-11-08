import { api, Card, grams, InstallPrompt, PushButton, rupees, useTenant } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { BookOpen, Briefcase, IndianRupee, Inbox, LogOut, PackageCheck } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { useLogout } from '../session'
import { LangToggle, useI18n, type Key } from './i18n'
import { OfflineBar } from './bars'
import type { KaarigarMe } from './me'

export interface KaarigarHome {
  gold: { fine: string; gross: string }
  jobs_due_today: number
  open_jobs: number
  new_issues: number
  labour_this_week: string
}

export default function Home({ me }: { me: KaarigarMe }) {
  const tenant = useTenant()
  const { t } = useI18n()
  const logout = useLogout()
  const home = useQuery({
    queryKey: ['kaarigar', 'home'],
    queryFn: () => api.get<KaarigarHome>('/api/kaarigar/v1/home'),
    meta: { persist: true },
    refetchInterval: 60_000,
  })
  const h = home.data

  return (
    <div className="mx-auto min-h-dvh max-w-md pb-8">
      <header className="flex items-center gap-3 bg-brand p-4 text-brand-foreground">
        <div className="min-w-0 flex-1">
          <p className="text-sm opacity-80">{tenant.name}</p>
          <p className="truncate text-xl font-semibold">{t('hello')}, {me.name}</p>
        </div>
        <LangToggle />
      </header>
      <OfflineBar />

      <div className="grid gap-3 p-4">
        {!!h?.new_issues && (
          <Link to="/kaarigar/issues" className="flex items-center gap-3 rounded-lg bg-brand/10 p-4 text-lg font-semibold text-brand">
            <PackageCheck className="size-7" /> {t('newIssues')}: {h.new_issues}
          </Link>
        )}

        <Link to="/kaarigar/khata">
          <Card className="p-5">
            <p className="text-muted-foreground">{t('goldInHand')} ({t('fine')})</p>
            <p className="tabular mt-1 text-4xl font-bold">{grams(h?.gold.fine ?? '0.000')}</p>
            <p className="tabular mt-1 text-muted-foreground">{t('gross')}: {grams(h?.gold.gross ?? '0.000')}</p>
          </Card>
        </Link>

        <div className="grid grid-cols-2 gap-3">
          <Card className="p-4">
            <p className="text-sm text-muted-foreground">{t('jobsToday')}</p>
            <p className="tabular mt-1 text-3xl font-semibold">{h?.jobs_due_today ?? 0}</p>
          </Card>
          <Card className="p-4">
            <p className="text-sm text-muted-foreground">{t('labourWeek')}</p>
            <p className="tabular mt-1 text-2xl font-semibold">{rupees(h?.labour_this_week ?? 0)}</p>
          </Card>
        </div>

        <div className="mt-2 grid grid-cols-2 gap-3">
          <Tile to="/kaarigar/jobs" icon={<Briefcase />} label="myJobs" badge={h?.open_jobs} />
          <Tile to="/kaarigar/khata" icon={<BookOpen />} label="khata" />
          <Tile to="/kaarigar/requests" icon={<Inbox />} label="requests" />
          <Tile to="/kaarigar/earnings" icon={<IndianRupee />} label="earnings" />
        </div>

        <div className="mt-2 grid gap-2"><InstallPrompt appName={tenant.name} /><PushButton label="Notify me of new gold · सूचना चालू करें" className="h-12 w-full text-base" /></div>

        <button className="mt-4 flex items-center justify-center gap-2 text-muted-foreground" onClick={() => logout.mutate()}>
          <LogOut className="size-4" /> {t('logout')}
        </button>
      </div>
    </div>
  )
}

function Tile({ to, icon, label, badge, soon }: { to?: string; icon: ReactNode; label: Key; badge?: number; soon?: boolean }) {
  const { t } = useI18n()
  const body = (
    <div className={`relative flex h-28 flex-col items-center justify-center gap-2 rounded-lg border bg-card text-lg font-medium [&_svg]:size-8 [&_svg]:text-brand ${soon ? 'opacity-60' : ''}`}>
      {icon}
      {t(label)}
      {soon && <span className="text-xs font-normal text-muted-foreground">{t('comingSoon')}</span>}
      {!!badge && <span className="absolute right-2 top-2 rounded-full bg-brand px-2 text-sm text-brand-foreground">{badge}</span>}
    </div>
  )
  return to ? <Link to={to}>{body}</Link> : body
}
