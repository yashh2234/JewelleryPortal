import { Alert, api, ApiError, Button, Card, Input, InstallPrompt, ThemeToggle, useTenant } from '@b2b/ui'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Pencil } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { HOME, sessionKey, useSession, type Session } from './session'

/** "+91 98765 43210", "09876543210" → "9876543210"; anything else → null. */
function asMobile(value: string): string | null {
  const digits = value.replace(/\D/g, '').slice(-10)
  return /^[6-9]\d{9}$/.test(digits) && !value.includes('@') ? digits : null
}

const isEmail = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())

type Step = 'id' | 'password' | 'otp'

/**
 * The one sign-in for everyone. Type an email or a mobile number:
 *   email  → password
 *   mobile → OTP by SMS
 * The server works out who you are and you land on your own dashboard.
 */
export default function Login() {
  const tenant = useTenant()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const session = useSession()

  const [step, setStep] = useState<Step>('id')
  const [id, setId] = useState('')
  const [secret, setSecret] = useState('')
  const [hint, setHint] = useState<string | null>(null)

  const mobile = asMobile(id)

  const signedIn = (s: Session) => {
    qc.setQueryData(sessionKey, s)
    navigate(HOME[s.type], { replace: true })
  }

  const sendOtp = useMutation({
    mutationFn: () => api.post<{ debug_otp?: string }>('/api/auth/otp', { phone: mobile }),
    onSuccess: (r) => {
      setStep('otp')
      setSecret(r.debug_otp ?? '')   // local development only
    },
  })
  const login = useMutation({
    mutationFn: () => api.post<Session>('/api/auth/login', { email: id.trim(), password: secret, remember: true }),
    onSuccess: signedIn,
  })
  const verify = useMutation({
    mutationFn: () => api.post<Session>('/api/auth/otp/verify', { phone: mobile, otp: secret }),
    onSuccess: signedIn,
  })

  if (session.data) return <Navigate to={HOME[session.data.type]} replace />

  const error = [sendOtp.error, login.error, verify.error].find((e) => e instanceof ApiError) as ApiError | undefined
  const message = hint ?? (error ? (error.field('email') ?? error.field('otp') ?? error.field('phone') ?? error.message) : null)
  const pending = (error?.body as { status?: string } | null)?.status === 'pending'

  const next = () => {
    setHint(null)
    if (mobile) sendOtp.mutate()
    else if (isEmail(id)) setStep('password')
    else setHint('Enter your email, or your 10-digit mobile number.')
  }

  const back = () => {
    setStep('id')
    setSecret('')
    setHint(null)
    sendOtp.reset()
    login.reset()
    verify.reset()
  }

  return (
    <div className="bg-aura relative flex min-h-dvh items-center justify-center p-4">
      <ThemeToggle className="absolute right-4 top-4" />
      <div className="animate-fade-up w-full max-w-sm space-y-6">
        <div className="text-center">
          {tenant.logo_url ? (
            <img src={tenant.logo_url} alt="" className="mx-auto mb-4 h-16 object-contain" />
          ) : (
            <div className="bg-brand-sheen mx-auto mb-4 grid size-16 place-items-center rounded-2xl font-display text-3xl font-semibold text-brand-foreground shadow-raised">{tenant.name.charAt(0)}</div>
          )}
          <h1 className="font-display text-[1.75rem] font-semibold leading-tight">{tenant.name}</h1>
          <p className="mt-1 text-sm text-muted-foreground">Sign in to continue · साइन इन करें</p>
        </div>

        <Card className="p-6 shadow-raised">
          {message && <div className="mb-4"><Alert tone={pending ? 'warning' : 'danger'}>{message}</Alert></div>}

          {step === 'id' ? (
            <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); next() }}>
              <label className="grid gap-2">
                <span className="text-sm font-medium">Email or mobile number · ईमेल या मोबाइल नंबर</span>
                <Input
                  autoFocus required autoComplete="username" inputMode="email" className="h-12 text-base"
                  placeholder="you@shop.com or 98765 43210"
                  value={id} onChange={(e) => { setId(e.target.value); setHint(null) }}
                />
              </label>
              <Button size="lg" type="submit" loading={sendOtp.isPending}>Continue <ArrowRight /></Button>
            </form>
          ) : (
            <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); if (step === 'otp') verify.mutate(); else login.mutate() }}>
              <button type="button" onClick={back} className="flex items-center justify-between rounded-lg border bg-muted/60 px-3 py-2.5 text-left text-sm transition-colors hover:bg-muted">
                <span className="truncate">{step === 'otp' ? `+91 ${mobile}` : id.trim()}</span>
                <Pencil className="size-4 shrink-0 text-muted-foreground" />
              </button>

              {step === 'password' ? (
                <label className="grid gap-2">
                  <span className="text-sm font-medium">Password</span>
                  <Input autoFocus required type="password" autoComplete="current-password" className="h-12 text-base"
                    value={secret} onChange={(e) => setSecret(e.target.value)} />
                </label>
              ) : (
                <label className="grid gap-2">
                  <span className="text-sm font-medium">Enter the 6-digit OTP sent by SMS · SMS में आया OTP</span>
                  <Input autoFocus required inputMode="numeric" autoComplete="one-time-code" maxLength={6}
                    className="h-14 text-center text-2xl tracking-[0.5em]"
                    value={secret} onChange={(e) => setSecret(e.target.value.replace(/\D/g, ''))} />
                </label>
              )}

              <Button size="lg" type="submit" disabled={step === 'otp' && secret.length !== 6} loading={login.isPending || verify.isPending}>
                Sign in
              </Button>

              <div className="flex justify-between text-sm">
                <button type="button" className="text-muted-foreground hover:underline" onClick={back}>Use a different email or number</button>
                {step === 'otp'
                  ? <button type="button" className="text-brand hover:underline disabled:opacity-50" disabled={sendOtp.isPending} onClick={() => sendOtp.mutate()}>Resend OTP</button>
                  : <Link to="/forgot" className="text-brand hover:underline">Forgot password?</Link>}
              </div>
            </form>
          )}
        </Card>

        <p className="text-center text-sm text-muted-foreground">
          New retailer? <Link to="/register" className="font-medium text-brand hover:underline">Request an account</Link>
        </p>
        <InstallPrompt appName={tenant.name} />
      </div>
    </div>
  )
}
