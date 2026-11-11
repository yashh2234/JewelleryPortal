import { Alert, api, ApiError, Badge, Button, Card, grams, Input, Textarea, WeightInput } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Camera, Check, FileText, Users, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useI18n } from './i18n'
import { submit } from './offline'

interface Issue {
  id: number
  voucher_no: string
  type: 'issue' | 'handoff'
  /** Handoffs: the kaarigar the gold came from. */
  from: string | null
  remarks: string | null
  date: string
  job_card: { id: number; card_no: string; description: string | null; due_date: string | null; pieces: number | null } | null
  process: string | null
  design_photo: string | null
  lines: { karat: string; gross_wt: string; fine_wt: string; pieces: number | null }[]
}

interface Job {
  id: number
  card_no: string
  description: string | null
  process: string
  karat: string
  pieces: number | null
  due_date: string | null
  kaarigar_status: 'started' | 'ready' | null
  design: { code: string; name: string; photo: string | null } | null
  outstanding_fine: string
  return_pending: boolean
  note: string | null
  photos: string[]
  from_kaarigar: string | null
  can_hand_back: boolean
  given_to: { card_no: string; kaarigar: string }[]
}

const day = (d: string | null) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—')

// ── New issues: accept or dispute ────────────────────────────────────────────

export function IssuesScreen() {
  const { t } = useI18n()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['kaarigar', 'issues'], queryFn: () => api.get<{ data: Issue[] }>('/api/kaarigar/v1/issues'), meta: { persist: true } })
  const [disputing, setDisputing] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const [weight, setWeight] = useState('')
  const [error, setError] = useState<string | null>(null)

  const act = useMutation({
    mutationFn: ({ id, kind }: { id: number; kind: 'accept' | 'dispute' }) =>
      submit({
        path: `/api/kaarigar/v1/issues/${id}/${kind}`,
        json: kind === 'dispute' ? { reason, weight_received: weight || null } : {},
        label: kind,
      }),
    onSuccess: (_r, { id }) => {
      qc.setQueryData<{ data: Issue[] }>(['kaarigar', 'issues'], (old) => old && { data: old.data.filter((i) => i.id !== id) })
      qc.invalidateQueries({ queryKey: ['kaarigar', 'home'] })
      setDisputing(null)
      setReason('')
      setWeight('')
    },
    onError: (e) => setError(e instanceof ApiError ? e.message : String(e)),
  })

  if (q.data?.data.length === 0) return <p className="py-10 text-center text-lg text-muted-foreground">{t('noIssues')}</p>

  return (
    <div className="grid gap-3">
      {error && <Alert>{error}</Alert>}
      {q.data?.data.map((i) => (
        <Card key={i.id} className="overflow-hidden">
          <div className="flex gap-3 p-4">
            {i.design_photo && <img src={i.design_photo} alt="" className="size-20 rounded object-cover" />}
            <div className="min-w-0 flex-1">
              <p className="text-sm text-muted-foreground">{i.voucher_no} · {day(i.date)}</p>
              {i.from && <Badge tone="brand" className="mb-1">{t('from')}: {i.from}</Badge>}
              <p className="font-semibold">{i.process} {i.job_card?.description && `· ${i.job_card.description}`}</p>
              {i.lines.map((l, j) => (
                <p key={j} className="tabular text-2xl font-bold">{l.gross_wt} g <span className="text-base font-normal text-muted-foreground">{l.karat}</span></p>
              ))}
              {i.job_card?.due_date && <p className="text-sm">{t('due')}: {day(i.job_card.due_date)}</p>}
            </div>
          </div>
          {disputing === i.id ? (
            <div className="grid gap-3 border-t p-4">
              <Textarea placeholder={t('disputeReason')} value={reason} onChange={(e) => setReason(e.target.value)} />
              <label className="grid gap-1 text-sm">{t('weightGot')}<WeightInput scale={false} className="h-12 text-lg" value={weight} onValueChange={setWeight} /></label>
              <div className="grid grid-cols-2 gap-2">
                <Button size="lg" variant="outline" onClick={() => setDisputing(null)}>{t('back')}</Button>
                <Button size="lg" variant="danger" disabled={!reason} loading={act.isPending} onClick={() => act.mutate({ id: i.id, kind: 'dispute' })}>{t('send')}</Button>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-2 border-t">
              <button className="flex h-16 items-center justify-center gap-2 text-lg font-semibold text-danger" onClick={() => setDisputing(i.id)}><X /> {t('dispute')}</button>
              <button className="flex h-16 items-center justify-center gap-2 bg-success text-lg font-semibold text-white dark:text-[#04230f]" onClick={() => act.mutate({ id: i.id, kind: 'accept' })}><Check /> {t('accept')}</button>
            </div>
          )}
        </Card>
      ))}
    </div>
  )
}

