import { api, Button, Field, previewFine, Select, WeightInput, type Paginated } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { FileText, Plus, Trash2 } from 'lucide-react'
import type { Kaarigar, Karat, Process } from '../types'

export function useKarats() {
  return useQuery({ queryKey: ['karats'], queryFn: () => api.get<{ data: Karat[] }>('/api/v1/karats'), select: (r) => r.data.filter((k) => k.is_active) })
}

export function useProcesses() {
  return useQuery({ queryKey: ['processes'], queryFn: () => api.get<{ data: Process[] }>('/api/v1/processes'), select: (r) => r.data.filter((p) => p.is_active) })
}

export function useActiveKaarigars() {
  return useQuery({
    queryKey: ['kaarigars', 'active-all'],
    queryFn: () => api.get<Paginated<Kaarigar>>('/api/v1/kaarigars?active=1&per_page=500'),
    select: (r) => r.data,
  })
}

export interface Item {
  karat_id: string
  gross: string
  purity: string
  pieces: string
}

export const emptyItem = (karatId = ''): Item => ({ karat_id: karatId, gross: '', purity: '', pieces: '' })

/** Turn form rows into API items (blank purity = the karat's tunch). */
export function toItems(items: Item[]) {
  return items
    .filter((i) => i.karat_id && i.gross)
    .map((i) => ({
      karat_id: Number(i.karat_id),
      gross: i.gross,
      ...(i.purity ? { purity: i.purity } : {}),
      ...(i.pieces ? { pieces: Number(i.pieces) } : {}),
    }))
}

export function itemsFine(items: Item[], karats: Karat[] | undefined): string {
  const total = items.reduce((sum, i) => {
    const purity = i.purity || karats?.find((k) => String(k.id) === i.karat_id)?.purity_pct || '0'
    return sum + Math.round(Number(previewFine(i.gross, purity)) * 1000)
  }, 0)
  return (total / 1000).toFixed(3)
}

/** Rows of karat × gross × purity × pieces with a live fine preview. */
export function ItemsEditor({ items, onChange, pieces = false }: { items: Item[]; onChange: (items: Item[]) => void; pieces?: boolean }) {
  const karats = useKarats()
  const set = (i: number, patch: Partial<Item>) => onChange(items.map((it, j) => (j === i ? { ...it, ...patch } : it)))

  return (
    <div className="grid gap-2">
      {items.map((it, i) => {
        const karat = karats.data?.find((k) => String(k.id) === it.karat_id)
        return (
          <div key={i} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[8rem_1fr_7rem_5rem_7rem_auto]">
            <Field label={i === 0 ? 'Karat' : ''}>
              <Select value={it.karat_id} onChange={(e) => set(i, { karat_id: e.target.value })} required>
                <option value="">—</option>
                {karats.data?.map((k) => <option key={k.id} value={k.id}>{k.label} ({k.purity_pct})</option>)}
              </Select>
            </Field>
            <Field label={i === 0 ? 'Gross g' : ''}>
              <WeightInput required value={it.gross} onValueChange={(v) => set(i, { gross: v })} />
            </Field>
            <Field label={i === 0 ? 'Tunch %' : ''}>
              <WeightInput scale={false} placeholder={karat?.purity_pct ?? ''} value={it.purity} onValueChange={(v) => set(i, { purity: v })} />
            </Field>
            {pieces ? (
              <Field label={i === 0 ? 'Pcs' : ''}>
                <input className="h-10 w-full rounded-md border border-input bg-card px-2 text-sm" inputMode="numeric" value={it.pieces}
                  onChange={(e) => set(i, { pieces: e.target.value.replace(/\D/g, '') })} />
              </Field>
            ) : <div className="hidden sm:block" />}
            <div className="tabular pb-2 text-sm">
              <span className="text-muted-foreground">Fine </span>{previewFine(it.gross, it.purity || karat?.purity_pct || '0')}
            </div>
            <div className="pb-1">
              {items.length > 1 && (
                <Button type="button" size="icon" variant="ghost" aria-label="Remove line" onClick={() => onChange(items.filter((_, j) => j !== i))}><Trash2 /></Button>
              )}
            </div>
          </div>
        )
      })}
      <div className="flex items-center justify-between">
        <Button type="button" size="sm" variant="ghost" onClick={() => onChange([...items, emptyItem(items.at(-1)?.karat_id)])}><Plus /> Add line</Button>
        <span className="tabular text-sm font-medium">Total fine {itemsFine(items, karats.data)} g</span>
      </div>
    </div>
  )
}

export function SlipLink({ tx }: { tx: { id: number; voucher_no: string } }) {
  return (
    <a href={`/api/v1/metal/transactions/${tx.id}/slip`} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-sm font-medium text-brand hover:underline">
      <FileText className="size-4" /> {tx.voucher_no} slip
    </a>
  )
}

export function fmtDate(d: string | null | undefined) {
  return d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—'
}
