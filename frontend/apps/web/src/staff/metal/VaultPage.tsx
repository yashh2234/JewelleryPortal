import { Alert, api, ApiError, Button, Card, CardHeader, Checkbox, Field, Input, Select, Table, Td, today, WeightInput, previewFine, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import type { Retailer } from '../types'
import { emptyItem, ItemsEditor, itemsFine, SlipLink, toItems, useKarats, type Item } from './shared'
import type { Tx } from './types'

type Tab = 'purchase' | 'deposit' | 'melt' | 'refine' | 'transfer'

interface StockRow { karat_id: number; karat: string; gross: string; fine: string }

function Stock({ location, title }: { location: 'vault' | 'scrap' | 'finished'; title: string }) {
  const q = useQuery({
    queryKey: ['metal', 'stock', location],
    queryFn: () => api.get<{ data: StockRow[]; total_fine: string }>(`/api/v1/metal/stock?location=${location}`),
  })
  return (
    <Card>
      <CardHeader title={title} description={<span className="tabular">{q.data?.total_fine ?? '…'} g fine</span>} />
      <Table head={['Karat', 'Gross g', 'Fine g']}>
        {q.data?.data.map((r) => (
          <tr key={r.karat_id}><Td>{r.karat}</Td><Td className="tabular">{r.gross}</Td><Td className="tabular font-medium">{r.fine}</Td></tr>
        ))}
      </Table>
    </Card>
  )
}

/** Vault, scrap and finished stock, plus the vault operations. */
export default function VaultPage() {
  const can = useCan()
  const [tab, setTab] = useState<Tab>('purchase')

  return (
    <>
      <PageTitle title="Vault & stock" />
      <div className="mb-4 grid gap-4 lg:grid-cols-3">
        <Stock location="vault" title="Vault" />
        <Stock location="finished" title="Finished goods" />
        <Stock location="scrap" title="Scrap & dust pool" />
      </div>

      {can('metal.vault') && (
        <Card>
          <div className="flex flex-wrap gap-1 border-b p-2">
            {([['purchase', 'Purchase / opening'], ['deposit', 'Retailer deposit'], ['melt', 'Melting'], ['refine', 'Refining'], ['transfer', 'Transfer']] as const).map(([k, label]) => (
              <Button key={k} size="sm" variant={tab === k ? 'default' : 'ghost'} onClick={() => setTab(k)}>{label}</Button>
            ))}
          </div>
          <div className="p-4">
            {tab === 'purchase' && <PurchaseForm />}
            {tab === 'deposit' && <DepositForm />}
            {tab === 'melt' && <MeltForm />}
            {tab === 'refine' && <RefineForm />}
            {tab === 'transfer' && <TransferForm />}
          </div>
        </Card>
      )}
    </>
  )
}

function usePost(path: string) {
  const qc = useQueryClient()
  const [done, setDone] = useState<Tx | null>(null)
  const m = useMutation({
    mutationFn: (body: object) => api.post<{ data: Tx }>(path, body),
    onSuccess: (r) => { setDone(r.data); qc.invalidateQueries({ queryKey: ['metal'] }) },
  })
  const error = m.error instanceof ApiError ? m.error : null
  const banner = (
    <>
      {error && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}
      {done && <Alert tone="success">Posted {done.voucher_no} · {done.fine_total} g fine. <SlipLink tx={done} /></Alert>}
    </>
  )
  return { m, banner, reset: () => setDone(null) }
}

function Common({ date, setDate, remarks, setRemarks, children }: { date: string; setDate: (v: string) => void; remarks: string; setRemarks: (v: string) => void; children?: React.ReactNode }) {
  return (
    <div className="grid gap-4 sm:grid-cols-[10rem_1fr_1fr]">
      <Field label="Date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
      {children}
      <Field label="Remarks"><Input value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
    </div>
  )
}

function PurchaseForm() {
  const { m, banner } = usePost('/api/v1/metal/purchase')
  const [items, setItems] = useState<Item[]>([emptyItem()])
  const [party, setParty] = useState('')
  const [reference, setReference] = useState('')
  const [opening, setOpening] = useState(false)
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')

  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); m.mutate({ items: toItems(items), party_name: party || null, reference: reference || null, opening, date, remarks: remarks || null }, { onSuccess: () => setItems([emptyItem()]) }) }}>
      {banner}
      <p className="text-sm text-muted-foreground">Bullion or old gold coming in. Use the tunch report's purity.</p>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Supplier / dealer"><Input value={party} onChange={(e) => setParty(e.target.value)} /></Field>
        <Field label="Bill / tunch report no."><Input value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
        <div className="flex items-end pb-2"><Checkbox label="Opening stock (first time setup)" checked={opening} onChange={(e) => setOpening(e.target.checked)} /></div>
      </div>
      <ItemsEditor items={items} onChange={setItems} />
      <Common date={date} setDate={setDate} remarks={remarks} setRemarks={setRemarks} />
      <div><Button type="submit" loading={m.isPending}>Post to vault</Button></div>
    </form>
  )
}

