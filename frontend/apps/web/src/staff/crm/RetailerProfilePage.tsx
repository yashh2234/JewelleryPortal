import { Alert, api, ApiError, Avatar, Badge, Button, Card, CardHeader, EmptyState, Field, Input, rupees, Select, Table, Td, Textarea } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Check, Mail, MapPin, MessageSquare, Phone, Repeat, Trash2 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { ORDER_STATUS, PAYMENT_STATUS, type OrderStatus } from '../production/types'

interface Profile {
  retailer: {
    id: number; shop_name: string; contact_name: string | null; gstin: string | null; city: string | null; address: string | null; phone: string | null; email: string | null
    status: string; credit_limit: string; payment_terms_days: number | null; metal_account_enabled: boolean; hallmark_mandatory: boolean
    salesperson: { id: number; name: string; phone: string | null } | null
    making_slab: { id: number; name: string; basis: string; rate: string } | null
    users: { id: number; name: string; email: string; phone: string | null; role: string; is_active: boolean }[]
  }
  position: { outstanding: string; metal: string; credit_limit: string; credit_available: string | null }
  stats: { orders: number; pending_orders: number; pieces: number; sales_total: string; sales_12m: string; first_order: string | null; last_order: string | null; repeat_buyer: boolean; avg_days_between_orders: number | null }
  orders: { id: number; order_no: string; status: OrderStatus; source: string; due_date: string | null; created_at: string; qty_total: number; estimated_value: string | null; payment_status: string }[]
  repeat_designs: { design: { id: number; code: string; name: string }; orders: number; pieces: number }[]
  notes: { id: number; channel: string; body: string; follow_up_on: string | null; created_at: string; user: { id: number; name: string } | null }[]
  queries: { id: number; ticket_no: string; type: string; subject: string; status: string; created_at: string }[]
}

const CHANNELS: Record<string, string> = { call: 'Call', visit: 'Visit', whatsapp: 'WhatsApp', email: 'Email', note: 'Note' }

