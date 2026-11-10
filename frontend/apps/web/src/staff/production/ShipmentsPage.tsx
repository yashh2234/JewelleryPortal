import { Alert, api, ApiError, Badge, Button, Card, EmptyState, Field, Input, Select, Table, Td, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, Paperclip, Search, Truck, Upload } from 'lucide-react'
import { Fragment, useRef, useState } from 'react'
import { Link } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { SHIPMENT_STATUS } from './types'

interface Row {
  id: number; challan_no: string; date: string; pieces: number; net_wt: string; fine_wt: string; invoice_voucher_id: number | null
  shipment_status: string; courier: string | null; tracking_no: string | null; tracking_url: string | null; delivery_remarks: string | null
  retailer: { shop_name: string; city?: string | null }; order: { id: number; order_no: string } | null
  documents: { id: number; path?: string; created_at: string }[]
}

/** Spec §9: challans, courier, tracking, documents and delivery status. */
export default function ShipmentsPage() {
  const can = useCan()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<number | null>(null)
  const q = useQuery({
    queryKey: ['dispatches', status, search],
    queryFn: () => api.get<Paginated<Row>>(`/api/v1/dispatches?${new URLSearchParams({ shipment_status: status, search })}`),
  })

  return (
    <>
      <PageTitle title="Dispatch & shipments" description="Every challan with its courier, tracking and delivery status">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input className="w-52 pl-9" placeholder="Challan or tracking no." value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <Select className="w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All shipments</option>
          <option value="packing,dispatched,in_transit,out_for_delivery">On the way</option>
          {Object.entries(SHIPMENT_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </Select>
        {can('dispatch.manage') && <Link to="/staff/finished?f=ready"><Button><Truck /> Dispatch pieces</Button></Link>}
      </PageTitle>

      <Card>
        {q.data?.data.length === 0 ? <EmptyState title="No shipments yet" icon={<Truck />}>QC-passed pieces are dispatched from Finished goods.</EmptyState> : (
          <Table head={['Challan', 'Retailer', 'Pieces', 'Net g', 'Status', 'Courier & tracking', 'Documents', '']}>
            {q.data?.data.map((d) => (
              <Fragment key={d.id}>
                <tr>
                  <Td className="whitespace-nowrap"><span className="font-mono text-xs font-medium">{d.challan_no}</span><div className="text-xs text-muted-foreground">{fmtDate(d.date)}</div></Td>
                  <Td>{d.retailer.shop_name}{d.order && <div className="text-xs"><Link className="text-brand hover:underline" to={`/staff/orders/${d.order.id}`}>{d.order.order_no}</Link></div>}</Td>
                  <Td className="tabular">{d.pieces}</Td>
                  <Td className="tabular">{d.net_wt}</Td>
                  <Td>
                    <Badge tone={SHIPMENT_STATUS[d.shipment_status]?.tone ?? 'neutral'}>{SHIPMENT_STATUS[d.shipment_status]?.label ?? d.shipment_status}</Badge>
                    {d.delivery_remarks && <div className="mt-0.5 max-w-40 truncate text-xs text-muted-foreground" title={d.delivery_remarks}>{d.delivery_remarks}</div>}
                  </Td>
                  <Td className="text-sm">
                    {d.courier ?? <span className="text-muted-foreground">—</span>}
                    {d.tracking_no && <div>{d.tracking_url
                      ? <a className="inline-flex items-center gap-0.5 font-mono text-xs text-brand hover:underline" href={d.tracking_url} target="_blank" rel="noreferrer">{d.tracking_no} <ExternalLink className="size-3" /></a>
                      : <span className="font-mono text-xs">{d.tracking_no}</span>}</div>}
                  </Td>
                  <Td>
                    {d.documents.map((doc, i) => (
                      <a key={doc.id} className="mr-2 inline-flex items-center gap-0.5 text-xs text-brand hover:underline" href={`/api/v1/dispatches/${d.id}/documents/${doc.id}`} target="_blank" rel="noreferrer"><Paperclip className="size-3" /> {i + 1}</a>
                    ))}
                    {d.documents.length === 0 && <span className="text-xs text-muted-foreground">—</span>}
                  </Td>
                  <Td className="whitespace-nowrap text-right text-sm">
                    <a className="text-brand hover:underline" href={`/api/v1/dispatches/${d.id}/challan`} target="_blank" rel="noreferrer">Challan</a>
                    {' · '}
                    {d.invoice_voucher_id
                      ? <a className="text-brand hover:underline" href={`/api/v1/invoices/${d.invoice_voucher_id}/pdf`} target="_blank" rel="noreferrer">Invoice</a>
                      : can('accounts.manage') ? <Link className="font-medium text-brand hover:underline" to={`/staff/accounts/bill/${d.id}`}>Bill now</Link> : <span className="text-muted-foreground">Not billed</span>}
                    {can('dispatch.manage') && <Button size="sm" variant="ghost" className="ml-1" onClick={() => setEditing(editing === d.id ? null : d.id)}>{editing === d.id ? 'Close' : 'Update'}</Button>}
                  </Td>
                </tr>
                {editing === d.id && (
                  <tr><td colSpan={8} className="bg-muted/40 px-5 py-4"><Editor d={d} onDone={() => setEditing(null)} /></td></tr>
                )}
              </Fragment>
            ))}
          </Table>
        )}
      </Card>
    </>
  )
}

function Editor({ d, onDone }: { d: Row; onDone: () => void }) {
  const qc = useQueryClient()
  const file = useRef<HTMLInputElement>(null)
  const couriers = useQuery({ queryKey: ['couriers'], queryFn: () => api.get<{ data: string[]; statuses: string[] }>('/api/v1/couriers') })
  const [f, setF] = useState({ shipment_status: d.shipment_status, courier: d.courier ?? '', tracking_no: d.tracking_no ?? '', tracking_url: d.tracking_url ?? '', delivery_remarks: d.delivery_remarks ?? '' })
  const refresh = () => qc.invalidateQueries({ queryKey: ['dispatches'] })

  const save = useMutation({
    mutationFn: () => api.patch(`/api/v1/dispatches/${d.id}/shipment`, {
      shipment_status: f.shipment_status, courier: f.courier || null, tracking_no: f.tracking_no || null,
      tracking_url: f.tracking_url && f.tracking_url !== d.tracking_url ? f.tracking_url : null, delivery_remarks: f.delivery_remarks || null,
    }),
    onSuccess: () => { refresh(); onDone() },
  })
  const upload = useMutation({
    mutationFn: (fl: File) => { const b = new FormData(); b.append('file', fl); return api.post(`/api/v1/dispatches/${d.id}/documents`, b) },
    onSuccess: refresh,
  })
  const err = [save.error, upload.error].find((e) => e instanceof ApiError) as ApiError | undefined

  return (
    <div className="grid gap-3">
      {err && <Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert>}
      <div className="grid gap-3 sm:grid-cols-5">
        <Field label="Status">
          <Select value={f.shipment_status} onChange={(e) => setF({ ...f, shipment_status: e.target.value })}>
            {Object.entries(SHIPMENT_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
          </Select>
        </Field>
        <Field label="Courier">
          <Select value={f.courier} onChange={(e) => setF({ ...f, courier: e.target.value })}>
            <option value="">—</option>
            {couriers.data?.data.map((c) => <option key={c} value={c}>{c}</option>)}
            {f.courier && !couriers.data?.data.includes(f.courier) && <option value={f.courier}>{f.courier}</option>}
          </Select>
        </Field>
        <Field label="Tracking no."><Input value={f.tracking_no} onChange={(e) => setF({ ...f, tracking_no: e.target.value })} /></Field>
        <Field label="Tracking link" hint="Filled in for known couriers"><Input value={f.tracking_url} onChange={(e) => setF({ ...f, tracking_url: e.target.value })} /></Field>
        <Field label="Delivery note"><Input value={f.delivery_remarks} placeholder="e.g. Received by Mr Sharma" onChange={(e) => setF({ ...f, delivery_remarks: e.target.value })} /></Field>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button onClick={() => save.mutate()} loading={save.isPending}>Save & notify retailer</Button>
        <input ref={file} type="file" accept=".pdf,image/*" className="hidden" onChange={(e) => { const fl = e.target.files?.[0]; if (fl) upload.mutate(fl); e.target.value = '' }} />
        <Button variant="outline" onClick={() => file.current?.click()} loading={upload.isPending}><Upload /> Upload document</Button>
        <span className="self-center text-xs text-muted-foreground">Courier receipt, insurance slip or parcel photo. Visible to the retailer.</span>
      </div>
    </div>
  )
}
