import { api, Badge, BarChart, Card, cn, grams, HBars, Meter, rupees, type Paginated } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import {
  AlertTriangle, ArrowUpRight, CheckCircle2, ClipboardCheck, Coins, Factory, Hammer, IndianRupee, Inbox, Package, Truck, TrendingUp, Vault, Wallet,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { Link, useNavigate } from 'react-router'
import { useCan, useMe } from '../auth'
import { PageTitle } from '../layout'
import type { Summary } from '../metal/types'
import { ORDER_STATUS, type OrderStatus } from '../production/types'
import type { GoldRate, Retailer } from '../types'

interface Dash {
  orders: { total: number; new: number; awaiting_approval: number; in_production: number; pending: number; completed: number; ready_for_dispatch: number; rework: number; approved_without_wo: number; overdue: number } | null
  gold: { total_stock: string; vault: string; with_kaarigars: string; in_production: string; received_this_month: string; finished: string; scrap: string; wastage_to_date: string; pending_reconciliation: string } | null
  finished: { pieces: number; ready: number; qc_pending: number } | null
  money: { outstanding: string; month_sales: string; last_month_sales: string } | null
  efficiency: { days: number; completed: number; on_time_pct: number | null; avg_days: number | null; rework_pct: number | null; wastage_pct: number | null; allowed_wastage_pct: number | null } | null
  charts: {
    months: { month: string; sales: number | null; orders: number; dispatched_pieces: number; wo_completed: number; pieces_finished: number; fine_finished: number }[]
    gold_by_location: { location: string; fine: number }[]
    orders_by_status: { status: OrderStatus; count: number }[]
  }
}

const monthLabel = (m: string) => new Date(m + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'short' })
const lakh = (v: number) => (v >= 1e7 ? `₹${(v / 1e7).toFixed(1)}Cr` : v >= 1e5 ? `₹${(v / 1e5).toFixed(1)}L` : `₹${Math.round(v).toLocaleString('en-IN')}`)
const LOCATION: Record<string, string> = { vault: 'Vault', kaarigar: 'With kaarigars', wo: 'In work orders', scrap: 'Scrap', finished: 'Finished goods' }

function greeting() {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
}

