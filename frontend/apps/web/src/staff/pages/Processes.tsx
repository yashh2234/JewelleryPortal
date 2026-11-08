import { Alert, api, ApiError, Badge, Button, Card, CardHeader, Field, Input, Table, Td } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { PageTitle } from '../layout'
import type { Karat, Process } from '../types'

/** Processes in shop order, each with its allowed wastage % and per-karat overrides. */
export default function Processes() {
  const qc = useQueryClient()
  const processes = useQuery({ queryKey: ['processes'], queryFn: () => api.get<{ data: Process[] }>('/api/v1/processes') })
  const karats = useQuery({ queryKey: ['karats'], queryFn: () => api.get<{ data: Karat[] }>('/api/v1/karats') })
  const [selected, setSelected] = useState<Process | null>(null)
  const [newName, setNewName] = useState('')
  const [newWastage, setNewWastage] = useState('0.500')

  const create = useMutation({
    mutationFn: () => api.post('/api/v1/processes', {
      name: newName,
      default_wastage_pct: newWastage,
      sequence: ((processes.data?.data.at(-1)?.sequence ?? 0) + 10),
    }),
    onSuccess: () => {
      setNewName('')
      qc.invalidateQueries({ queryKey: ['processes'] })
    },
  })

  const createError = create.error instanceof ApiError ? create.error : null

  return (
    <>
      <PageTitle title="Processes & allowed wastage" />
      <div className="grid gap-4 lg:grid-cols-[1fr_24rem]">
        <Card>
          <CardHeader
            title="Processes"
            description="Loss within the allowed % posts to Loss automatically; above it stays on the kaarigar and raises an alert."
          />
          <Table head={['#', 'Process', 'Allowed wastage', 'Karat overrides', '']}>
            {processes.data?.data.map((p) => (
              <tr key={p.id} className={selected?.id === p.id ? 'bg-brand/5' : undefined}>
                <Td className="tabular text-muted-foreground">{p.sequence}</Td>
                <Td className="font-medium">
                  {p.name} {!p.is_active && <Badge className="ml-1">Inactive</Badge>}
                </Td>
                <Td className="tabular">{p.default_wastage_pct}%</Td>
                <Td>{p.wastage_rules.length || '—'}</Td>
                <Td className="text-right">
                  <Button size="sm" variant="ghost" onClick={() => setSelected(p)}>Edit</Button>
                </Td>
              </tr>
            ))}
          </Table>
          <form
            className="flex flex-wrap items-end gap-2 border-t p-4"
            onSubmit={(e) => { e.preventDefault(); create.mutate() }}
          >
            <Field label="New process" error={createError?.field('name')} className="min-w-40 flex-1">
              <Input required value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="e.g. Enamelling" />
            </Field>
            <Field label="Allowed %" error={createError?.field('default_wastage_pct')} className="w-28">
              <Input required inputMode="decimal" value={newWastage} onChange={(e) => setNewWastage(e.target.value)} />
            </Field>
            <Button type="submit" loading={create.isPending}>Add</Button>
          </form>
        </Card>

        {selected && karats.data && (
          <ProcessEditor
            key={selected.id}
            process={processes.data?.data.find((p) => p.id === selected.id) ?? selected}
            karats={karats.data.data.filter((k) => k.is_active)}
            onDone={() => setSelected(null)}
          />
        )}
      </div>
    </>
  )
}

function ProcessEditor({ process, karats, onDone }: { process: Process; karats: Karat[]; onDone: () => void }) {
  const qc = useQueryClient()
  const [name, setName] = useState(process.name)
  const [wastage, setWastage] = useState(process.default_wastage_pct)
  const [active, setActive] = useState(process.is_active)
  const [overrides, setOverrides] = useState<Record<number, string>>(
    Object.fromEntries(process.wastage_rules.map((r) => [r.karat_id, r.allowed_wastage_pct])),
  )

  const save = useMutation({
    mutationFn: async () => {
      await api.patch(`/api/v1/processes/${process.id}`, { name, default_wastage_pct: wastage, is_active: active })
      await api.put(`/api/v1/processes/${process.id}/wastage-rules`, {
        rules: Object.entries(overrides)
          .filter(([, v]) => v.trim() !== '')
          .map(([karat_id, allowed_wastage_pct]) => ({ karat_id: Number(karat_id), allowed_wastage_pct })),
      })
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['processes'] })
      onDone()
    },
  })

  const error = save.error instanceof ApiError ? save.error : null

  return (
    <Card className="h-fit">
      <CardHeader title={`Edit ${process.name}`} />
      <div className="grid gap-3 p-4">
        {error && <Alert>{error.message}</Alert>}
        <Field label="Name"><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <Field label="Allowed wastage % (default)"><Input inputMode="decimal" value={wastage} onChange={(e) => setWastage(e.target.value)} /></Field>
        <div>
          <p className="mb-1 text-sm font-medium">Per-karat override</p>
          <p className="mb-2 text-xs text-muted-foreground">Leave blank to use the default.</p>
          <div className="grid grid-cols-2 gap-2">
            {karats.map((k) => (
              <label key={k.id} className="flex items-center gap-2 text-sm">
                <span className="w-10">{k.label}</span>
                <Input inputMode="decimal" className="h-8" placeholder={wastage} value={overrides[k.id] ?? ''}
                  onChange={(e) => setOverrides({ ...overrides, [k.id]: e.target.value })} />
              </label>
            ))}
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Active
        </label>
        <div className="flex gap-2">
          <Button onClick={() => save.mutate()} loading={save.isPending}>Save</Button>
          <Button variant="ghost" onClick={onDone}>Cancel</Button>
        </div>
      </div>
    </Card>
  )
}
