import { api, Badge, Button, Card, CardHeader, Field, Input, Table, Td } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { FileText } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { PageTitle } from '../layout'
import { fmtDate } from './shared'
import type { Statement } from './types'

const kindTone = { issued: 'brand', returned: 'success', scrap: 'neutral', loss: 'danger' } as const

/** Kaarigar khata: every issue, return, scrap and loss with a running fine balance. */
export default function KaarigarLedgerPage() {
  const { id } = useParams()
  const firstOfMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toLocaleDateString('en-CA')
  const [from, setFrom] = useState(firstOfMonth)
  const [to, setTo] = useState('')

  const q = useQuery({
    queryKey: ['kaarigar-ledger', id, from, to],
    queryFn: () => api.get<Statement & { kaarigar: { id: number; name: string; phone: string | null } }>(`/api/v1/kaarigars/${id}/ledger?from=${from}${to ? `&to=${to}` : ''}`),
  })
  const s = q.data

  return (
    <>
      <PageTitle title={s ? `Khata · ${s.kaarigar.name}` : 'Khata'}>
        <a href={`/api/v1/kaarigars/${id}/ledger.pdf?from=${from}${to ? `&to=${to}` : ''}`} target="_blank" rel="noreferrer">
          <Button variant="outline"><FileText /> PDF statement</Button>
        </a>
        <Link to={`/staff/metal/issue`}><Button>Issue gold</Button></Link>
      </PageTitle>

      <div className="mb-4 grid gap-3 sm:grid-cols-4">
        {s && (['issued', 'returned', 'scrap', 'loss'] as const).map((k) => (
          <Card key={k} className="p-3">
            <p className="text-xs capitalize text-muted-foreground">{k === 'loss' ? 'Wastage' : k}</p>
            <p className="tabular text-lg font-semibold">{s.totals[k]} g</p>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader
          title={<>Gold in hand: <span className="tabular">{s?.closing ?? '…'} g fine</span></>}
          description={`Opening ${s?.opening ?? '…'} g`}
          actions={
            <div className="flex items-end gap-2">
              <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
              <Field label="To"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            </div>
          }
        />
        <Table head={['Date', 'Voucher', 'Entry', 'Job card', 'Karat', 'Gross', 'In', 'Out', 'Balance']}>
          {s?.rows.map((r, i) => (
            <tr key={i}>
              <Td>{fmtDate(r.date)}</Td>
              <Td className="font-mono text-xs">{r.voucher_no}</Td>
              <Td><Badge tone={kindTone[r.kind]} className="capitalize">{r.kind === 'loss' ? 'wastage' : r.kind}</Badge>{r.type === 'reversal' && <Badge className="ml-1">reversal</Badge>}</Td>
              <Td>{r.job_card}{r.process && <div className="text-xs text-muted-foreground">{r.process}</div>}</Td>
              <Td>{r.karat}</Td>
              <Td className="tabular">{r.gross_wt}</Td>
              <Td className="tabular text-success">{r.in}</Td>
              <Td className="tabular">{r.out}</Td>
              <Td className="tabular font-medium">{r.balance}</Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  )
}
