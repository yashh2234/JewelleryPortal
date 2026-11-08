import { Alert, api, ApiError, Button, Card, CardHeader, Checkbox, Field, Input, previewFine, Select, today, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router'
import { PageTitle } from '../layout'
import { SlipLink, useActiveKaarigars } from './shared'
import type { JobCard, Tx } from './types'

const blank = { gross: '', stone: '', findings: '', purity: '', pieces: '', scrap: '', scrapPurity: '' }

/**
 * Receive work + scrap from a kaarigar. Shows the outstanding gold and the loss
 * that closing the card would post, against the allowed wastage.
 */
export default function ReceivePage() {
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const kaarigars = useActiveKaarigars()
  const [kaarigarId, setKaarigarId] = useState(params.get('kaarigar') ?? '')
  const [cardId, setCardId] = useState(params.get('card') ?? '')
  const [w, setW] = useState(blank)
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
  const purity = w.purity || card?.karat.purity_pct || '0'

  const preview = useMemo(() => {
    if (!card?.live) return null
    const work = previewFine(w.gross, purity, w.stone, w.findings)
    const scrap = previewFine(w.scrap, w.scrapPurity || card.karat.purity_pct)
    const mg = (v: string) => Math.round(Number(v) * 1000)
    const left = mg(card.live.outstanding) - mg(work) - mg(scrap)
    const issued = mg(card.live.issued)
    const lossPct = issued > 0 ? (left / issued) * 100 : 0
    return { work, scrap, left: (left / 1000).toFixed(3), lossPct: lossPct.toFixed(3), tooMuch: left < 0 }
  }, [card, w, purity])

  const receive = useMutation({
    mutationFn: () =>
      api.post<{ data: Tx; job_card: JobCard | null; alert: string | null }>('/api/v1/metal/receive', {
        job_card_id: Number(cardId),
        destination: effectiveDestination,
        date,
        close,
        work: w.gross ? { gross: w.gross, stone: w.stone || '0', findings: w.findings || '0', ...(w.purity ? { purity: w.purity } : {}), ...(w.pieces ? { pieces: Number(w.pieces) } : {}) } : null,
        scrap: w.scrap ? { gross: w.scrap, ...(w.scrapPurity ? { purity: w.scrapPurity } : {}) } : null,
      }),
    onSuccess: (r) => {
      setResult(r)
      setW(blank)
      setClose(false)
      if (r.job_card) setCardId('')
      qc.invalidateQueries({ queryKey: ['job-cards'] })
      qc.invalidateQueries({ queryKey: ['metal'] })
    },
  })

  const error = receive.error instanceof ApiError ? receive.error : null
  const set = (k: keyof typeof blank) => (v: string) => setW({ ...w, [k]: v })

  return (
    <>
      <PageTitle title="Receive gold" />
      {result && (
        <div className="mb-4 grid gap-2">
          <Alert tone={result.alert ? 'warning' : 'success'}>
            Received {result.data.fine_total} g fine on {result.data.job_card?.card_no}.{' '}
            {result.job_card && <>Card closed · loss {result.job_card.loss_fine} g. </>}
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
            <div className="grid grid-cols-2 gap-2 rounded-md bg-muted p-3 text-sm sm:grid-cols-4">
              <div>Issued <b className="tabular block">{card.live.issued} g</b></div>
              <div>Returned <b className="tabular block">{card.live.returned} g</b></div>
              <div>Scrap <b className="tabular block">{card.live.scrap} g</b></div>
              <div>Outstanding <b className="tabular block">{card.live.outstanding} g</b></div>
            </div>
          )}

          <fieldset className="grid gap-4 sm:grid-cols-5">
            <legend className="mb-2 text-sm font-medium">Work returned</legend>
            <Field label="Gross g"><WeightInput value={w.gross} onValueChange={set('gross')} /></Field>
            <Field label="Stone g"><WeightInput value={w.stone} onValueChange={set('stone')} /></Field>
            <Field label="Findings g" hint="Non-gold parts"><WeightInput scale={false} value={w.findings} onValueChange={set('findings')} /></Field>
            <Field label="Tunch %"><WeightInput scale={false} placeholder={card?.karat.purity_pct} value={w.purity} onValueChange={set('purity')} /></Field>
            <Field label="Pieces"><Input inputMode="numeric" value={w.pieces} onChange={(e) => set('pieces')(e.target.value.replace(/\D/g, ''))} /></Field>
          </fieldset>
          <fieldset className="grid gap-4 sm:grid-cols-5">
            <legend className="mb-2 text-sm font-medium">Scrap / dust returned</legend>
            <Field label="Gross g"><WeightInput value={w.scrap} onValueChange={set('scrap')} /></Field>
            <Field label="Tunch %"><WeightInput scale={false} placeholder={card?.karat.purity_pct} value={w.scrapPurity} onValueChange={set('scrapPurity')} /></Field>
          </fieldset>

          {preview && (
            <div className={`rounded-md border p-3 text-sm ${preview.tooMuch ? 'border-danger/40 bg-danger/5' : ''}`}>
              Work fine <b className="tabular">{preview.work}</b> + scrap fine <b className="tabular">{preview.scrap}</b> →{' '}
              {preview.tooMuch ? (
                <span className="text-danger">more than is outstanding. Check weights and tunch.</span>
              ) : (
                <>still with kaarigar <b className="tabular">{preview.left} g</b>
                  {close && <> · closing posts this as loss ({preview.lossPct}% of issued)</>}</>
              )}
            </div>
          )}

          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Put work into">
              <Select value={effectiveDestination} onChange={(e) => setDestination(e.target.value)}>
                {card?.wo_step_id && <option value="wo">Work order (next stage)</option>}
                <option value="finished">Finished goods</option>
                <option value="vault">Vault (semi-finished)</option>
              </Select>
            </Field>
            <Field label="Date"><Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
            <div className="flex items-end pb-2">
              <Checkbox label="Last return: close the job card" checked={close} onChange={(e) => setClose(e.target.checked)} />
            </div>
          </div>
          <div><Button type="submit" size="lg" loading={receive.isPending} disabled={!cardId || preview?.tooMuch}>Receive</Button></div>
        </form>
      </Card>
    </>
  )
}
