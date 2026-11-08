import { Alert, api, ApiError, Badge, Button, Card, CardHeader, EmptyState, Field, Input, Select, Table, Td, Textarea, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate, useKarats } from '../metal/shared'
import type { Design, Retailer } from '../types'
import { ORDER_STATUS, type OrderRow } from './types'

export default function OrdersPage() {
  const can = useCan()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)

  const orders = useQuery({
    queryKey: ['orders', status, search],
    queryFn: () => api.get<Paginated<OrderRow>>(`/api/v1/orders?status=${status}&search=${encodeURIComponent(search)}`),
  })

  return (
    <>
      <PageTitle title="Orders">
        <Input className="w-40" placeholder="Order no." value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select className="w-48" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All open & recent</option>
          {Object.entries(ORDER_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </Select>
        {can('orders.manage') && <Button onClick={() => setCreating(!creating)}>{creating ? 'Close' : 'New order'}</Button>}
      </PageTitle>

      {creating && <NewOrder onDone={() => setCreating(false)} />}

      <Card>
        {orders.data?.data.length === 0 ? <EmptyState title="No orders yet">Orders come from the retailer portal or are entered here for phone/WhatsApp orders.</EmptyState> : (
          <Table head={['Order', 'Retailer', 'Lines', 'Pieces', 'Due', 'Status']}>
            {orders.data?.data.map((o) => (
              <tr key={o.id}>
                <Td className="font-medium"><Link className="hover:underline" to={`/staff/orders/${o.id}`}>{o.order_no}</Link>
                  <div className="text-xs text-muted-foreground">{fmtDate(o.created_at)} · {o.source}</div></Td>
                <Td>{o.retailer.shop_name}<div className="text-xs text-muted-foreground">{o.retailer.city}</div></Td>
                <Td className="tabular">{o.lines_count}</Td>
                <Td className="tabular">{o.qty_dispatched_total}/{o.qty_total}</Td>
                <Td>{fmtDate(o.due_date)}</Td>
                <Td><Badge tone={ORDER_STATUS[o.status].tone}>{ORDER_STATUS[o.status].label}</Badge></Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  )
}

interface Line { design_id: string; karat_id: string; size: string; qty: string; target_wt_min: string; target_wt_max: string; stone_details: string; remarks: string }
const emptyLine: Line = { design_id: '', karat_id: '', size: '', qty: '1', target_wt_min: '', target_wt_max: '', stone_details: '', remarks: '' }

function NewOrder({ onDone }: { onDone: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const karats = useKarats()
  const retailers = useQuery({ queryKey: ['retailers', 'active'], queryFn: () => api.get<Paginated<Retailer>>('/api/v1/retailers?status=active&per_page=500') })
  const designs = useQuery({ queryKey: ['designs', 'all'], queryFn: () => api.get<Paginated<Design>>('/api/v1/designs?per_page=500') })
  const [retailerId, setRetailerId] = useState('')
  const [due, setDue] = useState('')
  const [remarks, setRemarks] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine])

  const set = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const pickDesign = (i: number, id: string) => {
    const d = designs.data?.data.find((x) => String(x.id) === id)
    set(i, {
      design_id: id,
      karat_id: lines[i].karat_id || String(d?.karats?.[0]?.id ?? ''),
      target_wt_min: d?.approx_weight_min ?? '',
      target_wt_max: d?.approx_weight_max ?? '',
    })
  }

  const save = useMutation({
    mutationFn: () => api.post<{ data: { id: number } }>('/api/v1/orders', {
      retailer_id: Number(retailerId), due_date: due || null, remarks: remarks || null,
      lines: lines.filter((l) => l.design_id).map((l) => ({
        design_id: Number(l.design_id), karat_id: Number(l.karat_id), qty: Number(l.qty), size: l.size || null,
        target_wt_min: l.target_wt_min || null, target_wt_max: l.target_wt_max || null,
        stone_details: l.stone_details || null, remarks: l.remarks || null,
      })),
    }),
    onSuccess: (r) => { qc.invalidateQueries({ queryKey: ['orders'] }); onDone(); navigate(`/staff/orders/${r.data.id}`) },
  })
  const error = save.error instanceof ApiError ? save.error : null

  return (
    <Card className="mb-4">
      <CardHeader title="New order" description="For phone / WhatsApp orders. Portal orders arrive here by themselves." />
      <form className="grid gap-4 p-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Retailer">
            <Select required value={retailerId} onChange={(e) => setRetailerId(e.target.value)}>
              <option value="">Choose…</option>
              {retailers.data?.data.map((r) => <option key={r.id} value={r.id}>{r.shop_name}{r.city ? `, ${r.city}` : ''}</option>)}
            </Select>
          </Field>
          <Field label="Delivery date"><Input type="date" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
        </div>
        <div className="grid gap-2">
          {lines.map((l, i) => (
            <div key={i} className="grid grid-cols-2 items-end gap-2 rounded-md border p-2 sm:grid-cols-[1fr_7rem_6rem_5rem_6rem_6rem_1fr_auto]">
              <Field label={i === 0 ? 'Design' : ''}>
                <Select required value={l.design_id} onChange={(e) => pickDesign(i, e.target.value)}>
                  <option value="">—</option>
                  {designs.data?.data.map((d) => <option key={d.id} value={d.id}>{d.code} · {d.name}</option>)}
                </Select>
              </Field>
              <Field label={i === 0 ? 'Karat' : ''}>
                <Select required value={l.karat_id} onChange={(e) => set(i, { karat_id: e.target.value })}>
                  <option value="">—</option>
                  {karats.data?.map((k) => <option key={k.id} value={k.id}>{k.label}</option>)}
                </Select>
              </Field>
              <Field label={i === 0 ? 'Size' : ''}><Input value={l.size} onChange={(e) => set(i, { size: e.target.value })} /></Field>
              <Field label={i === 0 ? 'Qty' : ''}><Input required inputMode="numeric" value={l.qty} onChange={(e) => set(i, { qty: e.target.value.replace(/\D/g, '') })} /></Field>
              <Field label={i === 0 ? 'Wt min g' : ''}><WeightInput scale={false} value={l.target_wt_min} onValueChange={(v) => set(i, { target_wt_min: v })} /></Field>
              <Field label={i === 0 ? 'Wt max g' : ''}><WeightInput scale={false} value={l.target_wt_max} onValueChange={(v) => set(i, { target_wt_max: v })} /></Field>
              <Field label={i === 0 ? 'Stones / remarks' : ''}><Input value={l.remarks} onChange={(e) => set(i, { remarks: e.target.value })} /></Field>
              <Button type="button" size="icon" variant="ghost" disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, j) => j !== i))} aria-label="Remove"><Trash2 /></Button>
            </div>
          ))}
          <div><Button type="button" size="sm" variant="ghost" onClick={() => setLines([...lines, { ...emptyLine, karat_id: lines.at(-1)?.karat_id ?? '' }])}><Plus /> Add line</Button></div>
        </div>
        <Field label="Remarks"><Textarea value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
        <div><Button type="submit" loading={save.isPending}>Save order</Button></div>
      </form>
    </Card>
  )
}
