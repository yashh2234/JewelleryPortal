import { Alert, api, ApiError, Button, Card, CardHeader, Field, Input, Textarea } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { PageTitle } from '../layout'

interface TenantSettings {
  name: string
  gstin: string | null
  phone: string | null
  email: string | null
  address: string | null
  primary_color: string
  logo_url: string | null
  status: string
  trial_ends_at: string | null
  plan?: { name: string } | null
}

export default function Branding() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['tenant-settings'], queryFn: () => api.get<{ data: TenantSettings }>('/api/v1/settings/tenant') })
  const [form, setForm] = useState({ name: '', gstin: '', phone: '', email: '', address: '', primary_color: '#7c5c1e' })
  const [logo, setLogo] = useState<File | null>(null)

  useEffect(() => {
    if (data) {
      const t = data.data
      setForm({ name: t.name, gstin: t.gstin ?? '', phone: t.phone ?? '', email: t.email ?? '', address: t.address ?? '', primary_color: t.primary_color })
    }
  }, [data])

  const save = useMutation({
    mutationFn: () => {
      const body = new FormData()
      // Empty strings arrive as null (Laravel ConvertEmptyStringsToNull), which clears a field.
      Object.entries(form).forEach(([k, v]) => body.append(k, v))
      if (logo) body.append('logo', logo)
      return api.post('/api/v1/settings/tenant', body)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tenant-settings'] })
      try { localStorage.removeItem('tenant-branding') } catch { /* ignore */ }
      document.documentElement.style.setProperty('--brand', form.primary_color)
    },
  })

  const error = save.error instanceof ApiError ? save.error : null
  const t = data?.data

  return (
    <>
      <PageTitle title="Business & branding" />
      {t && (
        <p className="mb-4 text-sm text-muted-foreground">
          Plan: <b>{t.plan?.name ?? '—'}</b> · Status: <b className="capitalize">{t.status}</b>
          {t.trial_ends_at && t.status === 'trial' && <> · Trial ends {new Date(t.trial_ends_at).toLocaleDateString('en-IN')}</>}
        </p>
      )}
      <Card className="max-w-2xl">
        <CardHeader title="Profile" description="Name, logo and colour appear on all three apps and on the installed app icon." />
        <form className="grid gap-4 p-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          {error && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
          {save.isSuccess && <div className="sm:col-span-2"><Alert tone="success">Saved. Reload to see the new branding everywhere.</Alert></div>}
          <Field label="Business name" error={error?.field('name')} className="sm:col-span-2">
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>
          <Field label="GSTIN" error={error?.field('gstin')}>
            <Input value={form.gstin} maxLength={15} onChange={(e) => setForm({ ...form, gstin: e.target.value.toUpperCase() })} />
          </Field>
          <Field label="Phone"><Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
          <Field label="Email" error={error?.field('email')}><Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
          <Field label="Brand colour" error={error?.field('primary_color')}>
            <div className="flex gap-2">
              <input type="color" className="h-10 w-14 rounded border" value={form.primary_color}
                onChange={(e) => setForm({ ...form, primary_color: e.target.value })} />
              <Input value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} />
            </div>
          </Field>
          <Field label="Address" className="sm:col-span-2"><Textarea value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} /></Field>
          <Field label="Logo" error={error?.field('logo')} hint="Square PNG or WebP works best (used as the app icon)." className="sm:col-span-2">
            <div className="flex items-center gap-3">
              {t?.logo_url && <img src={t.logo_url} alt="" className="size-12 rounded border object-contain" />}
              <Input type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} />
            </div>
          </Field>
          <div className="sm:col-span-2"><Button type="submit" loading={save.isPending}>Save</Button></div>
        </form>
      </Card>
    </>
  )
}
