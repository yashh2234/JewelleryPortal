import { Alert, api, ApiError, Badge, Button, Card, CardHeader, cn, Field, Input, rupees, Select, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ExternalLink, FileText, ImageIcon } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { ORDER_STATUS, PAYMENT_STATUS, SHIPMENT_STATUS, type OrderDetail, type OrderPayments, type OrderStatus } from './types'

interface Resp {
  data: OrderDetail
  position: { outstanding: string; metal: string; credit_limit: string; over_limit: boolean }
  payments: OrderPayments
  flow: OrderStatus[]
}

export default function OrderDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const can = useCan()
  const q = useQuery({ queryKey: ['order', id], queryFn: () => api.get<Resp>(`/api/v1/orders/${id}`) })
  const o = q.data?.data

  const act = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: object }) => api.post(`/api/v1/orders/${id}/${path}`, body),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['order', id] }); qc.invalidateQueries({ queryKey: ['orders'] }) },
  })
  const error = act.error instanceof ApiError ? act.error : null

  if (!o || !q.data) return <p className="text-muted-foreground">Loading…</p>
  const unplanned = o.lines.some((l) => l.work_orders.length === 0)
  const s = o.status
  const open = !['cancelled', 'closed'].includes(s)

  return (
    <>
      <PageTitle title={`Order ${o.order_no}`} description={`${o.retailer.shop_name} · placed ${fmtDate(o.created_at)} · from ${o.source}`}>
        <Badge tone={ORDER_STATUS[s].tone}>{ORDER_STATUS[s].label}</Badge>
        {can('orders.manage') && s === 'new' && <Button variant="outline" onClick={() => act.mutate({ path: 'submit' })}>Send for approval</Button>}
        {can('orders.approve') && ['new', 'pending_approval'].includes(s) && <Button onClick={() => act.mutate({ path: 'approve' })}><Check /> Approve</Button>}
        {can('production.manage') && ['approved', 'work_order_created', 'material_issued', 'in_production'].includes(s) && unplanned && (
          <Button onClick={() => act.mutate({ path: 'work-orders' })}>Create work orders</Button>
        )}
        {can('dispatch.manage') && ['ready_for_dispatch', 'partially_dispatched', 'production_completed'].includes(s) && (
          <Link to={`/staff/finished?order=${o.id}&retailer=${o.retailer.id}`}><Button>Dispatch</Button></Link>
        )}
        {can('orders.approve') && ['dispatched', 'delivered'].includes(s) && <Button variant="outline" onClick={() => act.mutate({ path: 'close' })}>Close order</Button>}
        {can('orders.manage') && open && !['on_hold', 'dispatched', 'delivered', 'partially_dispatched'].includes(s) && (
          <Button variant="ghost" onClick={() => act.mutate({ path: 'hold' })}>Hold</Button>
        )}
        {can('orders.manage') && s === 'on_hold' && <Button variant="ghost" onClick={() => act.mutate({ path: 'resume' })}>Resume</Button>}
        {can('orders.approve') && ['new', 'pending_approval', 'approved', 'on_hold', 'work_order_created', 'material_issued', 'in_production'].includes(s) && (
          <Button variant="ghost" className="text-danger" onClick={() => {
            const reason = prompt('Cancel this order? Reason:')
            if (reason) act.mutate({ path: 'cancel', body: { reason } })
          }}>Cancel</Button>
        )}
      </PageTitle>

      {error && <div className="mb-4"><Alert>{error.message}</Alert></div>}
      {o.cancel_reason && <div className="mb-4"><Alert>Cancelled: {o.cancel_reason}</Alert></div>}

      <Timeline flow={q.data.flow} status={s} />

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Info label="Retailer">
              <Link className="font-medium text-brand hover:underline" to={`/staff/retailers/${o.retailer.id}`}>{o.retailer.shop_name}</Link>
              {o.retailer.city && <span className="text-muted-foreground"> · {o.retailer.city}</span>}
            </Info>
            <Info label="Salesperson">{o.retailer.salesperson?.name ?? '—'}</Info>
            <Info label="Expected delivery">{fmtDate(o.due_date)}</Info>
            <Info label="Approved">{o.approved_at ? `${fmtDate(o.approved_at)}${o.approver ? ` by ${o.approver.name}` : ''}` : '—'}</Info>
            {o.remarks && <Info label="Instructions" wide>{o.remarks}</Info>}
          </dl>
          {q.data.position && (
            <p className={cn('mt-4 rounded-lg px-3 py-2 text-sm', q.data.position.over_limit ? 'bg-danger/10 font-medium text-danger' : 'bg-muted/60 text-muted-foreground')}>
              Retailer outstanding {rupees(q.data.position.outstanding)}
              {Number(q.data.position.credit_limit) > 0 && ` of ${rupees(q.data.position.credit_limit)} credit limit`}
              {Number(q.data.position.metal) !== 0 && ` · metal ${Math.abs(Number(q.data.position.metal)).toFixed(3)} g ${Number(q.data.position.metal) < 0 ? 'deposited with you' : 'owed to you'}`}
              {q.data.position.over_limit && ' · OVER CREDIT LIMIT'}
            </p>
          )}
        </Card>

        <Payments orderId={o.id} p={q.data.payments} canRecord={can('orders.manage|accounts.manage') && open} />
      </div>

      <Card className="mt-4">
        <CardHeader title="Designs" description={`${o.lines.reduce((n, l) => n + l.qty, 0)} pieces`} />
        <Table head={['Design', 'Karat', 'Size', 'Qty', 'Expected wt', 'Dispatched', 'Work order']}>
          {o.lines.map((l) => (
            <tr key={l.id}>
              <Td>
                <div className="flex items-center gap-3">
                  {l.design.images?.[0]
                    ? <img src={l.design.images[0].thumb_url ?? l.design.images[0].url} alt="" className="size-11 rounded-lg object-cover" />
                    : <div className="grid size-11 place-items-center rounded-lg bg-muted text-muted-foreground"><ImageIcon className="size-4" /></div>}
                  <div>
                    <div className="font-medium">{l.design.name}</div>
                    <span className="font-mono text-xs text-muted-foreground">{l.design.code}</span>
                  </div>
                </div>
                {(l.remarks || l.customization || l.stone_details || l.reference_image) && (
                  <div className="mt-2 grid gap-0.5 text-xs text-muted-foreground">
                    {l.customization && <span><b className="font-medium text-foreground">Customisation:</b> {l.customization}</span>}
                    {l.stone_details && <span>Stones: {l.stone_details}</span>}
                    {l.remarks && <span>{l.remarks}</span>}
                    {l.reference_image && <a className="inline-flex items-center gap-1 text-brand hover:underline" href={`/storage/${l.reference_image}`} target="_blank" rel="noreferrer"><ImageIcon className="size-3" /> Reference photo</a>}
                  </div>
                )}
              </Td>
              <Td>{l.karat.label}</Td>
              <Td>{l.size ?? '—'}</Td>
              <Td className="tabular">{l.qty}</Td>
              <Td className="tabular">{l.target_wt_min ? `${l.target_wt_min}–${l.target_wt_max} g` : '—'}</Td>
              <Td className="tabular">{l.qty_dispatched}/{l.qty}</Td>
              <Td>
                {l.work_orders.map((w) => <Link key={w.id} className="mr-2 font-medium text-brand hover:underline" to={`/staff/work-orders/${w.id}`}>{w.wo_no}</Link>)}
                {l.work_orders.length === 0 && <span className="text-muted-foreground">—</span>}
              </Td>
            </tr>
          ))}
        </Table>
      </Card>

      {o.dispatches.length > 0 && (
        <Card className="mt-4">
          <CardHeader title="Shipments" actions={<Link to="/staff/dispatches"><Button variant="outline" size="sm">Update tracking</Button></Link>} />
          <Table head={['Challan', 'Date', 'Pieces', 'Fine g', 'Status', 'Courier', '']}>
            {o.dispatches.map((d) => (
              <tr key={d.id}>
                <Td className="font-mono text-xs">{d.challan_no}</Td>
                <Td>{fmtDate(d.date)}</Td>
                <Td className="tabular">{d.pieces}</Td>
                <Td className="tabular">{d.fine_wt}</Td>
                <Td><Badge tone={SHIPMENT_STATUS[d.shipment_status]?.tone ?? 'neutral'}>{SHIPMENT_STATUS[d.shipment_status]?.label ?? d.shipment_status}</Badge></Td>
                <Td>
                  {d.courier ?? '—'}
                  {d.tracking_no && (d.tracking_url
                    ? <a className="ml-1 inline-flex items-center gap-0.5 text-brand hover:underline" href={d.tracking_url} target="_blank" rel="noreferrer">{d.tracking_no} <ExternalLink className="size-3" /></a>
                    : <span className="ml-1 font-mono text-xs">{d.tracking_no}</span>)}
                </Td>
                <Td className="text-right">
                  <a className="inline-flex items-center gap-1 text-brand hover:underline" href={`/api/v1/dispatches/${d.id}/challan`} target="_blank" rel="noreferrer"><FileText className="size-3.5" /> Challan</a>
                </Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
    </>
  )
}

function Info({ label, children, wide }: { label: string; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className={wide ? 'sm:col-span-2' : ''}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  )
}

/** All 13 statuses; the ones the order has passed are filled. Rework and hold show as a branch. */
function Timeline({ flow, status }: { flow: OrderStatus[]; status: OrderStatus }) {
  const at = status === 'partially_dispatched' ? flow.indexOf('ready_for_dispatch')
    : status === 'rework_required' ? flow.indexOf('under_qc')
    : flow.indexOf(status)
  const steps = flow.filter((f) => f !== 'rework_required')

  return (
    <Card className="overflow-x-auto p-4">
      <ol className="flex min-w-max items-start gap-0">
        {steps.map((f, i) => {
          const idx = flow.indexOf(f)
          const done = at >= 0 && idx < at
          const current = idx === at
          return (
            <li key={f} className="flex items-start">
              <div className="flex w-[5.5rem] flex-col items-center text-center">
                <span className={cn('grid size-7 place-items-center rounded-full border-2 text-[0.6875rem] font-semibold transition-colors',
                  done ? 'border-brand bg-brand text-brand-foreground' : current ? 'border-brand bg-brand/10 text-brand ring-4 ring-brand/10' : 'border-border text-muted-foreground')}>
                  {done ? <Check className="size-3.5" /> : i + 1}
                </span>
                <span className={cn('mt-1.5 text-[0.6875rem] leading-tight', current ? 'font-semibold text-brand' : done ? 'text-foreground' : 'text-muted-foreground')}>
                  {ORDER_STATUS[f].label}
                </span>
              </div>
              {i < steps.length - 1 && <span className={cn('mt-3.5 h-0.5 w-4 rounded-full', done ? 'bg-brand' : 'bg-border')} />}
            </li>
          )
        })}
      </ol>
      {['rework_required', 'on_hold', 'cancelled', 'partially_dispatched'].includes(status) && (
        <p className="mt-3 text-xs"><Badge tone={ORDER_STATUS[status].tone}>{ORDER_STATUS[status].label}</Badge></p>
      )}
    </Card>
  )
}

function Payments({ orderId, p, canRecord }: { orderId: number; p: OrderPayments; canRecord: boolean }) {
  const qc = useQueryClient()
  const [amount, setAmount] = useState('')
  const [mode, setMode] = useState('bank')
  const save = useMutation({
    mutationFn: () => api.post(`/api/v1/orders/${orderId}/advance`, { amount, mode }),
    onSuccess: () => { setAmount(''); qc.invalidateQueries({ queryKey: ['order', String(orderId)] }) },
  })
  const status = PAYMENT_STATUS[p.status] ?? { label: p.status, tone: 'neutral' as const }

  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <h2 className="font-semibold tracking-tight">Payment</h2>
        <Badge tone={status.tone}>{status.label}</Badge>
      </div>
      <dl className="mt-3 grid gap-2 text-sm">
        <div className="flex justify-between"><dt className="text-muted-foreground">{p.value_is_estimate ? 'Order value (estimate)' : 'Invoiced'}</dt><dd className="tabular font-medium">{rupees(p.value)}</dd></div>
        <div className="flex justify-between"><dt className="text-muted-foreground">Received</dt><dd className="tabular font-medium">{rupees(p.paid)}</dd></div>
        <div className="flex justify-between border-t pt-2"><dt className="text-muted-foreground">Balance</dt><dd className="tabular font-semibold">{rupees(p.balance)}</dd></div>
      </dl>
      {p.receipts.length > 0 && (
        <div className="mt-3 grid gap-1 text-xs text-muted-foreground">
          {p.receipts.map((r) => <div key={r.id} className={cn('flex justify-between', r.status === 'cancelled' && 'line-through')}><span>{r.voucher_no} · {fmtDate(r.date)}</span><span className="tabular">{rupees(r.amount)}</span></div>)}
        </div>
      )}
      {canRecord && (
        <form className="mt-4 grid grid-cols-[1fr_auto] gap-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <Field label="Record advance / payment" className="col-span-2">
            <div className="flex gap-2">
              <Input inputMode="decimal" placeholder="₹ amount" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} />
              <Select className="w-24" value={mode} onChange={(e) => setMode(e.target.value)}><option value="bank">Bank</option><option value="cash">Cash</option></Select>
            </div>
          </Field>
          {save.error instanceof ApiError && <div className="col-span-2"><Alert>{save.error.message}</Alert></div>}
          <Button className="col-span-2" variant="outline" type="submit" disabled={!amount} loading={save.isPending}>Save receipt</Button>
        </form>
      )}
    </Card>
  )
}
