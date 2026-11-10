import { Alert, api, ApiError, Badge, Button, Card, Checkbox, Field, Input, Select, Table, Td, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { useCan } from '../auth'
import { SHOW_EXTRAS } from '../../workflow'
import { PageTitle } from '../layout'
import { useKarats, useProcesses } from '../metal/shared'
import type { Retailer } from '../types'
import type { Ahc, FinishedItem, OrderRow } from './types'

type Action = 'qc' | 'rework' | 'hallmark' | 'dispatch' | 'location' | null

/** Finished pieces in stock. Select pieces → QC, rework, hallmark lot, or dispatch. */
export default function FinishedPage() {
  const qc = useQueryClient()
  const can = useCan()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [filter, setFilter] = useState(params.get('f') ?? (params.get('order') ? 'ready' : 'in_stock'))
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<number[]>([])
  const [action, setAction] = useState<Action>(params.get('order') ? 'dispatch' : null)
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null)

  const query = {
    in_stock: 'status=in_stock',
    ready: 'status=in_stock&qc=passed',
    qc: 'status=in_stock&qc=pending',
    failed: 'status=in_stock&qc=failed',
    at_ahc: 'status=at_ahc',
    dispatched: 'status=dispatched',
  }[filter]
  const orderFilter = params.get('order') ? `&order_id=${params.get('order')}` : ''

  const items = useQuery({
    queryKey: ['finished', filter, search, orderFilter],
    queryFn: () => api.get<Paginated<FinishedItem>>(`/api/v1/finished-items?${query}${orderFilter}&search=${encodeURIComponent(search)}&per_page=200`),
  })
  const list = items.data?.data ?? []
  const chosen = list.filter((i) => selected.includes(i.id))
  const fine = chosen.reduce((s, i) => s + Math.round(Number(i.fine_wt) * 1000), 0) / 1000

  const done = (text: string) => {
    setMessage({ tone: 'success', text })
    setSelected([])
    setAction(null)
    qc.invalidateQueries({ queryKey: ['finished'] })
    qc.invalidateQueries({ queryKey: ['metal'] })
  }
  const fail = (e: unknown) => setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Failed' })

  return (
    <>
      <PageTitle title="Finished goods">
        <Input className="w-44" placeholder="Tag or HUID" value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select className="w-48" value={filter} onChange={(e) => { setFilter(e.target.value); setSelected([]) }}>
          <option value="in_stock">In stock (all)</option>
          <option value="qc">Waiting for QC</option>
          <option value="ready">QC passed</option>
          <option value="failed">QC / hallmark failed</option>
          <option value="at_ahc">At hallmarking centre</option>
          <option value="dispatched">Dispatched</option>
        </Select>
      </PageTitle>

      {message && <div className="mb-3"><Alert tone={message.tone}>{message.text}</Alert></div>}

      {selected.length > 0 && can('production.manage|qc.manage|dispatch.manage') && (
        <Card className="sticky top-16 z-10 mb-3 flex flex-wrap items-center gap-2 p-3">
          <span className="text-sm font-medium">{selected.length} selected · {fine.toFixed(3)} g fine</span>
          {can('qc.manage') && <Button size="sm" variant={action === 'qc' ? 'default' : 'outline'} onClick={() => setAction('qc')}>Quality check</Button>}
          {SHOW_EXTRAS && can('production.manage') && <Button size="sm" variant={action === 'hallmark' ? 'default' : 'outline'} onClick={() => setAction('hallmark')}>Hallmark lot</Button>}
          {can('qc.manage') && <Button size="sm" variant={action === 'rework' ? 'default' : 'outline'} onClick={() => setAction('rework')}>Rework</Button>}
          <Button size="sm" variant={action === 'location' ? 'default' : 'outline'} onClick={() => setAction('location')}>Storage</Button>
          {can('dispatch.manage') && <Button size="sm" variant={action === 'dispatch' ? 'default' : 'outline'} onClick={() => setAction('dispatch')}>Dispatch</Button>}
          <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
        </Card>
      )}

      {action === 'qc' && selected.length > 0 && <QcForm ids={selected} onDone={done} onError={fail} />}
      {action === 'location' && selected.length > 0 && <LocationForm ids={selected} onDone={done} onError={fail} />}
      {action === 'rework' && selected.length > 0 && <ReworkForm ids={selected} onDone={(no) => done(`Rework ${no} created.`)} onError={fail} />}
      {action === 'hallmark' && selected.length > 0 && <LotForm items={chosen} onDone={(id) => { done('Lot created.'); navigate(`/staff/hallmarking/${id}`) }} onError={fail} />}
      {action === 'dispatch' && <DispatchForm ids={selected} defaultRetailer={params.get('retailer')} defaultOrder={params.get('order')} onDone={(no) => done(`Dispatched on challan ${no}.`)} onError={fail} />}

      <Card>
        <Table head={[
          <input key="all" type="checkbox" aria-label="Select all" checked={list.length > 0 && selected.length === list.length}
            onChange={(e) => setSelected(e.target.checked ? list.map((i) => i.id) : [])} />,
          'Tag', 'Design', 'Size', 'Gross', 'Net', 'Fine', 'QC', 'HUID', 'Where', 'Order',
        ]}>
          {list.map((i) => (
            <tr key={i.id} className={selected.includes(i.id) ? 'bg-brand/5' : undefined}>
              <Td><input type="checkbox" checked={selected.includes(i.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, i.id] : selected.filter((x) => x !== i.id))} aria-label={`Select ${i.tag_no}`} /></Td>
              <Td className="font-mono text-xs">{i.tag_no}<div className="text-muted-foreground">{i.work_order?.wo_no}</div></Td>
              <Td>{i.design?.code} <span className="text-muted-foreground">{i.design?.name}</span> · {i.karat?.label}</Td>
              <Td>{i.size ?? '—'}</Td>
              <Td className="tabular">{i.gross_wt}</Td>
              <Td className="tabular">{i.net_wt}</Td>
              <Td className="tabular">{i.fine_wt}</Td>
              <Td>
                <Badge tone={i.qc_status === 'passed' ? 'success' : i.qc_status === 'failed' ? 'danger' : 'neutral'}>{i.qc_status}</Badge>
                {i.qc_defects && <div className="mt-0.5 max-w-40 truncate text-xs text-danger" title={i.qc_defects}>{i.qc_defects}</div>}
              </Td>
              <Td className="font-mono">{i.huid ?? '—'}</Td>
              <Td className="text-xs">{i.storage_location ?? '—'}</Td>
              <Td className="text-xs">{i.order_line?.order.order_no ?? 'stock'}</Td>
            </tr>
          ))}
        </Table>
        {list.length === 0 && <p className="p-4 text-sm text-muted-foreground">Nothing here.</p>}
      </Card>
    </>
  )
}

