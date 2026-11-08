import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Select, Table, Td, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Upload } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import type { Ahc, HallmarkLot } from './types'

const lotTone = { draft: 'neutral', sent: 'warning', returned: 'success' } as const

/** Lots list and the AHC master. */
export function HallmarkingPage() {
  const qc = useQueryClient()
  const can = useCan()
  const lots = useQuery({ queryKey: ['hallmark-lots'], queryFn: () => api.get<Paginated<HallmarkLot>>('/api/v1/hallmark-lots') })
  const ahcs = useQuery({ queryKey: ['ahcs'], queryFn: () => api.get<{ data: Ahc[] }>('/api/v1/ahcs') })
  const [form, setForm] = useState({ name: '', bis_recognition_no: '', city: '', charge_per_piece: '' })
  const addAhc = useMutation({
    mutationFn: () => api.post('/api/v1/ahcs', { ...form, charge_per_piece: form.charge_per_piece || '0' }),
    onSuccess: () => { setForm({ name: '', bis_recognition_no: '', city: '', charge_per_piece: '' }); qc.invalidateQueries({ queryKey: ['ahcs'] }) },
  })

  return (
    <>
      <PageTitle title="Hallmarking (BIS HUID)">
        <Link to="/staff/finished?f=ready"><Button>New lot from finished goods</Button></Link>
      </PageTitle>
      <p className="mb-4 text-sm text-muted-foreground">
        Raise the request on Manak Online, send the lot to the AHC here (its gold stays on the books under “AHC”), then record each piece’s result and HUID when it comes back.
      </p>
      <Card className="mb-4">
        <Table head={['Lot', 'AHC', 'Purity', 'Pieces', 'Gross g', 'Sent', 'Status', '']}>
          {lots.data?.data.map((l) => (
            <tr key={l.id}>
              <Td className="font-mono text-xs"><Link className="hover:underline" to={`/staff/hallmarking/${l.id}`}>{l.lot_no}</Link></Td>
              <Td>{l.ahc.name}</Td><Td>{l.karat.label}</Td><Td className="tabular">{l.pieces}</Td><Td className="tabular">{l.gross_wt}</Td>
              <Td>{fmtDate(l.sent_at)}</Td>
              <Td><Badge tone={lotTone[l.status]}>{l.status}</Badge></Td>
              <Td><a className="text-brand hover:underline" href={`/api/v1/hallmark-lots/${l.id}/sheet`} target="_blank" rel="noreferrer">Lot sheet</a></Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card>
        <CardHeader title="Hallmarking centres (AHC)" />
        <Table head={['Name', 'BIS recognition', 'City', '₹ / piece']}>
          {ahcs.data?.data.map((a) => <tr key={a.id}><Td>{a.name}</Td><Td>{a.bis_recognition_no}</Td><Td>{a.city}</Td><Td className="tabular">{a.charge_per_piece}</Td></tr>)}
        </Table>
        {can('production.manage') && (
          <form className="flex flex-wrap items-end gap-2 border-t p-4" onSubmit={(e) => { e.preventDefault(); addAhc.mutate() }}>
            <Field label="Name"><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="BIS recognition no."><Input value={form.bis_recognition_no} onChange={(e) => setForm({ ...form, bis_recognition_no: e.target.value })} /></Field>
            <Field label="City"><Input value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} /></Field>
            <Field label="₹ per piece" className="w-28"><Input inputMode="decimal" value={form.charge_per_piece} onChange={(e) => setForm({ ...form, charge_per_piece: e.target.value })} /></Field>
            <Button type="submit" loading={addAhc.isPending}>Add AHC</Button>
          </form>
        )}
      </Card>
    </>
  )
}

interface Result { finished_item_id: number; result: 'pass' | 'fail'; huid: string; returned_wt: string; remarks: string }

/** One lot: send to AHC, then record results and HUIDs (typed or imported). */
export function HallmarkLotPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const can = useCan()
  const q = useQuery({ queryKey: ['hallmark-lot', id], queryFn: () => api.get<{ data: HallmarkLot }>(`/api/v1/hallmark-lots/${id}`) })
  const lot = q.data?.data
  const [manak, setManak] = useState('')
  const [results, setResults] = useState<Result[]>([])
  const [charges, setCharges] = useState('')

  useEffect(() => {
    if (lot?.items) setResults(lot.items.map((li) => ({ finished_item_id: li.finished_item_id, result: 'pass', huid: li.huid ?? '', returned_wt: li.returned_wt ?? li.sent_wt, remarks: '' })))
  }, [lot])

  const refresh = () => { qc.invalidateQueries({ queryKey: ['hallmark-lot', id] }); qc.invalidateQueries({ queryKey: ['hallmark-lots'] }); qc.invalidateQueries({ queryKey: ['finished'] }) }
  const send = useMutation({ mutationFn: () => api.post(`/api/v1/hallmark-lots/${id}/send`, { manak_request_no: manak || null }), onSuccess: refresh })
  const ret = useMutation({
    mutationFn: () => api.post(`/api/v1/hallmark-lots/${id}/return`, {
      results: results.map((r) => ({ ...r, huid: r.result === 'pass' ? r.huid.toUpperCase() : null, remarks: r.remarks || null })),
      charges: charges || null,
    }),
    onSuccess: refresh,
  })
  const importCsv = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('file', file); return api.post<{ data: (Result & { tag_no: string })[] }>(`/api/v1/hallmark-lots/${id}/huids/import`, fd) },
    onSuccess: (r) => setResults(results.map((row) => {
      const hit = r.data.find((x) => x.finished_item_id === row.finished_item_id)
      return hit ? { ...row, huid: hit.huid, result: hit.result, returned_wt: hit.returned_wt } : row
    })),
  })
  const err = [send.error, ret.error, importCsv.error].find((e) => e instanceof ApiError) as ApiError | undefined

  if (!lot) return <p className="text-muted-foreground">Loading…</p>
  const set = (i: number, patch: Partial<Result>) => setResults(results.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <>
      <PageTitle title={`Lot ${lot.lot_no}`}>
        <Badge tone={lotTone[lot.status]}>{lot.status}</Badge>
        <a href={`/api/v1/hallmark-lots/${lot.id}/sheet`} target="_blank" rel="noreferrer"><Button variant="outline"><FileText /> Lot sheet</Button></a>
      </PageTitle>
      <p className="mb-4 text-sm">{lot.ahc.name} · {lot.karat.label} · {lot.pieces} pieces · {lot.gross_wt} g {lot.manak_request_no && `· Manak ${lot.manak_request_no}`}{lot.status === 'returned' && ` · charges ₹${lot.charges}`}</p>
      {err && <div className="mb-3"><Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert></div>}

      {lot.status === 'draft' && can('production.manage') && (
        <Card className="mb-4 flex flex-wrap items-end gap-2 p-4">
          <Field label="Manak Online request no." className="min-w-60"><Input value={manak} onChange={(e) => setManak(e.target.value)} /></Field>
          <Button onClick={() => send.mutate()} loading={send.isPending}>Send to AHC</Button>
        </Card>
      )}

      <Card>
        {lot.status === 'sent' && can('production.manage') && (
          <div className="flex flex-wrap items-center gap-2 border-b p-3 text-sm">
            <label className="inline-flex cursor-pointer items-center gap-1 text-brand hover:underline">
              <Upload className="size-4" /> Import AHC HUID list (CSV: tag, huid, result, weight)
              <input type="file" accept=".csv,text/csv,text/plain" className="hidden" onChange={(e) => e.target.files?.[0] && importCsv.mutate(e.target.files[0])} />
            </label>
          </div>
        )}
        <Table head={['Tag', 'Design', 'Sent g', 'Result', 'HUID', 'Returned g', 'Remarks']}>
          {lot.items?.map((li, i) => {
            const r = results[i]
            const editable = lot.status === 'sent' && r
            return (
              <tr key={li.id}>
                <Td className="font-mono text-xs">{li.finished_item.tag_no}</Td>
                <Td>{li.finished_item.design?.code}</Td>
                <Td className="tabular">{li.sent_wt}</Td>
                <Td>{editable ? (
                  <Select className="h-8 w-24" value={r.result} onChange={(e) => set(i, { result: e.target.value as 'pass' | 'fail' })}><option value="pass">Pass</option><option value="fail">Fail</option></Select>
                ) : li.result && <Badge tone={li.result === 'pass' ? 'success' : 'danger'}>{li.result}</Badge>}</Td>
                <Td>{editable ? (
                  <Input className="h-8 w-28 font-mono uppercase" maxLength={6} disabled={r.result === 'fail'} value={r.huid} onChange={(e) => set(i, { huid: e.target.value.replace(/[^a-z0-9]/gi, '').toUpperCase() })} />
                ) : <span className="font-mono">{li.huid}</span>}</Td>
                <Td>{editable ? <WeightInput className="h-8 w-28" value={r.returned_wt} onValueChange={(v) => set(i, { returned_wt: v })} /> : <span className="tabular">{li.returned_wt}</span>}</Td>
                <Td>{editable ? <Input className="h-8" value={r.remarks} onChange={(e) => set(i, { remarks: e.target.value })} /> : li.remarks}</Td>
              </tr>
            )
          })}
        </Table>
        {lot.status === 'sent' && can('production.manage') && (
          <div className="flex flex-wrap items-end gap-2 border-t p-4">
            <Field label="AHC charges ₹" hint={`Default ₹${lot.ahc.charge_per_piece} × ${lot.pieces}`} className="w-40"><Input inputMode="decimal" value={charges} onChange={(e) => setCharges(e.target.value)} /></Field>
            <Button onClick={() => ret.mutate()} loading={ret.isPending}>Record return</Button>
            <span className="pb-2 text-xs text-muted-foreground">HUID = 6 letters/digits, unique. Weight lost to testing posts as hallmark loss.</span>
          </div>
        )}
      </Card>
    </>
  )
}