/** CRM profile (spec §4): everything about one retailer on one page. */
export default function RetailerProfilePage() {
  const { id } = useParams()
  const q = useQuery({ queryKey: ['crm', id], queryFn: () => api.get<Profile>(`/api/v1/crm/retailers/${id}`) })
  const p = q.data
  if (!p) return <p className="text-muted-foreground">Loading…</p>
  const r = p.retailer

  return (
    <>
      <PageTitle title={r.shop_name} description={[r.contact_name, r.city, r.gstin && `GSTIN ${r.gstin}`].filter(Boolean).join(' · ')}>
        <Badge tone={r.status === 'active' ? 'success' : r.status === 'pending' ? 'warning' : 'danger'} className="capitalize">{r.status}</Badge>
        {p.stats.repeat_buyer && <Badge tone="brand"><Repeat className="size-3" /> Repeat buyer</Badge>}
        <Link to="/staff/retailers"><Button variant="outline">Edit details</Button></Link>
      </PageTitle>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label="Outstanding" value={rupees(p.position.outstanding)} hint={Number(p.position.credit_limit) > 0 ? `Credit limit ${rupees(p.position.credit_limit)} · available ${rupees(p.position.credit_available)}` : 'No credit limit set'} />
        <Stat label="Sales, last 12 months" value={rupees(p.stats.sales_12m)} hint={`All time ${rupees(p.stats.sales_total)}`} />
        <Stat label="Orders" value={String(p.stats.orders)} hint={`${p.stats.pending_orders} pending · ${p.stats.pieces} pieces`} />
        <Stat label="Last order" value={p.stats.last_order ? fmtDate(p.stats.last_order) : '—'}
          hint={p.stats.avg_days_between_orders ? `Orders every ~${p.stats.avg_days_between_orders} days` : p.stats.first_order ? `Customer since ${fmtDate(p.stats.first_order)}` : 'No orders yet'} />
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <div className="grid h-fit gap-4">
          <Card className="p-5">
            <h2 className="font-semibold tracking-tight">Contact & terms</h2>
            <div className="mt-3 grid gap-2.5 text-sm">
              {r.phone && <Line icon={<Phone />}><a className="hover:underline" href={`tel:${r.phone}`}>{r.phone}</a> · <a className="text-brand hover:underline" href={`https://wa.me/91${r.phone.replace(/\D/g, '').slice(-10)}`} target="_blank" rel="noreferrer">WhatsApp</a></Line>}
              {r.email && <Line icon={<Mail />}><a className="hover:underline" href={`mailto:${r.email}`}>{r.email}</a></Line>}
              {(r.address || r.city) && <Line icon={<MapPin />}>{[r.address, r.city].filter(Boolean).join(', ')}</Line>}
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-3 border-t pt-4 text-sm">
              <Term label="Salesperson">{r.salesperson?.name ?? '—'}</Term>
              <Term label="Payment terms">{r.payment_terms_days ? `${r.payment_terms_days} days` : '—'}</Term>
              <Term label="Pricing">{r.making_slab ? `${r.making_slab.name}` : 'Design making'}</Term>
              <Term label="Metal account">{r.metal_account_enabled ? `${Math.abs(Number(p.position.metal)).toFixed(3)} g` : 'Off'}</Term>
            </dl>
          </Card>

          <Card>
            <CardHeader title="Portal users" />
            <div className="divide-y text-sm">
              {r.users.map((u) => (
                <div key={u.id} className="flex items-center gap-3 px-5 py-2.5">
                  <Avatar name={u.name} className="size-7" />
                  <div className="min-w-0 flex-1"><p className="truncate font-medium">{u.name}</p><p className="truncate text-xs text-muted-foreground">{u.email}</p></div>
                  <Badge className="capitalize">{u.role}</Badge>
                </div>
              ))}
            </div>
          </Card>

          {p.repeat_designs.length > 0 && (
            <Card className="p-5">
              <h2 className="flex items-center gap-2 font-semibold tracking-tight"><Repeat className="size-4 text-brand" /> Re-ordered designs</h2>
              <div className="mt-2 grid divide-y text-sm">
                {p.repeat_designs.map((d) => (
                  <div key={d.design.id} className="flex justify-between py-2"><span>{d.design.name} <span className="font-mono text-xs text-muted-foreground">{d.design.code}</span></span><span className="tabular text-muted-foreground">{d.orders}× · {d.pieces} pcs</span></div>
                ))}
              </div>
            </Card>
          )}
        </div>

        <div className="grid h-fit gap-4 lg:col-span-2">
          <Notes retailerId={r.id} notes={p.notes} />

          <Card>
            <CardHeader title="Purchase history" description="Every order from this retailer" />
            {p.orders.length === 0 ? <EmptyState title="No orders yet" /> : (
              <Table head={['Order', 'Date', 'Pieces', 'Value', 'Payment', 'Status']}>
                {p.orders.map((o) => (
                  <tr key={o.id}>
                    <Td><Link className="font-medium text-brand hover:underline" to={`/staff/orders/${o.id}`}>{o.order_no}</Link></Td>
                    <Td>{fmtDate(o.created_at)}</Td>
                    <Td className="tabular">{o.qty_total}</Td>
                    <Td className="tabular">{rupees(o.estimated_value)}</Td>
                    <Td><Badge tone={PAYMENT_STATUS[o.payment_status]?.tone ?? 'neutral'}>{PAYMENT_STATUS[o.payment_status]?.label ?? o.payment_status}</Badge></Td>
                    <Td><Badge tone={ORDER_STATUS[o.status].tone}>{ORDER_STATUS[o.status].label}</Badge></Td>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          {p.queries.length > 0 && (
            <Card>
              <CardHeader title="Queries & requests" />
              <div className="divide-y text-sm">
                {p.queries.map((qq) => (
                  <Link key={qq.id} to={`/staff/queries/${qq.id}`} className="flex items-center justify-between gap-3 px-5 py-3 hover:bg-muted/40">
                    <span className="min-w-0"><span className="font-mono text-xs text-muted-foreground">{qq.ticket_no}</span> <span className="font-medium">{qq.subject}</span> <span className="text-xs capitalize text-muted-foreground">· {qq.type}</span></span>
                    <Badge tone={qq.status === 'open' ? 'warning' : qq.status === 'answered' ? 'brand' : 'neutral'} className="capitalize">{qq.status}</Badge>
                  </Link>
                ))}
              </div>
            </Card>
          )}
        </div>
      </div>
    </>
  )
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-5">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="tabular mt-1 text-xl font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  )
}

const Line = ({ icon, children }: { icon: ReactNode; children: ReactNode }) => (
  <div className="flex items-start gap-2.5 [&>svg]:mt-0.5 [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:text-muted-foreground">{icon}<span className="min-w-0">{children}</span></div>
)
const Term = ({ label, children }: { label: string; children: ReactNode }) => (
  <div><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-0.5 font-medium">{children}</dd></div>
)

function Notes({ retailerId, notes }: { retailerId: number; notes: Profile['notes'] }) {
  const qc = useQueryClient()
  const [channel, setChannel] = useState('call')
  const [body, setBody] = useState('')
  const [follow, setFollow] = useState('')
  const refresh = () => qc.invalidateQueries({ queryKey: ['crm', String(retailerId)] })

  const add = useMutation({
    mutationFn: () => api.post(`/api/v1/crm/retailers/${retailerId}/notes`, { channel, body, follow_up_on: follow || null }),
    onSuccess: () => { setBody(''); setFollow(''); refresh() },
  })
  const done = useMutation({ mutationFn: (id: number) => api.post(`/api/v1/crm/retailers/${retailerId}/notes/${id}/done`), onSuccess: refresh })
  const del = useMutation({ mutationFn: (id: number) => api.delete(`/api/v1/crm/retailers/${retailerId}/notes/${id}`), onSuccess: refresh })

  return (
    <Card>
      <CardHeader title="Communication log" description="Calls, visits and messages with this retailer" />
      <form className="grid gap-3 border-b p-5" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
        {add.error instanceof ApiError && <Alert>{add.error.message}</Alert>}
        <Textarea placeholder="What was discussed?" value={body} onChange={(e) => setBody(e.target.value)} required />
        <div className="flex flex-wrap items-end gap-2">
          <Field label="Channel"><Select className="w-36" value={channel} onChange={(e) => setChannel(e.target.value)}>{Object.entries(CHANNELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
          <Field label="Follow up on"><Input type="date" className="w-40" value={follow} onChange={(e) => setFollow(e.target.value)} /></Field>
          <Button type="submit" className="ml-auto" loading={add.isPending}><MessageSquare /> Add to log</Button>
        </div>
      </form>
      {notes.length === 0 ? <EmptyState title="No conversations logged yet" icon={<MessageSquare />} /> : (
        <ol className="divide-y">
          {notes.map((n) => (
            <li key={n.id} className="group flex gap-3 px-5 py-3.5">
              <Avatar name={n.user?.name ?? '?'} className="size-7" />
              <div className="min-w-0 flex-1">
                <p className="text-xs text-muted-foreground"><b className="font-medium text-foreground">{n.user?.name ?? 'Someone'}</b> · {CHANNELS[n.channel] ?? n.channel} · {fmtDate(n.created_at)}</p>
                <p className="mt-1 whitespace-pre-line text-sm">{n.body}</p>
                {n.follow_up_on && (
                  <p className="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                    <CalendarClock className="size-3" /> Follow up {fmtDate(n.follow_up_on)}
                    <button className="ml-1 inline-flex items-center gap-0.5 hover:underline" onClick={() => done.mutate(n.id)}><Check className="size-3" /> Done</button>
                  </p>
                )}
              </div>
              <button className="self-start text-muted-foreground opacity-0 transition-opacity hover:text-danger group-hover:opacity-100" aria-label="Delete note" onClick={() => confirm('Delete this note?') && del.mutate(n.id)}><Trash2 className="size-4" /></button>
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}
