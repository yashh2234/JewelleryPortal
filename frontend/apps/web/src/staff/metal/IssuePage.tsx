import { Alert, api, ApiError, Button, Card, CardHeader, Field, Input, Select, today, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PageTitle } from '../layout'
import { emptyItem, ItemsEditor, SlipLink, toItems, useActiveKaarigars, useKarats, useProcesses, type Item } from './shared'
import type { JobCard, Tx } from './types'

/**
 * Issue gold to a kaarigar (spec §6B): for a work order (its current stage), on an
 * open job card, or, if the workspace allows it, on a loose job card. Before saving
 * we show anything that blocks the issue (spec §6D) and let owners override.
 */
export default function IssuePage() {
  const qc = useQueryClient()
  const kaarigars = useActiveKaarigars()
  const processes = useProcesses()
  const karats = useKarats()

  const [kaarigarId, setKaarigarId] = useState('')
  const [cardId, setCardId] = useState('wo')
  const [woId, setWoId] = useState('')
  const [override, setOverride] = useState('')
  const [card, setCard] = useState({ process_id: '', karat_id: '', description: '', pieces: '', due_date: '' })
  const [source, setSource] = useState('vault')
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const [items, setItems] = useState<Item[]>([emptyItem()])
  const [done, setDone] = useState<Tx | null>(null)

  const openCards = useQuery({
    queryKey: ['job-cards', 'open', kaarigarId],
    queryFn: () => api.get<Paginated<JobCard>>(`/api/v1/job-cards?status=open&kaarigar_id=${kaarigarId}&per_page=100`),
    enabled: !!kaarigarId,
  })

  const wos = useQuery({
    queryKey: ['work-orders', 'issuable'],
    queryFn: () => api.get<Paginated<{ id: number; wo_no: string; qty: number; design: { code: string; name: string }; karat: { id: number; label: string }; current_process: string | null }>>('/api/v1/work-orders?status=open,in_progress&per_page=200'),
  })
  const check = useQuery({
    queryKey: ['issue-check', kaarigarId],
    queryFn: () => api.get<{ blockers: string[]; can_override: boolean; held: string; limit: string | null }>(`/api/v1/metal/issue-check?kaarigar_id=${kaarigarId}`),
    enabled: !!kaarigarId,
  })
  const blocked = (check.data?.blockers.length ?? 0) > 0

  const issue = useMutation({
    mutationFn: () =>
      api.post<{ data: Tx }>('/api/v1/metal/issue', {
        source,
        date,
        remarks: remarks || null,
        items: toItems(items),
        ...(override ? { override_reason: override } : {}),
        ...(cardId === 'wo'
          ? { kaarigar_id: Number(kaarigarId), work_order_id: Number(woId) }
          : cardId !== 'new'
          ? { job_card_id: Number(cardId) }
          : {
              kaarigar_id: Number(kaarigarId),
              process_id: Number(card.process_id),
              karat_id: Number(card.karat_id),
              description: card.description || null,
              pieces: card.pieces ? Number(card.pieces) : null,
              due_date: card.due_date || null,
            }),
      }),
    onSuccess: (r) => {
      setDone(r.data)
      setItems([emptyItem(items[0]?.karat_id)])
      setRemarks('')
      setOverride('')
      setCardId('wo')
      qc.invalidateQueries({ queryKey: ['job-cards'] })
      qc.invalidateQueries({ queryKey: ['metal'] })
    },
  })

  const error = issue.error instanceof ApiError ? issue.error : null
  const firstError = error ? Object.values(error.errors)[0]?.[0] ?? error.message : null

  return (
    <>
      <PageTitle title="Issue gold" description="Every issue is tied to a kaarigar and a work order, and the kaarigar acknowledges it." />
      {done && (
        <div className="mb-4">
          <Alert tone="success">
            Issued {done.fine_total} g fine to {done.kaarigar?.name} on {done.job_card?.card_no}. <SlipLink tx={done} />
          </Alert>
        </div>
      )}
      <Card className="max-w-4xl">
        <CardHeader title="Kaarigar and job" description="The kaarigar sees this issue in the app and accepts or disputes it." />
        <form className="grid gap-4 p-4" onSubmit={(e) => { e.preventDefault(); setDone(null); issue.mutate() }}>
          {firstError && <Alert>{firstError}</Alert>}
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Kaarigar">
              <Select required value={kaarigarId} onChange={(e) => { setKaarigarId(e.target.value); setCardId('new') }}>
                <option value="">Choose…</option>
                {kaarigars.data?.map((k) => <option key={k.id} value={k.id}>{k.name}{k.type === 'outside' ? ' (karkhana)' : ''}</option>)}
              </Select>
            </Field>
            <Field label="For">
              <Select value={cardId} onChange={(e) => setCardId(e.target.value)} disabled={!kaarigarId}>
                <option value="wo">A work order (next stage)</option>
                {!!openCards.data?.data.length && (
                  <optgroup label="Top up an open job card">
                    {openCards.data?.data.map((c) => (
                      <option key={c.id} value={c.id}>{c.card_no} · {c.process.name} · {c.karat.label} · holds {c.live?.outstanding} g</option>
                    ))}
                  </optgroup>
                )}
                <option value="new">Loose job card (no work order)</option>
              </Select>
            </Field>
            <Field label="Issue from" className={cardId === 'wo' ? 'opacity-50' : ''}>
              <Select value={source} onChange={(e) => setSource(e.target.value)}>
                <option value="vault">Vault</option>
                <option value="finished">Finished goods (next stage)</option>
              </Select>
            </Field>
          </div>

          {kaarigarId && check.data && (
            blocked ? (
              <Alert tone="warning">
                <b>This kaarigar can't take more gold yet.</b>
                <ul className="mt-1 list-disc pl-4">{check.data.blockers.map((b) => <li key={b}>{b}</li>)}</ul>
                {check.data.can_override
                  ? <p className="mt-2">You can override with a reason; it is kept in the audit log.</p>
                  : <p className="mt-2">Ask the owner to resolve these, or to override.</p>}
              </Alert>
            ) : (
              <p className="-mt-1 text-sm text-muted-foreground">
                Holds <b className="tabular text-foreground">{check.data.held} g</b> fine now{check.data.limit && <> · limit {check.data.limit} g</>}. No pending issues.
              </p>
            )
          )}
          {blocked && check.data?.can_override && (
            <Field label="Override reason (required to issue)">
              <Input required value={override} onChange={(e) => setOverride(e.target.value)} placeholder="e.g. Slip signed on paper; will record tomorrow" />
            </Field>
          )}

          {cardId === 'wo' && (
            <div className="rounded-lg border border-dashed p-3">
              <Field label="Work order">
                <Select required value={woId} onChange={(e) => {
                  setWoId(e.target.value)
                  const k = wos.data?.data.find((w) => String(w.id) === e.target.value)?.karat.id
                  if (k) setItems(items.map((i) => (i.karat_id ? i : { ...i, karat_id: String(k) })))
                }}>
                  <option value="">Choose…</option>
                  {wos.data?.data.map((w) => <option key={w.id} value={w.id}>{w.wo_no} · {w.qty} × {w.design.name} · {w.karat.label}{w.current_process ? ` · next: ${w.current_process}` : ''}</option>)}
                </Select>
              </Field>
              <p className="mt-2 text-xs text-muted-foreground">Gold goes to the work order's current stage. The first stage issues from the vault; later stages use the work order's own gold.</p>
            </div>
          )}

          {cardId === 'new' && (
            <div className="grid gap-4 rounded-md border border-dashed p-3 sm:grid-cols-5">
              <Field label="Process">
                <Select required value={card.process_id} onChange={(e) => setCard({ ...card, process_id: e.target.value })}>
                  <option value="">—</option>
                  {processes.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </Select>
              </Field>
              <Field label="Karat of work">
                <Select required value={card.karat_id} onChange={(e) => { setCard({ ...card, karat_id: e.target.value }); setItems(items.map((i) => (i.karat_id ? i : { ...i, karat_id: e.target.value }))) }}>
                  <option value="">—</option>
                  {karats.data?.map((k) => <option key={k.id} value={k.id}>{k.label}</option>)}
                </Select>
              </Field>
              <Field label="What to make" className="sm:col-span-2">
                <Input value={card.description} placeholder="e.g. 50 Classic kade" onChange={(e) => setCard({ ...card, description: e.target.value })} />
              </Field>
              <Field label="Due date">
                <Input type="date" value={card.due_date} onChange={(e) => setCard({ ...card, due_date: e.target.value })} />
              </Field>
            </div>
          )}

          <ItemsEditor items={items} onChange={setItems} pieces />

          <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
            <Field label="Date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <Field label="Remarks"><Input value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
          </div>
          <div><Button type="submit" size="lg" loading={issue.isPending} disabled={blocked && !(check.data?.can_override && override)}>Issue gold</Button></div>
        </form>
      </Card>
    </>
  )
}
