import { api, Card, Field, Input, Select, Table, Td, today } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { PageTitle } from '../layout'

interface Row { id: number; name: string; cards: number; issued: string; loss: string; excess: string; wastage_pct: string }

/** Wastage % per kaarigar or per process, so the worst performers stand out. */
export default function WastagePage() {
  const first = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toLocaleDateString('en-CA')
  const [group, setGroup] = useState('kaarigar')
  const [from, setFrom] = useState(first)
  const [to, setTo] = useState(today())

  const q = useQuery({
    queryKey: ['metal', 'wastage', group, from, to],
    queryFn: () => api.get<{ data: Row[] }>(`/api/v1/reports/wastage?group=${group}&from=${from}&to=${to}`),
  })
  const worst = Math.max(0, ...(q.data?.data.map((r) => Number(r.wastage_pct)) ?? []))

  return (
    <>
      <PageTitle title="Wastage report" />
      <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
        <Field label="By"><Select value={group} onChange={(e) => setGroup(e.target.value)}><option value="kaarigar">Kaarigar</option><option value="process">Process</option></Select></Field>
        <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="To"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
      </Card>
      <Card>
        <Table head={[group === 'process' ? 'Process' : 'Kaarigar', 'Closed cards', 'Issued (fine g)', 'Loss (fine g)', 'Above limit g', 'Wastage %', '']}>
          {q.data?.data.map((r) => (
            <tr key={r.id}>
              <Td className="font-medium">{r.name}</Td>
              <Td className="tabular">{r.cards}</Td>
              <Td className="tabular">{r.issued}</Td>
              <Td className="tabular">{r.loss}</Td>
              <Td className={`tabular ${Number(r.excess) > 0 ? 'text-danger' : ''}`}>{r.excess}</Td>
              <Td className="tabular font-medium">{r.wastage_pct}%</Td>
              <Td className="w-40">
                <div className="h-2 rounded bg-muted">
                  <div className="h-2 rounded bg-brand" style={{ width: `${worst ? (Number(r.wastage_pct) / worst) * 100 : 0}%` }} />
                </div>
              </Td>
            </tr>
          ))}
        </Table>
        {q.data?.data.length === 0 && <p className="p-4 text-sm text-muted-foreground">No closed job cards in this period.</p>}
      </Card>
    </>
  )
}
