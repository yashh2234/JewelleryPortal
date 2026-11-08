import { Alert, api, ApiError, Button, Card, Field, Input, InstallPrompt, useTenant } from '@b2b/ui'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { LogIn, Phone, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { HOME, sessionKey, useSession, type Session } from './session'

/** "9876543210", "+91 98765 43210" → "9876543210"; anything else → null. */
function asMobile(value: string): string | null {
  const digits = value.replace(/[\s-]/g, '').replace(/^(\+91|0)/, '')
  return /^[6-9]\d{9}$/.test(digits) ? digits : null
}

/**
 * The one sign-in screen for everyone.
 *   Email + password → staff dashboard or retailer portal (server decides).
 *   Mobile number    → OTP → kaarigar app.
 */
export default function Login() {
  const tenant = useTenant()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const session = useSession()

  const [id, setId] = useState('')
  const [password, setPassword] = useState('')
  const [otp, setOtp] = useState('')
  const [otpSent, setOtpSent] = useState(false)

  const mobile = asMobile(id)
  const isEmail = id.includes('@')

  const signedIn = (s: Session) => {
    qc.setQueryData(sessionKey, s)
    navigate(HOME[s.type], { replace: true })
  }

  const login = useMutation({
    mutationFn: () => api.post<Session>('/api/auth/login', { email: id.trim(), password, remember: true }),
    onSuccess: signedIn,
  })

  const sendOtp = useMutation({
    mutationFn: () => api.post<{ debug_otp?: string }>('/api/auth/otp', { phone: mobile }),
    onSuccess: (r) => {
      setOtpSent(true)
      if (r.debug_otp) setOtp(r.debug_otp) // local development only
    },
  })

  const verifyOtp = useMutation({
    mutationFn: () => api.post<Session>('/api/auth/otp/verify', { phone: mobile, otp }),
    onSuccess: signedIn,
  })

  if (session.data) return <Navigate to={HOME[session.data.type]} replace />

  const error = [login.error, sendOtp.error, verifyOtp.error].find((e) => e instanceof ApiError) as ApiError | undefined
  const message = error ? (error.field('email') ?? error.field('otp') ?? error.field('phone') ?? error.message) : null
  const pending = (error?.body as { status?: string } | null)?.status === 'pending'

  const reset = () => {
    setOtpSent(false)
    setOtp('')
    login.reset()
    sendOtp.reset()
    verifyOtp.reset()
  }

  return (
    <div className="flex min-h-dvh items-center justify-center p-4">
      <div className="w-full max-w-sm space-y-4">
        <div className="text-center">
          {tenant.logo_url ? (
            <img src={tenant.logo_url} alt="" className="mx-auto mb-3 h-16 object-contain" />
          ) : (
            <div className="mx-auto mb-3 grid size-16 place-items-center rounded-2xl bg-brand text-2xl font-bold text-brand-foreground">
              {tenant.name.charAt(0)}
            </div>
          )}
          <h1 className="text-xl font-semibold">{tenant.name}</h1>
          <p className="text-sm text-muted-foreground">Sign in · साइन इन करें</p>
        </div>

        <Card className="p-5">
          {message && <div className="mb-4"><Alert tone={pending ? 'warning' : 'danger'}>{message}</Alert></div>}

          {!otpSent ? (
            <form
              className="grid gap-4"
              onSubmit={(e) => {
                e.preventDefault()
                if (mobile) sendOtp.mutate()
                else login.mutate()
              }}
            >
              <Field label="Email or mobile number" hint="Kaarigars: enter your mobile number · कारीगर अपना मोबाइल नंबर डालें">
                <Input
                  autoFocus required autoComplete="username" className="h-12 text-base"
                  value={id} onChange={(e) => { setId(e.target.value); reset() }}
                />
              </Field>

              {!mobile && (
                <Field label="Password">
                  <Input type="password" autoComplete="current-password" required={isEmail} className="h-12 text-base"
                    value={password} onChange={(e) => setPassword(e.target.value)} />
                </Field>
              )}

              {mobile ? (
                <Button size="lg" type="submit" loading={sendOtp.isPending}><Phone /> Send OTP · OTP भेजें</Button>
              ) : (
                <Button size="lg" type="submit" disabled={!isEmail} loading={login.isPending}><LogIn /> Sign in</Button>
              )}
            </form>
          ) : (
            <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); verifyOtp.mutate() }}>
              <p className="text-center text-sm text-success">OTP sent to +91 {mobile} · OTP भेज दिया गया</p>
              <Field label="6-digit OTP · 6 अंकों का OTP">
                <Input
                  autoFocus required inputMode="numeric" autoComplete="one-time-code" maxLength={6}
                  className="h-14 text-center text-2xl tracking-[0.5em]"
                  value={otp} onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
                />
              </Field>
              <Button size="lg" type="submit" disabled={otp.length !== 6} loading={verifyOtp.isPending}>
                <ShieldCheck /> Verify · लॉगिन करें
              </Button>
              <div className="flex justify-between text-sm">
                <Button type="button" variant="link" size="sm" onClick={reset}>Change number</Button>
                <Button type="button" variant="link" size="sm" disabled={sendOtp.isPending} onClick={() => sendOtp.mutate()}>Resend OTP</Button>
              </div>
            </form>
          )}
        </Card>

        <p className="text-center text-sm text-muted-foreground">
          <Link to="/forgot" className="hover:underline">Forgot password?</Link>
        </p>
        <p className="text-center text-sm text-muted-foreground">
          Retailer without an account? <Link to="/register" className="font-medium text-brand hover:underline">Request one</Link>
        </p>
        <InstallPrompt appName={tenant.name} />
      </div>
    </div>
  )
}
