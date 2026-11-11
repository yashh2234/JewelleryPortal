import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, grams, Input, Meter, rupees, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpen, ShieldAlert } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'

interface Profile {
  gold_held: { fine: string; gross: string }
  limit: string | null
  issue_blockers: string[]
  totals: { issued_fine: string; returned_fine: string; loss_fine: string; wastage_pct: number | null; unresolved_excess: string }
  production: { cards_closed: number; pieces_done: number; cards_open: number; pieces_pending: number; overdue: number }
  performance_90d: { cards: number; avg_days: number | null; on_time_pct: number | null; over_limit: number }
  work_orders: { id: number; wo_no: string; qty: number; status: string; due_date: string | null; design: { code: string; name: string } }[]
  money: { labour_total: string; paid_total: string; balance: string; recent_payments: { id: number; voucher_no: string; date: string; amount: string; narration: string | null }[] }
}
interface Kaarigar {
  id: number; name: string; phone: string | null; type: string; address: string | null; is_active: boolean; max_fine_balance: string | null; app_users: { id: number; name: string; phone: string }[]
  head: { id: number; name: string } | null
  sub_kaarigars: { id: number; name: string; phone: string | null; is_active: boolean }[]
}

/** Spec §6D: everything about one kaarigar — gold, work, money, performance. */
export default function KaarigarProfile() {
  const { id } = useParams()
  const can = useCan()
  const q = useQuery({ queryKey: ['kaarigar', id], queryFn: () => api.get<{ data: Kaarigar; profile: Profile }>(`/api/v1/kaarigars/${id}`) })
  const k = q.data?.data
  const p = q.data?.profile
  if (!k || !p) return <p className="text-muted-foreground">Loading…</p>

  const usage = p.limit && Number(p.limit) > 0 ? Math.round((Number(p.gold_held.fine) / Number(p.limit)) * 1000) / 10 : null

  return (
    <>
      <PageTitle title={k.name} description={[k.type === 'outside' ? 'Outside karkhana' : 'In-house', k.head && `under head kaarigar ${k.head.name}`, k.phone, k.address].filter(Boolean).join(' · ')}>
        {k.is_active ? <Badge tone="success">Active</Badge> : <Badge>Inactive</Badge>}
        <Link to={`/staff/kaarigars/${k.id}/ledger`}><Button variant="outline"><BookOpen /> Gold ledger</Button></Link>
        {can('metal.issue') && <Link to="/staff/metal/issue"><Button>Issue gold</Button></Link>}
      </PageTitle>

      {p.issue_blockers.length > 0 && (
        <div className="mb-4"><Alert tone="warning"><b>New gold is blocked.</b><ul className="mt-1 list-disc pl-4">{p.issue_blockers.map((b) => <li key={b}>{b}</li>)}</ul></Alert></div>
      )}

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Gold in hand (fine)" value={grams(p.gold_held.fine)} hint={`Gross ${grams(p.gold_held.gross)}${p.limit ? ` · limit ${grams(p.limit)}` : ''}`} />
        <Stat label="Issued to date (closed cards)" value={grams(p.totals.issued_fine)} hint={`Returned ${grams(p.totals.returned_fine)}`} />
        <Stat label="Difference (loss)" value={p.totals.wastage_pct === null ? '—' : `${p.totals.wastage_pct}%`} hint={`${grams(p.totals.loss_fine)} on closed cards${Number(p.totals.unresolved_excess) > 0 ? ` · ${grams(p.totals.unresolved_excess)} waiting for approval` : ''}`} />
        <Stat label="Labour balance" value={rupees(p.money.balance)} hint={`Earned ${rupees(p.money.labour_total)} · paid ${rupees(p.money.paid_total)}`} />
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <div className="grid h-fit gap-4">
          <Card className="p-5">
            <h2 className="font-semibold tracking-tight">Performance · last 90 days</h2>
            <div className="mt-4 grid gap-4">
              <Meter label="On time" value={p.performance_90d.on_time_pct} hint={`${p.performance_90d.cards} job cards closed`} />
              {usage !== null && <Meter label="Gold limit used" value={Math.min(usage, 100)} hint={`${grams(p.gold_held.fine)} of ${grams(p.limit)}`} />}
              <dl className="grid grid-cols-2 gap-3 border-t pt-3 text-sm">
                <Term label="Avg days per card">{p.performance_90d.avg_days ?? '—'}</Term>
                <Term label="Over wastage limit">{p.performance_90d.over_limit}</Term>
                <Term label="Pieces done">{p.production.pieces_done}</Term>
                <Term label="Pending">{p.production.cards_open} cards · {p.production.pieces_pending} pcs{p.production.overdue ? ` · ${p.production.overdue} overdue` : ''}</Term>
              </dl>
            </div>
          </Card>
          {k.sub_kaarigars.length > 0 && (
            <Card className="p-5">
              <h2 className="font-semibold tracking-tight">Sub-kaarigars ({k.sub_kaarigars.length})</h2>
              <p className="text-xs text-muted-foreground">{k.name} can hand them work and gold from his job cards, in the app or here.</p>
              <ul className="mt-2 grid gap-1 text-sm">
                {k.sub_kaarigars.map((s) => <li key={s.id}><Link className="text-brand hover:underline" to={`/staff/kaarigars/${s.id}`}>{s.name}</Link>{!s.is_active && ' (inactive)'}</li>)}
              </ul>
            </Card>
          )}
          {can('kaarigars.manage') && <LimitForm k={k} />}
        </div>

        <div className="grid h-fit gap-4 lg:col-span-2">
          <Card>
            <CardHeader title="Work orders" description="Assigned through job cards" />
            <Table head={['Work order', 'Design', 'Qty', 'Due', 'Status']}>
              {p.work_orders.map((w) => (
                <tr key={w.id}>
                  <Td><Link className="font-medium text-brand hover:underline" to={`/staff/work-orders/${w.id}`}>{w.wo_no}</Link></Td>
                  <Td>{w.design.name} <span className="font-mono text-xs text-muted-foreground">{w.design.code}</span></Td>
                  <Td className="tabular">{w.qty}</Td>
                  <Td>{fmtDate(w.due_date)}</Td>
                  <Td><Badge tone={w.status === 'completed' ? 'success' : w.status === 'cancelled' ? 'neutral' : 'brand'} className="capitalize">{w.status.replace('_', ' ')}</Badge></Td>
                </tr>
              ))}
            </Table>
            {p.work_orders.length === 0 && <p className="p-5 text-sm text-muted-foreground">No work orders yet.</p>}
          </Card>
          <Card>
            <CardHeader title="Payments" />
            <Table head={['Voucher', 'Date', 'Amount', 'Note']}>
              {p.money.recent_payments.map((v) => (
                <tr key={v.id}><Td className="font-mono text-xs">{v.voucher_no}</Td><Td>{fmtDate(v.date)}</Td><Td className="tabular">{rupees(v.amount)}</Td><Td className="text-muted-foreground">{v.narration}</Td></tr>
              ))}
            </Table>
            {p.money.recent_payments.length === 0 && <p className="p-5 text-sm text-muted-foreground">No payments yet.</p>}
          </Card>
        </div>
      </div>
    </>
  )
}

