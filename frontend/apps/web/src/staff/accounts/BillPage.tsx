import { Alert, api, ApiError, Button, Card, CardHeader, Field, Input, Select, Table, Td, today } from '@b2b/ui'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { PageTitle } from '../layout'

interface Preview {
  terms: 'cash' | 'metal'
  inter_state: boolean
  date: string
  lines: { finished_item_id: number; tag_no: string; description: string; huid: string | null; net_wt: string; fine_wt: string; rate: string; metal_value: string; making: string; stone_charge: string; hallmark_charge: string; taxable: string; gst_rate: string; cgst: string; sgst: string; igst: string; total: string }[]
  totals: Record<'metal_value' | 'making' | 'stone_charge' | 'hallmark_charge' | 'taxable' | 'cgst' | 'sgst' | 'igst' | 'total' | 'payable' | 'fine_wt' | 'net_wt', string>
}

const inr = (v: string) => Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 })

/** Bill a dispatch: preview with today's rate, adjust charges, post the GST invoice. */
export default function BillPage() {
  const { id } = useParams()
  const [terms, setTerms] = useState('')
  const [date, setDate] = useState(today())
  const [hm, setHm] = useState('0')
  const [stones, setStones] = useState<Record<number, string>>({})

  const opts = { date, ...(terms ? { terms } : {}), hallmark_per_piece: hm || '0', stone_charges: Object.fromEntries(Object.entries(stones).filter(([, v]) => v)) }
  const preview = useQuery({
    queryKey: ['invoice-preview', id, JSON.stringify(opts)],
    queryFn: () => api.get<Preview>(`/api/v1/dispatches/${id}/invoice-preview?${new URLSearchParams({ date, hallmark_per_piece: opts.hallmark_per_piece, ...(terms ? { terms } : {}), ...Object.fromEntries(Object.entries(opts.stone_charges).map(([k, v]) => [`stone_charges[${k}]`, v])) })}`),
    retry: false,
  })
  const post = useMutation({ mutationFn: () => api.post<{ data: { id: number; voucher_no: string } }>(`/api/v1/invoices/from-dispatch/${id}`, opts) })
  const p = preview.data
  const err = [preview.error, post.error].find((e) => e instanceof ApiError) as ApiError | undefined

  if (post.isSuccess) {
    const v = post.data.data
    return (
      <Alert tone="success">
        Invoice {v.voucher_no} posted. <a className="font-medium underline" href={`/api/v1/invoices/${v.id}/pdf`} target="_blank" rel="noreferrer">Open PDF</a> · <Link className="underline" to="/staff/accounts/vouchers">All vouchers</Link>
      </Alert>
    )
  }

  return (
    <>
      <PageTitle title="Bill dispatch" />
      {err && <div className="mb-3"><Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert></div>}
      <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
        <Field label="Invoice date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field label="Terms">
          <Select value={terms || p?.terms || ''} onChange={(e) => setTerms(e.target.value)}>
            <option value="cash">Cash (full amount in money)</option>
            <option value="metal">Metal account (gold from deposit)</option>
          </Select>
        </Field>
        <Field label="Hallmark ₹ / piece"><Input className="w-28" inputMode="decimal" value={hm} onChange={(e) => setHm(e.target.value)} /></Field>
        {p && <p className="pb-2 text-sm">{p.inter_state ? 'Inter-state: IGST' : 'Intra-state: CGST + SGST'}</p>}
      </Card>

      {p && (
        <Card>
          <CardHeader title={`${p.lines.length} pieces · ${p.totals.net_wt} g net · ${p.totals.fine_wt} g fine`} />
          <Table head={['Piece', 'HUID', 'Net g', 'Rate', 'Metal ₹', 'Making ₹', 'Stone ₹', 'Taxable ₹', 'GST ₹', 'Total ₹']}>
            {p.lines.map((l) => (
              <tr key={l.finished_item_id}>
                <Td className="text-xs">{l.description}<div className="font-mono text-muted-foreground">{l.tag_no}</div></Td>
                <Td className="font-mono">{l.huid}</Td>
                <Td className="tabular">{l.net_wt}</Td>
                <Td className="tabular">{inr(l.rate)}</Td>
                <Td className="tabular">{inr(l.metal_value)}</Td>
                <Td className="tabular">{inr(l.making)}</Td>
                <Td><Input className="h-8 w-24" inputMode="decimal" value={stones[l.finished_item_id] ?? ''} placeholder="0" onChange={(e) => setStones({ ...stones, [l.finished_item_id]: e.target.value })} /></Td>
                <Td className="tabular">{inr(l.taxable)}</Td>
                <Td className="tabular">{inr(String(Number(l.cgst) + Number(l.sgst) + Number(l.igst)))}</Td>
                <Td className="tabular font-medium">{inr(l.total)}</Td>
              </tr>
            ))}
          </Table>
          <div className="grid gap-1 border-t p-4 text-sm sm:ml-auto sm:w-80">
            <div className="flex justify-between"><span>Taxable</span><span className="tabular">₹{inr(p.totals.taxable)}</span></div>
            {Number(p.totals.igst) > 0 ? <div className="flex justify-between"><span>IGST</span><span className="tabular">₹{inr(p.totals.igst)}</span></div> : <>
              <div className="flex justify-between"><span>CGST</span><span className="tabular">₹{inr(p.totals.cgst)}</span></div>
              <div className="flex justify-between"><span>SGST</span><span className="tabular">₹{inr(p.totals.sgst)}</span></div></>}
            <div className="flex justify-between font-semibold"><span>Invoice total</span><span className="tabular">₹{inr(p.totals.total)}</span></div>
            {p.terms === 'metal' && <div className="flex justify-between font-semibold text-brand"><span>Payable in money</span><span className="tabular">₹{inr(p.totals.payable)}</span></div>}
            {p.terms === 'metal' && <p className="text-xs text-muted-foreground">{p.totals.fine_wt} g fine settled from the retailer’s metal account.</p>}
            <Button className="mt-2" onClick={() => post.mutate()} loading={post.isPending}>Post invoice</Button>
          </div>
        </Card>
      )}
    </>
  )
}
