import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Select, Table, Td, WeightInput } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate, SlipLink, useActiveKaarigars } from '../metal/shared'
import type { Tx } from '../metal/types'
import type { WorkOrderDetail, WoStep } from './types'

/**
 * One work order: its route, the job cards at each stage, gold in hand, and the
 * actions — issue for the current stage (pre-filled from what came back), advance,
 * record finished pieces, complete.
 */
export default function WorkOrderPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const can = useCan()
  const q = useQuery({ queryKey: ['work-order', id], queryFn: () => api.get<{ data: WorkOrderDetail }>(`/api/v1/work-orders/${id}`) })
  const wo = q.data?.data
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['work-order', id] })
    qc.invalidateQueries({ queryKey: ['work-orders'] })
    qc.invalidateQueries({ queryKey: ['metal'] })
  }

  const act = useMutation({ mutationFn: (path: string) => api.post(`/api/v1/work-orders/${id}/${path}`), onSuccess: refresh })
  const error = act.error instanceof ApiError ? act.error : null

  if (!wo) return <p className="text-muted-foreground">Loading…</p>
  const current = wo.steps.find((s) => s.status === 'pending' || s.status === 'in_progress')
  const active = wo.status === 'open' || wo.status === 'in_progress'
  const openCards = wo.steps.flatMap((s) => s.job_cards).filter((c) => c.status === 'open').length

  return (
    <>
      <PageTitle title={`${wo.wo_no} · ${wo.qty} × ${wo.design.name}`}>
        <Badge tone={wo.status === 'completed' ? 'success' : 'brand'}>{wo.status.replace('_', ' ')}</Badge>
        {can('production.manage') && wo.status === 'open' && <Button variant="ghost" onClick={() => confirm('Cancel this work order?') && act.mutate('cancel')}>Cancel</Button>}
        {can('production.manage') && active && wo.finished_items.length > 0 && openCards === 0 && (
          <Button onClick={() => confirm('Complete the work order? Any gold left in it goes to the scrap pool.') && act.mutate('complete')}>Complete</Button>
        )}
      </PageTitle>
      {error && <div className="mb-3"><Alert>{error.message}</Alert></div>}

      <div className="mb-4 grid gap-3 sm:grid-cols-4">
        <Card className="p-3 text-sm"><p className="text-muted-foreground">Karat</p><p className="font-semibold">{wo.karat.label}</p></Card>
        <Card className="p-3 text-sm"><p className="text-muted-foreground">Sizes</p><p className="font-semibold">{wo.sizes ? Object.entries(wo.sizes).map(([s, n]) => `${s}: ${n}`).join(' · ') : '—'}</p></Card>
        <Card className="p-3 text-sm"><p className="text-muted-foreground">Target / pc</p><p className="font-semibold">{wo.target_wt_min ? `${wo.target_wt_min}–${wo.target_wt_max} g` : '—'}</p></Card>
        <Card className="p-3 text-sm"><p className="text-muted-foreground">Gold in WO (between stages)</p><p className="tabular font-semibold">{wo.gold_in_wo} g</p></Card>
      </div>
      <p className="mb-4 text-sm text-muted-foreground">
        {wo.order_lines.map((l) => `${l.order.order_no} (${l.order.retailer.shop_name})`).join(', ') || 'Stock making'} · due <span className={wo.overdue ? 'font-semibold text-danger' : ''}>{fmtDate(wo.due_date)}</span>
      </p>

      <div className="grid gap-3">
        {wo.steps.map((s) => (
          <StepCard key={s.id} step={s} woId={wo.id} current={current?.id === s.id && active} goldInWo={wo.gold_in_wo} karatId={wo.karat.id} onChange={refresh} />
        ))}
      </div>

      {active && !current && can('production.manage') && <FinishForm wo={wo} onDone={refresh} />}

      {wo.finished_items.length > 0 && (
        <Card className="mt-4">
          <CardHeader title={`Finished pieces (${wo.finished_items.length})`} actions={<Link to="/staff/finished"><Button size="sm" variant="ghost">Finished goods →</Button></Link>} />
          <Table head={['Tag', 'Size', 'Gross', 'Stone', 'Net', 'Fine', 'QC', 'Status']}>
            {wo.finished_items.map((i) => (
              <tr key={i.id}>
                <Td className="font-mono text-xs">{i.tag_no}</Td><Td>{i.size}</Td>
                <Td className="tabular">{i.gross_wt}</Td><Td className="tabular">{i.stone_wt}</Td><Td className="tabular">{i.net_wt}</Td><Td className="tabular">{i.fine_wt}</Td>
                <Td><Badge tone={i.qc_status === 'passed' ? 'success' : i.qc_status === 'failed' ? 'danger' : 'neutral'}>{i.qc_status}</Badge></Td>
                <Td>{i.status.replace('_', ' ')}</Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
    </>
  )
}

function StepCard({ step, woId, current, goldInWo, karatId, onChange }: { step: WoStep; woId: number; current: boolean; goldInWo: string; karatId: number; onChange: () => void }) {
  const can = useCan()
  const kaarigars = useActiveKaarigars()
  const [kaarigarId, setKaarigarId] = useState('')
  // "One-click issue for next stage": pre-fill with what is sitting in the WO.
  const [gross, setGross] = useState('')
  const [override, setOverride] = useState('')
  const [done, setDone] = useState<Tx | null>(null)
  const [editing, setEditing] = useState(false)

  const issue = useMutation({
    mutationFn: () => api.post<{ data: Tx }>(`/api/v1/work-orders/${woId}/issue`, {
      step_id: step.id, kaarigar_id: Number(kaarigarId), items: [{ karat_id: karatId, gross }],
      ...(override ? { override_reason: override } : {}),
    }),
    onSuccess: (r) => { setDone(r.data); setGross(''); setOverride(''); onChange() },
  })
  const advance = useMutation({ mutationFn: () => api.post(`/api/v1/work-orders/${woId}/steps/${step.id}/advance`), onSuccess: onChange })
  const err = [issue.error, advance.error].find((e) => e instanceof ApiError) as ApiError | undefined
  const open = step.job_cards.filter((c) => c.status === 'open')
  const tone = { done: 'success', in_progress: 'brand', pending: 'neutral', skipped: 'neutral' } as const
  const fromWo = Number(goldInWo) > 0
  const canOverride = !!err?.message.includes('override with a reason')
  const when = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : null)

  return (
    <Card className={current ? 'border-brand/50' : step.status === 'pending' ? 'opacity-70' : ''}>
      <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2.5">
        <div>
          <p className="font-medium">{step.sequence}. {step.process.name}</p>
          <p className="text-xs text-muted-foreground">
            {step.assignee ? <>Assigned to <b className="font-medium text-foreground">{step.assignee.name}</b></> : 'Not assigned'}
            {when(step.started_at) && ` · started ${when(step.started_at)}`}{when(step.done_at) && ` · done ${when(step.done_at)}`}
          </p>
          {step.remarks && <p className="mt-0.5 text-xs italic text-muted-foreground">{step.remarks}</p>}
        </div>
        <div className="flex items-center gap-2">
          {can('production.manage') && <Button size="sm" variant="ghost" onClick={() => setEditing(!editing)}>{editing ? 'Close' : 'Assign / note'}</Button>}
          <Badge tone={tone[step.status]}>{step.status.replace('_', ' ')}</Badge>
        </div>
      </div>
      {editing && <StepEditor step={step} woId={woId} onDone={() => { setEditing(false); onChange() }} />}
      {step.job_cards.length > 0 && (
        <Table head={['Card', 'Kaarigar', 'Issued', 'Back', 'Held / loss', '']}>
          {step.job_cards.map((c) => (
            <tr key={c.id}>
              <Td className="font-mono text-xs">{c.card_no}</Td>
              <Td><Link className="hover:underline" to={`/staff/kaarigars/${c.kaarigar.id}/ledger`}>{c.kaarigar.name}</Link></Td>
              <Td className="tabular">{c.live?.issued ?? c.issued_fine}</Td>
              <Td className="tabular">{c.live ? `${c.live.returned} + ${c.live.scrap}` : `${c.returned_fine} + ${c.scrap_fine}`}</Td>
              <Td className="tabular">{c.status === 'open' ? `${c.live?.outstanding} held` : <>{c.loss_fine} loss{Number(c.excess_loss_fine) > 0 && <Badge tone="danger" className="ml-1">over limit</Badge>}</>}</Td>
              <Td className="text-right">{c.status === 'open' && can('metal.receive') && <Link to={`/staff/metal/receive?kaarigar=${c.kaarigar.id}&card=${c.id}`}><Button size="sm" variant="ghost">Receive</Button></Link>}</Td>
            </tr>
          ))}
        </Table>
      )}
      {current && (
        <div className="grid gap-3 p-4">
          {err && <Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert>}
          {done && <Alert tone="success">Issued {done.fine_total} g fine to {done.kaarigar?.name}. <SlipLink tx={done} /></Alert>}
          {can('metal.issue') && (
            <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); issue.mutate() }}>
              <Field label="Kaarigar" className="min-w-48">
                <Select required value={kaarigarId} onChange={(e) => setKaarigarId(e.target.value)}>
                  <option value="">Choose…</option>
                  {kaarigars.data?.map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}
                </Select>
              </Field>
              <Field label={`Gross g ${fromWo ? '(from WO)' : '(from vault)'}`} className="w-40">
                <WeightInput required value={gross} onValueChange={setGross} />
              </Field>
              {canOverride && (
                <Field label="Override reason" className="min-w-64 flex-1">
                  <Input required value={override} onChange={(e) => setOverride(e.target.value)} placeholder="Why issue anyway?" />
                </Field>
              )}
              <Button type="submit" loading={issue.isPending}>{canOverride ? 'Override & issue' : `Issue for ${step.process.name}`}</Button>
              {fromWo && <span className="pb-2 text-xs text-muted-foreground">{goldInWo} g fine waiting in the WO</span>}
            </form>
          )}
          {can('production.manage') && open.length === 0 && (
            <div><Button variant="outline" loading={advance.isPending} onClick={() => advance.mutate()}>
              {step.job_cards.length ? `Stage done → next` : `Skip ${step.process.name}`}
            </Button></div>
          )}
        </div>
      )}
    </Card>
  )
}

