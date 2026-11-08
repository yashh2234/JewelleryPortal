import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Format a gram value that arrives from the API as a decimal string ("91.600").
 * Never parse weights into floats for arithmetic; this is display only.
 * Ledgers show 3 decimals, the retailer portal 2 (per spec).
 */
export function grams(value: string | null | undefined, decimals: 2 | 3 = 3): string {
  if (value === null || value === undefined || value === '') return '—'
  const [int, frac = ''] = value.replace(/^-/, '').split('.')
  const sign = value.startsWith('-') ? '-' : ''
  const intFmt = Number(int).toLocaleString('en-IN')
  return `${sign}${intFmt}.${(frac + '000').slice(0, decimals)} g`
}

export function rupees(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return Number(value).toLocaleString('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 })
}

/**
 * Fine weight preview in the browser (gross × purity ÷ 100), done with integer
 * milligrams so the preview matches the server's bcmath result. Display only.
 */
export function previewFine(gross: string, purity: string, stone = '0', findings = '0'): string {
  const mg = (v: string) => Math.round(Number(v || '0') * 1000)
  const net = mg(gross) - mg(stone) - mg(findings)
  if (!Number.isFinite(net) || net < 0) return '0.000'
  const p = Math.round(Number(purity || '0') * 1000) // purity in 1/1000 %
  // net(mg) × p / 100000 → mg, rounded half up
  const fineMg = Math.floor((net * p) / 100000 + 0.5)
  return (fineMg / 1000).toFixed(3)
}

export function today(): string {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 10)
}
