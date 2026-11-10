import { Alert, api, ApiError, Button, Card, CardHeader } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { PageTitle } from '../layout'

type Rules = { disputed: boolean; unacknowledged: boolean; excess: boolean; limit: boolean; overdue: boolean }
const DEFAULTS: Rules = { disputed: true, unacknowledged: true, excess: true, limit: true, overdue: false }

const RULES: { key: keyof Rules; title: string; text: string }[] = [
  { key: 'disputed', title: 'Disputed issue not resolved', text: 'The kaarigar disputed an earlier issue and it has not been corrected (reversed and re-issued).' },
  { key: 'unacknowledged', title: 'Previous issue not acknowledged in 24 h', text: 'Not accepted in the kaarigar app, and no signed slip recorded by staff.' },
  { key: 'excess', title: 'Wastage above the limit not resolved', text: 'A closed job card lost more than the allowed wastage and nobody has written it off or recovered it.' },
  { key: 'limit', title: 'Gold limit per kaarigar', text: 'Blocks an issue that would take the kaarigar above the fine-gold limit set on their profile.' },
  { key: 'overdue', title: 'Overdue job cards', text: 'Blocks new gold while any of the kaarigar’s job cards is past its due date.' },
]

/** Spec §6D / 16.3: when a kaarigar may not take more gold. Owners can still override with a reason. */
export default function GoldRules() {
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['tenant-settings'], queryFn: () => api.get<{ data: { settings: { issue_rules?: Partial<Rules>; require_work_order?: boolean } | null } }>('/api/v1/settings/tenant') })
  const [rules, setRules] = useState<Rules>(DEFAULTS)
  const [requireWo, setRequireWo] = useState(true)

  useEffect(() => {
    const s = q.data?.data.settings
    if (q.data) {
      setRules({ ...DEFAULTS, ...(s?.issue_rules ?? {}) })
      setRequireWo(s?.require_work_order ?? true)
    }
  }, [q.data])

  const save = useMutation({
    mutationFn: () => api.post('/api/v1/settings/tenant', { issue_rules: rules, require_work_order: requireWo }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tenant-settings'] }),
  })

  return (
    <>
      <PageTitle title="Gold issue rules" description="A kaarigar never gets more gold until the previous issue is recorded and reconciled." />

      <div className="grid max-w-3xl gap-4">
        <Card>
          <CardHeader title="Block a new issue when…" description="Anyone with the “approve loss” right can still override, with a reason that is kept in the audit log." />
          <div className="divide-y">
            {RULES.map((r) => (
              <Toggle key={r.key} title={r.title} text={r.text} on={rules[r.key]} onChange={(v) => setRules({ ...rules, [r.key]: v })} />
            ))}
          </div>
        </Card>

        <Card>
          <CardHeader title="Work orders" />
          <Toggle
            title="Every gold issue must belong to a work order"
            text="Recommended. Turn off only if you also give gold for repairs or other work that has no work order."
            on={requireWo} onChange={setRequireWo}
          />
        </Card>

        {save.error instanceof ApiError && <Alert>{save.error.message}</Alert>}
        {save.isSuccess && <Alert tone="success">Saved.</Alert>}
        <div><Button onClick={() => save.mutate()} loading={save.isPending}>Save rules</Button></div>
      </div>
    </>
  )
}

function Toggle({ title, text, on, onChange }: { title: string; text: string; on: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="flex cursor-pointer items-start gap-4 px-5 py-4">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{title}</p>
        <p className="mt-0.5 text-sm text-muted-foreground">{text}</p>
      </div>
      <button type="button" role="switch" aria-checked={on} aria-label={title} onClick={() => onChange(!on)}
        className={`relative mt-0.5 h-6 w-11 shrink-0 rounded-full transition-colors ${on ? 'bg-brand' : 'bg-input'}`}>
        <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow-xs transition-all ${on ? 'left-[1.375rem]' : 'left-0.5'}`} />
      </button>
    </label>
  )
}
