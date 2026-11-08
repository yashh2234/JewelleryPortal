import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { PageTitle } from '../layout'
import type { Karat } from '../types'

export default function Karats() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['karats'], queryFn: () => api.get<{ data: Karat[] }>('/api/v1/karats') })
  const [editing, setEditing] = useState<Karat | null>(null)
  const [form, setForm] = useState({ label: '', purity_pct: '' })

  const save = useMutation({
    mutationFn: (body: Partial<Karat>) =>
      editing ? api.patch(`/api/v1/karats/${editing.id}`, body) : api.post('/api/v1/karats', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['karats'] })
      setEditing(null)
      setForm({ label: '', purity_pct: '' })
    },
  })

  const toggle = useMutation({
    mutationFn: (k: Karat) => api.patch(`/api/v1/karats/${k.id}`, { is_active: !k.is_active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['karats'] }),
  })

  const error = save.error instanceof ApiError ? save.error : null
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(form)
  }

  return (
    <>
      <PageTitle title="Karats & tunch" />
      <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <Card>
          <CardHeader title="Karats" description="Purity is touch % (22K = 91.600). Fine weight = gross × purity ÷ 100." />
          <Table head={['Karat', 'Purity %', 'Status', '']}>
            {data?.data.map((k) => (
              <tr key={k.id}>
                <Td className="font-medium">{k.label}</Td>
                <Td className="tabular">{k.purity_pct}</Td>
                <Td>{k.is_active ? <Badge tone="success">Active</Badge> : <Badge>Inactive</Badge>}</Td>
                <Td className="text-right">
                  <Button size="sm" variant="ghost" onClick={() => { setEditing(k); setForm({ label: k.label, purity_pct: k.purity_pct }) }}>Edit</Button>
                  <Button size="sm" variant="ghost" onClick={() => toggle.mutate(k)}>{k.is_active ? 'Deactivate' : 'Activate'}</Button>
                </Td>
              </tr>
            ))}
          </Table>
        </Card>

        <Card className="h-fit">
          <CardHeader title={editing ? `Edit ${editing.label}` : 'Add karat'} />
          <form onSubmit={submit} className="grid gap-3 p-4">
            {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
            <Field label="Label" error={error?.field('label')}>
              <Input required maxLength={10} placeholder="21K" value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} />
            </Field>
            <Field label="Purity %" error={error?.field('purity_pct')} hint="Up to 3 decimals">
              <Input required inputMode="decimal" placeholder="87.500" value={form.purity_pct}
                onChange={(e) => setForm({ ...form, purity_pct: e.target.value })} />
            </Field>
            <div className="flex gap-2">
              <Button type="submit" loading={save.isPending}>{editing ? 'Save' : 'Add'}</Button>
              {editing && <Button type="button" variant="ghost" onClick={() => { setEditing(null); setForm({ label: '', purity_pct: '' }) }}>Cancel</Button>}
            </div>
          </form>
        </Card>
      </div>
    </>
  )
}
