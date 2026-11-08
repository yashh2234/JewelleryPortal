import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Table, Td, today, WeightInput } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock } from 'lucide-react'
import { useState } from 'react'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { TX_LABEL, type Tx } from './types'

interface Daybook {
  date: string
  opening: Record<string, string>
  closing: Record<string, string>
  transactions: Tx[]
  closed: { system_vault_fine: string; physical_vault_fine: string | null; difference_fine: string; remarks: string | null } | null
  last_closed: string | null
}

const PLACES: [string, string][] = [['vault', 'Vault'], ['kaarigar', 'With kaarigars'], ['finished', 'Finished'], ['scrap', 'Scrap'], ['wo', 'In work orders'], ['ahc', 'At AHC'], ['loss', 'Loss (cumulative)']]

/** Every movement of the day, opening → closing by location, and the daily closing. */
export default function DaybookPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [date, setDate] = useState(today())
  const [physical, setPhysical] = useState('')
  const [remarks, setRemarks] = useState('')

  const q = useQuery({ queryKey: ['metal', 'daybook', date], queryFn: () => api.get<Daybook>(`/api/v1/metal/daybook?date=${date}`) })
  const close = useMutation({
    mutationFn: () => api.post('/api/v1/metal/closing', { date, physical_vault_fine: physical || null, remarks: remarks || null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['metal'] }),
  })
  const d = q.data
  const err = close.error instanceof ApiError ? close.error : null

  return (
    <>
      <PageTitle title="Day-book">
        <Input type="date" className="w-44" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
        {d?.last_closed && <Badge><Lock className="mr-1 size-3" /> closed up to {d.last_closed}</Badge>}
      </PageTitle>

      <Card className="mb-4">
        <Table head={['', 'Opening (fine g)', 'Closing (fine g)']}>
          {PLACES.map(([k, label]) => (
            <tr key={k}>
              <Td>{label}</Td>
              <Td className="tabular">{d?.opening[k] ?? '0.000'}</Td>
              <Td className="tabular font-medium">{d?.closing[k] ?? '0.000'}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card className="mb-4">
        <CardHeader title={`Movements (${d?.transactions.length ?? 0})`} />
        <Table head={['Voucher', 'Type', 'Kaarigar / party', 'Lines', 'Fine g', 'By']}>
          {d?.transactions.map((tx) => (
            <tr key={tx.id} className={tx.reversed_by_id ? 'text-muted-foreground line-through' : undefined}>
              <Td className="font-mono text-xs">{tx.voucher_no}</Td>
              <Td>{TX_LABEL[tx.type]}{tx.over_limit && <Badge tone="danger" className="ml-1">over limit</Badge>}</Td>
              <Td>{tx.kaarigar?.name ?? tx.retailer?.shop_name ?? tx.party_name ?? '—'}</Td>
              <Td className="text-xs">{tx.lines.map((l) => `${l.from?.name} → ${l.to?.name} ${l.fine_wt}`).join(' · ')}</Td>
              <Td className="tabular">{tx.fine_total}</Td>
              <Td className="text-xs">{tx.created_by}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card className="max-w-xl">
        <CardHeader title="Daily closing" description="Weigh the vault and compare. Closing locks this day and every earlier day." />
        <div className="grid gap-3 p-4">
          {d?.closed ? (
            <Alert tone={d.closed.difference_fine === '0.000' ? 'success' : 'warning'}>
              Closed. System {d.closed.system_vault_fine} g · physical {d.closed.physical_vault_fine ?? '—'} g · difference {d.closed.difference_fine} g
              {d.closed.remarks && <> · “{d.closed.remarks}”</>}
            </Alert>
          ) : can('metal.vault') ? (
            <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); close.mutate() }}>
              {err && <Alert>{err.message}</Alert>}
              <p className="text-sm">System vault at end of day: <b className="tabular">{d?.closing.vault ?? '…'} g fine</b></p>
              <Field label="Physical vault count (fine g)"><WeightInput value={physical} onValueChange={setPhysical} /></Field>
              <Field label="Remarks" hint="Required if the count differs"><Input value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
              <div><Button type="submit" loading={close.isPending}><Lock /> Close {date}</Button></div>
            </form>
          ) : <p className="text-sm text-muted-foreground">Not closed yet.</p>}
        </div>
      </Card>
    </>
  )
}
