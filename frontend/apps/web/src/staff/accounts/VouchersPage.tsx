import { Alert, api, ApiError, Badge, Button, Card, Field, Input, Select, Table, Td, today, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { PartySelect, useAccounts, VOUCHER_LABEL, type Voucher } from './shared'

type Form = 'receipt' | 'payment' | 'metal-receipt' | 'metal-payment' | 'purchase' | 'rate-cut' | 'journal'
const FORMS: [Form, string][] = [
  ['receipt', 'Receipt'], ['payment', 'Payment'], ['metal-receipt', 'Metal receipt'], ['metal-payment', 'Metal payment'],
  ['purchase', 'Purchase'], ['rate-cut', 'Rate-cut'], ['journal', 'Journal / contra / notes'],
]

export default function VouchersPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [type, setType] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [form, setForm] = useState<Form | null>(null)

  const list = useQuery({
    queryKey: ['vouchers', type, from, to],
    queryFn: () => api.get<Paginated<Voucher>>(`/api/v1/vouchers?type=${type}&from=${from}&to=${to}`),
  })
  const cancel = useMutation({
    mutationFn: ({ v, reason }: { v: Voucher; reason: string }) => api.post(`/api/v1/vouchers/${v.id}/cancel`, { reason }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vouchers'] }),
  })

  return (
    <>
      <PageTitle title="Vouchers">
        <Select className="w-44" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          {Object.entries(VOUCHER_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </Select>
        <Input type="date" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input type="date" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} />
      </PageTitle>

      {can('accounts.manage') && (
        <Card className="mb-4">
          <div className="flex flex-wrap gap-1 border-b p-2">
            {FORMS.map(([k, label]) => <Button key={k} size="sm" variant={form === k ? 'default' : 'ghost'} onClick={() => setForm(form === k ? null : k)}>{label}</Button>)}
          </div>
          {form && <div className="p-4"><VoucherForm kind={form} onDone={() => { qc.invalidateQueries({ queryKey: ['vouchers'] }); qc.invalidateQueries({ queryKey: ['parties'] }) }} /></div>}
        </Card>
      )}

      {cancel.error instanceof ApiError && <div className="mb-3"><Alert>{cancel.error.message}</Alert></div>}

      <Card>
        <Table head={['Date', 'Voucher', 'Type', 'Party', 'Amount ₹', 'Metal g', 'Narration', '']}>
          {list.data?.data.map((v) => (
            <tr key={v.id} className={v.status === 'cancelled' ? 'text-muted-foreground line-through' : undefined}>
              <Td>{fmtDate(v.date)}</Td>
              <Td className="font-mono text-xs">{v.voucher_no}</Td>
              <Td>{VOUCHER_LABEL[v.type] ?? v.type}{v.source_type && <Badge className="ml-1">auto</Badge>}</Td>
              <Td>{v.party?.name ?? '—'}</Td>
              <Td className="tabular">{Number(v.amount) ? Number(v.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '—'}</Td>
              <Td className="tabular">{Number(v.fine_wt) ? v.fine_wt : '—'}</Td>
              <Td className="max-w-64 truncate text-xs">{v.narration}</Td>
              <Td className="whitespace-nowrap text-right">
                {v.type === 'sales' && <a href={`/api/v1/invoices/${v.id}/pdf`} target="_blank" rel="noreferrer"><Button size="sm" variant="ghost">PDF</Button></a>}
                {can('accounts.manage') && v.status === 'posted' && !v.source_type && v.type !== 'reversal' && (
                  <Button size="sm" variant="ghost" onClick={() => { const reason = prompt(`Cancel ${v.voucher_no}? Reason:`); if (reason) cancel.mutate({ v, reason }) }}>Cancel</Button>
                )}
              </Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  )
}

function VoucherForm({ kind, onDone }: { kind: Form; onDone: () => void }) {
  const [partyId, setPartyId] = useState('')
  const [amount, setAmount] = useState('')
  const [fine, setFine] = useState('')
  const [mode, setMode] = useState('cash')
  const [rate, setRate] = useState('')
  const [terms, setTerms] = useState('rate')
  const [gst, setGst] = useState('3')
  const [billNo, setBillNo] = useState('')
  const [date, setDate] = useState(today())
  const [narration, setNarration] = useState('')
  const [jType, setJType] = useState('journal')
  const [lines, setLines] = useState([{ account_id: '', party_id: '', debit: '', credit: '' }, { account_id: '', party_id: '', debit: '', credit: '' }])
  const accounts = useAccounts()

  const path = kind === 'rate-cut' ? '/api/v1/rate-cuts' : `/api/v1/vouchers/${kind}`
  const body = () => {
    const base = { date, narration: narration || null }
    switch (kind) {
      case 'receipt': case 'payment': return { ...base, party_id: Number(partyId), amount, mode }
      case 'metal-receipt': case 'metal-payment': return { ...base, party_id: Number(partyId), fine }
      case 'purchase': return { ...base, party_id: Number(partyId), terms, fine: fine || '0', taxable: amount || '0', gst_rate: gst, bill_no: billNo || null }
      case 'rate-cut': return { ...base, party_id: Number(partyId), fine, rate }
      case 'journal': return { ...base, type: jType, lines: lines.filter((l) => l.account_id).map((l) => ({ account_id: Number(l.account_id), party_id: l.party_id ? Number(l.party_id) : null, debit: l.debit || '0', credit: l.credit || '0' })) }
    }
  }
  const post = useMutation({
    mutationFn: () => api.post<{ data: { voucher_no: string } }>(path, body()),
    onSuccess: () => { setAmount(''); setFine(''); setNarration(''); onDone() },
  })
  const err = post.error instanceof ApiError ? post.error : null
  const totalDr = lines.reduce((s, l) => s + Math.round(Number(l.debit || 0) * 100), 0) / 100
  const totalCr = lines.reduce((s, l) => s + Math.round(Number(l.credit || 0) * 100), 0) / 100

  const row = (children: ReactNode) => <div className="grid gap-3 sm:grid-cols-4">{children}</div>

  return (
    <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); post.mutate() }}>
      {err && <Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert>}
      {post.isSuccess && <Alert tone="success">Posted {post.data.data.voucher_no}.</Alert>}

      {kind !== 'journal' && row(<>
        <Field label="Party" className="sm:col-span-2"><PartySelect required value={partyId} onChange={setPartyId} type={kind === 'purchase' ? undefined : undefined} /></Field>
        <Field label="Date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
      </>)}

      {(kind === 'receipt' || kind === 'payment') && row(<>
        <Field label="Amount ₹"><Input required inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field label="Mode">
          <Select value={mode} onChange={(e) => setMode(e.target.value)}>
            <option value="cash">Cash</option><option value="bank">Bank / NEFT</option><option value="upi">UPI</option><option value="cheque">Cheque</option>
          </Select>
        </Field>
      </>)}

      {(kind === 'metal-receipt' || kind === 'metal-payment') && row(
        <Field label="Fine gold g" hint={kind === 'metal-receipt' ? 'Received on account (not stock entry; use Vault for physical gold)' : 'Given on account'}><WeightInput required value={fine} onValueChange={setFine} /></Field>,
      )}

      {kind === 'purchase' && row(<>
        <Field label="Terms">
          <Select value={terms} onChange={(e) => setTerms(e.target.value)}><option value="rate">At rate (money)</option><option value="metal">Metal terms (owe fine gold)</option></Select>
        </Field>
        {terms === 'metal' && <Field label="Fine gold owed g"><WeightInput required value={fine} onValueChange={setFine} /></Field>}
        <Field label={terms === 'metal' ? 'Charges ₹ (taxable)' : 'Taxable value ₹'}><Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field label="GST %"><Input inputMode="decimal" value={gst} onChange={(e) => setGst(e.target.value)} /></Field>
        <Field label="Supplier bill no."><Input value={billNo} onChange={(e) => setBillNo(e.target.value)} /></Field>
      </>)}

      {kind === 'rate-cut' && row(<>
        <Field label="Fine gold to fix g"><WeightInput required value={fine} onValueChange={setFine} /></Field>
        <Field label="Rate ₹ / g fine"><Input required inputMode="decimal" value={rate} onChange={(e) => setRate(e.target.value)} /></Field>
        <p className="pb-2 text-sm text-muted-foreground sm:col-span-2 self-end">
          {fine && rate ? `= ₹${(Math.round(Number(fine) * Number(rate) * 100) / 100).toLocaleString('en-IN')}` : 'Converts the party’s metal balance into money.'}
        </p>
      </>)}

      {kind === 'journal' && (
        <>
          {row(<>
            <Field label="Type"><Select value={jType} onChange={(e) => setJType(e.target.value)}><option value="journal">Journal</option><option value="contra">Contra (cash ↔ bank)</option><option value="credit_note">Credit note</option><option value="debit_note">Debit note</option></Select></Field>
            <Field label="Date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
          </>)}
          {lines.map((l, i) => (
            <div key={i} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[1fr_1fr_8rem_8rem_auto]">
              <Field label={i === 0 ? 'Account' : ''}>
                <Select value={l.account_id} onChange={(e) => setLines(lines.map((x, j) => j === i ? { ...x, account_id: e.target.value } : x))}>
                  <option value="">—</option>
                  {accounts.data?.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                </Select>
              </Field>
              <Field label={i === 0 ? 'Party (optional)' : ''}><PartySelect value={l.party_id} onChange={(v) => setLines(lines.map((x, j) => j === i ? { ...x, party_id: v } : x))} /></Field>
              <Field label={i === 0 ? 'Debit ₹' : ''}><Input inputMode="decimal" value={l.debit} onChange={(e) => setLines(lines.map((x, j) => j === i ? { ...x, debit: e.target.value } : x))} /></Field>
              <Field label={i === 0 ? 'Credit ₹' : ''}><Input inputMode="decimal" value={l.credit} onChange={(e) => setLines(lines.map((x, j) => j === i ? { ...x, credit: e.target.value } : x))} /></Field>
              <Button type="button" size="icon" variant="ghost" disabled={lines.length <= 2} onClick={() => setLines(lines.filter((_, j) => j !== i))} aria-label="Remove"><Trash2 /></Button>
            </div>
          ))}
          <div className="flex items-center gap-3">
            <Button type="button" size="sm" variant="ghost" onClick={() => setLines([...lines, { account_id: '', party_id: '', debit: '', credit: '' }])}><Plus /> Line</Button>
            <span className={`tabular text-sm ${totalDr !== totalCr ? 'text-danger' : 'text-success'}`}>Dr ₹{totalDr.toFixed(2)} · Cr ₹{totalCr.toFixed(2)}</span>
          </div>
        </>
      )}

      <Field label="Narration"><Input value={narration} onChange={(e) => setNarration(e.target.value)} /></Field>
      <div><Button type="submit" loading={post.isPending}>Post voucher</Button></div>
    </form>
  )
}