// ── My jobs ──────────────────────────────────────────────────────────────────

export function JobsScreen() {
  const { t } = useI18n()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['kaarigar', 'jobs'], queryFn: () => api.get<{ data: Job[] }>('/api/kaarigar/v1/jobs'), meta: { persist: true } })
  const home = useQuery({ queryKey: ['kaarigar', 'home'], queryFn: () => api.get<{ is_head: boolean }>('/api/kaarigar/v1/home'), meta: { persist: true } })

  const status = useMutation({
    mutationFn: ({ id, s }: { id: number; s: 'started' | 'ready' }) => submit({ path: `/api/kaarigar/v1/jobs/${id}/status`, json: { status: s }, label: s }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['kaarigar', 'jobs'] }),
  })

  if (q.data?.data.length === 0) return <p className="py-10 text-center text-lg text-muted-foreground">{t('noJobs')}</p>

  return (
    <div className="grid gap-3">
      {q.data?.data.map((j) => {
        const late = j.due_date && j.due_date < new Date().toISOString().slice(0, 10)
        return (
          <Card key={j.id} className="p-4">
            <div className="flex gap-3">
              {j.design?.photo && <img src={j.design.photo} alt="" className="size-20 rounded object-cover" />}
              <div className="min-w-0 flex-1">
                <p className="text-sm text-muted-foreground">{j.card_no} · {j.karat}</p>
                <p className="text-lg font-semibold">{j.process}</p>
                {j.description && <p>{j.description}</p>}
                {j.from_kaarigar && <p className="text-sm text-muted-foreground">{t('from')}: <b>{j.from_kaarigar}</b></p>}
                <p className={late ? 'font-semibold text-danger' : ''}>{t('due')}: {day(j.due_date)}</p>
                <p className="tabular">{t('holding')}: <b>{j.outstanding_fine} g</b></p>
                {j.given_to.length > 0 && <p className="text-sm text-muted-foreground">{t('givenTo')}: {j.given_to.map((g) => g.kaarigar).join(', ')}</p>}
                {j.note && <p className="mt-1 text-sm italic">“{j.note}”</p>}
                {j.photos.length > 0 && <div className="mt-1 flex gap-1">{j.photos.map((p) => <img key={p} src={p} alt="" className="size-10 rounded object-cover" />)}</div>}
              </div>
            </div>
            {j.return_pending ? (
              <p className="mt-3 rounded bg-muted p-2 text-center">{t('returnPending')}</p>
            ) : (
              <div className="mt-3 grid grid-cols-2 gap-2">
                {j.kaarigar_status === null ? (
                  <Button size="lg" variant="outline" onClick={() => status.mutate({ id: j.id, s: 'started' })}>{t('started')}</Button>
                ) : <Link to={`/kaarigar/jobs/${j.id}/update`}><Button size="lg" variant="outline" className="w-full">{j.kaarigar_status === 'ready' ? `✓ ${t('ready')}` : t('update')}</Button></Link>}
                {j.can_hand_back
                  ? <Link to={`/kaarigar/jobs/${j.id}/hand-back`}><Button size="lg" className="w-full">{t('giveBack')} {j.from_kaarigar}</Button></Link>
                  : <Link to={`/kaarigar/jobs/${j.id}/return`}><Button size="lg" className="w-full">{t('returnWork')}</Button></Link>}
                {home.data?.is_head && Number(j.outstanding_fine) > 0 && (
                  <Link to={`/kaarigar/jobs/${j.id}/handoff`} className="col-span-2"><Button size="lg" variant="outline" className="w-full"><Users /> {t('giveToTeam')}</Button></Link>
                )}
              </div>
            )}
          </Card>
        )
      })}
    </div>
  )
}

// ── Progress update: started / ready, a note and photos ─────────────────────

