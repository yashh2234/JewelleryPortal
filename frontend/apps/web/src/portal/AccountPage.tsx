import { Alert, api, ApiError, Badge, Button, Card, Field, Input, InstallPrompt, ThemeSwitcher, useTenant } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, FileText } from 'lucide-react'
import { useState } from 'react'
import { useSession } from '../session'
import { inr } from './types'

interface Account {
  retailer: { shop_name: string; gstin: string | null; city: string | null; credit_limit: string; metal_account_enabled: boolean }
  outstanding: string
  metal_balance: string
  credit_available: string | null
  invoices: { id: number; voucher_no: string; date: string; amount: string; payable: string; fine_wt: string; status: string }[]
  payments: { id: number; voucher_no: string; type: string; date: string; amount: string; fine_wt: string }[]
  challans: { id: number; challan_no: string; date: string; pieces: number; net_wt: string; invoice_voucher_id: number | null }[]
}

const day = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })

export function AccountPage() {
  const tenant = useTenant()
  const { data: session } = useSession()
  const q = useQuery({ queryKey: ['portal', 'account'], queryFn: () => api.get<Account>('/api/portal/v1/account'), meta: { persist: true } })
  const a = q.data
  const owner = session?.type === 'retailer' && session.user.role === 'owner'
  const due = Number(a?.outstanding ?? 0)

  return (
    <div className="grid gap-4">
      <h1 className="font-display text-2xl font-semibold">{a?.retailer.shop_name ?? 'Account'}</h1>
      <div className="grid gap-3 sm:grid-cols-3">
        <Card className="p-4">
          <p className="text-sm text-muted-foreground">{due >= 0 ? 'Outstanding' : 'Advance with us'}</p>
          <p className="tabular text-2xl font-semibold">{inr(Math.abs(due))}</p>
          {a?.credit_available && <p className="text-xs text-muted-foreground">Credit available {inr(a.credit_available)}</p>}
        </Card>
        {a?.retailer.metal_account_enabled && (
          <Card className="p-4">
            <p className="text-sm text-muted-foreground">Your gold with us (fine)</p>
            <p className="tabular text-2xl font-semibold">{Number(a.metal_balance).toFixed(2)} g</p>
            <p className="text-xs text-muted-foreground">Deposited minus gold billed</p>
          </Card>
        )}
      </div>

      <Card className="divide-y">
        <p className="p-3 font-semibold">Invoices</p>
        {a?.invoices.length === 0 && <p className="p-3 text-sm text-muted-foreground">No invoices yet.</p>}
        {a?.invoices.map((v) => (
          <a key={v.id} href={`/api/portal/v1/invoices/${v.id}/pdf`} target="_blank" rel="noreferrer" className="flex items-center justify-between gap-2 p-3 text-sm hover:bg-muted">
            <span><FileText className="mr-1 inline size-4 text-brand" />{v.voucher_no} · {day(v.date)}{v.status === 'cancelled' && <Badge className="ml-1">cancelled</Badge>}</span>
            <span className="tabular font-medium">{inr(v.payable)}</span>
          </a>
        ))}
      </Card>

      <Card className="divide-y">
        <p className="p-3 font-semibold">Delivery challans</p>
        {a?.challans.map((c) => (
          <a key={c.id} href={`/api/portal/v1/challans/${c.id}/pdf`} target="_blank" rel="noreferrer" className="flex items-center justify-between gap-2 p-3 text-sm hover:bg-muted">
            <span><FileText className="mr-1 inline size-4 text-brand" />{c.challan_no} · {day(c.date)}</span>
            <span className="tabular">{c.pieces} pcs · {c.net_wt} g</span>
          </a>
        ))}
      </Card>

      {a && a.payments.length > 0 && (
        <Card className="divide-y">
          <p className="p-3 font-semibold">Payments & deposits received</p>
          {a.payments.map((p) => (
            <div key={p.id} className="flex justify-between p-3 text-sm">
              <span>{p.voucher_no} · {day(p.date)}</span>
              <span className="tabular">{p.type === 'metal_receipt' ? `${p.fine_wt} g gold` : inr(p.amount)}</span>
            </div>
          ))}
        </Card>
      )}

      {owner && <Team />}

      <StatementCard />

      <Card className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
          <p className="font-semibold">Appearance</p>
          <p className="text-sm text-muted-foreground">Light, dark, or match this device.</p>
        </div>
        <ThemeSwitcher className="w-full sm:w-64" />
      </Card>
      <InstallPrompt appName={tenant.name} />
    </div>
  )
}

function Team() {
  const qc = useQueryClient()
  const team = useQuery({ queryKey: ['portal', 'team'], queryFn: () => api.get<{ data: { id: number; name: string; email: string; role: string; is_active: boolean }[] }>('/api/portal/v1/team') })
  const [f, setF] = useState({ name: '', email: '', password: '' })
  const add = useMutation({ mutationFn: () => api.post('/api/portal/v1/team', f), onSuccess: () => { setF({ name: '', email: '', password: '' }); qc.invalidateQueries({ queryKey: ['portal', 'team'] }) } })
  const toggle = useMutation({ mutationFn: (u: { id: number; is_active: boolean }) => api.patch(`/api/portal/v1/team/${u.id}`, { is_active: !u.is_active }), onSuccess: () => qc.invalidateQueries({ queryKey: ['portal', 'team'] }) })
  const err = [add.error, toggle.error].find((e) => e instanceof ApiError) as ApiError | undefined

  return (
    <Card className="divide-y">
      <p className="p-3 font-semibold">Shop logins</p>
      {team.data?.data.map((u) => (
        <div key={u.id} className="flex items-center justify-between gap-2 p-3 text-sm">
          <span>{u.name} · {u.email} <Badge className="ml-1">{u.role}</Badge></span>
          {u.role !== 'owner' && <Button size="sm" variant="ghost" onClick={() => toggle.mutate(u)}>{u.is_active ? 'Disable' : 'Enable'}</Button>}
        </div>
      ))}
      <form className="grid gap-2 p-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
        {err && <div className="sm:col-span-4"><Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert></div>}
        <Field label="Name"><Input required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Email"><Input required type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="Password"><Input required type="password" minLength={8} value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} /></Field>
        <div className="flex items-end"><Button type="submit" loading={add.isPending}>Add staff login</Button></div>
      </form>
    </Card>
  )
}

/** Spec §10: download an account statement for any period. */
function StatementCard() {
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  const [from, setFrom] = useState(iso(new Date(Date.now() - 90 * 86400000)))
  const [to, setTo] = useState(iso(new Date()))
  return (
    <Card className="flex flex-wrap items-end gap-3 p-4">
      <div className="mr-auto">
        <p className="font-semibold">Account statement</p>
        <p className="text-sm text-muted-foreground">Every invoice, payment and metal entry, as a PDF.</p>
      </div>
      <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
      <Field label="To"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
      <a href={`/api/portal/v1/statement.pdf?from=${from}&to=${to}`}><Button variant="outline"><Download /> Download</Button></a>
    </Card>
  )
}
