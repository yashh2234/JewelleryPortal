import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Checkbox, Field, Input, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PageTitle } from '../layout'
import type { Role, StaffListUser } from '../types'

const label = (s: string) => s.replaceAll('_', ' ').replaceAll('.', ': ')

export default function Users() {
  const qc = useQueryClient()
  const users = useQuery({ queryKey: ['users'], queryFn: () => api.get<{ data: StaffListUser[] }>('/api/v1/users') })
  const roles = useQuery({ queryKey: ['roles'], queryFn: () => api.get<{ data: Role[]; permissions: string[] }>('/api/v1/roles') })
  const [form, setForm] = useState({ name: '', email: '', password: '', roles: [] as string[] })

  const create = useMutation({
    mutationFn: () => api.post('/api/v1/users', form),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['users'] })
      setForm({ name: '', email: '', password: '', roles: [] })
    },
  })

  const toggle = useMutation({
    mutationFn: (u: StaffListUser) => api.patch(`/api/v1/users/${u.id}`, { is_active: !u.is_active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
  })

  const error = create.error instanceof ApiError ? create.error : null
  const toggleError = toggle.error instanceof ApiError ? toggle.error : null

  return (
    <>
      <PageTitle title="Users & roles" />
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div className="grid gap-4">
          <Card>
            <CardHeader title="Staff users" />
            {toggleError && <div className="p-4 pb-0"><Alert>{toggleError.message}</Alert></div>}
            <Table head={['Name', 'Email', 'Roles', 'Status', '']}>
              {users.data?.data.map((u) => (
                <tr key={u.id}>
                  <Td className="font-medium">{u.name}</Td>
                  <Td>{u.email}</Td>
                  <Td className="capitalize">{u.roles.map(label).join(', ')}</Td>
                  <Td>{u.is_active ? <Badge tone="success">Active</Badge> : <Badge>Inactive</Badge>}</Td>
                  <Td className="text-right">
                    <Button size="sm" variant="ghost" onClick={() => toggle.mutate(u)}>{u.is_active ? 'Deactivate' : 'Activate'}</Button>
                  </Td>
                </tr>
              ))}
            </Table>
          </Card>

          <Card>
            <CardHeader title="Roles" description="Owner always has every permission." />
            <div className="divide-y">
              {roles.data?.data.map((r) => (
                <div key={r.id} className="p-4">
                  <p className="font-medium capitalize">{label(r.name)}</p>
                  <div className="mt-2 flex flex-wrap gap-1">
                    {r.permissions.map((p) => <Badge key={p}>{label(p)}</Badge>)}
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </div>

        <Card className="h-fit">
          <CardHeader title="Add user" />
          <form className="grid gap-3 p-4" onSubmit={(e) => { e.preventDefault(); create.mutate() }}>
            {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
            <Field label="Name" error={error?.field('name')}><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Email" error={error?.field('email')}><Input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label="Password" error={error?.field('password')}><Input required type="password" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></Field>
            <div>
              <p className="mb-1 text-sm font-medium">Roles</p>
              {error?.field('roles') && <p className="text-xs text-danger">{error.field('roles')}</p>}
              <div className="grid gap-1">
                {roles.data?.data.map((r) => (
                  <Checkbox key={r.id} label={label(r.name)} className="capitalize" checked={form.roles.includes(r.name)}
                    onChange={(e) => setForm({ ...form, roles: e.target.checked ? [...form.roles, r.name] : form.roles.filter((x) => x !== r.name) })} />
                ))}
              </div>
            </div>
            <Button type="submit" loading={create.isPending}>Add user</Button>
          </form>
        </Card>
      </div>
    </>
  )
}
