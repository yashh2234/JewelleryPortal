import { api, Badge, Button, Card, EmptyState, Input, Select, Table, Td, type Paginated } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { PageTitle } from '../layout'

interface Row {
  id: number; at: string; event: string | null; description: string; subject: string; subject_id: number | null; by: string
  changes: Record<string, unknown> | null; old: Record<string, unknown> | null; extra: Record<string, unknown> | null
}

const SUBJECTS = ['Order', 'WorkOrder', 'JobCard', 'MetalTransaction', 'FinishedItem', 'Dispatch', 'Voucher', 'Retailer', 'Kaarigar', 'Design', 'User', 'GoldRate']
const show = (v: unknown) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))

/** Audit trail (spec §14): who did what, when, and what changed. */
export default function AuditPage() {
  const [subject, setSubject] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<number | null>(null)

  const q = useQuery({
    queryKey: ['audit', subject, search, page],
    queryFn: () => api.get<Paginated<Row>>(`/api/v1/audit?${new URLSearchParams({ subject, search, page: String(page) })}`),
  })

  return (
    <>
      <PageTitle title="Audit log" description="Every change to orders, gold, stock and accounts. Kept permanently.">
        <Input className="w-48" placeholder="Search description" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
        <Select className="w-44" value={subject} onChange={(e) => { setSubject(e.target.value); setPage(1) }}>
          <option value="">Everything</option>
          {SUBJECTS.map((s) => <option key={s} value={s}>{s.replace(/([a-z])([A-Z])/g, '$1 $2')}</option>)}
        </Select>
      </PageTitle>

      <Card>
        {q.data?.data.length === 0 ? <EmptyState title="No activity" icon={<ShieldCheck />} /> : (
          <Table head={['When', 'Who', 'What', 'Record', 'Changes']}>
            {q.data?.data.map((r) => {
              const keys = Object.keys(r.changes ?? {})
              return (
                <tr key={r.id} className="align-top">
                  <Td className="whitespace-nowrap text-xs text-muted-foreground">{new Date(r.at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</Td>
                  <Td className="font-medium">{r.by}</Td>
                  <Td><Badge tone={r.event === 'deleted' ? 'danger' : r.event === 'created' ? 'success' : 'neutral'}>{r.event ?? 'log'}</Badge> <span className="text-sm">{r.description}</span></Td>
                  <Td className="whitespace-nowrap text-sm">{r.subject}{r.subject_id ? ` #${r.subject_id}` : ''}</Td>
                  <Td className="text-xs">
                    {keys.length === 0 && !r.extra ? '—' : (
                      <>
                        <button className="text-brand hover:underline" onClick={() => setOpen(open === r.id ? null : r.id)}>{open === r.id ? 'Hide' : `${keys.length || 'Details'} ${keys.length ? 'field(s)' : ''}`}</button>
                        {open === r.id && (
                          <div className="mt-2 grid max-w-md gap-1 font-mono text-[0.6875rem]">
                            {keys.map((k) => (
                              <div key={k}><span className="text-muted-foreground">{k}:</span> {r.old && k in r.old && <><s className="text-danger/80">{show(r.old[k])}</s> → </>}<span>{show(r.changes?.[k])}</span></div>
                            ))}
                            {r.extra && <div className="text-muted-foreground">{show(r.extra)}</div>}
                          </div>
                        )}
                      </>
                    )}
                  </Td>
                </tr>
              )
            })}
          </Table>
        )}
        {q.data && q.data.last_page > 1 && (
          <div className="flex items-center justify-between border-t px-5 py-3 text-sm">
            <span className="text-muted-foreground">Page {q.data.current_page} of {q.data.last_page}</span>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
              <Button size="sm" variant="outline" disabled={page >= q.data.last_page} onClick={() => setPage(page + 1)}>Next</Button>
            </div>
          </div>
        )}
      </Card>
    </>
  )
}
