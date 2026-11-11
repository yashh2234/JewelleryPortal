import { api, Badge, Button, Card, Field, Input, Select, Table, Td, today } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { useState } from 'react'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import { drCr, VOUCHER_LABEL } from './shared'

type Tab = 'trial-balance' | 'pnl' | 'balance-sheet' | 'outstanding' | 'cash-book' | 'gst' | 'stock-valuation'
const TABS: [Tab, string][] = [
  ['trial-balance', 'Trial balance'], ['pnl', 'Profit & loss'], ['balance-sheet', 'Balance sheet'], ['outstanding', 'Outstanding & ageing'],
  ['cash-book', 'Cash / bank book'], ['gst', 'GST returns'], ['stock-valuation', 'Stock valuation'],
]
const inr = (v: string | number) => Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/* eslint-disable @typescript-eslint/no-explicit-any */
export default function ReportsPage() {
  const fyStart = (() => { const d = new Date(); const y = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1; return `${y}-04-01` })()
  const [tab, setTab] = useState<Tab>('trial-balance')
  const [from, setFrom] = useState(fyStart)
  const [to, setTo] = useState(today())
  const [type, setType] = useState('retailer')
  const [account, setAccount] = useState('cash')

  const name = tab === 'gst' ? 'gstr3b' : tab
  const qs = `from=${from}&to=${to}&as_of=${to}&type=${type}&account=${account}`
  const q = useQuery({ queryKey: ['acct-report', name, qs], queryFn: () => api.get<any>(`/api/v1/accounting-reports/${name}?${qs}`) })
  const gstr1 = useQuery({ queryKey: ['acct-report', 'gstr1', qs], queryFn: () => api.get<any>(`/api/v1/accounting-reports/gstr1?${qs}`), enabled: tab === 'gst' })
  const d = q.data

  const download = (data: unknown, file: string) => {
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }))
    Object.assign(document.createElement('a'), { href: url, download: file }).click()
    URL.revokeObjectURL(url)
  }

  return (
    <>
      <PageTitle title="Accounting reports" />
      <div className="mb-3 flex flex-wrap gap-1">
        {TABS.map(([k, label]) => <Button key={k} size="sm" variant={tab === k ? 'default' : 'ghost'} onClick={() => setTab(k)}>{label}</Button>)}
      </div>
      <Card className="mb-4 flex flex-wrap items-end gap-3 p-3">
        {['pnl', 'cash-book', 'gst'].includes(tab) && <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>}
        <Field label={['pnl', 'cash-book', 'gst'].includes(tab) ? 'To' : 'As on'}><Input type="date" max={today()} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
        {tab === 'outstanding' && <Field label="Parties"><Select value={type} onChange={(e) => setType(e.target.value)}>{['retailer', 'supplier', 'kaarigar', 'refiner'].map((t) => <option key={t} value={t}>{t}</option>)}</Select></Field>}
        {tab === 'cash-book' && <Field label="Book"><Select value={account} onChange={(e) => setAccount(e.target.value)}><option value="cash">Cash</option><option value="bank">Bank</option></Select></Field>}
        {d?.balanced !== undefined && <Badge tone={d.balanced ? 'success' : 'danger'} className="mb-2">{d.balanced ? 'Balanced' : 'Does not balance'}</Badge>}
      </Card>

      {d && tab === 'trial-balance' && (
        <Card><Table head={['Code', 'Account', 'Debit ₹', 'Credit ₹', 'Metal Dr g', 'Metal Cr g']}>
          {d.rows.map((r: any) => <tr key={r.code}><Td className="font-mono text-xs">{r.code}</Td><Td>{r.name}<div className="text-xs text-muted-foreground">{r.group}</div></Td><Td className="tabular">{Number(r.debit) ? inr(r.debit) : ''}</Td><Td className="tabular">{Number(r.credit) ? inr(r.credit) : ''}</Td><Td className="tabular">{Number(r.fine_dr) ? r.fine_dr : ''}</Td><Td className="tabular">{Number(r.fine_cr) ? r.fine_cr : ''}</Td></tr>)}
          <tr className="font-semibold"><Td colSpan={2}>Total</Td><Td className="tabular">{inr(d.totals.debit)}</Td><Td className="tabular">{inr(d.totals.credit)}</Td><Td className="tabular">{d.totals.fine_dr}</Td><Td className="tabular">{d.totals.fine_cr}</Td></tr>
        </Table></Card>
      )}

      {d && tab === 'pnl' && (
        <div className="grid gap-4 lg:grid-cols-2">
          {[['Income', d.income, d.total_income], ['Expenses', d.expense, d.total_expense]].map(([title, rows, total]: any) => (
            <Card key={title}><Table head={[title, '₹']}>
              {rows.map((r: any) => <tr key={r.code}><Td>{r.name}</Td><Td className="tabular">{inr(r.amount)}</Td></tr>)}
              <tr className="font-semibold"><Td>Total</Td><Td className="tabular">{inr(total)}</Td></tr>
            </Table></Card>
          ))}
          <Card className="p-4 lg:col-span-2"><p className="text-lg font-semibold">Net {Number(d.net_profit) >= 0 ? 'profit' : 'loss'}: ₹{inr(Math.abs(Number(d.net_profit)))}</p></Card>
        </div>
      )}

      {d && tab === 'balance-sheet' && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card><Table head={['Assets', '₹']}>
            {d.sections.asset.map((r: any) => <tr key={r.code}><Td>{r.name}{Number(r.fine) !== 0 && <div className="text-xs text-muted-foreground">metal {r.fine} g</div>}</Td><Td className="tabular">{inr(r.amount)}</Td></tr>)}
            <tr className="font-semibold"><Td>Total</Td><Td className="tabular">{inr(d.totals.asset)}</Td></tr>
          </Table></Card>
          <Card><Table head={['Liabilities & equity', '₹']}>
            {[...d.sections.liability, ...d.sections.equity].map((r: any) => <tr key={r.code + r.name}><Td>{r.name}</Td><Td className="tabular">{inr(r.amount)}</Td></tr>)}
            <tr className="font-semibold"><Td>Total</Td><Td className="tabular">{inr(Number(d.totals.liability) + Number(d.totals.equity))}</Td></tr>
          </Table></Card>
        </div>
      )}

      {d && tab === 'outstanding' && (
        <Card><Table head={['Party', 'Outstanding ₹', '0–30', '31–60', '61–90', '90+', 'Metal']}>
          {d.rows.map((r: any) => <tr key={r.party_id}><Td>{r.name}</Td><Td className="tabular font-medium">{inr(r.amount)}</Td>{['0_30', '31_60', '61_90', '90_plus'].map((k) => <Td key={k} className={`tabular ${k === '90_plus' && Number(r.ageing[k]) ? 'text-danger' : ''}`}>{Number(r.ageing[k]) ? inr(r.ageing[k]) : ''}</Td>)}<Td className="tabular">{Number(r.fine) ? `${r.fine} g` : ''}</Td></tr>)}
          <tr className="font-semibold"><Td>Total</Td><Td className="tabular">{inr(d.total)}</Td><Td colSpan={5} /></tr>
        </Table></Card>
      )}

      {d && tab === 'cash-book' && (
        <Card><Table head={['Date', 'Voucher', 'Party', 'Receipt ₹', 'Payment ₹', 'Balance ₹']}>
          <tr className="bg-muted/50"><Td colSpan={5}>Opening</Td><Td className="tabular">{inr(d.opening)}</Td></tr>
          {d.rows.map((r: any, i: number) => <tr key={i}><Td>{fmtDate(r.date)}</Td><Td className="font-mono text-xs">{r.voucher_no}<div className="font-sans text-muted-foreground">{VOUCHER_LABEL[r.type]}</div></Td><Td>{r.party}</Td><Td className="tabular">{Number(r.receipt) ? inr(r.receipt) : ''}</Td><Td className="tabular">{Number(r.payment) ? inr(r.payment) : ''}</Td><Td className="tabular">{inr(r.balance)}</Td></tr>)}
        </Table></Card>
      )}

      {d && tab === 'gst' && (
        <div className="grid gap-4">
          <Card className="p-4">
            <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold">GSTR-3B summary</h2><Button size="sm" variant="outline" onClick={() => download(d, `gstr3b-${from}-${to}.json`)}><Download /> JSON</Button></div>
            <Table head={['', 'CGST ₹', 'SGST ₹', 'IGST ₹']}>
              <tr><Td>Output tax (taxable ₹{inr(d.outward_taxable)})</Td>{['cgst', 'sgst', 'igst'].map((k) => <Td key={k} className="tabular">{inr(d.outward_tax[k])}</Td>)}</tr>
              <tr><Td>Input tax credit</Td>{['cgst', 'sgst', 'igst'].map((k) => <Td key={k} className="tabular">{inr(d.itc[k])}</Td>)}</tr>
              <tr className="font-semibold"><Td>Net payable</Td>{['cgst', 'sgst', 'igst'].map((k) => <Td key={k} className="tabular">{inr(d.net_payable[k])}</Td>)}</tr>
            </Table>
          </Card>
          {gstr1.data && (
            <Card className="p-4">
              <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold">GSTR-1: B2B invoices ({gstr1.data.b2b.length})</h2><Button size="sm" variant="outline" onClick={() => download(gstr1.data, `gstr1-${from}-${to}.json`)}><Download /> JSON</Button></div>
              <Table head={['Invoice', 'Date', 'Party', 'GSTIN', 'Taxable ₹', 'Tax ₹', 'Total ₹']}>
                {gstr1.data.b2b.map((r: any) => <tr key={r.invoice_no}><Td className="font-mono text-xs">{r.invoice_no}</Td><Td>{fmtDate(r.date)}</Td><Td>{r.party}</Td><Td className="font-mono text-xs">{r.gstin}</Td><Td className="tabular">{inr(r.taxable)}</Td><Td className="tabular">{inr(Number(r.cgst) + Number(r.sgst) + Number(r.igst))}</Td><Td className="tabular">{inr(r.total)}</Td></tr>)}
              </Table>
              <h3 className="mt-4 text-sm font-semibold">HSN summary</h3>
              <Table head={['HSN', 'Pieces', 'Net g', 'Taxable ₹', 'CGST', 'SGST', 'IGST']}>
                {gstr1.data.hsn.map((r: any) => <tr key={r.hsn}><Td>{r.hsn}</Td><Td>{r.qty}</Td><Td className="tabular">{r.net_wt}</Td><Td className="tabular">{inr(r.taxable)}</Td><Td className="tabular">{inr(r.cgst)}</Td><Td className="tabular">{inr(r.sgst)}</Td><Td className="tabular">{inr(r.igst)}</Td></tr>)}
              </Table>
              {gstr1.data.b2cs.length > 0 && <p className="mt-2 text-sm text-muted-foreground">B2C (no GSTIN): {gstr1.data.b2cs.map((r: any) => `state ${r.place_of_supply} @ ${r.rate}%: ₹${inr(r.taxable)}`).join(' · ')}</p>}
            </Card>
          )}
        </div>
      )}

      {d && tab === 'stock-valuation' && (
        <Card>
          {d.missing_rates.length > 0 && <p className="p-3 text-sm text-warning">No gold rate set for {d.missing_rates.join(', ')}; those rows are valued at ₹0.</p>}
          <Table head={['Where', 'Karat', 'Gross g', 'Fine g', 'Rate ₹/g', 'Value ₹']}>
            {d.rows.map((r: any, i: number) => <tr key={i}><Td className="capitalize">{r.location}</Td><Td>{r.karat}</Td><Td className="tabular">{r.gross}</Td><Td className="tabular">{r.fine}</Td><Td className="tabular">{r.rate ? inr(r.rate) : '—'}</Td><Td className="tabular">{inr(r.value)}</Td></tr>)}
            <tr className="font-semibold"><Td colSpan={5}>Total stock value</Td><Td className="tabular">{inr(d.total_value)}</Td></tr>
          </Table>
        </Card>
      )}
      {d && tab === 'outstanding' && d.rows.length === 0 && <p className="text-sm text-muted-foreground">Nothing outstanding. {drCr('0')}</p>}
    </>
  )
}