const Stat = ({ label, value, hint }: { label: string; value: string; hint?: string }) => (
  <Card className="p-5"><p className="text-sm text-muted-foreground">{label}</p><p className="tabular mt-1 text-xl font-semibold tracking-tight">{value}</p>{hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}</Card>
)
const Term = ({ label, children }: { label: string; children: ReactNode }) => (
  <div><dt className="text-xs text-muted-foreground">{label}</dt><dd className="tabular mt-0.5 font-medium">{children}</dd></div>
)

function LimitForm({ k }: { k: Kaarigar }) {
  const qc = useQueryClient()
  const [limit, setLimit] = useState(k.max_fine_balance ?? '')
  const save = useMutation({
    mutationFn: () => api.patch(`/api/v1/kaarigars/${k.id}`, { max_fine_balance: limit || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['kaarigar', String(k.id)] }),
  })
  return (
    <Card className="p-5">
      <h2 className="flex items-center gap-2 font-semibold tracking-tight"><ShieldAlert className="size-4 text-brand" /> Gold limit</h2>
      <p className="mt-1 text-sm text-muted-foreground">Most fine gold this kaarigar may hold at once. Blank = no limit.</p>
      <form className="mt-3 flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <Field label="Fine grams" className="flex-1"><Input inputMode="decimal" value={limit} onChange={(e) => setLimit(e.target.value.replace(/[^\d.]/g, ''))} /></Field>
        <Button type="submit" variant="outline" loading={save.isPending}>Save</Button>
      </form>
      {save.error instanceof ApiError && <div className="mt-2"><Alert>{save.error.message}</Alert></div>}
      {save.isSuccess && <p className="mt-2 text-xs text-success">Saved.</p>}
    </Card>
  )
}
