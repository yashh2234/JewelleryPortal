import { Alert, api, ApiError, Badge, Button, Card, Checkbox, EmptyState, Field, Input, Select, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PageTitle } from '../layout'
import { fmtDate } from './shared'

interface KRequest {
  id: number
  type: 'return' | 'dispute'
  status: string
  data: { work_gross?: string; stone?: string; scrap_gross?: string; raw_gross?: string; dust_gross?: string; rejected_gross?: string; rejected_pieces?: number; pieces?: number; note?: string; reason?: string; weight_received?: string }
  created_at: string
  kaarigar: { id: number; name: string }
  job_card: { id: number; card_no: string; wo_step_id?: number | null; karat: { label: string; purity_pct: string }; process: { name: string } } | null
  transaction: { id: number; voucher_no: string } | null
  attachments: { id: number; kind: string; url: string; thumb_url: string | null }[]
  review_note: string | null
}

/** Store inbox for kaarigar app requests. A return posts the real receive only when approved here. */
export default function RequestsPage() {
  const [status, setStatus] = useState('pending')
  const list = useQuery({
    queryKey: ['kaarigar-requests', status],
    queryFn: () => api.get<Paginated<KRequest>>(`/api/v1/kaarigar-requests?status=${status}`),
  })

  return (
    <>
      <PageTitle title="Kaarigar requests">
        <Select className="w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </Select>
      </PageTitle>
      {list.data?.data.length === 0 && <Card><EmptyState title="Nothing waiting">Returns and disputes from the kaarigar app appear here.</EmptyState></Card>}
      <div className="grid gap-3">
        {list.data?.data.map((r) => <RequestCard key={r.id} r={r} />)}
      </div>
    </>
  )
}

