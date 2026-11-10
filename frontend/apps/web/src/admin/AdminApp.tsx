import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Select, Table, Td, ThemeToggle, type Paginated } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'

interface Admin { id: number; name: string; email: string }
interface Plan { id: number; code: string; name: string }
interface TenantRow {
  id: number
  name: string
  subdomain: string
  status: 'trial' | 'active' | 'suspended' | 'cancelled'
  trial_ends_at: string | null
  created_at: string
  plan: Plan | null
  users_count: number
  retailers_count: number
}

const tone = { trial: 'warning', active: 'success', suspended: 'danger', cancelled: 'neutral' } as const

/**
 * Super-admin console for you, the SaaS operator. Opened on the central domain at
 * /admin; it is not tied to any workspace.
 */
export default function AdminApp() {
  const me = useQuery({
    queryKey: ['platform-admin'],
    queryFn: async () => {
      try {
        return (await api.get<{ admin: Admin }>('/api/platform/admin/me')).admin
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
  })

  if (me.isLoading) return <div className="p-8 text-muted-foreground">Loading…</div>
  return me.data ? <Console admin={me.data} /> : <AdminLogin />
}

function AdminLogin() {
  const qc = useQueryClient()
  const [form, setForm] = useState({ email: '', password: '' })
  const login = useMutation({
    mutationFn: () => api.post<{ admin: Admin }>('/api/platform/admin/login', form),
    onSuccess: (r) => qc.setQueryData(['platform-admin'], r.admin),
  })
  const error = login.error instanceof ApiError ? login.error : null

  return (
    <div className="bg-aura relative flex min-h-dvh items-center justify-center p-4">
      <ThemeToggle className="absolute right-4 top-4" />
      <Card className="animate-fade-up w-full max-w-sm p-6 shadow-raised">
        <h1 className="mb-5 font-display text-2xl font-semibold">Platform admin</h1>
        <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); login.mutate() }}>
          {error && <Alert>{error.field('email') ?? error.message}</Alert>}
          <Field label="Email"><Input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
          <Field label="Password"><Input type="password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></Field>
          <Button type="submit" loading={login.isPending}>Sign in</Button>
        </form>
      </Card>
    </div>
  )
}

function Console({ admin }: { admin: Admin }) {
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<TenantRow | null>(null)

  const tenants = useQuery({
    queryKey: ['platform-tenants', search],
    queryFn: () => api.get<Paginated<TenantRow>>(`/api/platform/admin/tenants?search=${encodeURIComponent(search)}`),
  })
  const plans = useQuery({ queryKey: ['platform-plans'], queryFn: () => api.get<{ data: Plan[] }>('/api/platform/admin/plans') })
  const logout = useMutation({
    mutationFn: () => api.post('/api/platform/admin/logout'),
    onSettled: () => qc.setQueryData(['platform-admin'], null),
  })

  return (
    <div className="mx-auto max-w-6xl p-4 sm:p-6">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="font-display text-2xl font-semibold">Workspaces</h1>
        <div className="flex items-center gap-2">
          <Input className="w-56" placeholder="Search name or subdomain" value={search} onChange={(e) => setSearch(e.target.value)} />
          <span className="text-sm text-muted-foreground">{admin.email}</span>
          <ThemeToggle />
          <Button variant="ghost" size="sm" onClick={() => logout.mutate()}>Log out</Button>
        </div>
      </div>

      {editing && plans.data && (
        <EditTenant key={editing.id} tenant={editing} plans={plans.data.data} onDone={() => { setEditing(null); qc.invalidateQueries({ queryKey: ['platform-tenants'] }) }} />
      )}

      <Card>
        <Table head={['Workspace', 'Plan', 'Status', 'Trial ends', 'Staff', 'Retailers', 'Created', '']}>
          {tenants.data?.data.map((t) => (
            <tr key={t.id}>
              <Td className="font-medium">{t.name}<div className="font-mono text-xs text-muted-foreground">{t.subdomain}</div></Td>
              <Td>{t.plan?.name ?? '—'}</Td>
              <Td><Badge tone={tone[t.status]} className="capitalize">{t.status}</Badge></Td>
              <Td>{t.trial_ends_at ? new Date(t.trial_ends_at).toLocaleDateString('en-IN') : '—'}</Td>
              <Td className="tabular">{t.users_count}</Td>
              <Td className="tabular">{t.retailers_count}</Td>
              <Td>{new Date(t.created_at).toLocaleDateString('en-IN')}</Td>
              <Td className="text-right"><Button size="sm" variant="ghost" onClick={() => setEditing(t)}>Manage</Button></Td>
            </tr>
          ))}
        </Table>
      </Card>
    </div>
  )
}

function EditTenant({ tenant, plans, onDone }: { tenant: TenantRow; plans: Plan[]; onDone: () => void }) {
  const [form, setForm] = useState({
    status: tenant.status,
    plan_id: String(tenant.plan?.id ?? ''),
    trial_ends_at: tenant.trial_ends_at?.slice(0, 10) ?? '',
  })
  const save = useMutation({
    mutationFn: () => api.patch(`/api/platform/admin/tenants/${tenant.id}`, {
      status: form.status,
      plan_id: form.plan_id ? Number(form.plan_id) : undefined,
      trial_ends_at: form.trial_ends_at || null,
    }),
    onSuccess: onDone,
  })
  const error = save.error instanceof ApiError ? save.error : null

  return (
    <Card className="mb-4">
      <CardHeader title={`Manage ${tenant.name}`} actions={<Button size="sm" variant="ghost" onClick={onDone}>Close</Button>} />
      <form className="grid gap-4 p-4 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <div className="sm:col-span-4"><Alert>{error.message}</Alert></div>}
        <Field label="Status">
          <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as TenantRow['status'] })}>
            <option value="trial">Trial</option>
            <option value="active">Active (paid)</option>
            <option value="suspended">Suspended</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </Field>
        <Field label="Plan">
          <Select value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })}>
            {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        </Field>
        <Field label="Trial ends" hint="Extend a trial by moving this date">
          <Input type="date" value={form.trial_ends_at} onChange={(e) => setForm({ ...form, trial_ends_at: e.target.value })} />
        </Field>
        <div className="flex items-end"><Button type="submit" loading={save.isPending}>Save</Button></div>
      </form>
    </Card>
  )
}
