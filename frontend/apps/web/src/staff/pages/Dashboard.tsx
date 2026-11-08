import { api, Badge, Card, grams, rupees } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { useCan, useMe } from '../auth'
import { PageTitle } from '../layout'
import type { Summary } from '../metal/types'
import type { GoldRate, Retailer } from '../types'
import type { Paginated } from '@b2b/ui'

/** Owner dashboard: where the gold is right now, today's movements, and what needs attention. */
export default function Dashboard() {
  const { data: me } = useMe()
  const can = useCan()
  const metal = can('kaarigars.view') || can('metal.issue') || can('reports.view')

  const summary = useQuery({
    queryKey: ['metal', 'summary'],
    queryFn: () => api.get<Summary>('/api/v1/metal/summary'),
    enabled: metal,
    refetchInterval: 60_000,
  })
  const rates = useQuery({ queryKey: ['gold-rates'], queryFn: () => api.get<{ data: GoldRate[] }>('/api/v1/gold-rates') })
  const pendingRetailers = useQuery({
    queryKey: ['retailers', 'pending'],
    queryFn: () => api.get<Paginated<Retailer>>('/api/v1/retailers?status=pending&per_page=1'),
    enabled: can('retailers.manage'),
  })

  const s = summary.data
  const held = s ? (['vault', 'kaarigar', 'wo', 'finished', 'scrap', 'ahc'] as const).reduce((sum, k) => sum + Math.round(Number(s.gold[k]) * 1000), 0) / 1000 : 0

  return (
    <>
      <PageTitle title={`Welcome, ${me?.name ?? ''}`} />

      {s && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Tile label="In vault" value={s.gold.vault} to="/staff/metal/vault" />
            <Tile label="With kaarigars" value={s.gold.kaarigar} to="/staff/kaarigars" />
            <Tile label="Finished goods" value={s.gold.finished} to="/staff/metal/vault" />
            <Tile label="Total gold held" value={held.toFixed(3)} hint={`scrap ${s.gold.scrap} · wastage to date ${s.gold.loss}`} />
          </div>

          <div className="mt-4 grid gap-4 lg:grid-cols-3">
            <Card className="p-4">
              <h2 className="font-semibold">Today</h2>
              <div className="mt-3 grid gap-2 text-sm">
                <Row label={`Issued (${s.today.issue.count})`} value={grams(s.today.issue.fine)} />
                <Row label={`Received (${s.today.receive.count})`} value={grams(s.today.receive.fine)} />
                <Row label="Open job cards" value={<Link className="text-brand" to="/staff/metal/job-cards">{s.open_job_cards}</Link>} />
                <Row label="Books closed up to" value={<Link className="text-brand" to="/staff/metal/daybook">{s.last_closed ?? 'never'}</Link>} />
              </div>
            </Card>

            <Card className="p-4">
              <h2 className="flex items-center gap-2 font-semibold"><AlertTriangle className="size-4 text-warning" /> Needs attention</h2>
              <div className="mt-3 grid gap-2 text-sm">
                <Alarm n={s.wastage_alerts} label="Wastage above limit" to="/staff/metal/job-cards?f=alerts" />
                <Alarm n={s.overdue_job_cards} label="Overdue job cards" to="/staff/metal/job-cards" />
                <Alarm n={s.pending_requests} label="Kaarigar requests" to="/staff/metal/requests" />
                <Alarm n={s.disputed_issues} label="Disputed issues" to="/staff/metal/transactions" />
                <Alarm n={s.unconfirmed_issues} label="Issues not accepted in 24 h" to="/staff/metal/transactions" />
                {pendingRetailers.data && <Alarm n={pendingRetailers.data.total} label="Retailers to approve" to="/staff/retailers" />}
              </div>
            </Card>

            <RatesCard rates={rates.data?.data} />
          </div>
        </>
      )}

      {!metal && <RatesCard rates={rates.data?.data} />}
    </>
  )
}

function Tile({ label, value, to, hint }: { label: string; value: string; to?: string; hint?: string }) {
  const body = (
    <Card className="p-4 transition hover:shadow-md">
      <p className="text-sm text-muted-foreground">{label} <span className="text-xs">(fine)</span></p>
      <p className="tabular mt-1 text-2xl font-semibold">{grams(value)}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return <div className="flex justify-between"><span>{label}</span><span className="tabular font-medium">{value}</span></div>
}

function Alarm({ n, label, to }: { n: number; label: string; to: string }) {
  return (
    <Link to={to} className="flex justify-between hover:underline">
      <span>{label}</span>
      <Badge tone={n ? 'warning' : 'neutral'}>{n}</Badge>
    </Link>
  )
}

function RatesCard({ rates }: { rates: GoldRate[] | undefined }) {
  return (
    <Card className="p-4">
      <div className="flex items-center justify-between">
        <h2 className="font-semibold">Gold rate</h2>
        <Link to="/staff/settings/gold-rates" className="text-sm text-brand hover:underline">Update</Link>
      </div>
      <div className="mt-3 grid gap-2">
        {rates?.length ? rates.map((r) => (
          <div key={r.id} className="flex justify-between text-sm">
            <span>{r.karat.label}</span>
            <span className="tabular font-medium">{rupees(r.rate_per_g)}/g <span className="text-xs text-muted-foreground">({r.date})</span></span>
          </div>
        )) : <p className="text-sm text-muted-foreground">No rate set yet.</p>}
      </div>
    </Card>
  )
}