export function UpdateScreen() {
  const { t } = useI18n()
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [ready, setReady] = useState(true)
  const [note, setNote] = useState('')
  const [photos, setPhotos] = useState<File[]>([])
  const [error, setError] = useState<string | null>(null)

  const send = useMutation({
    mutationFn: () => {
      const form: [string, string | Blob][] = [['status', ready ? 'ready' : 'started']]
      if (note) form.push(['note', note])
      photos.forEach((f) => form.push(['photos[]', f]))
      return submit({ path: `/api/kaarigar/v1/jobs/${id}/status`, form, label: 'update' })
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['kaarigar', 'jobs'] }); navigate('/kaarigar/jobs') },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : String(e)),
  })

  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
      {error && <Alert>{error}</Alert>}
      <div className="grid grid-cols-2 gap-2">
        <Button type="button" size="lg" variant={ready ? 'outline' : 'default'} onClick={() => setReady(false)}>{t('started')}</Button>
        <Button type="button" size="lg" variant={ready ? 'default' : 'outline'} onClick={() => setReady(true)}>{t('markReady')}</Button>
      </div>
      <PhotoPicker label={t('photoProgress')} files={photos} onChange={setPhotos} />
      <label className="grid gap-1">{t('note')}<Textarea value={note} onChange={(e) => setNote(e.target.value)} /></label>
      <Button size="xl" type="submit" loading={send.isPending}>{t('send')}</Button>
    </form>
  )
}

// ── Head kaarigar: my team ───────────────────────────────────────────────────

interface Team {
  data: { id: number; name: string; phone: string | null; gold: { fine: string; gross: string }; open_jobs: { card_no: string; process: string; kaarigar_status: string | null; due_date: string | null }[] }[]
  processes: { id: number; name: string }[]
}

const useTeam = () => useQuery({ queryKey: ['kaarigar', 'team'], queryFn: () => api.get<Team>('/api/kaarigar/v1/team'), meta: { persist: true } })

export function TeamScreen() {
  const { t } = useI18n()
  const q = useTeam()

  if (q.data?.data.length === 0) return <p className="py-10 text-center text-lg text-muted-foreground">{t('noTeam')}</p>

  return (
    <div className="grid gap-3">
      {q.data?.data.map((k) => (
        <Card key={k.id} className="p-4">
          <div className="flex items-baseline justify-between gap-2">
            <p className="text-lg font-semibold">{k.name}</p>
            <p className="tabular text-xl font-bold">{grams(k.gold.fine)}</p>
          </div>
          {k.open_jobs.map((j) => (
            <p key={j.card_no} className="text-sm text-muted-foreground">
              {j.card_no} · {j.process} · {t('due')} {day(j.due_date)}{j.kaarigar_status && <Badge tone={j.kaarigar_status === 'ready' ? 'success' : 'brand'} className="ml-1">{t(j.kaarigar_status as 'started' | 'ready')}</Badge>}
            </p>
          ))}
        </Card>
      ))}
    </div>
  )
}

/** Head kaarigar passes part of a job and its gold to a sub-kaarigar. Posts at once, so it needs internet. */
export function HandoffScreen() {
  const { t } = useI18n()
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const team = useTeam()
  const [f, setF] = useState({ kaarigar_id: '', process_id: '', gross: '', pieces: '', note: '' })
  const [error, setError] = useState<string | null>(null)

  const send = useMutation({
    mutationFn: () => api.post(`/api/kaarigar/v1/jobs/${id}/handoff`, {
      kaarigar_id: Number(f.kaarigar_id), process_id: f.process_id ? Number(f.process_id) : null,
      gross: f.gross, pieces: f.pieces ? Number(f.pieces) : null, note: f.note || null,
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['kaarigar'] }); navigate('/kaarigar/jobs') },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : String(e)),
  })

  const big = 'h-14 text-xl'
  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
      {error && <Alert>{error}</Alert>}
      {!navigator.onLine && <Alert tone="warning">{t('onlineOnly')}</Alert>}
      <label className="grid gap-1 text-lg font-medium">{t('chooseKaarigar')}
        <select className={`rounded-md border bg-background px-3 ${big}`} value={f.kaarigar_id} onChange={(e) => setF({ ...f, kaarigar_id: e.target.value })}>
          <option value="">—</option>
          {team.data?.data.map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}
        </select>
      </label>
      <label className="grid gap-1 text-lg font-medium">{t('stage')}
        <select className={`rounded-md border bg-background px-3 ${big}`} value={f.process_id} onChange={(e) => setF({ ...f, process_id: e.target.value })}>
          <option value="">{t('sameStage')}</option>
          {team.data?.processes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </label>
      <label className="grid gap-1 text-lg font-medium">{t('weightGive')}<WeightInput scale={false} className={big} value={f.gross} onValueChange={(v) => setF({ ...f, gross: v })} /></label>
      <label className="grid gap-1 text-lg font-medium">{t('pieces')}<Input inputMode="numeric" className={big} value={f.pieces} onChange={(e) => setF({ ...f, pieces: e.target.value.replace(/\D/g, '') })} /></label>
      <label className="grid gap-1">{t('note')}<Textarea value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></label>
      <Button size="xl" type="submit" disabled={!f.kaarigar_id || !f.gross || !navigator.onLine} loading={send.isPending}>{t('send')}</Button>
    </form>
  )
}

