import { Alert, api, ApiError, Badge, Button, Card, cn, Field, Input, Textarea, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Clock, ExternalLink, FileText, ImagePlus, MessageCircleQuestion, Paperclip, RotateCcw, Truck } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { inr, SHIPMENT_LABEL, STAGE_LABEL, type Stage } from './types'

interface OrderRow { id: number; order_no: string; status: string; stage: Stage; due_date: string | null; created_at: string; lines_count: number; qty_total: number; qty_dispatched_total: number }
interface Line {
  id: number; qty: number; qty_dispatched: number; size: string | null; remarks: string | null; customization: string | null; reference_image: string | null
  design: { code: string; name: string; photo: string | null }; karat: string
}
interface Shipment {
  id: number; challan_no: string; date: string; pieces: number; net_wt: string; invoice_voucher_id: number | null
  shipment_status: string; courier: string | null; tracking_no: string | null; tracking_url: string | null
  shipped_at: string | null; delivered_at: string | null; delivery_remarks: string | null
  documents: { id: number; name: string }[]
}
interface OrderDetail {
  id: number; order_no: string; status: string; stage: Stage; due_date: string | null; remarks: string | null; cancel_reason: string | null; created_at: string
  approved_at: string | null; delivered_at: string | null
  timeline: Stage[]; production_stage: string | null; expected_completion: string | null; editable: boolean
  payments: { value: string | null; value_is_estimate: boolean; paid: string; balance: string | null; status: string }
  lines: Line[]
  dispatches: Shipment[]
}

