import { api, Select, type Paginated } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'

export interface Party {
  id: number
  type: 'retailer' | 'supplier' | 'kaarigar' | 'refiner' | 'ahc' | 'other'
  name: string
  gstin: string | null
  state_code: string | null
  phone: string | null
  balance?: { money: string; fine: string }
}

export interface Account { id: number; code: string; name: string; nature: string; group: string; system_key: string | null; is_active: boolean }

export interface Voucher {
  id: number
  voucher_no: string
  type: string
  date: string
  narration: string | null
  status: 'posted' | 'cancelled'
  amount: string
  fine_wt: string
  source_type: string | null
  party: { id: number; name: string; type: string } | null
  meta: Record<string, unknown> | null
}

export const VOUCHER_LABEL: Record<string, string> = {
  sales: 'Sales invoice', purchase: 'Purchase', receipt: 'Receipt', payment: 'Payment',
  metal_receipt: 'Metal receipt', metal_payment: 'Metal payment', rate_cut: 'Rate-cut', labour: 'Labour bill',
  journal: 'Journal', contra: 'Contra', credit_note: 'Credit note', debit_note: 'Debit note', reversal: 'Reversal',
}

export function useParties(type?: string) {
  return useQuery({
    queryKey: ['parties', type ?? 'all'],
    queryFn: () => api.get<Paginated<Party>>(`/api/v1/parties?per_page=500${type ? `&type=${type}` : ''}`),
    select: (r) => r.data,
  })
}

export function useAccounts() {
  return useQuery({ queryKey: ['accounts'], queryFn: () => api.get<{ data: Account[] }>('/api/v1/accounts'), select: (r) => r.data })
}

export function PartySelect({ value, onChange, type, required }: { value: string; onChange: (v: string) => void; type?: string; required?: boolean }) {
  const parties = useParties(type)
  return (
    <Select required={required} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">Choose…</option>
      {parties.data?.map((p) => <option key={p.id} value={p.id}>{p.name} · {p.type}</option>)}
    </Select>
  )
}

/** "₹12,345.00 Dr" style: positive = they owe us (Dr), negative = we owe them (Cr). */
export function drCr(money: string): string {
  const n = Number(money)
  if (n === 0) return '₹0'
  return `₹${Math.abs(n).toLocaleString('en-IN', { minimumFractionDigits: 2 })} ${n > 0 ? 'Dr' : 'Cr'}`
}

export function metalDrCr(fine: string): string {
  const n = Number(fine)
  if (n === 0) return '0 g'
  return `${Math.abs(n).toFixed(3)} g ${n > 0 ? 'Dr' : 'Cr'}`
}