/** Sub-kaarigar gives the work back to the head kaarigar it came from. */
export function HandBackScreen() {
  const { t } = useI18n()
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const jobs = useQuery({ queryKey: ['kaarigar', 'jobs'], queryFn: () => api.get<{ data: Job[] }>('/api/kaarigar/v1/jobs'), meta: { persist: true } })
  const job = jobs.data?.data.find((j) => String(j.id) === id)
  const [f, setF] = useState({ gross: '', pieces: '', note: '' })
  const [error, setError] = useState<string | null>(null)

  const send = useMutation({
    mutationFn: () => api.post(`/api/kaarigar/v1/jobs/${id}/hand-back`, { gross: f.gross, pieces: f.pieces ? Number(f.pieces) : null, note: f.note || null }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['kaarigar'] }); navigate('/kaarigar/jobs') },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : String(e)),
  })

  const big = 'h-14 text-xl'
  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
      {error && <Alert>{error}</Alert>}
      {!navigator.onLine && <Alert tone="warning">{t('onlineOnly')}</Alert>}
      {job && <p className="text-lg">{t('giveBack')} <b>{job.from_kaarigar}</b> · {t('holding')} <b className="tabular">{job.outstanding_fine} g</b></p>}
      <label className="grid gap-1 text-lg font-medium">{t('weightGive')}<WeightInput scale={false} className={big} value={f.gross} onValueChange={(v) => setF({ ...f, gross: v })} /></label>
      <label className="grid gap-1 text-lg font-medium">{t('pieces')}<Input inputMode="numeric" className={big} value={f.pieces} onChange={(e) => setF({ ...f, pieces: e.target.value.replace(/\D/g, '') })} /></label>
      <label className="grid gap-1">{t('note')}<Textarea value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></label>
      <Button size="xl" type="submit" disabled={!f.gross || !navigator.onLine} loading={send.isPending}>{t('send')}</Button>
    </form>
  )
}

// ── Return work with photos ──────────────────────────────────────────────────