const day = (d: string | null) => (d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
const tone = (s: Stage) => (s === 'cancelled' || s === 'on_hold' ? 'danger' : s === 'dispatched' || s === 'delivered' ? 'success' : s === 'received' ? 'warning' : 'brand') as 'danger' | 'success' | 'warning' | 'brand'
const PAY: Record<string, string> = { unpaid: 'Not paid yet', advance: 'Advance paid', partly_paid: 'Part paid', paid: 'Paid' }

export function OrdersPage() {
  const q = useQuery({ queryKey: ['portal', 'orders'], queryFn: () => api.get<Paginated<OrderRow>>('/api/portal/v1/orders'), meta: { persist: true } })

  if (q.data?.data.length === 0) {
    return <div className="py-16 text-center"><p className="text-lg font-medium">No orders yet</p><Link to="/portal"><Button className="mt-4">Start ordering</Button></Link></div>
  }

  return (
    <div className="grid gap-3">
      <h1 className="font-display text-2xl font-semibold">My orders</h1>
      {q.data?.data.map((o) => (
        <Link key={o.id} to={`/portal/orders/${o.id}`} className="group">
          <Card className="flex items-center justify-between gap-3 p-4 transition-all group-hover:-translate-y-0.5 group-hover:shadow-raised">
            <div className="min-w-0">
              <p className="font-medium">{o.order_no}</p>
              <p className="text-sm text-muted-foreground">{day(o.created_at)} · {o.lines_count} design{o.lines_count === 1 ? '' : 's'} · {o.qty_dispatched_total}/{o.qty_total} pcs sent</p>
            </div>
            <Badge tone={tone(o.stage)}>{STAGE_LABEL[o.stage] ?? o.stage}</Badge>
          </Card>
        </Link>
      ))}
    </div>
  )
}

/** Spec §10: one page with the whole journey of the order. */
export function OrderPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const q = useQuery({ queryKey: ['portal', 'order', id], queryFn: () => api.get<{ data: OrderDetail }>(`/api/portal/v1/orders/${id}`), meta: { persist: true } })
  const reorder = useMutation({
    mutationFn: () => api.post(`/api/portal/v1/orders/${id}/reorder`),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['portal', 'cart'] }); navigate('/portal/cart') },
  })
  const o = q.data?.data
  if (!o) return <p className="text-muted-foreground">Loading…</p>

  const reached = o.timeline.indexOf(o.stage)
  const stopped = o.stage === 'cancelled' || o.stage === 'on_hold'

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-semibold">{o.order_no}</h1>
          <p className="text-sm text-muted-foreground">Placed {day(o.created_at)}{o.approved_at && ` · approved ${day(o.approved_at)}`}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to={`/portal/queries/new?order=${o.id}`}><Button variant="outline"><MessageCircleQuestion /> Ask about this order</Button></Link>
          <Button variant="outline" onClick={() => reorder.mutate()} loading={reorder.isPending}><RotateCcw /> Order again</Button>
        </div>
      </div>

      <Card className="p-5">
        {stopped ? (
          <p className="font-medium text-danger">{STAGE_LABEL[o.stage]}{o.cancel_reason && `: ${o.cancel_reason}`}</p>
        ) : (
          <>
            <ol className="grid grid-cols-7 gap-1">
              {o.timeline.map((s, i) => (
                <li key={s} className="relative flex flex-col items-center text-center">
                  {i > 0 && <span className={cn('absolute right-1/2 top-4 -z-0 h-0.5 w-full', i <= reached ? 'bg-brand' : 'bg-border')} />}
                  <span className={cn('relative z-10 grid size-8 place-items-center rounded-full border-2 text-xs font-semibold',
                    i < reached ? 'border-brand bg-brand text-brand-foreground' : i === reached ? 'border-brand bg-card text-brand ring-4 ring-brand/15' : 'border-border bg-card text-muted-foreground')}>
                    {i < reached ? <Check className="size-4" /> : i + 1}
                  </span>
                  <span className={cn('mt-1.5 text-[0.6875rem] leading-tight', i === reached ? 'font-semibold text-brand' : 'text-muted-foreground')}>{STAGE_LABEL[s]}</span>
                </li>
              ))}
            </ol>
            <div className="mt-5 grid gap-3 border-t pt-4 text-sm sm:grid-cols-3">
              <Fact label="Current stage" value={o.production_stage ? `In ${o.production_stage}` : STAGE_LABEL[o.stage]} />
              <Fact label="Expected completion" value={o.expected_completion ? day(o.expected_completion) : 'Confirmed after approval'} icon={<Clock className="size-3.5" />} />
              <Fact label="Payment" value={`${PAY[o.payments.status] ?? o.payments.status} · ${inr(o.payments.paid)} of ${o.payments.value ? inr(o.payments.value) : '—'}${o.payments.value_is_estimate ? ' (est.)' : ''}`} />
            </div>
          </>
        )}
      </Card>

      {o.editable && <Alert tone="warning">You can still add customisation notes and a reference photo to each design until we approve the order.</Alert>}

      <Card className="divide-y">
        {o.lines.map((l) => <LineRow key={l.id} line={l} orderId={o.id} editable={o.editable} />)}
      </Card>

      {o.dispatches.length > 0 && (
        <Card className="divide-y">
          <p className="flex items-center gap-2 p-4 font-semibold"><Truck className="size-4 text-brand" /> Shipments</p>
          {o.dispatches.map((d) => (
            <div key={d.id} className="grid gap-2 p-4 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">{d.challan_no} <span className="font-normal text-muted-foreground">· {day(d.date)} · {d.pieces} pcs · {d.net_wt} g</span></span>
                <Badge tone={d.shipment_status === 'delivered' ? 'success' : d.shipment_status === 'failed' ? 'danger' : 'brand'}>{SHIPMENT_LABEL[d.shipment_status] ?? d.shipment_status}</Badge>
              </div>
              {(d.courier || d.tracking_no) && (
                <p className="text-muted-foreground">
                  {d.courier}{d.tracking_no && <> · tracking {d.tracking_url
                    ? <a className="inline-flex items-center gap-0.5 font-medium text-brand hover:underline" href={d.tracking_url} target="_blank" rel="noreferrer">{d.tracking_no} <ExternalLink className="size-3" /></a>
                    : <span className="font-mono">{d.tracking_no}</span>}</>}
                </p>
              )}
              {d.delivered_at && <p className="text-success">Delivered {day(d.delivered_at)}{d.delivery_remarks && ` · ${d.delivery_remarks}`}</p>}
              <div className="flex flex-wrap gap-3">
                <a className="inline-flex items-center gap-1 text-brand hover:underline" href={`/api/portal/v1/challans/${d.id}/pdf`} target="_blank" rel="noreferrer"><FileText className="size-4" /> Challan</a>
                {d.invoice_voucher_id && <a className="inline-flex items-center gap-1 text-brand hover:underline" href={`/api/portal/v1/invoices/${d.invoice_voucher_id}/pdf`} target="_blank" rel="noreferrer"><FileText className="size-4" /> Invoice</a>}
                {d.documents.map((doc, i) => (
                  <a key={doc.id} className="inline-flex items-center gap-1 text-brand hover:underline" href={`/api/portal/v1/dispatches/${d.id}/documents/${doc.id}`} target="_blank" rel="noreferrer"><Paperclip className="size-4" /> Document {i + 1}</a>
                ))}
              </div>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}

function Fact({ label, value, icon }: { label: string; value: string; icon?: React.ReactNode }) {
  return (
    <div>
      <p className="flex items-center gap-1 text-xs text-muted-foreground">{icon}{label}</p>
      <p className="mt-0.5 font-medium">{value}</p>
    </div>
  )
}

function LineRow({ line: l, orderId, editable }: { line: Line; orderId: number; editable: boolean }) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [custom, setCustom] = useState(l.customization ?? '')
  const [file, setFile] = useState<File | null>(null)
  const save = useMutation({
    mutationFn: () => {
      const b = new FormData()
      b.append('customization', custom)
      if (l.remarks) b.append('remarks', l.remarks)
      if (file) b.append('reference', file)
      return api.post(`/api/portal/v1/orders/${orderId}/lines/${l.id}`, b)
    },
    onSuccess: () => { setOpen(false); setFile(null); qc.invalidateQueries({ queryKey: ['portal', 'order', String(orderId)] }) },
  })

  return (
    <div className="p-4">
      <div className="flex items-center gap-3">
        {l.design.photo ? <img src={l.design.photo} alt="" className="size-14 rounded-lg object-cover" /> : <div className="size-14 rounded-lg bg-muted" />}
        <div className="min-w-0 flex-1">
          <p className="font-medium">{l.design.name} <span className="font-mono text-xs text-muted-foreground">{l.design.code}</span></p>
          <p className="text-sm text-muted-foreground">{l.karat}{l.size && ` · size ${l.size}`}{l.remarks && ` · ${l.remarks}`}</p>
          {l.customization && <p className="text-sm"><span className="text-muted-foreground">Customisation:</span> {l.customization}</p>}
          {l.reference_image && <a className="text-xs text-brand hover:underline" href={l.reference_image} target="_blank" rel="noreferrer">Reference photo</a>}
        </div>
        <div className="text-right">
          <p className="tabular text-sm">{l.qty_dispatched}/{l.qty}</p>
          {editable && <button className="text-xs font-medium text-brand hover:underline" onClick={() => setOpen(!open)}>{open ? 'Close' : 'Customise'}</button>}
        </div>
      </div>
      {open && (
        <form className="mt-3 grid gap-3 rounded-lg bg-muted/50 p-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          {save.error instanceof ApiError && <Alert>{save.error.message}</Alert>}
          <Field label="Customisation"><Textarea value={custom} onChange={(e) => setCustom(e.target.value)} placeholder="Engraving, stone colour, finish…" /></Field>
          <Field label="Reference photo"><Input type="file" accept="image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
          <div><Button type="submit" loading={save.isPending}><ImagePlus /> Save</Button></div>
        </form>
      )}
    </div>
  )
}
