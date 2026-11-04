import { ArrowRight, LogIn, Mail, TriangleAlert } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router'
import { googleRedirectUrl } from '@/api/endpoints/auth'
import { errorMessage, isApiError } from '@/api/errors'
import { useSession } from '@/session/SessionProvider'
import { safeNext } from '@/lib/nextPath'
import { leaveSite } from '@/lib/leaveSite'
import { useCooldown } from '@/lib/useCooldown'
import { useServerErrors } from '@/lib/useServerErrors'
import { Button, Input, useToast } from '@/components/ui'
import { AuthCard, AuthIcon, OrDivider, Reveal, Shake } from './components/AuthCard'
import { GoogleButton } from './components/GoogleButton'
import { PasswordInput } from './components/PasswordInput'
import { googleErrorMessage, validateEmail } from './components/authUtils'

export default function SignInPage() {
  const session = useSession()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const toast = useToast()
  const next = safeNext(params.get('next'))
  const googleError = googleErrorMessage(params.get('error'))

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [touched, setTouched] = useState({ email: false, password: false })
  const [loading, setLoading] = useState<'email' | 'google' | null>(null)
  const [shake, setShake] = useState(0)
  const [formError, setFormError] = useState<string | undefined>(googleError)
  const server = useServerErrors()
  const cooldown = useCooldown()

  const emailError = server.get('email') ?? (touched.email ? validateEmail(email) : undefined)
  const passwordError = server.get('password') ?? (touched.password && !password ? 'Enter your password' : undefined)

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setTouched({ email: true, password: true })
    if (validateEmail(email) || !password) {
      setShake((n) => n + 1)
      return
    }
    setLoading('email')
    setFormError(undefined)
    server.reset()
    try {
      await session.signIn(email.trim(), password, remember)
      toast({ tone: 'success', title: 'Welcome back' })
      navigate(next, { replace: true })
    } catch (err) {
      server.set(err)
      if (isApiError(err) && err.status === 429 && err.retryAfter) cooldown.start(err.retryAfter)
      // A 422 whose message sits on a field is shown on the input; anything else is a form-level message.
      if (!isApiError(err) || Object.keys(err.fields).length === 0) setFormError(errorMessage(err))
      setShake((n) => n + 1)
      setLoading(null)
    }
  }

  const onGoogle = () => {
    setLoading('google')
    leaveSite(googleRedirectUrl())
  }

  // Already signed in (for example a second tab): go where the member was headed.
  if (session.status === 'member' && !loading) return <Navigate to={next} replace />

  return (
    <AuthCard
      icon={<AuthIcon><LogIn /></AuthIcon>}
      title={<>Welcome <span className="text-gradient">back</span></>}
      subtitle="Sign in to vote, comment and follow makers."
      footer={
        <>
          New to Launchpad?{' '}
          <Link to="/signup" className="font-semibold text-brand-600 hover:underline dark:text-brand-400">
            Create a free account
          </Link>
        </>
      }
    >
      {formError && (
        <Reveal>
          <p role="alert" className="mb-4 flex items-start gap-2 rounded-xl bg-red-50 p-3 text-sm font-medium text-red-600 dark:bg-red-500/10 dark:text-red-400">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {formError}
          </p>
        </Reveal>
      )}
      <Reveal>
        <GoogleButton onClick={onGoogle} loading={loading === 'google'} />
      </Reveal>
      <Reveal>
        <OrDivider label="or with email" />
      </Reveal>
      <Reveal>
        <Shake trigger={shake}>
          <form onSubmit={onSubmit} noValidate className="space-y-4">
            <Input
              label="Email"
              type="email"
              autoComplete="email"
              inputMode="email"
              placeholder="you@example.com"
              leading={<Mail className="size-4" />}
              value={email}
              onChange={(e) => { setEmail(e.target.value); server.clear('email') }}
              onBlur={() => setTouched((t) => ({ ...t, email: true }))}
              error={emailError}
            />
            <div className="space-y-1.5">
              <PasswordInput
                label="Password"
                autoComplete="current-password"
                placeholder="Your password"
                value={password}
                onChange={(e) => { setPassword(e.target.value); server.clear('password') }}
                onBlur={() => setTouched((t) => ({ ...t, password: true }))}
                error={passwordError}
              />
              <div className="flex items-center justify-between gap-4 pt-2">
                <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink-500 dark:text-ink-400">
                  <input type="checkbox" name="remember" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="size-4 accent-violet-600" />
                  Remember me
                </label>
                <Link to="/forgot-password" className="text-xs font-semibold text-ink-500 transition hover:text-brand-600 dark:text-ink-400">
                  Forgot password?
                </Link>
              </div>
            </div>
            <Button type="submit" size="lg" className="group w-full" loading={loading === 'email'} disabled={(!!loading && loading !== 'email') || cooldown.remaining > 0}>
              {cooldown.remaining > 0 ? `Try again in ${cooldown.remaining}s` : 'Sign in'}
              {cooldown.remaining === 0 && <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />}
            </Button>
          </form>
        </Shake>
      </Reveal>
    </AuthCard>
  )
}