export function ReturnScreen() {
  const { t } = useI18n()
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [f, setF] = useState({ work_gross: '', stone: '', scrap_gross: '', pieces: '', note: '', raw_gross: '', dust_gross: '', rejected_gross: '', rejected_pieces: '' })
  const [more, setMore] = useState(false)
  const [work, setWork] = useState<File[]>([])
  const [scale, setScale] = useState<File[]>([])
  const [result, setResult] = useState<'sent' | 'queued' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const send = useMutation({
    mutationFn: () => {
      const form: [string, string | Blob][] = []
      Object.entries(f).forEach(([k, v]) => v && form.push([k, v]))
      work.forEach((file) => form.push(['work_photos[]', file]))
      scale.forEach((file) => form.push(['scale_photos[]', file]))
      return submit({ path: `/api/kaarigar/v1/jobs/${id}/return`, form, label: 'return' })
    },
    onSuccess: (r) => {
      setResult(r)
      qc.invalidateQueries({ queryKey: ['kaarigar'] })
      setTimeout(() => navigate('/kaarigar/jobs'), 2000)
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : String(e)),
  })

  if (result) return <Alert tone="success">{t('returnSent')}{result === 'queued' && ` (${t('queued')})`}</Alert>

  const big = 'h-14 text-xl'
  return (
    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
      {error && <Alert>{error}</Alert>}
      <p className="text-sm text-muted-foreground">{t('storeWeighs')}</p>
      <label className="grid gap-1 text-lg font-medium">{t('workWeight')}<WeightInput scale={false} className={big} value={f.work_gross} onValueChange={(v) => setF({ ...f, work_gross: v })} /></label>
      <label className="grid gap-1 text-lg font-medium">{t('stoneWeight')}<WeightInput scale={false} className={big} value={f.stone} onValueChange={(v) => setF({ ...f, stone: v })} /></label>
      <label className="grid gap-1 text-lg font-medium">{t('scrapWeight')}<WeightInput scale={false} className={big} value={f.scrap_gross} onValueChange={(v) => setF({ ...f, scrap_gross: v })} /></label>
      <label className="grid gap-1 text-lg font-medium">{t('pieces')}<Input inputMode="numeric" className={big} value={f.pieces} onChange={(e) => setF({ ...f, pieces: e.target.value.replace(/\D/g, '') })} /></label>
      {more ? (
        <>
          <label className="grid gap-1 text-lg font-medium">{t('rawWeight')}<WeightInput scale={false} className={big} value={f.raw_gross} onValueChange={(v) => setF({ ...f, raw_gross: v })} /></label>
          <label className="grid gap-1 text-lg font-medium">{t('dustWeight')}<WeightInput scale={false} className={big} value={f.dust_gross} onValueChange={(v) => setF({ ...f, dust_gross: v })} /></label>
          <label className="grid gap-1 text-lg font-medium">{t('rejectedWeight')}<WeightInput scale={false} className={big} value={f.rejected_gross} onValueChange={(v) => setF({ ...f, rejected_gross: v })} /></label>
          <label className="grid gap-1 text-lg font-medium">{t('rejectedPieces')}<Input inputMode="numeric" className={big} value={f.rejected_pieces} onChange={(e) => setF({ ...f, rejected_pieces: e.target.value.replace(/\D/g, '') })} /></label>
        </>
      ) : <Button type="button" variant="ghost" onClick={() => setMore(true)}>{t('moreReturn')}</Button>}
      <div className="grid grid-cols-2 gap-3">
        <PhotoPicker label={t('photoWork')} files={work} onChange={setWork} />
        <PhotoPicker label={t('photoScale')} files={scale} onChange={setScale} />
      </div>
      <label className="grid gap-1">{t('note')}<Textarea value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></label>
      <Button size="xl" type="submit" disabled={!f.work_gross && !f.scrap_gross && !f.raw_gross && !f.dust_gross && !f.rejected_gross} loading={send.isPending}>{t('send')}</Button>
    </form>
  )
}

function PhotoPicker({ label, files, onChange }: { label: string; files: File[]; onChange: (f: File[]) => void }) {
  return (
    <label className="flex h-28 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-center">
      {files.length ? (
        <img src={URL.createObjectURL(files[0])} alt="" className="h-20 rounded object-cover" />
      ) : <Camera className="size-8 text-brand" />}
      <span className="text-sm">{label}{files.length > 1 && ` (${files.length})`}</span>
      <input type="file" accept="image/*" capture="environment" className="hidden"
        onChange={(e) => e.target.files?.length && onChange([...files, ...Array.from(e.target.files)].slice(0, 3))} />
    </label>
  )
}

// ── Gold khata ───────────────────────────────────────────────────────────────

interface Khata {
  opening: string
  closing: string
  rows: { date: string; voucher_no: string; kind: 'issued' | 'handoff' | 'returned' | 'scrap' | 'loss'; karat: string; gross_wt: string; in: string | null; out: string | null; balance: string; job_card: string | null }[]
  totals: Record<'issued' | 'handoff' | 'returned' | 'scrap' | 'loss', string>
}

