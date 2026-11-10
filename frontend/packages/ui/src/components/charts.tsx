import { useState, type ReactNode } from 'react'
import { cn } from '../lib/utils'

/**
 * Small, dependency-free charts for dashboards. Each is ONE series in the brand
 * hue (no categorical palette → nothing to tell apart by colour), with thin
 * marks, rounded data-ends on the baseline, a recessive grid, a hover tooltip on
 * every mark and a "Table" view so values are never colour- or hover-only.
 */

export interface Series { label: string; value: number }

const nice = (max: number) => {
  if (max <= 0) return 1
  const pow = 10 ** Math.floor(Math.log10(max))
  return [1, 2, 2.5, 5, 10].map((m) => m * pow).find((n) => n >= max) ?? max
}

function Frame({ title, subtitle, table, children, className }: { title: string; subtitle?: string; table: ReactNode; children: ReactNode; className?: string }) {
  const [asTable, setAsTable] = useState(false)
  return (
    <figure className={cn('rounded-xl border bg-card p-5 shadow-card', className)}>
      <figcaption className="mb-4 flex items-start justify-between gap-3">
        <div>
          <p className="font-semibold tracking-tight">{title}</p>
          {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
        </div>
        <button type="button" onClick={() => setAsTable((t) => !t)} className="rounded-md px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">
          {asTable ? 'Chart' : 'Table'}
        </button>
      </figcaption>
      {asTable ? table : children}
    </figure>
  )
}

function DataTable({ data, format }: { data: Series[]; format: (v: number) => string }) {
  return (
    <div className="max-h-56 overflow-y-auto text-sm">
      {data.map((d) => (
        <div key={d.label} className="flex justify-between border-b py-1.5 last:border-b-0">
          <span className="text-muted-foreground">{d.label}</span>
          <span className="tabular font-medium">{format(d.value)}</span>
        </div>
      ))}
    </div>
  )
}

/** Vertical bars over time (e.g. 12 months). Labels every few bars to avoid collisions. */
export function BarChart({ title, subtitle, data, format = (v) => v.toLocaleString('en-IN'), height = 180, className }: {
  title: string; subtitle?: string; data: Series[]; format?: (v: number) => string; height?: number; className?: string
}) {
  const [hover, setHover] = useState<number | null>(null)
  const max = nice(Math.max(0, ...data.map((d) => d.value)))
  const ticks = [0, 0.5, 1].map((f) => f * max)
  const every = Math.ceil(data.length / 6)

  return (
    <Frame title={title} subtitle={subtitle} className={className} table={<DataTable data={data} format={format} />}>
      <div className="relative flex gap-2" style={{ height }}>
        {/* y axis */}
        <div className="flex w-12 shrink-0 flex-col-reverse justify-between pb-5 text-right text-[0.625rem] text-muted-foreground">
          {ticks.map((t) => <span key={t} className="tabular leading-none">{format(t)}</span>)}
        </div>
        <div className="relative flex-1">
          {/* grid */}
          <div className="absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between">
            {ticks.map((t) => <div key={t} className="border-t border-dashed border-border/70" />)}
          </div>
          <div className="absolute inset-x-0 top-0 bottom-5 flex items-end gap-[2px]">
            {data.map((d, i) => (
              <div
                key={d.label}
                className="group relative flex h-full flex-1 items-end"
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
                onFocus={() => setHover(i)}
                onBlur={() => setHover(null)}
                tabIndex={0}
                aria-label={`${d.label}: ${format(d.value)}`}
              >
                <div
                  className={cn('mx-auto w-full max-w-7 rounded-t-[4px] bg-brand transition-opacity', hover !== null && hover !== i && 'opacity-40')}
                  style={{ height: `${max ? (d.value / max) * 100 : 0}%`, minHeight: d.value > 0 ? 2 : 0 }}
                />
                {hover === i && (
                  <div className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-md border bg-elevated px-2 py-1 text-xs shadow-raised">
                    <span className="text-muted-foreground">{d.label}</span> <b className="tabular">{format(d.value)}</b>
                  </div>
                )}
              </div>
            ))}
          </div>
          {/* x labels */}
          <div className="absolute inset-x-0 bottom-0 flex gap-[2px] text-[0.625rem] text-muted-foreground">
            {data.map((d, i) => <span key={d.label} className="flex-1 truncate text-center">{i % every === 0 || i === data.length - 1 ? d.label : ''}</span>)}
          </div>
        </div>
      </div>
    </Frame>
  )
}

/** Labelled horizontal bars for magnitudes by category (sorted by the caller). */
export function HBars({ title, subtitle, data, format = (v) => v.toLocaleString('en-IN'), className, onSelect }: {
  title: string; subtitle?: string; data: Series[]; format?: (v: number) => string; className?: string; onSelect?: (label: string) => void
}) {
  const max = Math.max(0, ...data.map((d) => d.value)) || 1
  return (
    <Frame title={title} subtitle={subtitle} className={className} table={<DataTable data={data} format={format} />}>
      <div className="grid gap-2.5">
        {data.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">Nothing yet.</p>}
        {data.map((d) => {
          const Row = onSelect ? 'button' : 'div'
          return (
            <Row key={d.label} type={onSelect ? 'button' : undefined} onClick={onSelect ? () => onSelect(d.label) : undefined}
              className={cn('group grid w-full grid-cols-[minmax(6rem,9rem)_1fr_auto] items-center gap-3 text-left text-sm', onSelect && 'rounded-md hover:bg-muted/50')}
              title={`${d.label}: ${format(d.value)}`}>
              <span className="truncate text-muted-foreground group-hover:text-foreground">{d.label}</span>
              <span className="h-2 overflow-hidden rounded-full bg-muted">
                <span className="block h-full rounded-full bg-brand" style={{ width: `${Math.max(d.value > 0 ? 2 : 0, (d.value / max) * 100)}%` }} />
              </span>
              <span className="tabular min-w-[4.5rem] text-right font-medium">{format(d.value)}</span>
            </Row>
          )
        })}
      </div>
    </Frame>
  )
}

/** Share of a whole as a single meter (e.g. on-time %). */
export function Meter({ label, value, hint }: { label: string; value: number | null; hint?: string }) {
  const v = value === null ? null : Math.max(0, Math.min(100, value))
  return (
    <div>
      <div className="flex items-baseline justify-between">
        <span className="text-sm text-muted-foreground">{label}</span>
        <span className="tabular text-lg font-semibold">{v === null ? '—' : `${v}%`}</span>
      </div>
      <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted" role="meter" aria-valuenow={v ?? undefined} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
        <div className="h-full rounded-full bg-brand" style={{ width: `${v ?? 0}%` }} />
      </div>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

/** Tiny trend line for a stat tile (decorative; the tile states the number). */
export function Sparkline({ values, className }: { values: number[]; className?: string }) {
  if (values.length < 2) return null
  const max = Math.max(...values, 1)
  const pts = values.map((v, i) => `${(i / (values.length - 1)) * 100},${30 - (v / max) * 28}`).join(' ')
  return (
    <svg viewBox="0 0 100 32" preserveAspectRatio="none" className={cn('h-8 w-full', className)} aria-hidden>
      <polyline points={pts} fill="none" stroke="var(--accent)" strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
    </svg>
  )
}
