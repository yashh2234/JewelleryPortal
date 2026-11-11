import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Checkbox, EmptyState, Field, Input, Select, Table, Td, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useCan } from '../auth'
import { PageTitle } from '../layout'
import type { Kaarigar } from '../types'

const empty = { name: '', phone: '', type: 'in_house', address: '', app_access: true, language: 'hi', head_kaarigar_id: '' }

export default function Kaarigars() {
  const qc = useQueryClient()
  const can = useCan()
  const [search, setSearch] = useState('')
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState(empty)

  const list = useQuery({
    queryKey: ['kaarigars', search],
    queryFn: () => api.get<Paginated<Kaarigar>>(`/api/v1/kaarigars?search=${encodeURIComponent(search)}`),
  })

  const balances = useQuery({
    queryKey: ['metal', 'balances', 'kaarigar'],
    queryFn: () => api.get<{ data: { ref_id: number; fine: string }[] }>('/api/v1/metal/balances?location_type=kaarigar'),
    select: (r) => Object.fromEntries(r.data.map((b) => [b.ref_id, b.fine])),
  })

  const create = useMutation({
    mutationFn: () => api.post('/api/v1/kaarigars', { ...form, phone: form.phone || null, head_kaarigar_id: form.head_kaarigar_id ? Number(form.head_kaarigar_id) : null }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['kaarigars'] })
      setForm(empty)
      setAdding(false)
    },
  })

  const setHead = useMutation({
    mutationFn: ({ k, head }: { k: Kaarigar; head: string }) => api.patch(`/api/v1/kaarigars/${k.id}`, { head_kaarigar_id: head ? Number(head) : null }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['kaarigars'] }),
  })
  // A head kaarigar is anyone not himself under a head (one level, blueprint §2).
  const heads = list.data?.data.filter((k) => !k.head_kaarigar_id && k.is_active) ?? []

  const toggle = useMutation({
    mutationFn: (k: Kaarigar) => api.patch(`/api/v1/kaarigars/${k.id}`, { is_active: !k.is_active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['kaarigars'] }),
  })

  const error = create.error instanceof ApiError ? create.error : null
  const submit = (e: FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  return (
    <>
      <PageTitle title="Karigar management" description="Kaarigar profiles and hierarchy: a head kaarigar can pass work and gold to the sub-kaarigars under him.">
        <Input className="w-56" placeholder="Search name or phone" value={search} onChange={(e) => setSearch(e.target.value)} />
        {can('kaarigars.manage') && <Button onClick={() => setAdding(!adding)}>{adding ? 'Close' : 'Add kaarigar'}</Button>}
      </PageTitle>

      {adding && (
        <Card className="mb-4">
          <CardHeader title="New kaarigar" />
          <form onSubmit={submit} className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
            {error && !Object.keys(error.errors).length && <div className="sm:col-span-full"><Alert>{error.message}</Alert></div>}
            <Field label="Name" error={error?.field('name')}>
              <Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field label="Mobile" error={error?.field('phone')} hint="10 digits; used for app login (OTP)">
              <Input inputMode="numeric" maxLength={10} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value.replace(/\D/g, '') })} />
            </Field>
            <Field label="Type">
              <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                <option value="in_house">In-house</option>
                <option value="outside">Outside karkhana</option>
              </Select>
            </Field>
            <Field label="Works under (head kaarigar)" error={error?.field('head_kaarigar_id')}>
              <Select value={form.head_kaarigar_id} onChange={(e) => setForm({ ...form, head_kaarigar_id: e.target.value })}>
                <option value="">Nobody (direct to the factory)</option>
                {heads.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
              </Select>
            </Field>
            <Field label="Address" className="sm:col-span-2">
              <Input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
            </Field>
            <Field label="App language">
              <Select value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value })}>
                <option value="hi">हिन्दी</option>
                <option value="en">English</option>
                <option value="bn">বাংলা</option>
                <option value="gu">ગુજરાતી</option>
              </Select>
            </Field>
            <Checkbox label="Give kaarigar app access (needs mobile)" checked={form.app_access}
              onChange={(e) => setForm({ ...form, app_access: e.target.checked })} />
            <div className="sm:col-span-full"><Button type="submit" loading={create.isPending}>Save kaarigar</Button></div>
          </form>
        </Card>
      )}

      {setHead.error instanceof ApiError && <div className="mb-3"><Alert>{Object.values(setHead.error.errors)[0]?.[0] ?? setHead.error.message}</Alert></div>}
      <Card>
        {list.data?.data.length === 0 ? (
          <EmptyState title="No kaarigars yet">Add your in-house kaarigars and outside karkhanas.</EmptyState>
        ) : (
          <Table head={['Name', 'Mobile', 'Type', 'Head / team', 'Gold in hand', 'App', 'Status', '']}>
            {list.data?.data.map((k) => (
              <tr key={k.id}>
                <Td className="font-medium"><Link to={`/staff/kaarigars/${k.id}`} className="text-brand hover:underline">{k.name}</Link></Td>
                <Td className="tabular">{k.phone ?? '—'}</Td>
                <Td>{k.type === 'outside' ? 'Outside karkhana' : 'In-house'}</Td>
                <Td>
                  {can('kaarigars.manage') && !k.sub_kaarigars_count ? (
                    <Select className="h-8 w-40" value={k.head_kaarigar_id ?? ''} onChange={(e) => setHead.mutate({ k, head: e.target.value })} aria-label={`Head kaarigar of ${k.name}`}>
                      <option value="">—</option>
                      {heads.filter((h) => h.id !== k.id).map((h) => <option key={h.id} value={h.id}>under {h.name}</option>)}
                    </Select>
                  ) : k.sub_kaarigars_count ? <Badge tone="brand">Head · {k.sub_kaarigars_count} under</Badge> : (k.head?.name ? `under ${k.head.name}` : '—')}
                </Td>
                <Td className="tabular"><Link to={`/staff/kaarigars/${k.id}/ledger`} className="hover:underline">{balances.data?.[k.id] ?? '0.000'} g</Link></Td>
                <Td>{k.app_users_count ? <Badge tone="brand">{k.app_users_count} login{k.app_users_count > 1 ? 's' : ''}</Badge> : '—'}</Td>
                <Td>{k.is_active ? <Badge tone="success">Active</Badge> : <Badge>Inactive</Badge>}</Td>
                <Td className="text-right">
                  {can('kaarigars.manage') && (
                    <Button size="sm" variant="ghost" onClick={() => toggle.mutate(k)}>{k.is_active ? 'Deactivate' : 'Activate'}</Button>
                  )}
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  )
}