export function KhataScreen() {
  const { t } = useI18n()
  const q = useQuery({ queryKey: ['kaarigar', 'khata'], queryFn: () => api.get<Khata>('/api/kaarigar/v1/khata'), meta: { persist: true } })
  const k = q.data
  const label = { issued: t('issued'), handoff: t('handoff'), returned: t('returned'), scrap: t('scrap'), loss: t('loss') }

  return (
    <div className="grid gap-3">
      <Card className="p-4">
        <p className="text-muted-foreground">{t('goldInHand')}</p>
        <p className="tabular text-3xl font-bold">{grams(k?.closing ?? '0.000')}</p>
        <div className="mt-2 grid grid-cols-5 gap-1 text-center text-xs">
          {(['issued', 'handoff', 'returned', 'scrap', 'loss'] as const).map((key) => (
            <div key={key}><p className="text-muted-foreground">{label[key]}</p><p className="tabular font-semibold">{k?.totals[key]}</p></div>
          ))}
        </div>
      </Card>
      <div className="divide-y rounded-lg border bg-card">
        {k?.rows.slice().reverse().map((r, i) => (
          <div key={i} className="flex items-center justify-between gap-2 p-3">
            <div>
              <p className="font-medium">{label[r.kind]} <span className="text-sm font-normal text-muted-foreground">{r.karat}</span></p>
              <p className="text-xs text-muted-foreground">{day(r.date)} · {r.voucher_no}{r.job_card && ` · ${r.job_card}`}</p>
            </div>
            <div className="text-right">
              <p className={`tabular font-semibold ${r.in ? 'text-success' : ''}`}>{r.in ? `+${r.in}` : `−${r.out}`}</p>
              <p className="tabular text-xs text-muted-foreground">{t('balance')} {r.balance}</p>
            </div>
          </div>
        ))}
      </div>
      <p className="text-center text-xs text-muted-foreground"><FileText className="mr-1 inline size-3" />{t('download')}: ask the store for a printed khata with QR.</p>
    </div>
  )
}

// ── My requests ──────────────────────────────────────────────────────────────

interface Req { id: number; type: string; status: 'pending' | 'approved' | 'rejected'; created_at: string; review_note: string | null; data: Record<string, string>; job_card: { card_no: string } | null }

export function RequestsScreen() {
  const { t } = useI18n()
  const q = useQuery({ queryKey: ['kaarigar', 'requests'], queryFn: () => api.get<{ data: Req[] }>('/api/kaarigar/v1/requests'), meta: { persist: true } })
  const tone = { pending: 'warning', approved: 'success', rejected: 'danger' } as const

  return (
    <div className="grid gap-3">
      {q.data?.data.length === 0 && <p className="py-8 text-center text-muted-foreground">{t('noRequests')}</p>}
      {q.data?.data.map((r) => (
        <Card key={r.id} className="flex items-start justify-between gap-2 p-3">
          <div>
            <p className="font-medium capitalize">{r.type}{r.job_card && ` · ${r.job_card.card_no}`}</p>
            <p className="text-xs text-muted-foreground">{new Date(r.created_at).toLocaleDateString('en-IN')}</p>
            {r.review_note && <p className="text-sm">“{r.review_note}”</p>}
          </div>
          <Badge tone={tone[r.status]}>{t(r.status)}</Badge>
        </Card>
      ))}
    </div>
  )
}

// ── Earnings ─────────────────────────────────────────────────────────────────

interface Earnings { balance_due: string; this_week: string; rows: { date: string; voucher_no: string; type: string; narration: string | null; earned: string | null; paid: string | null }[] }

export function EarningsScreen() {
  const { t } = useI18n()
  const q = useQuery({ queryKey: ['kaarigar', 'earnings'], queryFn: () => api.get<Earnings>('/api/kaarigar/v1/earnings'), meta: { persist: true } })
  const e = q.data
  const inr = (v: string) => `₹${Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`
  const due = Number(e?.balance_due ?? 0)

  return (
    <div className="grid gap-3">
      <Card className="p-4">
        <p className="text-muted-foreground">{due >= 0 ? t('balance') : t('owedToStore')}</p>
        <p className="tabular text-3xl font-bold">{inr(String(Math.abs(due)))}</p>
        <p className="mt-1 text-sm text-muted-foreground">{t('labourWeek')}: <b className="tabular">{inr(e?.this_week ?? '0')}</b></p>
      </Card>
      <div className="divide-y rounded-lg border bg-card">
        {e?.rows.map((r, i) => (
          <div key={i} className="flex items-center justify-between gap-2 p-3">
            <div className="min-w-0">
              <p className="truncate font-medium">{r.narration ?? r.type}</p>
              <p className="text-xs text-muted-foreground">{day(r.date)} · {r.voucher_no}</p>
            </div>
            <p className={`tabular font-semibold ${r.earned ? 'text-success' : ''}`}>{r.earned ? `+${inr(r.earned)}` : `−${inr(r.paid ?? '0')}`}</p>
          </div>
        ))}
      </div>
    </div>
  )
}
