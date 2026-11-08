import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Select, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PageTitle } from '../layout'
import { useActiveKaarigars, useProcesses } from '../metal/shared'
import { useAccounts } from './shared'

interface LabourRate { id: number; basis: 'per_gram' | 'per_piece'; rate: string; process: { id: number; name: string }; kaarigar: { id: number; name: string } | null }
interface Tax { id: number; hsn: string; description: string; gst_rate: string; effective_from: string }
interface Fy { id: number; label: string; start_date: string; end_date: string; locked_until: string | null }

/** Labour rates, GST settings, chart of accounts, financial years and period lock. */
export default function AccountSettingsPage() {
  const qc = useQueryClient()
  const processes = useProcesses()
  const kaarigars = useActiveKaarigars()
  const accounts = useAccounts()
  const rates = useQuery({ queryKey: ['labour-rates'], queryFn: () => api.get<{ data: LabourRate[] }>('/api/v1/labour-rates') })
  const taxes = useQuery({ queryKey: ['tax-settings'], queryFn: () => api.get<{ data: Tax[] }>('/api/v1/tax-settings') })
  const fys = useQuery({ queryKey: ['financial-years'], queryFn: () => api.get<{ data: Fy[] }>('/api/v1/financial-years') })

  const [lr, setLr] = useState({ process_id: '', kaarigar_id: '', basis: 'per_gram', rate: '' })
  const [tax, setTax] = useState({ hsn: '7113', description: 'Gold jewellery', gst_rate: '3', effective_from: '' })
  const [acc, setAcc] = useState({ code: '', name: '', nature: 'expense', group: 'Indirect expenses' })

  const inv = (k: string) => () => qc.invalidateQueries({ queryKey: [k] })
  const saveRate = useMutation({ mutationFn: () => api.post('/api/v1/labour-rates', { ...lr, kaarigar_id: lr.kaarigar_id || null }), onSuccess: inv('labour-rates') })
  const delRate = useMutation({ mutationFn: (id: number) => api.delete(`/api/v1/labour-rates/${id}`), onSuccess: inv('labour-rates') })
  const saveTax = useMutation({ mutationFn: () => api.post('/api/v1/tax-settings', tax), onSuccess: inv('tax-settings') })
  const saveAcc = useMutation({ mutationFn: () => api.post('/api/v1/accounts', acc), onSuccess: () => { setAcc({ ...acc, code: '', name: '' }); inv('accounts')() } })
  const lock = useMutation({ mutationFn: ({ id, date }: { id: number; date: string | null }) => api.post(`/api/v1/financial-years/${id}/lock`, { locked_until: date }), onSuccess: inv('financial-years') })
  const err = [saveRate.error, saveTax.error, saveAcc.error, lock.error].find((e) => e instanceof ApiError) as ApiError | undefined

  return (
    <>
      <PageTitle title="Accounting settings" />
      {err && <div className="mb-3"><Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert></div>}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Kaarigar labour rates" description="Closing a job card bills labour automatically. A kaarigar-specific rate beats the process rate." />
          <Table head={['Process', 'Kaarigar', 'Rate', '']}>
            {rates.data?.data.map((r) => (
              <tr key={r.id}><Td>{r.process.name}</Td><Td>{r.kaarigar?.name ?? 'Everyone'}</Td><Td className="tabular">₹{r.rate} {r.basis === 'per_gram' ? '/ g' : '/ pc'}</Td>
                <Td className="text-right"><Button size="icon" variant="ghost" onClick={() => delRate.mutate(r.id)} aria-label="Delete"><Trash2 /></Button></Td></tr>
            ))}
          </Table>
          <form className="flex flex-wrap items-end gap-2 border-t p-3" onSubmit={(e) => { e.preventDefault(); saveRate.mutate() }}>
            <Field label="Process"><Select required value={lr.process_id} onChange={(e) => setLr({ ...lr, process_id: e.target.value })}><option value="">—</option>{processes.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
            <Field label="Kaarigar"><Select value={lr.kaarigar_id} onChange={(e) => setLr({ ...lr, kaarigar_id: e.target.value })}><option value="">Everyone</option>{kaarigars.data?.map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}</Select></Field>
            <Field label="Basis"><Select value={lr.basis} onChange={(e) => setLr({ ...lr, basis: e.target.value })}><option value="per_gram">₹ per gram</option><option value="per_piece">₹ per piece</option></Select></Field>
            <Field label="Rate ₹"><Input className="w-24" required inputMode="decimal" value={lr.rate} onChange={(e) => setLr({ ...lr, rate: e.target.value })} /></Field>
            <Button type="submit" loading={saveRate.isPending}>Save</Button>
          </form>
        </Card>

        <Card>
          <CardHeader title="GST rates" description="Confirm with your CA. A new row with a later date replaces the rate from that date." />
          <Table head={['HSN', 'Description', 'Rate', 'From']}>
            {taxes.data?.data.map((t) => <tr key={t.id}><Td>{t.hsn}</Td><Td>{t.description}</Td><Td>{t.gst_rate}%</Td><Td>{t.effective_from}</Td></tr>)}
          </Table>
          <form className="flex flex-wrap items-end gap-2 border-t p-3" onSubmit={(e) => { e.preventDefault(); saveTax.mutate() }}>
            <Field label="HSN"><Input className="w-20" value={tax.hsn} onChange={(e) => setTax({ ...tax, hsn: e.target.value })} /></Field>
            <Field label="Description"><Input value={tax.description} onChange={(e) => setTax({ ...tax, description: e.target.value })} /></Field>
            <Field label="GST %"><Input className="w-16" value={tax.gst_rate} onChange={(e) => setTax({ ...tax, gst_rate: e.target.value })} /></Field>
            <Field label="From"><Input type="date" required value={tax.effective_from} onChange={(e) => setTax({ ...tax, effective_from: e.target.value })} /></Field>
            <Button type="submit" loading={saveTax.isPending}>Add</Button>
          </form>
        </Card>

        <Card>
          <CardHeader title="Financial years & period lock" description="After the books are closed with your CA, lock the period: nothing on or before that date can be posted or cancelled." />
          <Table head={['Year', 'Period', 'Locked up to', '']}>
            {fys.data?.data.map((f) => (
              <tr key={f.id}><Td className="font-medium">FY {f.label}</Td><Td className="text-xs">{f.start_date} – {f.end_date}</Td>
                <Td>{f.locked_until ? <Badge tone="warning"><Lock className="mr-1 size-3" />{f.locked_until}</Badge> : 'Open'}</Td>
                <Td className="text-right whitespace-nowrap">
                  <Button size="sm" variant="ghost" onClick={() => { const d = prompt('Lock up to (YYYY-MM-DD):', f.end_date < new Date().toISOString().slice(0, 10) ? f.end_date : ''); if (d) lock.mutate({ id: f.id, date: d }) }}>Lock</Button>
                  {f.locked_until && <Button size="sm" variant="ghost" onClick={() => confirm('Unlock this year?') && lock.mutate({ id: f.id, date: null })}>Unlock</Button>}
                </Td></tr>
            ))}
          </Table>
        </Card>

        <Card>
          <CardHeader title="Chart of accounts" />
          <div className="max-h-80 overflow-y-auto">
            <Table head={['Code', 'Account', 'Nature']}>
              {accounts.data?.map((a) => <tr key={a.id}><Td className="font-mono text-xs">{a.code}</Td><Td>{a.name}<div className="text-xs text-muted-foreground">{a.group}{a.system_key && ' · system'}</div></Td><Td className="capitalize">{a.nature}</Td></tr>)}
            </Table>
          </div>
          <form className="flex flex-wrap items-end gap-2 border-t p-3" onSubmit={(e) => { e.preventDefault(); saveAcc.mutate() }}>
            <Field label="Code"><Input className="w-20" required value={acc.code} onChange={(e) => setAcc({ ...acc, code: e.target.value })} /></Field>
            <Field label="Name"><Input required value={acc.name} onChange={(e) => setAcc({ ...acc, name: e.target.value })} /></Field>
            <Field label="Nature"><Select value={acc.nature} onChange={(e) => setAcc({ ...acc, nature: e.target.value })}>{['asset', 'liability', 'equity', 'income', 'expense'].map((n) => <option key={n} value={n}>{n}</option>)}</Select></Field>
            <Field label="Group"><Input value={acc.group} onChange={(e) => setAcc({ ...acc, group: e.target.value })} /></Field>
            <Button type="submit" loading={saveAcc.isPending}>Add</Button>
          </form>
        </Card>
      </div>
    </>
  )
}
