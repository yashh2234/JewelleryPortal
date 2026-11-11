import { Alert, api, ApiError, Button, Card, CardHeader, Checkbox, Field, Input, previewFine, Select, today, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router'
import { PageTitle } from '../layout'
import { SlipLink, useActiveKaarigars } from './shared'
import type { JobCard, Tx } from './types'

/** Blueprint §3 return voucher: each kind is its own line and lands in its own place. */
const KINDS = [
  { key: 'work', label: 'Finished work', hint: 'Goes to the place chosen below', stone: true, pieces: true },
  { key: 'semi', label: 'Semi-finished pieces', hint: 'Back to the work order (or vault)', stone: true, pieces: true },
  { key: 'rejected', label: 'Rejected pieces', hint: 'Back to the work order (or vault) for rework', stone: true, pieces: true },
  { key: 'raw', label: 'Raw gold returned', hint: 'Back to available raw stock (vault)', stone: false, pieces: false },
  { key: 'scrap', label: 'Scrap / cuttings', hint: 'Scrap & dust pool, recovered by refining', stone: false, pieces: false },
  { key: 'dust', label: 'Dust / filings', hint: 'Scrap & dust pool, recovered by refining', stone: false, pieces: false },
] as const

type Kind = (typeof KINDS)[number]['key']
type Part = { gross: string; stone: string; findings: string; purity: string; pieces: string }
const blankPart: Part = { gross: '', stone: '', findings: '', purity: '', pieces: '' }
const blank = Object.fromEntries(KINDS.map((k) => [k.key, blankPart])) as Record<Kind, Part>

/**
 * Receive work back from a kaarigar: finished work, semi-finished or rejected pieces,
 * raw gold, scrap and dust. Shows what is still with the kaarigar; closing the card
 * leaves any difference waiting for approval with a reason.
 */
export default function ReceivePage() {
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const kaarigars = useActiveKaarigars()
  const [kaarigarId, setKaarigarId] = useState(params.get('kaarigar') ?? '')
  const [cardId, setCardId] = useState(params.get('card') ?? '')
  const [parts, setParts] = useState(blank)
  const [shown, setShown] = useState<Kind[]>(['work', 'scrap'])
  const [destination, setDestination] = useState('finished')
  const [close, setClose] = useState(false)
  const [date, setDate] = useState(today())
  const [result, setResult] = useState<{ data: Tx; job_card: JobCard | null; alert: string | null } | null>(null)

  const cards = useQuery({
    queryKey: ['job-cards', 'open', kaarigarId],
    queryFn: () => api.get<Paginated<JobCard>>(`/api/v1/job-cards?status=open&kaarigar_id=${kaarigarId}&per_page=100`),
    enabled: !!kaarigarId,
  })
  const card = cards.data?.data.find((c) => String(c.id) === cardId)
  const effectiveDestination = card?.wo_step_id && destination === 'finished' ? 'wo' : destination

  const preview = useMemo(() => {
    if (!card?.live) return null
    const mg = (v: string) => Math.round(Number(v) * 1000)
    const fine = KINDS.reduce((sum, k) => {
      const p = parts[k.key]
      return sum + mg(previewFine(p.gross, p.purity || card.karat.purity_pct, p.stone, p.findings))
    }, 0)
    const left = mg(card.live.outstanding) - fine
    const gotIn = mg(card.live.issued) + mg(card.live.handed_in)
    return { fine: (fine / 1000).toFixed(3), left: (left / 1000).toFixed(3), pct: gotIn > 0 ? ((left / gotIn) * 100).toFixed(3) : '0.000', tooMuch: left < 0 }
  }, [card, parts])

  const receive = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = { job_card_id: Number(cardId), destination: effectiveDestination, date, close }
      for (const k of KINDS) {
        const p = parts[k.key]
        if (!p.gross) continue
        body[k.key] = {
          gross: p.gross,
          ...(k.stone ? { stone: p.stone || '0', findings: p.findings || '0' } : {}),
          ...(p.purity ? { purity: p.purity } : {}),
          ...(k.pieces && p.pieces ? { pieces: Number(p.pieces) } : {}),
        }
      }
      return api.post<{ data: Tx; job_card: JobCard | null; alert: string | null }>('/api/v1/metal/receive', body)
    },
    onSuccess: (r) => {
      setResult(r)
      setParts(blank)
      setClose(false)
      if (r.job_card) setCardId('')
      qc.invalidateQueries({ queryKey: ['job-cards'] })
      qc.invalidateQueries({ queryKey: ['metal'] })
    },
  })

  const error = receive.error instanceof ApiError ? receive.error : null
  const set = (kind: Kind, field: keyof Part) => (v: string) => setParts({ ...parts, [kind]: { ...parts[kind], [field]: v } })
  const anyWeight = KINDS.some((k) => parts[k.key].gross)

  return (
    <>
      <PageTitle title="Receive / return gold" description="Return voucher: finished work, semi-finished or rejected pieces, raw gold, scrap and dust, each recorded separately." />
      {result && (
        <div className="mb-4 grid gap-2">
          <Alert tone={result.alert ? 'warning' : 'success'}>
            Received {result.data.fine_total} g fine on {result.data.job_card?.card_no}.{' '}
            {result.job_card && <>Card closed · difference {result.job_card.loss_fine} g. </>}
            <SlipLink tx={result.data} />
          </Alert>
          {result.alert && <Alert tone="warning">{result.alert}</Alert>}
        </div>
      )}

      <Card className="max-w-4xl">
        <CardHeader title="Kaarigar and job card" />
        <form className="grid gap-4 p-4" onSubmit={(e) => { e.preventDefault(); setResult(null); receive.mutate() }}>
          {error && <Alert>{Object.values(error.errors)[0]?.[0] ?? error.message}</Alert>}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Kaarigar">
              <Select required value={kaarigarId} onChange={(e) => { setKaarigarId(e.target.value); setCardId('') }}>
                <option value="">Choose…</option>
                {kaarigars.data?.map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}
              </Select>
            </Field>
            <Field label="Open job card">
              <Select required value={cardId} onChange={(e) => setCardId(e.target.value)} disabled={!kaarigarId}>
                <option value="">{cards.data?.data.length === 0 ? 'No open cards' : 'Choose…'}</option>
                {cards.data?.data.map((c) => <option key={c.id} value={c.id}>{c.card_no} · {c.process.name} · {c.karat.label}{c.kaarigar_status === 'ready' ? ' · ready' : ''}</option>)}
              </Select>
            </Field>
          </div>

          {card?.live && (
            <div className="grid grid-cols-2 gap-2 rounded-md bg-muted p-3 text-sm sm:grid-cols-5">
              <div>Issued <b className="tabular block">{card.live.issued} g</b></div>
              <div>Handed in <b className="tabular block">{card.live.handed_in} g</b></div>
              <div>Returned + scrap <b className="tabular block">{card.live.returned} + {card.live.scrap} g</b></div>
              <div>Handed on <b className="tabular block">{card.live.handed_out} g</b></div>
              <div>Still with kaarigar <b className="tabular block">{card.live.outstanding} g</b></div>
            </div>
          )}

          {KINDS.filter((k) => shown.includes(k.key)).map((k) => (
            <fieldset key={k.key} className="grid gap-4 sm:grid-cols-5">
              <legend className="mb-2 text-sm font-medium">{k.label} <span className="font-normal text-muted-foreground">· {k.hint}</span></legend>
              <Field label="Gross g"><WeightInput value={parts[k.key].gross} onValueChange={set(k.key, 'gross')} /></Field>
              {k.stone && <Field label="Stone g"><WeightInput value={parts[k.key].stone} onValueChange={set(k.key, 'stone')} /></Field>}
              {k.stone && <Field label="Findings g" hint="Non-gold parts"><WeightInput scale={false} value={parts[k.key].findings} onValueChange={set(k.key, 'findings')} /></Field>}
              <Field label="Tunch %"><WeightInput scale={false} placeholder={card?.karat.purity_pct} value={parts[k.key].purity} onValueChange={set(k.key, 'purity')} /></Field>
              {k.pieces && <Field label="Pieces"><Input inputMode="numeric" value={parts[k.key].pieces} onChange={(e) => set(k.key, 'pieces')(e.target.value.replace(/\D/g, ''))} /></Field>}
            </fieldset>
          ))}
          {shown.length < KINDS.length && (
            <div className="flex flex-wrap gap-2">
              {KINDS.filter((k) => !shown.includes(k.key)).map((k) => (
                <Button key={k.key} type="button" size="sm" variant="outline" onClick={() => setShown([...shown, k.key])}>+ {k.label}</Button>
              ))}
            </div>
          )}

          {preview && anyWeight && (
            <div className={`rounded-md border p-3 text-sm ${preview.tooMuch ? 'border-danger/40 bg-danger/5' : ''}`}>
              Coming back <b className="tabular">{preview.fine} g fine</b> →{' '}
              {preview.tooMuch ? (
                <span className="text-danger">more than is outstanding. Check weights and tunch.</span>
              ) : (
                <>still with kaarigar <b className="tabular">{preview.left} g</b>
                  {close && <> · closing leaves this as a difference to approve ({preview.pct}% of gold in)</>}</>
              )}
            </div>
          )}

          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Put finished work into">
              <Select value={effectiveDestination} onChange={(e) => setDestination(e.target.value)}>
                {card?.wo_step_id && <option value="wo">Work order (next stage)</option>}
                <option value="finished">Finished goods</option>
                <option value="vault">Vault</option>
              </Select>
            </Field>
            <Field label="Date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <div className="flex items-end pb-2">
              <Checkbox label="Last return: close the job card" checked={close} onChange={(e) => setClose(e.target.checked)} />
            </div>
          </div>
          <div><Button type="submit" size="lg" loading={receive.isPending} disabled={!cardId || !anyWeight || preview?.tooMuch}>Receive</Button></div>
        </form>
      </Card>
    </>
  )
}
