import { Alert, api, ApiError, Badge, Button, Card, CardHeader, EmptyState, Field, Input, Select, Table, Td, rupees, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { PageTitle } from '../layout'
import RetailerSettings from './RetailerSettings'
import type { Retailer } from '../types'

const statusTone = { pending: 'warning', active: 'success', suspended: 'danger', rejected: 'neutral' } as const
const empty = { shop_name: '', city: '', gstin: '', phone: '', login_name: '', login_email: '', login_password: '', credit_limit: '0' }

export default function Retailers() {
  const qc = useQueryClient()
  const [status, setStatus] = useState('')
  const [adding, setAdding] = useState(false)
  const [settings, setSettings] = useState<number | null>(null)
  const [form, setForm] = useState(empty)

  const list = useQuery({
    queryKey: ['retailers', status],
    queryFn: () => api.get<Paginated<Retailer>>(`/api/v1/retailers?status=${status}`),
  })

  const action = useMutation({
    mutationFn: ({ id, act }: { id: number; act: 'approve' | 'reject' | 'suspend' }) => api.post(`/api/v1/retailers/${id}/${act}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['retailers'] }),
  })

  const create = useMutation({
    mutationFn: () => api.post('/api/v1/retailers', { ...form, gstin: form.gstin || null }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['retailers'] })
      setForm(empty)
      setAdding(false)
    },
  })
  const error = create.error instanceof ApiError ? create.error : null
  const set = (k: keyof typeof empty) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })

  return (
    <>
      <PageTitle title="Retailers">
        <Select className="w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          <option value="pending">Pending approval</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="rejected">Rejected</option>
        </Select>
        <Button onClick={() => setAdding(!adding)}>{adding ? 'Close' : 'Add retailer'}</Button>
      </PageTitle>

      {adding && (
        <Card className="mb-4">
          <CardHeader title="New retailer" description="Retailers you add are active straight away and can log in to the portal." />
          <form className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => { e.preventDefault(); create.mutate() }}>
            {error && !Object.keys(error.errors).length && <div className="sm:col-span-full"><Alert>{error.message}</Alert></div>}
            <Field label="Shop name" error={error?.field('shop_name')}><Input required value={form.shop_name} onChange={set('shop_name')} /></Field>
            <Field label="City"><Input value={form.city} onChange={set('city')} /></Field>
            <Field label="GSTIN" error={error?.field('gstin')}><Input maxLength={15} value={form.gstin} onChange={(e) => setForm({ ...form, gstin: e.target.value.toUpperCase() })} /></Field>
            <Field label="Credit limit (₹)" error={error?.field('credit_limit')}><Input inputMode="decimal" value={form.credit_limit} onChange={set('credit_limit')} /></Field>
            <Field label="Login name" error={error?.field('login_name')}><Input required value={form.login_name} onChange={set('login_name')} /></Field>
            <Field label="Login email" error={error?.field('login_email')}><Input required type="email" value={form.login_email} onChange={set('login_email')} /></Field>
            <Field label="Password" error={error?.field('login_password')}><Input required type="password" minLength={8} value={form.login_password} onChange={set('login_password')} /></Field>
            <Field label="Phone"><Input value={form.phone} onChange={set('phone')} /></Field>
            <div className="sm:col-span-full"><Button type="submit" loading={create.isPending}>Create retailer</Button></div>
          </form>
        </Card>
      )}

      {settings && <RetailerSettings key={settings} id={settings} onDone={() => setSettings(null)} />}

      <Card>
        {list.data?.data.length === 0 ? (
          <EmptyState title="No retailers here">Retailers can also sign up on your portal and wait for approval.</EmptyState>
        ) : (
          <Table head={['Shop', 'City', 'Salesperson', 'Credit limit', 'Status', '']}>
            {list.data?.data.map((r) => (
              <tr key={r.id}>
                <Td className="font-medium"><Link className="text-brand hover:underline" to={`/staff/retailers/${r.id}`}>{r.shop_name}</Link><div className="text-xs font-normal text-muted-foreground">{[r.contact_name, r.gstin].filter(Boolean).join(' · ')}</div></Td>
                <Td>{r.city ?? '—'}</Td>
                <Td>{(r as { salesperson?: { name: string } | null }).salesperson?.name ?? '—'}</Td>
                <Td className="tabular">{rupees(r.credit_limit)}</Td>
                <Td><Badge tone={statusTone[r.status]} className="capitalize">{r.status}</Badge></Td>
                <Td className="whitespace-nowrap text-right">
                  {r.status === 'pending' && (
                    <>
                      <Button size="sm" onClick={() => action.mutate({ id: r.id, act: 'approve' })}>Approve</Button>
                      <Button size="sm" variant="ghost" onClick={() => action.mutate({ id: r.id, act: 'reject' })}>Reject</Button>
                    </>
                  )}
                  {r.status === 'active' && <Button size="sm" variant="ghost" onClick={() => action.mutate({ id: r.id, act: 'suspend' })}>Suspend</Button>}
                  {r.status === 'suspended' && <Button size="sm" variant="ghost" onClick={() => action.mutate({ id: r.id, act: 'approve' })}>Reactivate</Button>}
                  <Link to={`/staff/retailers/${r.id}`}><Button size="sm" variant="ghost">Profile</Button></Link>
                  <Button size="sm" variant="ghost" onClick={() => setSettings(r.id)}>Settings</Button>
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  )
}
