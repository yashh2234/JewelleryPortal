import { Alert, api, ApiError, Badge, Button, Card, Input, Select, Table, Td, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Fragment, useState } from 'react'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from './shared'
import { TX_LABEL, type Tx } from './types'

/** Every ledger voucher. Mistakes are fixed by reversing, never by editing. */
export default function TransactionsPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [type, setType] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [open, setOpen] = useState<number | null>(null)

  const list = useQuery({
    queryKey: ['metal', 'transactions', type, from, to],
    queryFn: () => api.get<Paginated<Tx>>(`/api/v1/metal/transactions?type=${type}&from=${from}&to=${to}`),
  })

  const ack = useMutation({
    mutationFn: (tx: Tx) => api.post(`/api/v1/metal/transactions/${tx.id}/acknowledge`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['metal'] }),
  })

  const reverse = useMutation({
    mutationFn: ({ tx, reason }: { tx: Tx; reason: string }) => api.post(`/api/v1/metal/transactions/${tx.id}/reverse`, { reason }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['metal'] }),
  })

  return (
    <>
      <PageTitle title="Gold transactions">
        <Select className="w-40" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          {Object.entries(TX_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </Select>
        <Input type="date" className="w-40" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input type="date" className="w-40" value={to} onChange={(e) => setTo(e.target.value)} />
      </PageTitle>
      {reverse.error instanceof ApiError && <div className="mb-3"><Alert>{reverse.error.message}</Alert></div>}

      <Card>
        <Table head={['Date', 'Voucher', 'Type', 'Party / kaarigar', 'Fine g', 'By', '']}>
          {list.data?.data.map((tx) => (
            <Fragment key={tx.id}>
              <tr className={tx.reversed_by_id ? 'text-muted-foreground line-through decoration-1' : undefined}>
                <Td>{fmtDate(tx.date)}</Td>
                <Td className="font-mono text-xs"><button className="hover:underline" onClick={() => setOpen(open === tx.id ? null : tx.id)}>{tx.voucher_no}</button></Td>
                <Td>
                  {TX_LABEL[tx.type]}
                  {tx.over_limit && <Badge tone="danger" className="ml-1">over limit</Badge>}
                  {tx.kaarigar_ack === 'disputed' && <Badge tone="warning" className="ml-1">disputed</Badge>}
                  {tx.type === 'issue' && !tx.reversed_by_id && tx.kaarigar_ack === 'accepted' && <Badge tone="success" className="ml-1">acknowledged</Badge>}
                  {tx.type === 'issue' && !tx.reversed_by_id && !tx.kaarigar_ack && <Badge className="ml-1">awaiting acknowledgement</Badge>}
                </Td>
                <Td>{tx.kaarigar?.name ?? tx.retailer?.shop_name ?? tx.party_name ?? '—'}{tx.job_card && <span className="text-xs text-muted-foreground"> · {tx.job_card.card_no}</span>}</Td>
                <Td className="tabular">{tx.fine_total}</Td>
                <Td className="text-xs">{tx.created_by}</Td>
                <Td className="whitespace-nowrap text-right">
                  <a href={`/api/v1/metal/transactions/${tx.id}/slip`} target="_blank" rel="noreferrer"><Button size="sm" variant="ghost">Slip</Button></a>
                  {can('metal.issue') && tx.type === 'issue' && !tx.reversed_by_id && !tx.kaarigar_ack && (
                    <Button size="sm" variant="ghost" title="The kaarigar signed the printed slip" onClick={() => confirm(`Record that the kaarigar signed slip ${tx.voucher_no}?`) && ack.mutate(tx)}>Signed slip</Button>
                  )}
                  {can('metal.reverse') && !tx.reversed_by_id && tx.type !== 'reversal' && (
                    <Button size="sm" variant="ghost" onClick={() => {
                      const reason = prompt(`Reverse ${tx.voucher_no}? A mirror entry will be posted. Reason:`)
                      if (reason) reverse.mutate({ tx, reason })
                    }}>Reverse</Button>
                  )}
                </Td>
              </tr>
              {open === tx.id && (
                <tr>
                  <td colSpan={7} className="bg-muted/50 px-4 py-2">
                    <table className="w-full text-xs">
                      <tbody>
                        {tx.lines.map((l, i) => (
                          <tr key={i}>
                            <td className="py-1 capitalize">{l.kind}</td>
                            <td>{l.from?.name} → {l.to?.name}</td>
                            <td>{l.karat}</td>
                            <td className="tabular">gross {l.gross_wt}</td>
                            <td className="tabular">stone {l.stone_wt}</td>
                            <td className="tabular">tunch {l.purity_pct}</td>
                            <td className="tabular font-medium">fine {l.fine_wt}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                    {tx.remarks && <p className="mt-1 text-muted-foreground">{tx.remarks}</p>}
                  </td>
                </tr>
              )}
            </Fragment>
          ))}
        </Table>
      </Card>
    </>
  )
}
