import { Alert, api, ApiError, Badge, Button, Card, cn, EmptyState, Field, Input, Select, Textarea, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageCircleQuestion, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'

interface Query {
  id: number; ticket_no: string; type: string; subject: string; status: string; created_at: string; messages_count?: number
  order: { id: number; order_no: string } | null
  messages?: { id: number; author_type: 'staff' | 'retailer'; author_name: string | null; body: string; created_at: string }[]
}

const TYPES: Record<string, string> = { query: 'Question', customization: 'Customisation request', complaint: 'Complaint' }
const STATUS: Record<string, { label: string; tone: 'warning' | 'brand' | 'neutral' }> = {
  open: { label: 'Waiting for reply', tone: 'warning' }, answered: { label: 'Answered', tone: 'brand' }, closed: { label: 'Closed', tone: 'neutral' },
}

/** Spec §10: raise queries and customisation requests, follow the replies. */
export function QueriesPage() {
  const q = useQuery({ queryKey: ['portal', 'queries'], queryFn: () => api.get<Paginated<Query>>('/api/portal/v1/queries') })

  return (
    <div className="grid gap-3">
      <div className="flex items-center justify-between">
        <h1 className="font-display text-2xl font-semibold">Help & requests</h1>
        <Link to="/portal/queries/new"><Button><Plus /> New request</Button></Link>
      </div>
      {q.data?.data.length === 0 ? (
        <Card><EmptyState title="No requests yet" icon={<MessageCircleQuestion />}>Ask a question, request a customisation or report a problem.</EmptyState></Card>
      ) : q.data?.data.map((t) => (
        <Link key={t.id} to={`/portal/queries/${t.id}`} className="group">
          <Card className="flex items-center justify-between gap-3 p-4 transition-all group-hover:shadow-raised">
            <div className="min-w-0">
              <p className="truncate font-medium">{t.subject}</p>
              <p className="text-sm text-muted-foreground">{t.ticket_no} · {TYPES[t.type] ?? t.type}{t.order && ` · ${t.order.order_no}`}</p>
            </div>
            <Badge tone={STATUS[t.status]?.tone ?? 'neutral'}>{STATUS[t.status]?.label ?? t.status}</Badge>
          </Card>
        </Link>
      ))}
    </div>
  )
}

export function NewQueryPage() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const orders = useQuery({ queryKey: ['portal', 'orders'], queryFn: () => api.get<Paginated<{ id: number; order_no: string }>>('/api/portal/v1/orders') })
  const [f, setF] = useState({ type: params.get('order') ? 'customization' : 'query', subject: '', body: '', order_id: params.get('order') ?? '' })
  const save = useMutation({
    mutationFn: () => api.post<{ data: { id: number } }>('/api/portal/v1/queries', { ...f, order_id: f.order_id ? Number(f.order_id) : null }),
    onSuccess: (r) => navigate(`/portal/queries/${r.data.id}`, { replace: true }),
  })

  return (
    <Card className="mx-auto w-full max-w-xl p-5">
      <h1 className="font-display text-2xl font-semibold">New request</h1>
      <form className="mt-4 grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {save.error instanceof ApiError && <Alert>{Object.values(save.error.errors)[0]?.[0] ?? save.error.message}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Type"><Select value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>{Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
          <Field label="About order (optional)">
            <Select value={f.order_id} onChange={(e) => setF({ ...f, order_id: e.target.value })}>
              <option value="">—</option>
              {orders.data?.data.map((o) => <option key={o.id} value={o.id}>{o.order_no}</option>)}
            </Select>
          </Field>
        </div>
        <Field label="Subject"><Input required value={f.subject} onChange={(e) => setF({ ...f, subject: e.target.value })} /></Field>
        <Field label="Details"><Textarea required className="min-h-32" value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} /></Field>
        <Button type="submit" size="lg" loading={save.isPending}>Send</Button>
      </form>
    </Card>
  )
}

export function QueryPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['portal', 'query', id], queryFn: () => api.get<{ data: Query }>(`/api/portal/v1/queries/${id}`) })
  const [body, setBody] = useState('')
  const reply = useMutation({
    mutationFn: () => api.post(`/api/portal/v1/queries/${id}/reply`, { body }),
    onSuccess: () => { setBody(''); qc.invalidateQueries({ queryKey: ['portal', 'query', id] }); qc.invalidateQueries({ queryKey: ['portal', 'queries'] }) },
  })
  const t = q.data?.data
  if (!t) return <p className="text-muted-foreground">Loading…</p>

  return (
    <Card className="mx-auto w-full max-w-2xl">
      <div className="border-b p-5">
        <div className="flex items-start justify-between gap-3">
          <h1 className="font-display text-xl font-semibold">{t.subject}</h1>
          <Badge tone={STATUS[t.status]?.tone ?? 'neutral'}>{STATUS[t.status]?.label ?? t.status}</Badge>
        </div>
        <p className="text-sm text-muted-foreground">{t.ticket_no} · {TYPES[t.type] ?? t.type}{t.order && <> · <Link className="text-brand hover:underline" to={`/portal/orders/${t.order.id}`}>{t.order.order_no}</Link></>}</p>
      </div>
      <div className="grid gap-3 p-5">
        {t.messages?.map((m) => (
          <div key={m.id} className={cn('max-w-[85%] rounded-2xl px-4 py-2.5 text-sm', m.author_type === 'retailer' ? 'ml-auto rounded-br-md bg-brand text-brand-foreground' : 'rounded-bl-md bg-muted')}>
            <p className={cn('mb-0.5 text-[0.6875rem]', m.author_type === 'retailer' ? 'opacity-80' : 'text-muted-foreground')}>{m.author_name} · {new Date(m.created_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</p>
            <p className="whitespace-pre-line">{m.body}</p>
          </div>
        ))}
      </div>
      {t.status !== 'closed' && (
        <form className="grid gap-2 border-t p-5" onSubmit={(e) => { e.preventDefault(); reply.mutate() }}>
          <Textarea placeholder="Write a reply…" value={body} onChange={(e) => setBody(e.target.value)} required />
          <div className="flex justify-end"><Button type="submit" disabled={!body} loading={reply.isPending}>Send</Button></div>
        </form>
      )}
    </Card>
  )
}
