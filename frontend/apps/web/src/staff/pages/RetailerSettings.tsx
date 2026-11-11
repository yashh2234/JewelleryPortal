import { Alert, api, ApiError, Button, Card, CardHeader, Checkbox, Field, Input, Select } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'

interface Detail {
  id: number
  shop_name: string
  credit_limit: string
  making_slab_id: number | null
  metal_account_enabled: boolean
  salesperson_id: number | null
  payment_terms_days: number | null
  collections: { id: number; name: string }[]
}

/** Per-retailer portal settings: what they see, how they're charged, credit and metal account. */
export default function RetailerSettings({ id, onDone }: { id: number; onDone: () => void }) {
  const qc = useQueryClient()
  const detail = useQuery({ queryKey: ['retailer', id], queryFn: () => api.get<{ data: Detail }>(`/api/v1/retailers/${id}`) })
  const slabs = useQuery({ queryKey: ['making-slabs'], queryFn: () => api.get<{ data: { id: number; name: string; basis: string; rate: string }[] }>('/api/v1/making-slabs') })
  const staff = useQuery({ queryKey: ['staff-directory'], queryFn: () => api.get<{ data: { id: number; name: string }[] }>('/api/v1/staff-directory') })
  const collections = useQuery({ queryKey: ['collections'], queryFn: () => api.get<{ data: { id: number; name: string }[] }>('/api/v1/collections') })
  const d = detail.data?.data

  const [form, setForm] = useState<{ credit_limit: string; making_slab_id: string; metal_account_enabled: boolean; collection_ids: number[]; salesperson_id: string; payment_terms_days: string } | null>(null)
  if (d && !form) {
    setForm({
      credit_limit: d.credit_limit, making_slab_id: d.making_slab_id ? String(d.making_slab_id) : '',
      metal_account_enabled: d.metal_account_enabled,
      collection_ids: d.collections.map((c) => c.id),
      salesperson_id: d.salesperson_id ? String(d.salesperson_id) : '', payment_terms_days: d.payment_terms_days ? String(d.payment_terms_days) : '',
    })
  }

  const save = useMutation({
    mutationFn: async () => {
      await api.patch(`/api/v1/retailers/${id}`, {
        credit_limit: form!.credit_limit || '0', making_slab_id: form!.making_slab_id ? Number(form!.making_slab_id) : null,
        metal_account_enabled: form!.metal_account_enabled,
        salesperson_id: form!.salesperson_id ? Number(form!.salesperson_id) : null,
        payment_terms_days: form!.payment_terms_days ? Number(form!.payment_terms_days) : null,
      })
      await api.put(`/api/v1/retailers/${id}/collections`, { collection_ids: form!.collection_ids })
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['retailers'] }); qc.invalidateQueries({ queryKey: ['retailer', id] }); onDone() },
  })
  const err = save.error instanceof ApiError ? save.error : null

  if (!d || !form) return null

  return (
    <Card className="mb-4">
      <CardHeader title={`Settings · ${d.shop_name}`} actions={<Button size="sm" variant="ghost" onClick={onDone}>Close</Button>} />
      <div className="grid gap-4 p-4 sm:grid-cols-2">
        {err && <div className="sm:col-span-2"><Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert></div>}
        <Field label="Credit limit ₹" hint="0 = no limit; sales sees a warning on orders above it"><Input inputMode="decimal" value={form.credit_limit} onChange={(e) => setForm({ ...form, credit_limit: e.target.value })} /></Field>
        <Field label="Making-charge slab" hint="Blank = the design's own making charge">
          <Select value={form.making_slab_id} onChange={(e) => setForm({ ...form, making_slab_id: e.target.value })}>
            <option value="">Design default</option>
            {slabs.data?.data.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.basis === 'percent' ? `${s.rate}%` : `₹${s.rate} ${s.basis.replace('_', ' ')}`})</option>)}
          </Select>
        </Field>
        <Field label="Salesperson" hint="Gets this retailer's new orders and queries">
          <Select value={form.salesperson_id} onChange={(e) => setForm({ ...form, salesperson_id: e.target.value })}>
            <option value="">—</option>
            {staff.data?.data.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select>
        </Field>
        <Field label="Payment terms (days)" hint="Credit period after invoice"><Input inputMode="numeric" value={form.payment_terms_days} onChange={(e) => setForm({ ...form, payment_terms_days: e.target.value.replace(/\D/g, '') })} /></Field>
        <Checkbox label="Metal account (can deposit gold and buy against it)" checked={form.metal_account_enabled} onChange={(e) => setForm({ ...form, metal_account_enabled: e.target.checked })} />
        <div className="sm:col-span-2">
          <p className="mb-1 text-sm font-medium">Catalogue collections this retailer sees</p>
          <p className="mb-2 text-xs text-muted-foreground">None ticked = all published designs.</p>
          <div className="flex flex-wrap gap-3">
            {collections.data?.data.map((c) => (
              <Checkbox key={c.id} label={c.name} checked={form.collection_ids.includes(c.id)}
                onChange={(e) => setForm({ ...form, collection_ids: e.target.checked ? [...form.collection_ids, c.id] : form.collection_ids.filter((x) => x !== c.id) })} />
            ))}
          </div>
        </div>
        <div><Button onClick={() => save.mutate()} loading={save.isPending}>Save settings</Button></div>
      </div>
    </Card>
  )
}
