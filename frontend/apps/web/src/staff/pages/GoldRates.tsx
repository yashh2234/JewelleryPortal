import { Alert, api, ApiError, Button, Card, CardHeader, Field, Input } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { PageTitle } from '../layout'
import type { GoldRate, Karat } from '../types'

function today() {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 10)
}

/** Day rate per karat, used for estimates on the retailer portal. */
export default function GoldRates() {
  const qc = useQueryClient()
  const [date, setDate] = useState(today())
  const karats = useQuery({ queryKey: ['karats'], queryFn: () => api.get<{ data: Karat[] }>('/api/v1/karats') })
  const rates = useQuery({
    queryKey: ['gold-rates', date],
    queryFn: () => api.get<{ data: GoldRate[] }>(`/api/v1/gold-rates?date=${date}`),
  })
  const [values, setValues] = useState<Record<number, string>>({})

  useEffect(() => {
    if (rates.data) setValues(Object.fromEntries(rates.data.data.map((r) => [r.karat_id, r.rate_per_g])))
  }, [rates.data])

  const save = useMutation({
    mutationFn: () =>
      api.post('/api/v1/gold-rates', {
        date,
        rates: Object.entries(values)
          .filter(([, v]) => v.trim() !== '')
          .map(([karat_id, rate_per_g]) => ({ karat_id: Number(karat_id), rate_per_g })),
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['gold-rates'] }),
  })

  const error = save.error instanceof ApiError ? save.error : null

  return (
    <>
      <PageTitle title="Gold rate" />
      <Card className="max-w-lg">
        <CardHeader title="Rate per gram" description="Blank karats keep their last rate." />
        <form className="grid gap-3 p-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          {error && <Alert>{error.message}</Alert>}
          {save.isSuccess && <Alert tone="success">Rates saved.</Alert>}
          <Field label="Date">
            <Input type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
          {karats.data?.data.filter((k) => k.is_active).map((k) => (
            <Field key={k.id} label={`${k.label} (${k.purity_pct}%) ₹ per gram`}>
              <Input inputMode="decimal" value={values[k.id] ?? ''} onChange={(e) => setValues({ ...values, [k.id]: e.target.value })} />
            </Field>
          ))}
          <Button type="submit" loading={save.isPending}>Save rates</Button>
        </form>
      </Card>
    </>
  )
}
