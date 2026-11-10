import { Alert, api, ApiError, Button, Card, Field, Input, useTenant, ThemeToggle } from '@b2b/ui'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'

const empty = { shop_name: '', contact_name: '', gstin: '', city: '', phone: '', email: '', password: '', password_confirmation: '' }

/** Self-signup. The account stays pending until the manufacturer approves it. */
export default function Register() {
  const tenant = useTenant()
  const [form, setForm] = useState(empty)

  const register = useMutation({
    mutationFn: () => api.post<{ message: string }>('/api/portal/v1/register', { ...form, gstin: form.gstin || null }),
  })

  const error = register.error instanceof ApiError ? register.error : null
  const set = (k: keyof typeof empty) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })

  if (register.isSuccess) {
    return (
      <div className="bg-aura flex min-h-dvh items-center justify-center p-4">
        <Card className="animate-fade-up max-w-sm p-8 text-center shadow-raised">
          <h1 className="font-display text-2xl font-semibold">Request sent</h1>
          <p className="mt-2 text-sm text-muted-foreground">{register.data.message}</p>
          <Link to="/login" className="mt-4 inline-block text-sm font-medium text-brand hover:underline">Back to sign in</Link>
        </Card>
      </div>
    )
  }

  return (
    <div className="bg-aura relative flex min-h-dvh items-center justify-center p-4">
      <ThemeToggle className="absolute right-4 top-4" />
      <div className="animate-fade-up w-full max-w-md space-y-6">
        <div className="text-center">
          <h1 className="font-display text-[1.75rem] font-semibold leading-tight">Request a retailer account</h1>
          <p className="text-sm text-muted-foreground">{tenant.name} will review and approve it.</p>
        </div>
        <Card className="p-6 shadow-raised">
          <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); register.mutate() }}>
            {error && !Object.keys(error.errors).length && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
            <Field label="Shop name" error={error?.field('shop_name')} className="sm:col-span-2"><Input required value={form.shop_name} onChange={set('shop_name')} /></Field>
            <Field label="Your name" error={error?.field('contact_name')}><Input required value={form.contact_name} onChange={set('contact_name')} /></Field>
            <Field label="City" error={error?.field('city')}><Input required value={form.city} onChange={set('city')} /></Field>
            <Field label="GSTIN" error={error?.field('gstin')} hint="Optional"><Input maxLength={15} value={form.gstin} onChange={(e) => setForm({ ...form, gstin: e.target.value.toUpperCase() })} /></Field>
            <Field label="Mobile" error={error?.field('phone')}><Input required inputMode="tel" value={form.phone} onChange={set('phone')} /></Field>
            <Field label="Email" error={error?.field('email')} className="sm:col-span-2"><Input required type="email" value={form.email} onChange={set('email')} /></Field>
            <Field label="Password" error={error?.field('password')}><Input required type="password" minLength={8} value={form.password} onChange={set('password')} /></Field>
            <Field label="Confirm password"><Input required type="password" value={form.password_confirmation} onChange={set('password_confirmation')} /></Field>
            <div className="sm:col-span-2"><Button type="submit" className="w-full" loading={register.isPending}>Send request</Button></div>
          </form>
        </Card>
        <p className="text-center text-sm"><Link to="/login" className="text-brand hover:underline">Already have an account? Sign in</Link></p>
      </div>
    </div>
  )
}