function DepositForm() {
  const { m, banner } = usePost('/api/v1/metal/deposit')
  const retailers = useQuery({ queryKey: ['retailers', 'metal'], queryFn: () => api.get<Paginated<Retailer>>('/api/v1/retailers?status=active&per_page=500') })
  const [retailerId, setRetailerId] = useState('')
  const [items, setItems] = useState<Item[]>([emptyItem()])
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const eligible = retailers.data?.data.filter((r) => r.metal_account_enabled) ?? []

  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); m.mutate({ retailer_id: Number(retailerId), items: toItems(items), date, remarks: remarks || null }, { onSuccess: () => setItems([emptyItem()]) }) }}>
      {banner}
      <p className="text-sm text-muted-foreground">Gold a retailer gives you against future orders. It goes into your vault; the retailer's metal account is credited.</p>
      <Field label="Retailer (metal account on)" className="max-w-sm">
        <Select required value={retailerId} onChange={(e) => setRetailerId(e.target.value)}>
          <option value="">Choose…</option>
          {eligible.map((r) => <option key={r.id} value={r.id}>{r.shop_name}</option>)}
        </Select>
      </Field>
      <ItemsEditor items={items} onChange={setItems} />
      <Common date={date} setDate={setDate} remarks={remarks} setRemarks={setRemarks} />
      <div><Button type="submit" loading={m.isPending}>Post deposit</Button></div>
    </form>
  )
}

function MeltForm() {
  const { m, banner } = usePost('/api/v1/metal/melt')
  const karats = useKarats()
  const [inputs, setInputs] = useState<Item[]>([emptyItem()])
  const [alloy, setAlloy] = useState('')
  const [out, setOut] = useState({ karat_id: '', gross: '', purity: '' })
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const outKarat = karats.data?.find((k) => String(k.id) === out.karat_id)
  const fineIn = itemsFine(inputs, karats.data)
  const fineOut = previewFine(out.gross, out.purity || outKarat?.purity_pct || '0')
  const loss = ((Math.round(Number(fineIn) * 1000) - Math.round(Number(fineOut) * 1000)) / 1000).toFixed(3)

  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); m.mutate({ inputs: toItems(inputs), alloy_gross: alloy || '0', output: { karat_id: Number(out.karat_id), gross: out.gross, ...(out.purity ? { purity: out.purity } : {}) }, date, remarks: remarks || null }) }}>
      {banner}
      <p className="text-sm text-muted-foreground">Gold in from the vault + alloy → one bar back. Fine in must equal fine out + melting loss.</p>
      <p className="text-sm font-medium">Gold put in</p>
      <ItemsEditor items={inputs} onChange={setInputs} />
      <div className="grid gap-4 sm:grid-cols-4">
        <Field label="Alloy added g" hint="Copper/silver: adds weight, not gold"><WeightInput value={alloy} onValueChange={setAlloy} /></Field>
        <Field label="Bar karat">
          <Select required value={out.karat_id} onChange={(e) => setOut({ ...out, karat_id: e.target.value })}>
            <option value="">—</option>
            {karats.data?.map((k) => <option key={k.id} value={k.id}>{k.label}</option>)}
          </Select>
        </Field>
        <Field label="Bar gross g"><WeightInput required value={out.gross} onValueChange={(v) => setOut({ ...out, gross: v })} /></Field>
        <Field label="Bar tunch %"><WeightInput scale={false} placeholder={outKarat?.purity_pct} value={out.purity} onValueChange={(v) => setOut({ ...out, purity: v })} /></Field>
      </div>
      <p className={`tabular text-sm ${Number(loss) < 0 ? 'text-danger' : ''}`}>Fine in {fineIn} g · bar {fineOut} g · melting loss {loss} g</p>
      <Common date={date} setDate={setDate} remarks={remarks} setRemarks={setRemarks} />
      <div><Button type="submit" loading={m.isPending}>Post melting</Button></div>
    </form>
  )
}

