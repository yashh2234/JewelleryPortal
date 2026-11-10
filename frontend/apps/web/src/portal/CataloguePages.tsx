import { Alert, api, ApiError, Badge, Button, Card, cn, Field, Input, Select, Textarea, WeightInput, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Clock, Gem, Heart, ImageOff, Minus, Package, Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { AVAILABILITY, inr, type CartSummary, type PortalDesign } from './types'

interface Filters {
  categories: { id: number; name: string }[]
  collections: { id: number; name: string; slug: string }[]
  karats: { id: number; label: string }[]
}

export function CataloguePage() {
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [collection, setCollection] = useState('')
  const [karat, setKarat] = useState('')
  const [wtMin, setWtMin] = useState('')
  const [wtMax, setWtMax] = useState('')
  const [availability, setAvailability] = useState('')
  const [saved, setSaved] = useState(false)
  const [page, setPage] = useState(1)

  const filters = useQuery({ queryKey: ['portal', 'filters'], queryFn: () => api.get<Filters>('/api/portal/v1/catalogue/filters'), meta: { persist: true } })
  const qs = new URLSearchParams({ search, category_id: category, collection, karat_id: karat, wt_min: wtMin, wt_max: wtMax, availability, wishlist: saved ? '1' : '', page: String(page) })
  ;[...qs.entries()].forEach(([k, v]) => !v && qs.delete(k))
  const designs = useQuery({ queryKey: ['portal', 'designs', qs.toString()], queryFn: () => api.get<Paginated<PortalDesign>>(`/api/portal/v1/designs?${qs}`), meta: { persist: true } })

  const chip = (active: boolean) => `whitespace-nowrap rounded-full border px-3 py-1 text-sm ${active ? 'border-brand bg-brand text-brand-foreground' : 'bg-card'}`

  return (
    <div className="grid gap-3">
      <div className="relative">
        <Search className="absolute left-3 top-3 size-4 text-muted-foreground" />
        <Input className="h-11 pl-9" placeholder="Search design code or name" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
      </div>
      {filters.data && (
        <>
          {filters.data.collections.length > 0 && (
            <div className="-mx-4 flex gap-2 overflow-x-auto px-4">
              <button className={chip(saved)} onClick={() => { setSaved(!saved); setPage(1) }}><Heart className={cn('mr-1 inline size-3.5', saved && 'fill-current')} />Wishlist</button>
              <button className={chip(!collection)} onClick={() => setCollection('')}>All</button>
              {filters.data.collections.map((c) => <button key={c.id} className={chip(collection === c.slug)} onClick={() => { setCollection(c.slug); setPage(1) }}>{c.name}</button>)}
            </div>
          )}
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <Select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1) }}>
              <option value="">All categories</option>
              {filters.data.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
            <Select value={karat} onChange={(e) => { setKarat(e.target.value); setPage(1) }}>
              <option value="">Any karat</option>
              {filters.data.karats.map((k) => <option key={k.id} value={k.id}>{k.label}</option>)}
            </Select>
            <Input inputMode="decimal" placeholder="Min weight g" value={wtMin} onChange={(e) => setWtMin(e.target.value)} />
            <Input inputMode="decimal" placeholder="Max weight g" value={wtMax} onChange={(e) => setWtMax(e.target.value)} />
            <Select value={availability} onChange={(e) => { setAvailability(e.target.value); setPage(1) }}>
              <option value="">Any availability</option>
              <option value="in_stock">Ready stock</option>
              <option value="made_to_order">Made to order</option>
            </Select>
          </div>
        </>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        {designs.data?.data.map((d) => {
          const est = d.karats[0]?.estimate
          return (
            <Link key={d.id} to={`/portal/designs/${encodeURIComponent(d.code)}`} className="group">
              <Card className="overflow-hidden transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-raised">
                <div className="relative aspect-square bg-muted">
                  <WishButton design={d} className="absolute right-2 top-2" />
                  {d.availability === 'in_stock' && <Badge tone="success" className="absolute left-2 top-2 bg-card/90 backdrop-blur">Ready stock</Badge>}
                  {d.images[0] ? <img src={d.images[0].thumb_url ?? d.images[0].url} alt={d.name} loading="lazy" className="size-full object-cover" />
                    : <div className="grid size-full place-items-center text-muted-foreground"><ImageOff className="size-8" /></div>}
                </div>
                <div className="p-2.5">
                  <p className="font-mono text-xs text-muted-foreground">{d.code}</p>
                  <p className="truncate font-medium">{d.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {d.approx_weight_min ? `${Number(d.approx_weight_min)}–${Number(d.approx_weight_max)} g` : ''} · {d.karats.map((k) => k.label).join('/')}
                  </p>
                  {est && <p className="mt-1 text-sm font-semibold text-brand">≈ {inr(est.total)}</p>}
                  {d.min_order_qty > 1 && <p className="text-[0.6875rem] text-muted-foreground">Min. {d.min_order_qty} pcs</p>}
                </div>
              </Card>
            </Link>
          )
        })}
      </div>
      {designs.data?.data.length === 0 && <p className="py-10 text-center text-muted-foreground">No designs match.</p>}
      {designs.data && designs.data.last_page > 1 && (
        <div className="flex justify-center gap-2">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
          <span className="py-2 text-sm">{page} / {designs.data.last_page}</span>
          <Button variant="outline" disabled={page >= designs.data.last_page} onClick={() => setPage(page + 1)}>Next</Button>
        </div>
      )}
    </div>
  )
}

/** Heart toggle: saves a design to the shop's wishlist. */
function WishButton({ design, className }: { design: PortalDesign; className?: string }) {
  const qc = useQueryClient()
  const [on, setOn] = useState(!!design.wishlisted)
  const m = useMutation({
    mutationFn: () => (on ? api.delete(`/api/portal/v1/wishlist/${design.id}`) : api.post(`/api/portal/v1/wishlist/${design.id}`)),
    onMutate: () => setOn(!on),
    onError: () => setOn(on),
    onSettled: () => qc.invalidateQueries({ queryKey: ['portal', 'designs'] }),
  })
  return (
    <button type="button" aria-label={on ? 'Remove from wishlist' : 'Add to wishlist'} aria-pressed={on}
      onClick={(e) => { e.preventDefault(); e.stopPropagation(); m.mutate() }}
      className={cn('z-10 grid size-9 place-items-center rounded-full bg-card/90 shadow-xs backdrop-blur transition hover:scale-105', on ? 'text-danger' : 'text-muted-foreground', className)}>
      <Heart className={cn('size-4', on && 'fill-current')} />
    </button>
  )
}

/** Design detail: photos, karat, size, quantity, weight preference, instructions → cart. */
export function DesignPage() {
  const { code } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['portal', 'design', code], queryFn: () => api.get<{ data: PortalDesign }>(`/api/portal/v1/designs/${encodeURIComponent(code ?? '')}`), meta: { persist: true } })
  const d = q.data?.data
  const [photo, setPhoto] = useState(0)
  const [karatId, setKaratId] = useState<number | null>(null)
  const [size, setSize] = useState('')
  const [qty, setQty] = useState(0)
  const [wtMin, setWtMin] = useState('')
  const [wtMax, setWtMax] = useState('')
  const [remarks, setRemarks] = useState('')

  const add = useMutation({
    mutationFn: () => api.post<CartSummary>('/api/portal/v1/cart', {
      design_id: d!.id, karat_id: karatId ?? d!.karats[0].id, size: size || null, qty: qty || d!.min_order_qty || 1,
      target_wt_min: wtMin || null, target_wt_max: wtMax || null, remarks: remarks || null,
    }),
    onSuccess: (r) => { qc.setQueryData(['portal', 'cart'], r); setQty(0); setRemarks('') },
  })

  if (q.error instanceof ApiError && q.error.status === 404) return <Alert>This design is not available.</Alert>
  if (!d) return <p className="text-muted-foreground">Loading…</p>
  const k = d.karats.find((x) => x.id === karatId) ?? d.karats[0]
  const moq = d.min_order_qty || 1
  const pieces = qty || moq
  const unavailable = d.availability === 'unavailable'
  const err = add.error instanceof ApiError ? add.error : null

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <div>
        <button className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground" onClick={() => navigate(-1)}><ArrowLeft className="size-4" /> Back</button>
        <div className="relative aspect-square overflow-hidden rounded-xl bg-muted">
          <WishButton design={d} className="absolute right-3 top-3" />
          {d.images[photo] ? <img src={d.images[photo].url} alt={d.name} className="size-full object-cover" /> : <div className="grid size-full place-items-center text-muted-foreground"><ImageOff className="size-10" /></div>}
        </div>
        {d.images.length > 1 && (
          <div className="mt-2 flex gap-2 overflow-x-auto">
            {d.images.map((im, i) => <button key={im.id} onClick={() => setPhoto(i)}><img src={im.thumb_url ?? im.url} alt="" className={`size-16 rounded object-cover ${i === photo ? 'ring-2 ring-brand' : ''}`} /></button>)}
          </div>
        )}
      </div>

      <div className="grid content-start gap-3">
        <div>
          <p className="font-mono text-sm text-muted-foreground">{d.code}{d.category && ` · ${d.category}`}</p>
          <h1 className="font-display text-[1.75rem] font-semibold leading-tight">{d.name}</h1>
          {d.approx_weight_min && <p className="text-muted-foreground">Approx. {Number(d.approx_weight_min)}–{Number(d.approx_weight_max)} g per piece</p>}
          {d.collections.length > 0 && <p className="text-sm text-brand">{d.collections.join(' · ')}</p>}
          {d.description && <p className="mt-2 text-sm">{d.description}</p>}
        </div>

        <div className="grid grid-cols-3 gap-2 text-sm">
          <Fact icon={<Package />} label="Availability" value={AVAILABILITY[d.availability] ?? d.availability} warn={unavailable} />
          <Fact icon={<Clock />} label="Production" value={d.production_days ? `${d.production_days} days` : '—'} />
          <Fact icon={<Plus />} label="Minimum order" value={`${moq} pc${moq > 1 ? 's' : ''}`} />
        </div>
        {(d.stone_details || d.specifications) && (
          <Card className="grid gap-1.5 p-3 text-sm">
            {d.stone_details && <p className="flex gap-2"><Gem className="mt-0.5 size-4 shrink-0 text-brand" /><span><b className="font-medium">Stones:</b> {d.stone_details}</span></p>}
            {d.specifications && <p className="whitespace-pre-line text-muted-foreground">{d.specifications}</p>}
          </Card>
        )}

        {k?.estimate && (
          <Card className="p-3 text-sm">
            <p className="text-lg font-semibold">≈ {inr(k.estimate.total)} <span className="text-sm font-normal text-muted-foreground">per piece + GST</span></p>
            <p className="text-muted-foreground">at {k.estimate.weight} g: metal {inr(k.estimate.metal)} + making {inr(k.estimate.making)} · {k.estimate.fine} g fine</p>
            <p className="text-xs text-muted-foreground">Final bill uses the actual weight of each piece.</p>
          </Card>
        )}

        {err && <Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert>}
        {add.isSuccess && <Alert tone="success">Added to cart. <Link className="font-medium underline" to="/portal/cart">View cart</Link></Alert>}

        <div className="flex flex-wrap gap-2">
          {d.karats.map((x) => (
            <button key={x.id} onClick={() => setKaratId(x.id)} className={`rounded-md border px-4 py-2 ${k?.id === x.id ? 'border-brand bg-brand text-brand-foreground' : ''}`}>{x.label}</button>
          ))}
        </div>
        {d.sizes.length > 0 && (
          <Field label="Size">
            <div className="flex flex-wrap gap-2">
              {d.sizes.map((s) => <button key={s} onClick={() => setSize(s)} className={`min-w-12 rounded-md border px-3 py-2 ${size === s ? 'border-brand bg-brand/10 font-medium text-brand' : ''}`}>{s}</button>)}
            </div>
          </Field>
        )}
        <Field label="Quantity">
          <div className="flex items-center gap-2">
            <Button size="icon" variant="outline" onClick={() => setQty(Math.max(1, pieces - 1))} aria-label="Less"><Minus /></Button>
            <Input className="w-20 text-center text-lg" inputMode="numeric" value={pieces} onChange={(e) => setQty(Math.max(1, Number(e.target.value.replace(/\D/g, '')) || 1))} />
            <Button size="icon" variant="outline" onClick={() => setQty(pieces + 1)} aria-label="More"><Plus /></Button>
            {moq > 1 && <span className="text-xs text-muted-foreground">Minimum {moq} per order (all sizes together)</span>}
          </div>
        </Field>
        <div className="grid grid-cols-2 gap-2">
          <Field label="Weight from g (optional)"><WeightInput scale={false} value={wtMin} onValueChange={setWtMin} /></Field>
          <Field label="to g"><WeightInput scale={false} value={wtMax} onValueChange={setWtMax} /></Field>
        </div>
        <Field label="Customisation & special instructions"><Textarea value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Stone colour, finish, engraving, hook type…" /></Field>
        <Button size="lg" disabled={unavailable || (!!d.sizes.length && !size)} onClick={() => add.mutate()} loading={add.isPending}>
          {unavailable ? 'Not available right now' : `Add ${pieces} to cart`}
        </Button>
        <p className="text-center text-xs text-muted-foreground">You can add a reference photo to each design after placing the order.</p>
        {!!d.sizes.length && !size && <p className="text-center text-xs text-muted-foreground">Choose a size.</p>}
      </div>
    </div>
  )
}

function Fact({ icon, label, value, warn }: { icon: React.ReactNode; label: string; value: string; warn?: boolean }) {
  return (
    <div className="rounded-lg border bg-card px-3 py-2">
      <p className="flex items-center gap-1 text-[0.6875rem] text-muted-foreground [&_svg]:size-3">{icon}{label}</p>
      <p className={cn('mt-0.5 font-medium', warn && 'text-danger')}>{value}</p>
    </div>
  )
}