export function DispatchesPage() {
  const q = useQuery({
    queryKey: ['dispatches'],
    queryFn: () => api.get<Paginated<{ id: number; challan_no: string; date: string; pieces: number; net_wt: string; fine_wt: string; invoice_voucher_id: number | null; retailer: { shop_name: string }; order: { order_no: string } | null }>>('/api/v1/dispatches'),
  })
  return (
    <>
      <PageTitle title="Dispatches & challans">
        <Link to="/staff/finished?f=ready"><Button>Dispatch pieces</Button></Link>
      </PageTitle>
      <Card>
        <Table head={['Challan', 'Date', 'Retailer', 'Order', 'Pieces', 'Net g', 'Fine g', '']}>
          {q.data?.data.map((d) => (
            <tr key={d.id}>
              <Td className="font-mono text-xs">{d.challan_no}</Td><Td>{fmtDate(d.date)}</Td><Td>{d.retailer.shop_name}</Td><Td>{d.order?.order_no ?? '—'}</Td>
              <Td className="tabular">{d.pieces}</Td><Td className="tabular">{d.net_wt}</Td><Td className="tabular">{d.fine_wt}</Td>
              <Td className="whitespace-nowrap">
                <a className="text-brand hover:underline" href={`/api/v1/dispatches/${d.id}/challan`} target="_blank" rel="noreferrer">Challan</a>
                {' · '}
                {d.invoice_voucher_id
                  ? <a className="text-brand hover:underline" href={`/api/v1/invoices/${d.invoice_voucher_id}/pdf`} target="_blank" rel="noreferrer">Invoice</a>
                  : <Link className="font-medium text-brand hover:underline" to={`/staff/accounts/bill/${d.id}`}>Bill now</Link>}
              </Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  )
}