function StepEditor({ step, woId, onDone }: { step: WoStep; woId: number; onDone: () => void }) {
  const staff = useQuery({ queryKey: ['staff-directory'], queryFn: () => api.get<{ data: { id: number; name: string }[] }>('/api/v1/staff-directory') })
  const [userId, setUserId] = useState(step.assignee ? String(step.assignee.id) : '')
  const [remarks, setRemarks] = useState(step.remarks ?? '')
  const save = useMutation({
    mutationFn: () => api.patch(`/api/v1/work-orders/${woId}/steps/${step.id}`, { assigned_user_id: userId ? Number(userId) : null, remarks: remarks || null }),
    onSuccess: onDone,
  })
  return (
    <form className="flex flex-wrap items-end gap-2 border-b bg-muted/40 px-4 py-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
      <Field label="Responsible" className="min-w-48">
        <Select value={userId} onChange={(e) => setUserId(e.target.value)}>
          <option value="">—</option>
          {staff.data?.data.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
        </Select>
      </Field>
      <Field label="Remarks" className="min-w-64 flex-1"><Input value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
      <Button type="submit" loading={save.isPending}>Save</Button>
    </form>
  )
}

interface Piece { size: string; gross: string; stone: string; order_line_id: string }

function FinishForm({ wo, onDone }: { wo: WorkOrderDetail; onDone: () => void }) {
  const sizes = wo.sizes ? Object.keys(wo.sizes) : ['']
  const [pieces, setPieces] = useState<Piece[]>(() => {
    const rows: Piece[] = []
    if (wo.sizes) Object.entries(wo.sizes).forEach(([size, n]) => { for (let i = 0; i < Math.min(n, 50); i++) rows.push({ size: size === '-' ? '' : size, gross: '', stone: '', order_line_id: String(wo.order_lines.find((l) => (l.size ?? '-') === size)?.id ?? '') }) })
    return rows.length ? rows : [{ size: '', gross: '', stone: '', order_line_id: '' }]
  })
  const finish = useMutation({
    mutationFn: () => api.post(`/api/v1/work-orders/${wo.id}/finish`, {
      pieces: pieces.filter((p) => p.gross).map((p) => ({ gross: p.gross, stone: p.stone || '0', size: p.size || null, order_line_id: p.order_line_id ? Number(p.order_line_id) : null })),
    }),
    onSuccess: onDone,
  })
  const err = finish.error instanceof ApiError ? finish.error : null
  const set = (i: number, patch: Partial<Piece>) => setPieces(pieces.map((p, j) => (j === i ? { ...p, ...patch } : p)))
  const total = pieces.reduce((s, p) => s + Math.round(Number(p.gross || 0) * 1000), 0) / 1000

  return (
    <Card className="mt-4">
      <CardHeader title="Record finished pieces" description={`Weigh each piece. ${wo.gold_in_wo} g fine is in the WO; what is left after this goes to scrap when you complete.`} />
      <form className="grid gap-2 p-4" onSubmit={(e) => { e.preventDefault(); finish.mutate() }}>
        {err && <Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert>}
        {pieces.map((p, i) => (
          <div key={i} className="flex flex-wrap items-end gap-2">
            <span className="w-8 pb-2 text-sm text-muted-foreground">{i + 1}.</span>
            <Field label={i === 0 ? 'Size' : ''} className="w-24">
              {sizes.length > 1 ? <Select value={p.size} onChange={(e) => set(i, { size: e.target.value })}>{sizes.map((s) => <option key={s} value={s === '-' ? '' : s}>{s}</option>)}</Select>
                : <Input value={p.size} onChange={(e) => set(i, { size: e.target.value })} />}
            </Field>
            <Field label={i === 0 ? 'Gross g' : ''} className="w-36"><WeightInput value={p.gross} onValueChange={(v) => set(i, { gross: v })} /></Field>
            <Field label={i === 0 ? 'Stone g' : ''} className="w-32"><WeightInput value={p.stone} onValueChange={(v) => set(i, { stone: v })} /></Field>
            <Button type="button" size="icon" variant="ghost" onClick={() => setPieces(pieces.filter((_, j) => j !== i))} aria-label="Remove"><Trash2 /></Button>
          </div>
        ))}
        <div className="flex items-center gap-3">
          <Button type="button" size="sm" variant="ghost" onClick={() => setPieces([...pieces, { ...pieces.at(-1)!, gross: '', stone: '' }])}><Plus /> Piece</Button>
          <span className="tabular text-sm">{pieces.filter((p) => p.gross).length} pieces · {total.toFixed(3)} g gross</span>
        </div>
        <div><Button type="submit" loading={finish.isPending}>Save pieces to finished goods</Button></div>
      </form>
    </Card>
  )
}
