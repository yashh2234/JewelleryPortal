import { api, Badge, Button, Card, Select, type Paginated } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'
import type { BoardCard, WorkOrder } from './types'

const priorityTone = { urgent: 'danger', high: 'warning', normal: 'neutral', low: 'neutral' } as const

/** Kanban: one column per process; each work order sits at its current stage. */
export function BoardPage() {
  const q = useQuery({
    queryKey: ['work-orders', 'board'],
    queryFn: () => api.get<{ columns: { process: { id: number; name: string }; cards: BoardCard[] }[]; ready_to_finish: BoardCard[] }>('/api/v1/work-orders/board'),
    refetchInterval: 60_000,
  })

  const columns = [...(q.data?.columns ?? []), { process: { id: 0, name: 'Ready to finish' }, cards: q.data?.ready_to_finish ?? [] }]

  return (
    <>
      <PageTitle title="Production board">
        <Link to="/staff/work-orders"><Button variant="outline">List view</Button></Link>
      </PageTitle>
      <div className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6">
        {columns.map((col) => (
          <div key={col.process.id} className="w-64 shrink-0">
            <div className="mb-2 flex items-center justify-between px-1 text-sm font-semibold">
              {col.process.name}<span className="text-muted-foreground">{col.cards.length}</span>
            </div>
            <div className="grid gap-2">
              {col.cards.map((c) => (
                <Link key={c.id} to={`/staff/work-orders/${c.id}`}>
                  <Card className={`p-3 text-sm transition hover:shadow-md ${c.overdue ? 'border-danger/50' : ''} ${c.alert ? 'bg-warning/5' : ''}`}>
                    <div className="flex items-center justify-between">
                      <span className="font-mono text-xs">{c.wo_no}</span>
                      {c.priority !== 'normal' && <Badge tone={priorityTone[c.priority]}>{c.priority}</Badge>}
                    </div>
                    <p className="mt-1 font-medium">{c.qty} × {c.design.name}</p>
                    <p className="text-xs text-muted-foreground">{c.design.code} · {c.karat.label}{c.rework_of_id && ' · rework'}</p>
                    <div className="mt-2 flex items-center justify-between text-xs">
                      <span className={c.overdue ? 'font-semibold text-danger' : ''}>Due {fmtDate(c.due_date)}</span>
                      {c.alert && <AlertTriangle className="size-4 text-warning" />}
                    </div>
                    {c.kaarigars.length > 0 ? (
                      <p className="mt-1 text-xs"><b>{c.kaarigars.join(', ')}</b> holds <span className="tabular">{c.with_kaarigar_fine} g</span></p>
                    ) : Number(c.gold_in_wo) > 0 ? (
                      <p className="mt-1 text-xs text-brand">Waiting to issue · <span className="tabular">{c.gold_in_wo} g</span> in WO</p>
                    ) : c.step && c.step.cards > 0 && c.step.open_cards === 0 ? (
                      <p className="mt-1 text-xs text-success">Stage done, advance</p>
                    ) : <p className="mt-1 text-xs text-muted-foreground">Not started</p>}
                  </Card>
                </Link>
              ))}
            </div>
          </div>
        ))}
      </div>
    </>
  )
}

export function WorkOrdersPage() {
  const [status, setStatus] = useState('open,in_progress')
  const q = useQuery({ queryKey: ['work-orders', status], queryFn: () => api.get<Paginated<WorkOrder>>(`/api/v1/work-orders?status=${status}`) })

  return (
    <>
      <PageTitle title="Work orders">
        <Select className="w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="open,in_progress">Active</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
          <option value="">All</option>
        </Select>
        <Link to="/staff/production"><Button variant="outline">Board</Button></Link>
      </PageTitle>
      <div className="grid gap-2">
        {q.data?.data.map((w) => (
          <Link key={w.id} to={`/staff/work-orders/${w.id}`}>
            <Card className="flex flex-wrap items-center gap-4 p-3 text-sm hover:shadow-md">
              <div className="w-32"><span className="font-mono text-xs">{w.wo_no}</span><div className="text-xs text-muted-foreground">{w.remarks}</div></div>
              <div className="min-w-40 flex-1 font-medium">{w.qty} × {w.design.name} <span className="text-muted-foreground">{w.karat.label}</span></div>
              <div className="flex flex-wrap gap-1">
                {w.stages.map((s, i) => (
                  <span key={i} className={`rounded px-1.5 py-0.5 text-xs ${s.status === 'done' ? 'bg-success/15 text-success' : s.status === 'in_progress' ? 'bg-brand text-brand-foreground' : s.status === 'skipped' ? 'bg-muted line-through' : 'bg-muted text-muted-foreground'}`}>{s.name}</span>
                ))}
              </div>
              <div className={w.overdue ? 'font-semibold text-danger' : ''}>Due {fmtDate(w.due_date)}</div>
              <Badge tone={w.status === 'completed' ? 'success' : w.status === 'cancelled' ? 'neutral' : 'brand'}>{w.status.replace('_', ' ')}</Badge>
            </Card>
          </Link>
        ))}
      </div>
    </>
  )
}
