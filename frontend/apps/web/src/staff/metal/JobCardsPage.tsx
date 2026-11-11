import { Alert, api, ApiError, Badge, Button, Card, Checkbox, EmptyState, Field, Input, Select, Table, Td, Textarea, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import { fmtDate, useActiveKaarigars, useProcesses } from './shared'
import { LOSS_REASONS, type JobCard } from './types'

type Panel = { kind: 'allocate' | 'hand-back' | 'approve'; card: JobCard } | null
type Msg = { tone: 'success' | 'warning' | 'danger'; text: string } | null

const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Something went wrong')

/**
 * Job cards (blueprint §2–§3): one stage given to one kaarigar. Gold in = issued from
 * stock + handed in from another kaarigar; gold out = returned + scrap/dust + handed
 * on. Any difference on close waits here for an approval with a reason code and note.
 */
export default function JobCardsPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [params] = useSearchParams()
  const [filter, setFilter] = useState(params.get('f') ?? 'open')
  const [search, setSearch] = useState('')
  const [message, setMessage] = useState<Msg>(null)
  const [panel, setPanel] = useState<Panel>(null)

  const query = { open: 'status=open', overdue: 'overdue=1', alerts: 'alerts=1', closed: 'status=closed', all: '' }[filter]
  const cards = useQuery({
    queryKey: ['job-cards', filter, search],
    queryFn: () => api.get<Paginated<JobCard>>(`/api/v1/job-cards?${query}&search=${encodeURIComponent(search)}`),
  })

  const refresh = (text?: string) => {
    qc.invalidateQueries({ queryKey: ['job-cards'] })
    qc.invalidateQueries({ queryKey: ['metal'] })
    setPanel(null)
    if (text) setMessage({ tone: 'success', text })
  }

  const close = useMutation({
    mutationFn: (c: JobCard) => api.post<{ data: JobCard; alert: string | null }>(`/api/v1/job-cards/${c.id}/close`),
    onSuccess: (r) => {
      refresh()
      setMessage(r.alert ? { tone: 'warning', text: r.alert } : { tone: 'success', text: `${r.data.card_no} closed with no difference.` })
    },
    onError: (e) => setMessage({ tone: 'danger', text: errText(e) }),
  })

  return (
    <>
      <PageTitle title="Job cards & handoffs" description="Every gram on a stage: issued, handed between kaarigars, returned, and any difference approved with a reason.">
        <Input className="w-40" placeholder="Card no." value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select className="w-52" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="open">Open</option>
          <option value="overdue">Overdue</option>
          <option value="alerts">Differences to approve</option>
          <option value="closed">Closed</option>
          <option value="all">All</option>
        </Select>
        {can('metal.issue') && <Link to="/staff/metal/issue"><Button>Issue gold</Button></Link>}
      </PageTitle>

      {message && <div className="mb-4"><Alert tone={message.tone}>{message.text}</Alert></div>}

      {panel?.kind === 'allocate' && <AllocateForm card={panel.card} onDone={(no) => refresh(`Handed over on ${no}.`)} onCancel={() => setPanel(null)} />}
      {panel?.kind === 'hand-back' && <HandBackForm card={panel.card} onDone={(no) => refresh(`Handed back on ${no}.`)} onCancel={() => setPanel(null)} />}
      {panel?.kind === 'approve' && <ApproveForm card={panel.card} onDone={() => refresh(`Difference on ${panel.card.card_no} approved.`)} onCancel={() => setPanel(null)} />}

      <Card>
        {cards.data?.data.length === 0 ? (
          <EmptyState title="No job cards here" />
        ) : (
          <Table head={['Card', 'Kaarigar', 'Stage', 'Gold in', 'Gold out', 'Holding / difference', 'Due', '']}>
            {cards.data?.data.map((c) => {
              const open = c.status === 'open'
              const overdue = open && c.due_date && c.due_date < new Date().toISOString().slice(0, 10)
              const pending = c.loss_status === 'pending'
              const handedIn = open ? c.live?.handed_in : c.handed_in_fine
              const handedOut = open ? c.live?.handed_out : c.handed_out_fine
              return (
                <tr key={c.id} className={overdue || pending ? 'bg-warning/5' : undefined}>
                  <Td className="font-medium">
                    {c.card_no}
                    <div className="text-xs text-muted-foreground">{c.description ?? c.karat.label}</div>
                    {c.parent && <div className="text-xs text-muted-foreground">from {c.parent.card_no} · {c.parent.kaarigar?.name}</div>}
                  </Td>
                  <Td><Link className="hover:underline" to={`/staff/kaarigars/${c.kaarigar.id}`}>{c.kaarigar.name}</Link></Td>
                  <Td>
                    {c.process.name}
                    {open && c.kaarigar_status && <Badge tone={c.kaarigar_status === 'ready' ? 'success' : 'brand'} className="ml-1">{c.kaarigar_status}</Badge>}
                  </Td>
                  <Td className="tabular">
                    {open ? c.live?.issued : c.issued_fine}
                    {Number(handedIn) > 0 && <div className="text-xs text-muted-foreground">+{handedIn} handed in</div>}
                  </Td>
                  <Td className="tabular">
                    {open ? `${c.live?.returned} + ${c.live?.scrap}` : `${c.returned_fine} + ${c.scrap_fine}`}
                    {Number(handedOut) > 0 && <div className="text-xs text-muted-foreground">+{handedOut} handed on</div>}
                  </Td>
                  <Td className="tabular">
                    {open ? <>{c.live?.outstanding} <span className="text-xs text-muted-foreground">held</span></> : (
                      <>
                        {c.loss_fine} <span className="text-xs text-muted-foreground">difference</span>
                        {pending && <Badge tone="danger" className="ml-1">to approve</Badge>}
                        {Number(c.excess_loss_fine) > 0 && <Badge tone={pending ? 'warning' : 'neutral'} className="ml-1">+{c.excess_loss_fine} over {c.allowed_wastage_pct}%</Badge>}
                        {c.loss_status === 'approved' && c.loss_reason_code && <div className="text-xs text-muted-foreground">{LOSS_REASONS[c.loss_reason_code] ?? c.loss_reason_code}{c.excess_resolution === 'recovered' && ' · excess recovered'}</div>}
                      </>
                    )}
                  </Td>
                  <Td>{open ? <span className={overdue ? 'font-medium text-danger' : ''}>{fmtDate(c.due_date)}</span> : <Badge>closed</Badge>}</Td>
                  <Td className="whitespace-nowrap text-right">
                    {open && can('metal.issue') && Number(c.live?.outstanding) > 0 && (
                      <Button size="sm" variant="ghost" onClick={() => setPanel({ kind: 'allocate', card: c })}>Hand to kaarigar</Button>
                    )}
                    {open && can('metal.issue') && c.parent && Number(c.live?.outstanding) > 0 && (
                      <Button size="sm" variant="ghost" onClick={() => setPanel({ kind: 'hand-back', card: c })}>Hand back</Button>
                    )}
                    {open && can('metal.receive') && (
                      <>
                        <Link to={`/staff/metal/receive?kaarigar=${c.kaarigar.id}&card=${c.id}`}><Button size="sm" variant="ghost">Receive</Button></Link>
                        <Button size="sm" variant="ghost" onClick={() => confirm(`Close ${c.card_no}? ${c.live?.outstanding} g still with the kaarigar becomes a difference to approve.`) && close.mutate(c)}>Close</Button>
                      </>
                    )}
                    {pending && can('metal.approve_loss') && (
                      <Button size="sm" variant="outline" onClick={() => setPanel({ kind: 'approve', card: c })}>Approve difference</Button>
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

/** Pass work and gold from this card to another kaarigar; a child card is opened for him. */
function AllocateForm({ card, onDone, onCancel }: { card: JobCard; onDone: (voucher: string) => void; onCancel: () => void }) {
  const kaarigars = useActiveKaarigars()
  const processes = useProcesses()
  const [f, setF] = useState({ kaarigar_id: '', process_id: '', gross: '', pieces: '', due_date: '', remarks: '' })
  const m = useMutation({
    mutationFn: () => api.post<{ data: { voucher_no: string } }>(`/api/v1/job-cards/${card.id}/allocate`, {
      kaarigar_id: Number(f.kaarigar_id), process_id: f.process_id ? Number(f.process_id) : null, gross: f.gross,
      pieces: f.pieces ? Number(f.pieces) : null, due_date: f.due_date || null, remarks: f.remarks || null,
    }),
    onSuccess: (r) => onDone(r.data.voucher_no),
  })
  const subs = kaarigars.data?.filter((k) => k.id !== card.kaarigar.id) ?? []

  return (
    <Card className="mb-4 grid gap-3 p-4">
      <p className="font-medium">Hand part of {card.card_no} ({card.kaarigar.name}, holding {card.live?.outstanding} g fine) to another kaarigar</p>
      <p className="text-sm text-muted-foreground">Custody moves from {card.kaarigar.name} to the receiver; nothing new leaves the vault. The receiver accepts it like an issue.</p>
      {m.error && <Alert>{errText(m.error)}</Alert>}
      <div className="flex flex-wrap items-end gap-2">
        <Field label="To kaarigar" className="min-w-52">
          <Select value={f.kaarigar_id} onChange={(e) => setF({ ...f, kaarigar_id: e.target.value })}>
            <option value="">Choose…</option>
            {subs.map((k) => <option key={k.id} value={k.id}>{k.name}{k.head_kaarigar_id === card.kaarigar.id ? ' (sub-kaarigar)' : ''}</option>)}
          </Select>
        </Field>
        <Field label="Stage" className="min-w-44">
          <Select value={f.process_id} onChange={(e) => setF({ ...f, process_id: e.target.value })}>
            <option value="">Same as {card.process.name}</option>
            {processes.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        </Field>
        <Field label={`Gross g (${card.karat.label})`} className="w-32"><WeightInput value={f.gross} onValueChange={(v) => setF({ ...f, gross: v })} /></Field>
        <Field label="Pieces" className="w-24"><Input inputMode="numeric" value={f.pieces} onChange={(e) => setF({ ...f, pieces: e.target.value.replace(/\D/g, '') })} /></Field>
        <Field label="Due" className="w-40"><Input type="date" value={f.due_date} onChange={(e) => setF({ ...f, due_date: e.target.value })} /></Field>
        <Field label="Remarks" className="min-w-52 flex-1"><Input value={f.remarks} onChange={(e) => setF({ ...f, remarks: e.target.value })} /></Field>
      </div>
      <div className="flex gap-2">
        <Button disabled={!f.kaarigar_id || !f.gross} loading={m.isPending} onClick={() => m.mutate()}>Hand over</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </Card>
  )
}

function HandBackForm({ card, onDone, onCancel }: { card: JobCard; onDone: (voucher: string) => void; onCancel: () => void }) {
  const [f, setF] = useState({ gross: '', pieces: '', remarks: '' })
  const m = useMutation({
    mutationFn: () => api.post<{ data: { voucher_no: string } }>(`/api/v1/job-cards/${card.id}/hand-back`, { gross: f.gross, pieces: f.pieces ? Number(f.pieces) : null, remarks: f.remarks || null }),
    onSuccess: (r) => onDone(r.data.voucher_no),
  })
  return (
    <Card className="mb-4 grid gap-3 p-4">
      <p className="font-medium">Hand {card.card_no} back from {card.kaarigar.name} to {card.parent?.kaarigar?.name} ({card.parent?.card_no})</p>
      {m.error && <Alert>{errText(m.error)}</Alert>}
      <div className="flex flex-wrap items-end gap-2">
        <Field label="Gross g" className="w-32"><WeightInput value={f.gross} onValueChange={(v) => setF({ ...f, gross: v })} /></Field>
        <Field label="Pieces" className="w-24"><Input inputMode="numeric" value={f.pieces} onChange={(e) => setF({ ...f, pieces: e.target.value.replace(/\D/g, '') })} /></Field>
        <Field label="Remarks" className="min-w-52 flex-1"><Input value={f.remarks} onChange={(e) => setF({ ...f, remarks: e.target.value })} /></Field>
        <Button disabled={!f.gross} loading={m.isPending} onClick={() => m.mutate()}>Hand back</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </Card>
  )
}

/** Blueprint §3 control rule: no difference is written off without a reason code, a note and an authorised approver. */
function ApproveForm({ card, onDone, onCancel }: { card: JobCard; onDone: () => void; onCancel: () => void }) {
  const excess = Number(card.excess_loss_fine) > 0
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [recover, setRecover] = useState(false)
  const m = useMutation({
    mutationFn: () => api.post(`/api/v1/job-cards/${card.id}/approve-loss`, { reason_code: reason, note, recover_excess: excess && recover }),
    onSuccess: onDone,
  })
  return (
    <Card className="mb-4 grid gap-3 p-4">
      <p className="font-medium">
        Approve the {card.loss_fine} g fine difference on {card.card_no} ({card.kaarigar.name}, {card.process.name})
      </p>
      <p className="text-sm text-muted-foreground">
        Allowed for this stage: {card.allowed_loss_fine} g ({card.allowed_wastage_pct}%).
        {excess ? ` ${card.excess_loss_fine} g is above the limit.` : ' The difference is within the limit.'}
        {' '}Until approved it stays on the kaarigar's balance and blocks the work order from closing.
      </p>
      {m.error && <Alert>{errText(m.error)}</Alert>}
      <div className="flex flex-wrap items-end gap-2">
        <Field label="Reason" className="min-w-64">
          <Select value={reason} onChange={(e) => setReason(e.target.value)}>
            <option value="">Choose…</option>
            {Object.entries(LOSS_REASONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </Select>
        </Field>
        <Field label="Note (required)" className="min-w-72 flex-1"><Textarea rows={1} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
      </div>
      {excess && <Checkbox label={`Recover the ${card.excess_loss_fine} g above the limit from ${card.kaarigar.name}'s labour (at today's fine rate)`} checked={recover} onChange={(e) => setRecover(e.target.checked)} />}
      <div className="flex gap-2">
        <Button disabled={!reason || note.trim().length < 3} loading={m.isPending} onClick={() => m.mutate()}>Approve</Button>
        <Button variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </Card>
  )
}
