import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Select, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { drCr, metalDrCr, useParties, VOUCHER_LABEL } from './shared'

export function PartiesPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [type, setType] = useState('')
  const parties = useParties(type || undefined)
  const [form, setForm] = useState({ type: 'supplier', name: '', gstin: '', phone: '' })
  const add = useMutation({
    mutationFn: () => api.post('/api/v1/parties', { ...form, gstin: form.gstin || null }),
    onSuccess: () => { setForm({ type: 'supplier', name: '', gstin: '', phone: '' }); qc.invalidateQueries({ queryKey: ['parties'] }) },
  })
  const err = add.error instanceof ApiError ? add.error : null

  return (
    <>
      <PageTitle title="Parties">
        <Select className="w-40" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All</option>
          {['retailer', 'supplier', 'kaarigar', 'refiner', 'ahc', 'other'].map((t) => <option key={t} value={t} className="capitalize">{t}</option>)}
        </Select>
      </PageTitle>
      <p className="mb-4 text-sm text-muted-foreground">Retailers, kaarigars and hallmarking centres appear here automatically. Dr = they owe you; Cr = you owe them.</p>
      <Card className="mb-4">
        <Table head={['Party', 'Type', 'GSTIN', 'Money', 'Metal (fine)', '']}>
          {parties.data?.map((p) => (
            <tr key={p.id}>
              <Td className="font-medium">{p.name}</Td>
              <Td><Badge className="capitalize">{p.type}</Badge></Td>
              <Td className="font-mono text-xs">{p.gstin ?? '—'}</Td>
              <Td className="tabular">{p.balance ? drCr(p.balance.money) : '—'}</Td>
              <Td className="tabular">{p.balance ? metalDrCr(p.balance.fine) : '—'}</Td>
              <Td className="text-right"><Link to={`/staff/accounts/parties/${p.id}`}><Button size="sm" variant="ghost">Ledger</Button></Link></Td>
            </tr>
          ))}
        </Table>
      </Card>
      {can('accounts.manage') && (
        <Card>
          <CardHeader title="Add supplier / refiner" />
          <form className="flex flex-wrap items-end gap-2 p-4" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
            {err && <div className="w-full"><Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert></div>}
            <Field label="Type"><Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}><option value="supplier">Bullion supplier</option><option value="refiner">Refiner</option><option value="other">Other</option></Select></Field>
            <Field label="Name"><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="GSTIN"><Input maxLength={15} value={form.gstin} onChange={(e) => setForm({ ...form, gstin: e.target.value.toUpperCase() })} /></Field>
            <Field label="Phone"><Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            <Button type="submit" loading={add.isPending}>Add</Button>
          </form>
        </Card>
      )}
    </>
  )
}

interface Ledger {
  party: { id: number; name: string; type: string; gstin: string | null }
  opening: { money: string; fine: string }
  closing: { money: string; fine: string }
  gold_in_hand?: string
  rows: { date: string; voucher_id: number; voucher_no: string; type: string; narration: string | null; cancelled: boolean; debit: string; credit: string; fine_dr: string; fine_cr: string; balance: string; fine_balance: string }[]
}

/** Money and metal side by side, with running balances. */
export function PartyLedgerPage() {
  const { id } = useParams()
  const fyStart = (() => { const d = new Date(); const y = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1; return `${y}-04-01` })()
  const [from, setFrom] = useState(fyStart)
  const [to, setTo] = useState('')
  const q = useQuery({ queryKey: ['party-ledger', id, from, to], queryFn: () => api.get<Ledger>(`/api/v1/parties/${id}/ledger?from=${from}${to ? `&to=${to}` : ''}`) })
  const l = q.data
  const num = (v: string) => (Number(v) ? Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : '')
  const g = (v: string) => (Number(v) ? v : '')

  return (
    <>
      <PageTitle title={l ? `Ledger · ${l.party.name}` : 'Ledger'}>
        <Input type="date" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input type="date" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} />
      </PageTitle>
      {l && (
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <Card className="p-3"><p className="text-xs text-muted-foreground">Money balance</p><p className="tabular text-lg font-semibold">{drCr(l.closing.money)}</p></Card>
          <Card className="p-3"><p className="text-xs text-muted-foreground">Metal on account</p><p className="tabular text-lg font-semibold">{metalDrCr(l.closing.fine)}</p></Card>
          {l.gold_in_hand !== undefined && <Card className="p-3"><p className="text-xs text-muted-foreground">Gold in hand (gold ledger)</p><p className="tabular text-lg font-semibold">{l.gold_in_hand} g</p></Card>}
        </div>
      )}
      <Card>
        <Table head={['Date', 'Voucher', 'Type', 'Dr ₹', 'Cr ₹', 'Balance', 'Metal Dr', 'Metal Cr', 'Metal bal.']}>
          {l && <tr className="bg-muted/50"><Td colSpan={5}>Opening</Td><Td className="tabular">{drCr(l.opening.money)}</Td><Td colSpan={2} /><Td className="tabular">{metalDrCr(l.opening.fine)}</Td></tr>}
          {l?.rows.map((r, i) => (
            <tr key={i} className={r.cancelled ? 'text-muted-foreground line-through' : undefined}>
              <Td>{fmtDate(r.date)}</Td>
              <Td className="font-mono text-xs">{r.type === 'sales' ? <a className="hover:underline" href={`/api/v1/invoices/${r.voucher_id}/pdf`} target="_blank" rel="noreferrer">{r.voucher_no}</a> : r.voucher_no}</Td>
              <Td>{VOUCHER_LABEL[r.type] ?? r.type}<div className="max-w-56 truncate text-xs text-muted-foreground">{r.narration}</div></Td>
              <Td className="tabular">{num(r.debit)}</Td><Td className="tabular">{num(r.credit)}</Td><Td className="tabular">{drCr(r.balance)}</Td>
              <Td className="tabular">{g(r.fine_dr)}</Td><Td className="tabular">{g(r.fine_cr)}</Td><Td className="tabular">{metalDrCr(r.fine_balance)}</Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  )
}