/** Owner dashboard (spec §3): orders, gold, money, efficiency, and what needs attention. */
export default function Dashboard() {
  const { data: me } = useMe()
  const can = useCan()
  const navigate = useNavigate()
  const metal = can('kaarigars.view') || can('metal.issue') || can('reports.view')

  const dash = useQuery({ queryKey: ['dashboard'], queryFn: () => api.get<Dash>('/api/v1/dashboard'), refetchInterval: 120_000 })
  const summary = useQuery({ queryKey: ['metal', 'summary'], queryFn: () => api.get<Summary>('/api/v1/metal/summary'), enabled: metal, refetchInterval: 60_000 })
  const rates = useQuery({ queryKey: ['gold-rates'], queryFn: () => api.get<{ data: GoldRate[] }>('/api/v1/gold-rates') })
  const pendingRetailers = useQuery({
    queryKey: ['retailers', 'pending'],
    queryFn: () => api.get<Paginated<Retailer>>('/api/v1/retailers?status=pending&per_page=1'),
    enabled: can('retailers.manage'),
  })

  const d = dash.data
  const s = summary.data
  const months = d?.charts.months ?? []
  const salesDelta = d?.money && Number(d.money.last_month_sales) > 0
    ? Math.round(((Number(d.money.month_sales) - Number(d.money.last_month_sales)) / Number(d.money.last_month_sales)) * 100) : null

  return (
    <>
      <PageTitle
        title={`${greeting()}, ${me?.name?.split(' ')[0] ?? ''}`}
        description={new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
      />

      {d?.orders && (
        <Section title="Orders">
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
            <Kpi label="Total orders" value={d.orders.total} icon={<Package />} to="/staff/orders" />
            <Kpi label="New" value={d.orders.new} icon={<Inbox />} to="/staff/orders?status=new" tone={d.orders.new ? 'warning' : undefined} />
            <Kpi label="Awaiting approval" value={d.orders.awaiting_approval} icon={<ClipboardCheck />} to="/staff/orders?status=awaiting" tone={d.orders.awaiting_approval ? 'warning' : undefined} />
            <Kpi label="In production" value={d.orders.in_production} icon={<Factory />} to="/staff/orders?status=production" />
            <Kpi label="Pending" value={d.orders.pending} icon={<Hammer />} to="/staff/orders?status=pending" />
            <Kpi label="Ready for dispatch" value={d.orders.ready_for_dispatch} icon={<Truck />} to="/staff/orders?status=ready_for_dispatch" tone={d.orders.ready_for_dispatch ? 'success' : undefined} />
            <Kpi label="Completed" value={d.orders.completed} icon={<CheckCircle2 />} to="/staff/orders?status=completed" />
          </div>
        </Section>
      )}

      {(d?.gold || d?.money) && (
        <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {d.gold && <Tile featured icon={<Coins />} label="Total gold stock" value={grams(d.gold.total_stock)} hint={`Vault ${grams(d.gold.vault)}`} to="/staff/metal/vault" />}
          {d.gold && <Tile icon={<Hammer />} label="Gold with kaarigars" value={grams(d.gold.with_kaarigars)} hint={`In production ${grams(d.gold.in_production)} · back this month ${grams(d.gold.received_this_month)}`} to="/staff/reports/gold-with-kaarigars" />}
          {d.finished && d.gold && <Tile icon={<Vault />} label="Finished jewellery" value={grams(d.gold.finished)} hint={`${d.finished.pieces} pcs · ${d.finished.ready} QC-passed · ${d.finished.qc_pending} awaiting QC`} to="/staff/finished" />}
          {d.money && <Tile icon={<IndianRupee />} label="Sales this month" value={rupees(d.money.month_sales)}
            hint={salesDelta === null ? `Last month ${rupees(d.money.last_month_sales)}` : `${salesDelta >= 0 ? '▲' : '▼'} ${Math.abs(salesDelta)}% vs last month`} to="/staff/reports/monthly-revenue" />}
          {d.money && <Tile icon={<Wallet />} label="Outstanding from retailers" value={rupees(d.money.outstanding)} hint="Money owed to you" to="/staff/reports/outstanding" />}
        </div>
      )}

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        {d?.money && <BarChart className="lg:col-span-2" title="Sales" subtitle="Invoiced, last 12 months" format={lakh}
          data={months.map((m) => ({ label: monthLabel(m.month), value: m.sales ?? 0 }))} />}
        {!d?.money && d?.orders && <BarChart className="lg:col-span-2" title="Orders received" subtitle="Last 12 months"
          data={months.map((m) => ({ label: monthLabel(m.month), value: m.orders }))} />}

        {d?.efficiency && (
          <Card className="p-5">
            <h2 className="font-semibold tracking-tight">Production efficiency</h2>
            <p className="text-xs text-muted-foreground">Last {d.efficiency.days} days · {d.efficiency.completed} work orders done</p>
            <div className="mt-4 grid gap-4">
              <Meter label="On time" value={d.efficiency.on_time_pct} hint="Work orders finished by their due date" />
              <Meter label="First-time right" value={d.efficiency.rework_pct === null ? null : Math.round((100 - d.efficiency.rework_pct) * 10) / 10} hint="Not sent back for rework" />
              <div className="grid grid-cols-2 gap-3 border-t pt-3 text-sm">
                <div><p className="text-muted-foreground">Avg production</p><p className="tabular text-lg font-semibold">{d.efficiency.avg_days ?? '—'}<span className="text-sm font-normal text-muted-foreground"> days</span></p></div>
                <div><p className="text-muted-foreground">Wastage</p><p className="tabular text-lg font-semibold">{d.efficiency.wastage_pct ?? '—'}<span className="text-sm font-normal text-muted-foreground">%</span></p>
                  {d.efficiency.allowed_wastage_pct !== null && <p className="text-xs text-muted-foreground">allowed {d.efficiency.allowed_wastage_pct}%</p>}</div>
              </div>
            </div>
          </Card>
        )}
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        {d?.orders && <BarChart title="Production" subtitle="Pieces finished per month" data={months.map((m) => ({ label: monthLabel(m.month), value: m.pieces_finished }))} />}
        {d?.charts.gold_by_location.length ? (
          <HBars title="Where the gold is" subtitle="Fine grams now" format={(v) => `${v.toFixed(3)} g`}
            data={[...d.charts.gold_by_location].sort((a, b) => b.fine - a.fine).map((g) => ({ label: LOCATION[g.location] ?? g.location, value: g.fine }))} />
        ) : null}
        {d?.charts.orders_by_status.length ? (
          <HBars title="Orders by status" subtitle="Click to open the list"
            data={d.charts.orders_by_status.map((o) => ({ label: ORDER_STATUS[o.status]?.label ?? o.status, value: o.count }))}
            onSelect={(label) => {
              const hit = d.charts.orders_by_status.find((o) => (ORDER_STATUS[o.status]?.label ?? o.status) === label)
              if (hit) navigate(`/staff/orders?status=${hit.status}`)
            }} />
        ) : null}
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <Card className="p-5">
          <h2 className="flex items-center gap-2 font-semibold tracking-tight"><AlertTriangle className="size-4 text-warning" /> Needs attention</h2>
          <div className="-mx-2 mt-2 grid text-sm">
            {d?.orders && <Alarm n={d.orders.awaiting_approval} label="Orders awaiting approval" to="/staff/orders?status=awaiting" />}
            {d?.orders && <Alarm n={d.orders.approved_without_wo} label="Approved orders without a work order" to="/staff/orders?status=approved" />}
            {d?.orders && <Alarm n={d.orders.rework} label="Orders in rework" to="/staff/orders?status=rework_required" />}
            {d?.orders && <Alarm n={d.orders.overdue} label="Orders past due date" to="/staff/orders?overdue=1" />}
            {d?.gold && Number(d.gold.pending_reconciliation) > 0 && <Alarm n={grams(d.gold.pending_reconciliation)} label="Gold pending reconciliation" to="/staff/reports/gold-reconciliation" />}
            {s && <>
              <Alarm n={s.wastage_alerts} label="Gold differences to approve" to="/staff/metal/job-cards?f=alerts" />
              <Alarm n={s.overdue_job_cards} label="Overdue job cards" to="/staff/metal/job-cards" />
              <Alarm n={s.pending_requests} label="Kaarigar requests" to="/staff/metal/requests" />
              <Alarm n={s.disputed_issues} label="Disputed issues / handoffs" to="/staff/metal/transactions" />
              <Alarm n={s.unconfirmed_issues} label="Issues / handoffs not acknowledged in 24 h" to="/staff/metal/transactions" />
            </>}
            {pendingRetailers.data && <Alarm n={pendingRetailers.data.total} label="Retailers to approve" to="/staff/retailers" />}
          </div>
        </Card>
        <RatesCard rates={rates.data?.data} />
      </div>
    </>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section>
      <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</h2>
      {children}
    </section>
  )
}

function Kpi({ label, value, icon, to, tone }: { label: string; value: number; icon: ReactNode; to: string; tone?: 'warning' | 'success' }) {
  return (
    <Link to={to} className="group rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      <Card className="h-full p-4 transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-raised">
        <div className="flex items-center justify-between text-muted-foreground [&_svg]:size-4">
          {icon}
          {tone && value > 0 && <span className={cn('size-2 rounded-full', tone === 'warning' ? 'bg-warning' : 'bg-success')} />}
        </div>
        <p className="tabular mt-3 text-2xl font-semibold tracking-tight">{value.toLocaleString('en-IN')}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">{label}</p>
      </Card>
    </Link>
  )
}

function Tile({ label, value, to, hint, icon, featured }: { label: string; value: string; to?: string; hint?: string; icon: ReactNode; featured?: boolean }) {
  const body = (
    <Card
      className={cn(
        'group relative h-full overflow-hidden p-5 transition-all duration-200',
        to && 'hover:-translate-y-0.5 hover:shadow-raised',
        featured && 'border-brand/25 bg-gradient-to-br from-brand/[0.09] via-card to-card',
      )}
    >
      <div className="flex items-center justify-between">
        <span className={cn('grid size-9 place-items-center rounded-lg [&_svg]:size-[1.125rem]', featured ? 'bg-brand text-brand-foreground' : 'bg-brand/10 text-brand')}>{icon}</span>
        {to && <ArrowUpRight className="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />}
      </div>
      <p className="mt-4 text-sm text-muted-foreground">{label}</p>
      <p className="tabular mt-0.5 text-[1.5rem] font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  )
  return to ? <Link to={to} className="rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">{body}</Link> : body
}

function Alarm({ n, label, to }: { n: number | string; label: string; to: string }) {
  const on = typeof n === 'string' ? Number(n.replace(/[^\d.]/g, '')) > 0 : n > 0
  return (
    <Link to={to} className="flex items-center justify-between rounded-lg px-2 py-2 transition-colors hover:bg-muted">
      <span className={on ? '' : 'text-muted-foreground'}>{label}</span>
      <Badge tone={on ? 'warning' : 'neutral'}>{n}</Badge>
    </Link>
  )
}

function RatesCard({ rates }: { rates: GoldRate[] | undefined }) {
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <h2 className="flex items-center gap-2 font-semibold tracking-tight"><TrendingUp className="size-4 text-brand" /> Gold rate</h2>
        <Link to="/staff/settings/gold-rates" className="text-sm font-medium text-brand hover:underline">Update</Link>
      </div>
      <div className="mt-3 grid divide-y">
        {rates?.length ? rates.map((r) => (
          <div key={r.id} className="flex items-baseline justify-between py-2.5 text-sm first:pt-1 last:pb-0">
            <span className="font-medium">{r.karat.label}</span>
            <span className="tabular text-right"><span className="font-semibold">{rupees(r.rate_per_g)}</span><span className="text-muted-foreground">/g</span> <span className="block text-xs text-muted-foreground">{r.date}</span></span>
          </div>
        )) : <p className="text-sm text-muted-foreground">No rate set yet.</p>}
      </div>
    </Card>
  )
}
