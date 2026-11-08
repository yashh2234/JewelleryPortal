import { Alert, api, ApiError, Button, Card, Field, Input, useTenant } from '@b2b/ui'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'

function Shell({ title, children }: { title: string; children: React.ReactNode }) {
  const tenant = useTenant()
  return (
    <div className="flex min-h-dvh items-center justify-center p-4">
      <div className="w-full max-w-sm space-y-4">
        <div className="text-center">
          <h1 className="text-xl font-semibold">{tenant.name}</h1>
          <p className="text-sm text-muted-foreground">{title}</p>
        </div>
        <Card className="p-5">{children}</Card>
        <p className="text-center text-sm"><Link to="/login" className="text-brand hover:underline">Back to sign in</Link></p>
      </div>
    </div>
  )
}

/** Staff and retailers. Kaarigars sign in with OTP and have no password. */
export function ForgotPassword() {
  const [email, setEmail] = useState('')
  const send = useMutation({ mutationFn: () => api.post<{ message: string }>('/api/auth/forgot', { email }) })
  const error = send.error instanceof ApiError ? send.error : null

  return (
    <Shell title="Forgot password">
      {send.isSuccess ? (
        <Alert tone="success">{send.data.message} Check your inbox.</Alert>
      ) : (
        <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
          {error && <Alert>{error.field('email') ?? error.message}</Alert>}
          <Field label="Email">
            <Input type="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Button type="submit" loading={send.isPending}>Send reset link</Button>
          <p className="text-xs text-muted-foreground">Kaarigars don’t need a password; sign in with your mobile number.</p>
        </form>
      )}
    </Shell>
  )
}

export function ResetPassword() {
  const [params] = useSearchParams()
  const [form, setForm] = useState({ password: '', password_confirmation: '' })
  const reset = useMutation({
    mutationFn: () => api.post<{ message: string }>('/api/auth/reset', {
      email: params.get('email'),
      token: params.get('token'),
      ...form,
    }),
  })
  const error = reset.error instanceof ApiError ? reset.error : null

  return (
    <Shell title="Choose a new password">
      {reset.isSuccess ? (
        <Alert tone="success">{reset.data.message}</Alert>
      ) : (
        <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); reset.mutate() }}>
          {error && <Alert>{error.field('token') ?? error.field('password') ?? error.message}</Alert>}
          <p className="text-sm text-muted-foreground">{params.get('email')}</p>
          <Field label="New password">
            <Input type="password" required minLength={8} autoComplete="new-password" value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })} />
          </Field>
          <Field label="Confirm password">
            <Input type="password" required autoComplete="new-password" value={form.password_confirmation}
              onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />
          </Field>
          <Button type="submit" loading={reset.isPending}>Save password</Button>
        </form>
      )}
    </Shell>
  )
}