const QC_CHECKS: [string, string][] = [
  ['design', 'Design matches the order'], ['quantity', 'Quantity correct'], ['weight', 'Weight within range'],
  ['purity', 'Purity / karat correct'], ['finishing', 'Finishing & polish'], ['stone_setting', 'Stone setting secure'],
]

/** Spec §8: checklist to pass; defects (and optionally a rework route) to fail. */
function QcForm({ ids, onDone, onError }: { ids: number[]; onDone: (t: string) => void; onError: (e: unknown) => void }) {
  const processes = useProcesses()
  const [checks, setChecks] = useState<Record<string, boolean>>(Object.fromEntries(QC_CHECKS.map(([k]) => [k, false])))
  const [remarks, setRemarks] = useState('')
  const [defects, setDefects] = useState('')
  const [route, setRoute] = useState<number[]>([])
  const all = Object.values(checks).every(Boolean)

  const m = useMutation({
    mutationFn: (result: 'passed' | 'failed') => api.post<{ updated: number; rework: { wo_no: string } | null }>('/api/v1/finished-items/qc', {
      item_ids: ids, result, remarks: remarks || null, checklist: checks, defects: defects || null,
      rework_process_ids: result === 'failed' && route.length ? route : null,
    }),
    onSuccess: (r, result) => onDone(`${r.updated} piece(s) QC ${result}.${r.rework ? ` Rework ${r.rework.wo_no} sent to production.` : ''}`),
    onError,
  })

  return (
    <Card className="mb-3 p-4">
      <p className="mb-3 text-sm font-semibold">Quality check · {ids.length} piece(s)</p>
      <div className="grid gap-2 sm:grid-cols-3">
        {QC_CHECKS.map(([k, label]) => (
          <label key={k} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors ${checks[k] ? 'border-success/40 bg-success/[0.06]' : ''}`}>
            <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={checks[k]} onChange={(e) => setChecks({ ...checks, [k]: e.target.checked })} />
            {label}
          </label>
        ))}
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <Field label="Defects found (for a fail)"><Input value={defects} placeholder="e.g. Stone loose on 2 pieces" onChange={(e) => setDefects(e.target.value)} /></Field>
        <Field label="Remarks"><Input value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
      </div>
      <div className="mt-3">
        <p className="mb-1.5 text-xs text-muted-foreground">On fail, send back for rework through (optional):</p>
        <div className="flex flex-wrap gap-1.5">
          {processes.data?.map((p) => (
            <button key={p.id} type="button" onClick={() => setRoute(route.includes(p.id) ? route.filter((x) => x !== p.id) : [...route, p.id])}
              className={`rounded-full border px-3 py-1 text-xs transition-colors ${route.includes(p.id) ? 'border-brand bg-brand text-brand-foreground' : 'hover:bg-muted'}`}>
              {route.includes(p.id) && `${route.indexOf(p.id) + 1}. `}{p.name}
            </button>
          ))}
        </div>
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        <Button onClick={() => m.mutate('passed')} disabled={!all} loading={m.isPending} title={all ? '' : 'Tick every check to pass'}>Approve</Button>
        <Button variant="danger" onClick={() => m.mutate('failed')} disabled={!defects && !remarks} loading={m.isPending}>Reject{route.length ? ' & send to rework' : ''}</Button>
        {!all && <span className="self-center text-xs text-muted-foreground">Tick every check to approve.</span>}
      </div>
    </Card>
  )
}

function LocationForm({ ids, onDone, onError }: { ids: number[]; onDone: (t: string) => void; onError: (e: unknown) => void }) {
  const [loc, setLoc] = useState('')
  const m = useMutation({
    mutationFn: () => api.post<{ updated: number }>('/api/v1/finished-items/location', { item_ids: ids, storage_location: loc || null }),
    onSuccess: (r) => onDone(`${r.updated} piece(s) moved to ${loc || 'no location'}.`),
    onError,
  })
  return (
    <Card className="mb-3 flex flex-wrap items-end gap-2 p-3">
      <Field label="Storage location" className="min-w-60 flex-1"><Input value={loc} placeholder="e.g. Safe A · Tray 3" onChange={(e) => setLoc(e.target.value)} /></Field>
      <Button onClick={() => m.mutate()} loading={m.isPending}>Save</Button>
    </Card>
  )
}

function ReworkForm({ ids, onDone, onError }: { ids: number[]; onDone: (no: string) => void; onError: (e: unknown) => void }) {
  const processes = useProcesses()
  const [route, setRoute] = useState<number[]>([])
  const [reason, setReason] = useState('')
  const m = useMutation({
    mutationFn: () => api.post<{ data: { wo_no: string } }>('/api/v1/finished-items/rework', { item_ids: ids, process_ids: route, reason }),
    onSuccess: (r) => onDone(r.data.wo_no),
    onError,
  })
  return (
    <Card className="mb-3 grid gap-3 p-3">
      <p className="text-sm">Opens a high-priority work order; the pieces' gold moves into it.</p>
      <div className="flex flex-wrap gap-3">
        {processes.data?.map((p) => (
          <Checkbox key={p.id} label={p.name} checked={route.includes(p.id)} onChange={(e) => setRoute(e.target.checked ? [...route, p.id] : route.filter((x) => x !== p.id))} />
        ))}
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <Field label="Reason" className="min-w-60 flex-1"><Input required value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        <Button disabled={!route.length || !reason} onClick={() => m.mutate()} loading={m.isPending}>Create rework WO</Button>
      </div>
    </Card>
  )
}

function LotForm({ items, onDone, onError }: { items: FinishedItem[]; onDone: (id: number) => void; onError: (e: unknown) => void }) {
  const karats = useKarats()
  const ahcs = useQuery({ queryKey: ['ahcs'], queryFn: () => api.get<{ data: Ahc[] }>('/api/v1/ahcs') })
  const [ahcId, setAhcId] = useState('')
  const karatId = items[0]?.karat_id
  const mixed = items.some((i) => i.karat_id !== karatId)
  const m = useMutation({
    mutationFn: () => api.post<{ data: { id: number } }>('/api/v1/hallmark-lots', { ahc_id: Number(ahcId), karat_id: karatId, item_ids: items.map((i) => i.id) }),
    onSuccess: (r) => onDone(r.data.id),
    onError,
  })
  return (
    <Card className="mb-3 flex flex-wrap items-end gap-2 p-3">
      {mixed ? <Alert>One lot has one declared purity. Select pieces of a single karat.</Alert> : (
        <>
          <Field label="Hallmarking centre" className="min-w-60">
            <Select value={ahcId} onChange={(e) => setAhcId(e.target.value)}>
              <option value="">Choose…</option>
              {ahcs.data?.data.filter((a) => a.is_active).map((a) => <option key={a.id} value={a.id}>{a.name}{a.city ? `, ${a.city}` : ''}</option>)}
            </Select>
          </Field>
          <p className="pb-2 text-sm">Declared purity: <b>{karats.data?.find((k) => k.id === karatId)?.label}</b></p>
          <Button disabled={!ahcId} onClick={() => m.mutate()} loading={m.isPending}>Create lot</Button>
          {ahcs.data?.data.length === 0 && <span className="pb-2 text-sm text-muted-foreground">Add an AHC on the Hallmarking page first.</span>}
        </>
      )}
    </Card>
  )
}

function DispatchForm({ ids, defaultRetailer, defaultOrder, onDone, onError }: { ids: number[]; defaultRetailer: string | null; defaultOrder: string | null; onDone: (no: string) => void; onError: (e: unknown) => void }) {
  const retailers = useQuery({ queryKey: ['retailers', 'active'], queryFn: () => api.get<Paginated<Retailer>>('/api/v1/retailers?status=active&per_page=500') })
  const [retailerId, setRetailerId] = useState(defaultRetailer ?? '')
  const [orderId, setOrderId] = useState(defaultOrder ?? '')
  const [remarks, setRemarks] = useState('')
  const [ship, setShip] = useState({ shipment_status: 'dispatched', courier: '', tracking_no: '' })
  const couriers = useQuery({ queryKey: ['couriers'], queryFn: () => api.get<{ data: string[] }>('/api/v1/couriers') })
  const orders = useQuery({
    queryKey: ['orders', 'dispatchable', retailerId],
    queryFn: () => api.get<Paginated<OrderRow>>(`/api/v1/orders?retailer_id=${retailerId}&status=production_completed,ready_for_dispatch,partially_dispatched,in_production,under_qc`),
    enabled: !!retailerId,
  })
  const m = useMutation({
    mutationFn: () => api.post<{ data: { id: number; challan_no: string } }>('/api/v1/dispatches', {
      retailer_id: Number(retailerId), order_id: orderId ? Number(orderId) : null, item_ids: ids, remarks: remarks || null,
      shipment_status: ship.shipment_status, courier: ship.courier || null, tracking_no: ship.tracking_no || null,
    }),
    onSuccess: (r) => { window.open(`/api/v1/dispatches/${r.data.id}/challan`, '_blank'); onDone(r.data.challan_no) },
    onError,
  })
  return (
    <Card className="mb-3 flex flex-wrap items-end gap-2 p-3">
      <Field label="Retailer" className="min-w-52">
        <Select value={retailerId} onChange={(e) => { setRetailerId(e.target.value); setOrderId('') }}>
          <option value="">Choose…</option>
          {retailers.data?.data.map((r) => <option key={r.id} value={r.id}>{r.shop_name}</option>)}
        </Select>
      </Field>
      <Field label="Against order" className="min-w-44">
        <Select value={orderId} onChange={(e) => setOrderId(e.target.value)}>
          <option value="">—</option>
          {orders.data?.data.map((o) => <option key={o.id} value={o.id}>{o.order_no}</option>)}
        </Select>
      </Field>
      <Field label="Status" className="min-w-36">
        <Select value={ship.shipment_status} onChange={(e) => setShip({ ...ship, shipment_status: e.target.value })}>
          <option value="packing">Packing</option><option value="dispatched">Dispatched</option><option value="in_transit">In transit</option>
        </Select>
      </Field>
      <Field label="Courier" className="min-w-40">
        <Select value={ship.courier} onChange={(e) => setShip({ ...ship, courier: e.target.value })}>
          <option value="">—</option>
          {couriers.data?.data.map((c) => <option key={c} value={c}>{c}</option>)}
        </Select>
      </Field>
      <Field label="Tracking no." className="min-w-36"><Input value={ship.tracking_no} onChange={(e) => setShip({ ...ship, tracking_no: e.target.value })} /></Field>
      <Field label="Remarks" className="min-w-48 flex-1"><Input value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
      <Button disabled={!retailerId || ids.length === 0} onClick={() => m.mutate()} loading={m.isPending}>Dispatch {ids.length} & print challan</Button>
      {ids.length === 0 && <span className="pb-2 text-sm text-muted-foreground">Tick the pieces to send.</span>}
    </Card>
  )
}
