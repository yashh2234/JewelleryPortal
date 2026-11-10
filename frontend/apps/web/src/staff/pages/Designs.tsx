import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Checkbox, EmptyState, Field, Input, Select, grams, type Paginated, Textarea } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImageOff, Upload } from 'lucide-react'
import { useState } from 'react'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import DesignImport from './DesignImport'
import type { Category, Design, Karat } from '../types'

const empty = {
  code: '', name: '', category_id: '', approx_weight_min: '', approx_weight_max: '', sizes: '',
  making_charge: '', making_type: 'per_gram', karat_ids: [] as number[], is_published: false,
  stone_details: '', specifications: '', min_order_qty: '1', production_days: '', availability: 'made_to_order',
}

export default function Designs() {
  const qc = useQueryClient()
  const can = useCan()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Design | 'new' | null>(null)
  const [importing, setImporting] = useState(false)

  const list = useQuery({
    queryKey: ['designs', search],
    queryFn: () => api.get<Paginated<Design>>(`/api/v1/designs?search=${encodeURIComponent(search)}`),
  })

  return (
    <>
      <PageTitle title="Designs">
        <Input className="w-56" placeholder="Search code or name" value={search} onChange={(e) => setSearch(e.target.value)} />
        {can('designs.manage') && <Button variant="outline" onClick={() => setImporting(!importing)}>Bulk upload</Button>}
        {can('designs.manage') && <Button onClick={() => setEditing('new')}>Add design</Button>}
      </PageTitle>

      {importing && <DesignImport onDone={() => setImporting(false)} />}

      {editing && (
        <DesignForm
          key={editing === 'new' ? 'new' : editing.id}
          designId={editing === 'new' ? null : editing.id}
          onDone={() => { setEditing(null); qc.invalidateQueries({ queryKey: ['designs'] }) }}
        />
      )}

      {list.data?.data.length === 0 ? (
        <Card><EmptyState title="No designs yet">Add designs with photos; published ones appear on the retailer portal.</EmptyState></Card>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {list.data?.data.map((d) => (
            <button key={d.id} className="text-left" onClick={() => can('designs.manage') && setEditing(d)}>
              <Card className="overflow-hidden transition hover:shadow-md">
                <div className="aspect-square bg-muted">
                  {d.images?.[0] ? (
                    <img src={d.images[0].thumb_url ?? d.images[0].url} alt={d.name} className="size-full object-cover" loading="lazy" />
                  ) : (
                    <div className="grid size-full place-items-center text-muted-foreground"><ImageOff className="size-8" /></div>
                  )}
                </div>
                <div className="p-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-mono text-xs text-muted-foreground">{d.code}</span>
                    {d.is_published ? <Badge tone="success">Live</Badge> : <Badge>Draft</Badge>}
                  </div>
                  <p className="mt-1 truncate font-medium">{d.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {d.approx_weight_min ? `${grams(d.approx_weight_min, 2)} – ${grams(d.approx_weight_max, 2)}` : '—'}
                    {d.karats?.length ? ` · ${d.karats.map((k) => k.label).join(', ')}` : ''}
                  </p>
                </div>
              </Card>
            </button>
          ))}
        </div>
      )}
    </>
  )
}

function DesignForm({ designId, onDone }: { designId: number | null; onDone: () => void }) {
  const qc = useQueryClient()
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.get<{ data: Category[] }>('/api/v1/categories') })
  const karats = useQuery({ queryKey: ['karats'], queryFn: () => api.get<{ data: Karat[] }>('/api/v1/karats') })
  const detail = useQuery({
    queryKey: ['design', designId],
    queryFn: () => api.get<{ data: Design }>(`/api/v1/designs/${designId}`),
    enabled: designId !== null,
  })

  const d = detail.data?.data
  const [form, setForm] = useState(empty)
  const [loadedId, setLoadedId] = useState<number | null>(null)
  if (d && loadedId !== d.id) {
    setLoadedId(d.id)
    setForm({
      code: d.code, name: d.name, category_id: d.category_id ? String(d.category_id) : '',
      approx_weight_min: d.approx_weight_min ?? '', approx_weight_max: d.approx_weight_max ?? '',
      sizes: (d.sizes ?? []).join(', '), making_charge: d.making_charge ?? '', making_type: d.making_type ?? 'per_gram',
      karat_ids: d.karats?.map((k) => k.id) ?? [], is_published: d.is_published,
      stone_details: d.stone_details ?? '', specifications: d.specifications ?? '', min_order_qty: String(d.min_order_qty ?? 1),
      production_days: d.production_days ? String(d.production_days) : '', availability: d.availability ?? 'made_to_order',
    })
  }

  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form,
        category_id: form.category_id ? Number(form.category_id) : null,
        approx_weight_min: form.approx_weight_min || null,
        approx_weight_max: form.approx_weight_max || null,
        making_charge: form.making_charge || null,
        making_type: form.making_charge ? form.making_type : null,
        sizes: form.sizes.split(',').map((s) => s.trim()).filter(Boolean),
        stone_details: form.stone_details || null,
        specifications: form.specifications || null,
        min_order_qty: Number(form.min_order_qty) || 1,
        production_days: form.production_days ? Number(form.production_days) : null,
      }
      return designId ? api.patch(`/api/v1/designs/${designId}`, body) : api.post('/api/v1/designs', body)
    },
    onSuccess: () => onDone(),
  })

  const upload = useMutation({
    mutationFn: (files: FileList) => {
      const body = new FormData()
      Array.from(files).forEach((f) => body.append('images[]', f))
      return api.post(`/api/v1/designs/${designId}/images`, body)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['design', designId] }),
  })

  const removeImage = useMutation({
    mutationFn: (imageId: number) => api.delete(`/api/v1/designs/${designId}/images/${imageId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['design', designId] }),
  })

  const remove = useMutation({
    mutationFn: () => api.delete(`/api/v1/designs/${designId}`),
    onSuccess: () => onDone(),
  })

  const error = save.error instanceof ApiError ? save.error : null
  const uploadError = upload.error instanceof ApiError ? upload.error : null
  const set = (k: keyof typeof empty) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })

  return (
    <Card className="mb-4">
      <CardHeader
        title={designId ? `Edit ${d?.code ?? ''}` : 'New design'}
        actions={<Button variant="ghost" size="sm" onClick={onDone}>Close</Button>}
      />
      <form className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && !Object.keys(error.errors).length && <div className="sm:col-span-full"><Alert>{error.message}</Alert></div>}
        <Field label="Design code" error={error?.field('code')}><Input required value={form.code} onChange={set('code')} /></Field>
        <Field label="Name" error={error?.field('name')} className="sm:col-span-2"><Input required value={form.name} onChange={set('name')} /></Field>
        <Field label="Category">
          <Select value={form.category_id} onChange={set('category_id')}>
            <option value="">—</option>
            {categories.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
        </Field>
        <Field label="Approx. weight min (g)" error={error?.field('approx_weight_min')}><Input inputMode="decimal" value={form.approx_weight_min} onChange={set('approx_weight_min')} /></Field>
        <Field label="Approx. weight max (g)" error={error?.field('approx_weight_max')}><Input inputMode="decimal" value={form.approx_weight_max} onChange={set('approx_weight_max')} /></Field>
        <Field label="Making charge" error={error?.field('making_charge')}><Input inputMode="decimal" value={form.making_charge} onChange={set('making_charge')} /></Field>
        <Field label="Charged">
          <Select value={form.making_type} onChange={set('making_type')}>
            <option value="per_gram">₹ per gram</option>
            <option value="per_piece">₹ per piece</option>
            <option value="percent">% of metal value</option>
          </Select>
        </Field>
        <Field label="Sizes" hint="Comma separated, e.g. 2.4, 2.6, 2.8" className="sm:col-span-2"><Input value={form.sizes} onChange={set('sizes')} /></Field>
        <Field label="Minimum order qty" error={error?.field('min_order_qty')}><Input inputMode="numeric" value={form.min_order_qty} onChange={set('min_order_qty')} /></Field>
        <Field label="Production time (days)" error={error?.field('production_days')}><Input inputMode="numeric" value={form.production_days} onChange={set('production_days')} /></Field>
        <Field label="Availability">
          <Select value={form.availability} onChange={set('availability')}>
            <option value="made_to_order">Made to order</option>
            <option value="in_stock">Ready stock</option>
            <option value="unavailable">Not available</option>
          </Select>
        </Field>
        <Field label="Stone details" hint="e.g. Kundan, 12 CZ 2 mm"><Input value={form.stone_details} onChange={set('stone_details')} /></Field>
        <Field label="Design specifications" className="sm:col-span-full"><Textarea value={form.specifications} placeholder="Finish, clasp, hollow/solid, enamel…" onChange={set('specifications')} /></Field>
        <div className="sm:col-span-2">
          <p className="mb-1 text-sm font-medium">Karats available</p>
          <div className="flex flex-wrap gap-3">
            {karats.data?.data.filter((k) => k.is_active).map((k) => (
              <Checkbox key={k.id} label={k.label} checked={form.karat_ids.includes(k.id)}
                onChange={(e) => setForm({ ...form, karat_ids: e.target.checked ? [...form.karat_ids, k.id] : form.karat_ids.filter((x) => x !== k.id) })} />
            ))}
          </div>
        </div>
        <Checkbox label="Published on retailer portal" checked={form.is_published} onChange={(e) => setForm({ ...form, is_published: e.target.checked })} />
        <div className="flex flex-wrap gap-2 sm:col-span-full">
          <Button type="submit" loading={save.isPending}>{designId ? 'Save' : 'Create design'}</Button>
          {designId && (
            <Button type="button" variant="ghost" className="text-danger" loading={remove.isPending}
              onClick={() => confirm('Delete this design and its photos?') && remove.mutate()}>Delete</Button>
          )}
        </div>
      </form>

      {designId ? (
        <div className="border-t p-4">
          <p className="mb-2 text-sm font-medium">Photos</p>
          {uploadError && <div className="mb-2"><Alert>{uploadError.message}</Alert></div>}
          <div className="flex flex-wrap gap-3">
            {d?.images?.map((img) => (
              <div key={img.id} className="relative">
                <img src={img.thumb_url ?? img.url} alt="" className="size-24 rounded border object-cover" />
                <button type="button" className="absolute -right-2 -top-2 rounded-full bg-card px-1.5 text-xs shadow" onClick={() => removeImage.mutate(img.id)} aria-label="Remove photo">✕</button>
              </div>
            ))}
            <label className="grid size-24 cursor-pointer place-items-center rounded border border-dashed text-muted-foreground hover:bg-muted">
              {upload.isPending ? <span className="text-xs">Uploading…</span> : <Upload className="size-6" />}
              <input type="file" accept="image/*" multiple className="hidden" onChange={(e) => e.target.files?.length && upload.mutate(e.target.files)} />
            </label>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Photos are resized and saved as WebP on upload.</p>
        </div>
      ) : (
        <p className="border-t p-4 text-sm text-muted-foreground">Save the design first, then add photos.</p>
      )}
    </Card>
  )
}
