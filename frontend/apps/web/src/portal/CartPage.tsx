import { Alert, api, ApiError, Button, Card, Field, Input, Textarea, today } from '@b2b/ui'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ImageOff, Minus, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useCart } from './cart'
import { inr, type CartSummary } from './types'

export function CartPage() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const cart = useCart()
  const [due, setDue] = useState('')
  const [remarks, setRemarks] = useState('')

  const set = (r: CartSummary) => qc.setQueryData(['portal', 'cart'], r)
  const update = useMutation({ mutationFn: ({ id, qty }: { id: number; qty: number }) => api.patch<CartSummary>(`/api/portal/v1/cart/${id}`, { qty }), onSuccess: set })
  const remove = useMutation({ mutationFn: (id: number) => api.delete<CartSummary>(`/api/portal/v1/cart/${id}`), onSuccess: set })
  const place = useMutation({
    mutationFn: () => api.post<{ data: { id: number; order_no: string } }>('/api/portal/v1/orders', { due_date: due || null, remarks: remarks || null }),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ['portal'] })
      navigate(`/portal/orders/${r.data.id}`)
    },
  })
  const err = [place.error, update.error, remove.error].find((e) => e instanceof ApiError) as ApiError | undefined
  const c = cart.data

  if (c && c.data.length === 0) {
    return (
      <div className="py-16 text-center">
        <p className="text-lg font-medium">Your cart is empty</p>
        <Link to="/portal"><Button className="mt-4">Browse the catalogue</Button></Link>
      </div>
    )
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
      <div className="grid gap-2">
        {err && <Alert>{err.message}</Alert>}
        {c?.data.map((l) => (
          <Card key={l.id} className="flex gap-3 p-3">
            <div className="size-20 shrink-0 overflow-hidden rounded bg-muted">
              {l.design.photo ? <img src={l.design.photo} alt="" className="size-full object-cover" /> : <div className="grid size-full place-items-center text-muted-foreground"><ImageOff /></div>}
            </div>
            <div className="min-w-0 flex-1">
              <p className="font-medium">{l.design.name} <span className="font-mono text-xs text-muted-foreground">{l.design.code}</span></p>
              <p className="text-sm text-muted-foreground">{l.karat.label}{l.size && ` · size ${l.size}`}{l.target_wt_min && ` · ${Number(l.target_wt_min)}–${Number(l.target_wt_max)} g`}</p>
              {l.remarks && <p className="truncate text-xs text-muted-foreground">“{l.remarks}”</p>}
              {(() => {
                const moq = l.design.min_order_qty ?? 1
                const total = c.data.filter((x) => x.design.id === l.design.id).reduce((n, x) => n + x.qty, 0)
                return total < moq ? <p className="text-xs font-medium text-warning">Minimum {moq} pieces of this design (now {total}).</p> : null
              })()}
              <div className="mt-2 flex items-center gap-2">
                <Button size="icon" variant="outline" className="size-8" disabled={l.qty <= 1} onClick={() => update.mutate({ id: l.id, qty: l.qty - 1 })} aria-label="Less"><Minus /></Button>
                <span className="w-8 text-center font-medium">{l.qty}</span>
                <Button size="icon" variant="outline" className="size-8" onClick={() => update.mutate({ id: l.id, qty: l.qty + 1 })} aria-label="More"><Plus /></Button>
                {l.est_value && <span className="ml-auto text-sm font-medium">≈ {inr(l.est_value)}</span>}
                <Button size="icon" variant="ghost" onClick={() => remove.mutate(l.id)} aria-label="Remove"><Trash2 /></Button>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {c && (
        <Card className="h-fit p-4">
          <h2 className="font-semibold">Order summary</h2>
          <div className="mt-3 grid gap-1 text-sm">
            <div className="flex justify-between"><span>Pieces</span><span className="tabular">{c.totals.pieces}</span></div>
            <div className="flex justify-between"><span>Est. fine gold</span><span className="tabular">{c.totals.est_fine} g</span></div>
            <div className="flex justify-between"><span>Est. making</span><span className="tabular">{inr(c.totals.est_making)}</span></div>
            <div className="flex justify-between font-semibold"><span>Est. value (before GST)</span><span className="tabular">{inr(c.totals.est_value)}</span></div>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Estimates use today’s rate and the middle of each weight range. Your invoice uses the actual weights.</p>
          <div className="mt-4 grid gap-3">
            <Field label="Needed by (optional)"><Input type="date" min={today()} value={due} onChange={(e) => setDue(e.target.value)} /></Field>
            <Field label="Note for the manufacturer"><Textarea value={remarks} onChange={(e) => setRemarks(e.target.value)} /></Field>
            <Button size="lg" onClick={() => place.mutate()} loading={place.isPending}>Place order</Button>
          </div>
        </Card>
      )}
    </div>
  )
}
