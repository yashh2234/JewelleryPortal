import { api, Card, grams, InstallPrompt, NotificationBell, PushButton, rupees, ThemeToggle, useTenant } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { BookOpen, Briefcase, ChevronRight, IndianRupee, Inbox, LogOut, PackageCheck, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link, useNavigate } from 'react-router'
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
  is_head: boolean
  head: { id: number; name: string } | null
}

export default function Home({ me }: { me: KaarigarMe }) {
  const tenant = useTenant()
  const { t } = useI18n()
  const logout = useLogout()
  const navigate = useNavigate()
  const home = useQuery({
    queryKey: ['kaarigar', 'home'],
    queryFn: () => api.get<KaarigarHome>('/api/kaarigar/v1/home'),
    meta: { persist: true },
    refetchInterval: 60_000,
  })
  const h = home.data

  return (
    <div className="mx-auto min-h-dvh max-w-md pb-8">
      <header className="bg-brand-sheen flex items-center gap-2 rounded-b-3xl px-5 pb-8 pt-[max(1.25rem,env(safe-area-inset-top))] text-brand-foreground shadow-raised">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium opacity-75">{tenant.name}</p>
          <p className="truncate font-display text-2xl font-semibold">{t('hello')}, {me.name}</p>
        </div>
        <NotificationBell base="/api/kaarigar/v1" onBrand onOpen={(url) => navigate(url)} />
        <ThemeToggle onBrand />
        <LangToggle />
      </header>
      <OfflineBar />

      <div className="-mt-5 grid gap-3 px-4">
        {!!h?.new_issues && (
          <Link to="/kaarigar/issues" className="animate-fade-up flex items-center gap-3 rounded-2xl border border-brand/30 bg-card p-4 text-lg font-semibold text-brand shadow-raised">
            <span className="grid size-11 place-items-center rounded-xl bg-brand text-brand-foreground"><PackageCheck className="size-6" /></span> <span className="flex-1">{t('newIssues')}: {h.new_issues}</span><ChevronRight className="size-5" />
          </Link>
        )}

        <Link to="/kaarigar/khata">
          <Card className="relative overflow-hidden bg-gradient-to-br from-brand/[0.10] via-card to-card p-5 shadow-raised">
            <p className="text-muted-foreground">{t('goldInHand')} ({t('fine')})</p>
            <p className="tabular mt-1 text-4xl font-bold tracking-tight">{grams(h?.gold.fine ?? '0.000')}</p>
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
          {h?.is_head && <Tile to="/kaarigar/team" icon={<Users />} label="team" />}
          <Tile to="/kaarigar/requests" icon={<Inbox />} label="requests" />
          <Tile to="/kaarigar/earnings" icon={<IndianRupee />} label="earnings" />
        </div>

        <div className="mt-2 grid gap-2"><InstallPrompt appName={tenant.name} /><PushButton label="Notify me of new gold · सूचना चालू करें" className="h-12 w-full text-base" /></div>

        <button className="mt-4 flex h-12 items-center justify-center gap-2 rounded-xl text-muted-foreground transition-colors hover:bg-muted" onClick={() => logout.mutate()}>
          <LogOut className="size-4" /> {t('logout')}
        </button>
      </div>
    </div>
  )
}

function Tile({ to, icon, label, badge, soon }: { to?: string; icon: ReactNode; label: Key; badge?: number; soon?: boolean }) {
  const { t } = useI18n()
  const body = (
    <div className={`relative flex h-32 flex-col items-center justify-center gap-2.5 rounded-2xl border bg-card text-lg font-medium shadow-card transition-all active:scale-[0.97] ${soon ? 'opacity-60' : ''}`}>
      <span className="grid size-14 place-items-center rounded-2xl bg-brand/10 text-brand [&_svg]:size-7">{icon}</span>
      {t(label)}
      {soon && <span className="text-xs font-normal text-muted-foreground">{t('comingSoon')}</span>}
      {!!badge && <span className="tabular absolute right-2.5 top-2.5 grid min-w-6 place-items-center rounded-full bg-brand px-1.5 text-sm leading-6 text-brand-foreground">{badge}</span>}
    </div>
  )
  return to ? <Link to={to}>{body}</Link> : body
}