function RequestCard({ r }: { r: KRequest }) {
  const qc = useQueryClient()
  const [w, setW] = useState({
    work_gross: r.data.work_gross ?? '', stone: r.data.stone ?? '', scrap_gross: r.data.scrap_gross ?? '',
    raw_gross: r.data.raw_gross ?? '', dust_gross: r.data.dust_gross ?? '', rejected_gross: r.data.rejected_gross ?? '', purity: '',
  })
  const [destination, setDestination] = useState('')
  const [close, setClose] = useState(true)
  const [note, setNote] = useState('')

  const done = () => {
    qc.invalidateQueries({ queryKey: ['kaarigar-requests'] })
    qc.invalidateQueries({ queryKey: ['job-cards'] })
    qc.invalidateQueries({ queryKey: ['metal'] })
  }
  const approve = useMutation({
    mutationFn: () => api.post(`/api/v1/kaarigar-requests/${r.id}/approve`, r.type === 'return'
      ? { ...w, rejected_pieces: r.data.rejected_pieces ?? null, purity: w.purity || null, destination: destination || null, close, note: note || null }
      : { note: note || null }),
    onSuccess: done,
  })
  const reject = useMutation({ mutationFn: () => api.post(`/api/v1/kaarigar-requests/${r.id}/reject`, { note }), onSuccess: done })
  const error = [approve.error, reject.error].find((e) => e instanceof ApiError) as ApiError | undefined
  const pending = r.status === 'pending'

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="font-medium">
            {r.kaarigar.name} · <span className="capitalize">{r.type}</span>
            {r.job_card && <span className="text-muted-foreground"> · {r.job_card.card_no} ({r.job_card.process.name}, {r.job_card.karat.label})</span>}
          </p>
          <p className="text-xs text-muted-foreground">{fmtDate(r.created_at)}{r.transaction && ` · issue ${r.transaction.voucher_no}`}</p>
        </div>
        <Badge tone={pending ? 'warning' : r.status === 'approved' ? 'success' : 'neutral'}>{r.status}</Badge>
      </div>

      <div className="mt-2 text-sm">
        {r.type === 'return' && (
          <>
            Kaarigar says: work <b>{r.data.work_gross ?? '—'} g</b>{r.data.stone && <>, stone {r.data.stone} g</>}{r.data.pieces != null && <>, {r.data.pieces} pcs</>}
            {r.data.raw_gross && <>, raw gold <b>{r.data.raw_gross} g</b></>}
            {r.data.scrap_gross && <>, scrap <b>{r.data.scrap_gross} g</b></>}
            {r.data.dust_gross && <>, dust <b>{r.data.dust_gross} g</b></>}
            {r.data.rejected_gross && <>, rejected <b>{r.data.rejected_gross} g</b>{r.data.rejected_pieces != null && ` (${r.data.rejected_pieces} pcs)`}</>}
          </>
        )}
        {r.type === 'dispute' && <>“{r.data.reason}”{r.data.weight_received && <> · says received {r.data.weight_received} g</>}</>}
        {r.data.note && <p className="text-muted-foreground">“{r.data.note}”</p>}
        {r.review_note && <p className="text-muted-foreground">Store: {r.review_note}</p>}
      </div>

      {r.attachments.length > 0 && (
        <div className="mt-2 flex gap-2">
          {r.attachments.map((a) => (
            <a key={a.id} href={a.url} target="_blank" rel="noreferrer" title={a.kind.replace('_', ' ')}>
              <img src={a.thumb_url ?? a.url} alt={a.kind} className="size-20 rounded border object-cover" />
            </a>
          ))}
        </div>
      )}

      {pending && (
        <div className="mt-3 grid gap-3 border-t pt-3">
          {error && <Alert>{error.message}</Alert>}
          {r.type === 'return' && (
            <div className="grid gap-3 sm:grid-cols-4">
              <Field label="Weighed work g"><WeightInput value={w.work_gross} onValueChange={(v) => setW({ ...w, work_gross: v })} /></Field>
              <Field label="Stone g"><WeightInput value={w.stone} onValueChange={(v) => setW({ ...w, stone: v })} /></Field>
              <Field label="Raw gold g" hint="Back to the vault"><WeightInput value={w.raw_gross} onValueChange={(v) => setW({ ...w, raw_gross: v })} /></Field>
              <Field label="Rejected pieces g" hint="Back for rework"><WeightInput value={w.rejected_gross} onValueChange={(v) => setW({ ...w, rejected_gross: v })} /></Field>
              <Field label="Scrap g"><WeightInput value={w.scrap_gross} onValueChange={(v) => setW({ ...w, scrap_gross: v })} /></Field>
              <Field label="Dust g"><WeightInput value={w.dust_gross} onValueChange={(v) => setW({ ...w, dust_gross: v })} /></Field>
              <Field label="Tunch %"><WeightInput scale={false} placeholder={r.job_card?.karat.purity_pct} value={w.purity} onValueChange={(v) => setW({ ...w, purity: v })} /></Field>
              <Field label="Work into">
                <Select value={destination} onChange={(e) => setDestination(e.target.value)}>
                  <option value="">Work order if linked, else finished</option>
                  <option value="finished">Finished goods</option>
                  <option value="vault">Vault</option>
                </Select>
              </Field>
              <div className="flex items-end pb-2"><Checkbox label="Close card" checked={close} onChange={(e) => setClose(e.target.checked)} /></div>
            </div>
          )}
          <div className="flex flex-wrap items-end gap-2">
            <Field label="Note" className="min-w-60 flex-1"><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder={r.type === 'dispute' ? 'How it was resolved (reverse the issue separately if needed)' : ''} /></Field>
            <Button onClick={() => approve.mutate()} loading={approve.isPending}>{r.type === 'return' ? 'Post receive' : 'Approve'}</Button>
            <Button variant="ghost" onClick={() => note ? reject.mutate() : alert('Write a note for the kaarigar first.')} loading={reject.isPending}>Reject</Button>
          </div>
        </div>
      )}
    </Card>
  )
}
