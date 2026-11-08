import { Alert, api, ApiError, Badge, Button, Card, EmptyState, Input, Select, Table, Td, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate } from './shared'
import type { JobCard } from './types'

export default function JobCardsPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [params] = useSearchParams()
  const [filter, setFilter] = useState(params.get('f') ?? 'open')
  const [search, setSearch] = useState('')
  const [message, setMessage] = useState<{ tone: 'success' | 'warning' | 'danger'; text: string } | null>(null)

  const query = { open: 'status=open', overdue: 'overdue=1', alerts: 'alerts=1', closed: 'status=closed', all: '' }[filter]
  const cards = useQuery({
    queryKey: ['job-cards', filter, search],
    queryFn: () => api.get<Paginated<JobCard>>(`/api/v1/job-cards?${query}&search=${encodeURIComponent(search)}`),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['job-cards'] })
    qc.invalidateQueries({ queryKey: ['metal'] })
  }

  const close = useMutation({
    mutationFn: (c: JobCard) => api.post<{ data: JobCard; alert: string | null }>(`/api/v1/job-cards/${c.id}/close`),
    onSuccess: (r) => {
      setMessage(r.alert ? { tone: 'warning', text: r.alert } : { tone: 'success', text: `${r.data.card_no} closed · loss ${r.data.loss_fine} g (within ${r.data.allowed_wastage_pct}%).` })
      refresh()
    },
    onError: (e) => setMessage({ tone: 'danger', text: e instanceof ApiError ? e.message : 'Could not close' }),
  })

  const writeOff = useMutation({
    mutationFn: ({ c, reason }: { c: JobCard; reason: string }) => api.post(`/api/v1/job-cards/${c.id}/write-off-excess`, { reason }),
    onSuccess: () => { setMessage({ tone: 'success', text: 'Excess wastage written off.' }); refresh() },
    onError: (e) => setMessage({ tone: 'danger', text: e instanceof ApiError ? e.message : 'Failed' }),
  })

  return (
    <>
      <PageTitle title="Job cards">
        <Input className="w-40" placeholder="Card no." value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select className="w-44" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="open">Open</option>
          <option value="overdue">Overdue</option>
          <option value="alerts">Over-wastage alerts</option>
          <option value="closed">Closed</option>
          <option value="all">All</option>
        </Select>
        {can('metal.issue') && <Link to="/staff/metal/issue"><Button>Issue gold</Button></Link>}
      </PageTitle>

      {message && <div className="mb-4"><Alert tone={message.tone}>{message.text}</Alert></div>}

      <Card>
        {cards.data?.data.length === 0 ? (
          <EmptyState title="No job cards here" />
        ) : (
          <Table head={['Card', 'Kaarigar', 'Process', 'Issued', 'Back + scrap', 'Holding / loss', 'Due', '']}>
            {cards.data?.data.map((c) => {
              const open = c.status === 'open'
              const overdue = open && c.due_date && c.due_date < new Date().toISOString().slice(0, 10)
              const excess = Number(c.excess_loss_fine) > 0
              return (
                <tr key={c.id} className={overdue || (excess && !c.excess_resolution) ? 'bg-warning/5' : undefined}>
                  <Td className="font-medium">
                    {c.card_no}
                    <div className="text-xs text-muted-foreground">{c.description ?? c.karat.label}</div>
                  </Td>
                  <Td><Link className="hover:underline" to={`/staff/kaarigars/${c.kaarigar.id}/ledger`}>{c.kaarigar.name}</Link></Td>
                  <Td>{c.process.name}{c.kaarigar_status === 'ready' && open && <Badge tone="brand" className="ml-1">ready</Badge>}</Td>
                  <Td className="tabular">{open ? c.live?.issued : c.issued_fine}</Td>
                  <Td className="tabular">{open ? `${c.live?.returned} + ${c.live?.scrap}` : `${c.returned_fine} + ${c.scrap_fine}`}</Td>
                  <Td className="tabular">
                    {open ? <>{c.live?.outstanding} <span className="text-xs text-muted-foreground">held</span></> : (
                      <>
                        {c.loss_fine} <span className="text-xs text-muted-foreground">loss</span>
                        {excess && <Badge tone={c.excess_resolution ? 'neutral' : 'danger'} className="ml-1">+{c.excess_loss_fine} over {c.allowed_wastage_pct}%</Badge>}
                      </>
                    )}
                  </Td>
                  <Td>{open ? <span className={overdue ? 'font-medium text-danger' : ''}>{fmtDate(c.due_date)}</span> : <Badge>closed</Badge>}</Td>
                  <Td className="whitespace-nowrap text-right">
                    {open && can('metal.receive') && (
                      <>
                        <Link to={`/staff/metal/receive?kaarigar=${c.kaarigar.id}&card=${c.id}`}><Button size="sm" variant="ghost">Receive</Button></Link>
                        <Button size="sm" variant="ghost" onClick={() => confirm(`Close ${c.card_no}? ${c.live?.outstanding} g still with the kaarigar becomes loss.`) && close.mutate(c)}>Close</Button>
                      </>
                    )}
                    {!open && excess && !c.excess_resolution && can('metal.approve_loss') && (
                      <Button size="sm" variant="ghost" onClick={() => {
                        const reason = prompt(`Write off ${c.excess_loss_fine} g excess wastage for ${c.kaarigar.name}? Reason:`)
                        if (reason) writeOff.mutate({ c, reason })
                      }}>Write off excess</Button>
                    )}
                  </Td>
                </tr>
              )
            })}
          </Table>
        )}
      </Card>
    </>
  )
}
