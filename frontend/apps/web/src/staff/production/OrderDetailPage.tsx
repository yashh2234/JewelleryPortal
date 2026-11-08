import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { ORDER_STATUS, type OrderDetail } from './types'

const STEPS = ['placed', 'confirmed', 'in_production', 'ready', 'dispatched'] as const

export default function OrderDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const can = useCan()
  const q = useQuery({ queryKey: ['order', id], queryFn: () => api.get<{ data: OrderDetail }>(`/api/v1/orders/${id}`) })
  const o = q.data?.data

  const act = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: object }) => api.post(`/api/v1/orders/${id}/${path}`, body),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['order', id] }); qc.invalidateQueries({ queryKey: ['orders'] }) },
  })
  const error = act.error instanceof ApiError ? act.error : null

  if (!o) return <p className="text-muted-foreground">Loading…</p>
  const reached = STEPS.indexOf(o.status === 'partially_dispatched' ? 'ready' : (o.status as (typeof STEPS)[number]))
  const unplanned = o.lines.some((l) => l.work_orders.length === 0)

  return (
    <>
      <PageTitle title={`Order ${o.order_no}`}>
        <Badge tone={ORDER_STATUS[o.status].tone}>{ORDER_STATUS[o.status].label}</Badge>
        {can('orders.manage') && o.status === 'placed' && <Button onClick={() => act.mutate({ path: 'confirm' })}>Confirm order</Button>}
        {can('production.manage') && ['confirmed', 'in_production'].includes(o.status) && unplanned && (
          <Button onClick={() => act.mutate({ path: 'work-orders' })}>Create work orders</Button>
        )}
        {can('orders.manage') && ['ready', 'partially_dispatched'].includes(o.status) && (
          <Link to={`/staff/finished?order=${o.id}&retailer=${o.retailer.id}`}><Button>Dispatch</Button></Link>
        )}
        {can('orders.manage') && ['placed', 'confirmed', 'in_production', 'ready'].includes(o.status) && (
          <Button variant="ghost" onClick={() => act.mutate({ path: 'hold' })}>Hold</Button>
        )}
        {can('orders.manage') && o.status === 'on_hold' && <Button variant="ghost" onClick={() => act.mutate({ path: 'resume' })}>Resume</Button>}
        {can('orders.manage') && ['placed', 'confirmed', 'on_hold', 'in_production'].includes(o.status) && (
          <Button variant="ghost" className="text-danger" onClick={() => {
            const reason = prompt('Cancel this order? Reason:')
            if (reason) act.mutate({ path: 'cancel', body: { reason } })
          }}>Cancel</Button>
        )}
      </PageTitle>

      {error && <div className="mb-3"><Alert>{error.message}</Alert></div>}

      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          {STEPS.map((s, i) => (
            <div key={s} className="flex items-center gap-2">
              <span className={`rounded-full px-3 py-1 ${i <= reached ? 'bg-brand text-brand-foreground' : 'bg-muted text-muted-foreground'}`}>{ORDER_STATUS[s].label}</span>
              {i < STEPS.length - 1 && <span className="text-muted-foreground">→</span>}
            </div>
          ))}
        </div>
        <p className="mt-3 text-sm">
          <b>{o.retailer.shop_name}</b> · due {fmtDate(o.due_date)} · from {o.source}
          {o.remarks && <span className="text-muted-foreground"> · {o.remarks}</span>}
          {o.cancel_reason && <span className="text-danger"> · cancelled: {o.cancel_reason}</span>}
        </p>
      </Card>

      <Card className="mb-4">
        <CardHeader title="Lines" />
        <Table head={['Design', 'Karat', 'Size', 'Qty', 'Target wt', 'Dispatched', 'Work order']}>
          {o.lines.map((l) => (
            <tr key={l.id}>
              <Td>
                <div className="flex items-center gap-2">
                  {l.design.images?.[0] && <img src={l.design.images[0].thumb_url ?? l.design.images[0].url} alt="" className="size-10 rounded object-cover" />}
                  <div><span className="font-mono text-xs">{l.design.code}</span><div>{l.design.name}</div></div>
                </div>
                {l.remarks && <div className="text-xs text-muted-foreground">{l.remarks}</div>}
              </Td>
              <Td>{l.karat.label}</Td>
              <Td>{l.size ?? '—'}</Td>
              <Td className="tabular">{l.qty}</Td>
              <Td className="tabular">{l.target_wt_min ? `${l.target_wt_min}–${l.target_wt_max} g` : '—'}</Td>
              <Td className="tabular">{l.qty_dispatched}</Td>
              <Td>{l.work_orders.map((w) => <Link key={w.id} className="mr-2 text-brand hover:underline" to={`/staff/work-orders/${w.id}`}>{w.wo_no}</Link>)}{l.work_orders.length === 0 && '—'}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      {o.dispatches.length > 0 && (
        <Card>
          <CardHeader title="Dispatches" />
          <Table head={['Challan', 'Date', 'Pieces', 'Fine g', '']}>
            {o.dispatches.map((d) => (
              <tr key={d.id}>
                <Td className="font-mono text-xs">{d.challan_no}</Td><Td>{fmtDate(d.date)}</Td><Td>{d.pieces}</Td><Td className="tabular">{d.fine_wt}</Td>
                <Td><a className="text-brand hover:underline" href={`/api/v1/dispatches/${d.id}/challan`} target="_blank" rel="noreferrer">Challan PDF</a></Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
    </>
  )
}
