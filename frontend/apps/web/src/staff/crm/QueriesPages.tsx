import { Alert, api, ApiError, Badge, Button, Card, CardHeader, cn, EmptyState, Select, Table, Td, Textarea, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Check, MessagesSquare } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { PageTitle } from '../layout'
import { fmtDate } from '../metal/shared'

interface Query {
  id: number; ticket_no: string; type: string; subject: string; status: 'open' | 'answered' | 'closed'; created_at: string; messages_count?: number
  retailer: { id: number; shop_name: string; city?: string | null; phone?: string | null }
  order: { id: number; order_no: string; status?: string } | null
  messages?: { id: number; author_type: 'staff' | 'retailer'; author_name: string | null; body: string; created_at: string }[]
}

const tone = (s: string) => (s === 'open' ? 'warning' : s === 'answered' ? 'brand' : 'neutral') as 'warning' | 'brand' | 'neutral'

/** Retailer queries, customisation requests and complaints; plus the follow-ups due. */
export function QueriesPage() {
  const [status, setStatus] = useState('open,answered')
  const q = useQuery({ queryKey: ['queries', status], queryFn: () => api.get<Paginated<Query>>(`/api/v1/queries?status=${status}`) })
  const follow = useQuery({
    queryKey: ['crm', 'follow-ups'],
    queryFn: () => api.get<{ data: { id: number; body: string; follow_up_on: string; retailer: { id: number; shop_name: string; city: string | null }; user: { name: string } | null }[] }>('/api/v1/crm/follow-ups'),
  })
  const today = new Date().toISOString().slice(0, 10)

  return (
    <>
      <PageTitle title="CRM inbox" description="Queries from retailers and your follow-ups">
        <Select className="w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="open,answered">Open & answered</option>
          <option value="open">Waiting for us</option>
          <option value="closed">Closed</option>
          <option value="">All</option>
        </Select>
      </PageTitle>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Queries & requests" />
          {q.data?.data.length === 0 ? <EmptyState title="Nothing here" icon={<MessagesSquare />}>Retailers raise queries from the portal.</EmptyState> : (
            <Table head={['Ticket', 'Retailer', 'Subject', 'Order', 'Status']}>
              {q.data?.data.map((t) => (
                <tr key={t.id}>
                  <Td className="whitespace-nowrap"><Link className="font-mono text-xs font-medium text-brand hover:underline" to={`/staff/queries/${t.id}`}>{t.ticket_no}</Link><div className="text-xs text-muted-foreground">{fmtDate(t.created_at)}</div></Td>
                  <Td><Link className="hover:underline" to={`/staff/retailers/${t.retailer.id}`}>{t.retailer.shop_name}</Link></Td>
                  <Td><Link className="font-medium hover:underline" to={`/staff/queries/${t.id}`}>{t.subject}</Link> <span className="text-xs capitalize text-muted-foreground">· {t.type}</span></Td>
                  <Td>{t.order ? <Link className="text-brand hover:underline" to={`/staff/orders/${t.order.id}`}>{t.order.order_no}</Link> : '—'}</Td>
                  <Td><Badge tone={tone(t.status)} className="capitalize">{t.status === 'open' ? 'Waiting for us' : t.status}</Badge></Td>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card className="h-fit">
          <CardHeader title="Follow-ups" description="This week" />
          {follow.data?.data.length === 0 ? <EmptyState title="No follow-ups due" icon={<CalendarClock />} /> : (
            <div className="divide-y">
              {follow.data?.data.map((n) => (
                <Link key={n.id} to={`/staff/retailers/${n.retailer.id}`} className="block px-5 py-3 hover:bg-muted/40">
                  <p className="flex items-center justify-between text-sm font-medium">{n.retailer.shop_name}
                    <span className={cn('text-xs', n.follow_up_on < today ? 'font-semibold text-danger' : n.follow_up_on === today ? 'text-warning' : 'text-muted-foreground')}>{fmtDate(n.follow_up_on)}</span></p>
                  <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{n.body}</p>
                </Link>
              ))}
            </div>
          )}
        </Card>
      </div>
    </>
  )
}

export function QueryPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['query', id], queryFn: () => api.get<{ data: Query }>(`/api/v1/queries/${id}`) })
  const [body, setBody] = useState('')
  const reply = useMutation({
    mutationFn: (close: boolean) => api.post(`/api/v1/queries/${id}/reply`, { body, close }),
    onSuccess: () => { setBody(''); qc.invalidateQueries({ queryKey: ['query', id] }); qc.invalidateQueries({ queryKey: ['queries'] }) },
  })
  const setStatus = useMutation({
    mutationFn: (status: string) => api.post(`/api/v1/queries/${id}/status`, { status }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['query', id] }); qc.invalidateQueries({ queryKey: ['queries'] }) },
  })
  const t = q.data?.data
  if (!t) return <p className="text-muted-foreground">Loading…</p>

  return (
    <>
      <PageTitle title={t.subject} description={`${t.ticket_no} · ${t.type} · ${t.retailer.shop_name}${t.order ? ` · order ${t.order.order_no}` : ''}`}>
        <Badge tone={tone(t.status)} className="capitalize">{t.status}</Badge>
        {t.status !== 'closed'
          ? <Button variant="outline" onClick={() => setStatus.mutate('closed')}><Check /> Close</Button>
          : <Button variant="outline" onClick={() => setStatus.mutate('open')}>Reopen</Button>}
      </PageTitle>

      <Card className="mx-auto max-w-3xl">
        <div className="grid gap-3 p-5">
          {t.messages?.map((m) => (
            <div key={m.id} className={cn('max-w-[85%] rounded-2xl px-4 py-2.5 text-sm', m.author_type === 'staff' ? 'ml-auto rounded-br-md bg-brand text-brand-foreground' : 'rounded-bl-md bg-muted')}>
              <p className={cn('mb-0.5 text-[0.6875rem]', m.author_type === 'staff' ? 'opacity-80' : 'text-muted-foreground')}>{m.author_name} · {new Date(m.created_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</p>
              <p className="whitespace-pre-line">{m.body}</p>
            </div>
          ))}
        </div>
        <form className="grid gap-2 border-t p-5" onSubmit={(e) => { e.preventDefault(); reply.mutate(false) }}>
          {reply.error instanceof ApiError && <Alert>{reply.error.message}</Alert>}
          <Textarea placeholder="Write a reply… (the retailer gets it in the app and on WhatsApp)" value={body} onChange={(e) => setBody(e.target.value)} required />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" disabled={!body} onClick={() => reply.mutate(true)}>Reply & close</Button>
            <Button type="submit" disabled={!body} loading={reply.isPending}>Send reply</Button>
          </div>
        </form>
      </Card>
    </>
  )
}