function RefineForm() {
  const { m, banner } = usePost('/api/v1/metal/refine')
  const karats = useKarats()
  const [sent, setSent] = useState<Item[]>([emptyItem()])
  const [back, setBack] = useState({ karat_id: '', gross: '', purity: '99.9' })
  const [party, setParty] = useState('')
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')

  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); m.mutate({ sent: toItems(sent), returned: { karat_id: Number(back.karat_id), gross: back.gross, purity: back.purity }, party_name: party || null, date, remarks: remarks || null }) }}>
      {banner}
      <p className="text-sm text-muted-foreground">Scrap from the pool goes to the refiner; fine gold comes back to the vault. The difference is refining loss.</p>
      <Field label="Refiner" className="max-w-sm"><Input value={party} onChange={(e) => setParty(e.target.value)} /></Field>
      <p className="text-sm font-medium">Scrap sent</p>
      <ItemsEditor items={sent} onChange={setSent} />
      <div className="grid gap-4 sm:grid-cols-4">
        <Field label="Returned as">
          <Select required value={back.karat_id} onChange={(e) => setBack({ ...back, karat_id: e.target.value })}>
            <option value="">—</option>
            {karats.data?.map((k) => <option key={k.id} value={k.id}>{k.label}</option>)}
          </Select>
        </Field>
        <Field label="Returned gross g"><WeightInput required value={back.gross} onValueChange={(v) => setBack({ ...back, gross: v })} /></Field>
        <Field label="Tunch %"><WeightInput scale={false} value={back.purity} onValueChange={(v) => setBack({ ...back, purity: v })} /></Field>
      </div>
      <Common date={date} setDate={setDate} remarks={remarks} setRemarks={setRemarks} />
      <div><Button type="submit" loading={m.isPending}>Post refining</Button></div>
    </form>
  )
}

function TransferForm() {
  const { m, banner } = usePost('/api/v1/metal/transfer')
  const [from, setFrom] = useState('finished')
  const [to, setTo] = useState('vault')
  const [items, setItems] = useState<Item[]>([emptyItem()])
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const places = [['vault', 'Vault'], ['finished', 'Finished goods'], ['scrap', 'Scrap pool']]

  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); m.mutate({ from, to, items: toItems(items), date, remarks: remarks || null }) }}>
      {banner}
      <div className="grid max-w-md gap-4 sm:grid-cols-2">
        <Field label="From"><Select value={from} onChange={(e) => setFrom(e.target.value)}>{places.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
        <Field label="To"><Select value={to} onChange={(e) => setTo(e.target.value)}>{places.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
      </div>
      <ItemsEditor items={items} onChange={setItems} pieces />
      <Common date={date} setDate={setDate} remarks={remarks} setRemarks={setRemarks} />
      <div><Button type="submit" loading={m.isPending}>Post transfer</Button></div>
    </form>
  )
}
