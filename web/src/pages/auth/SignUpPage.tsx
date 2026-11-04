import { ArrowRight, AtSign, Mail, Sparkles, TriangleAlert, User as UserIcon } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { googleRedirectUrl } from '@/api/endpoints/auth'
import { useRegister } from '@/api/hooks/useAuthMutations'
import { errorMessage, isApiError } from '@/api/errors'
import { leaveSite } from '@/lib/leaveSite'
import { useCooldown } from '@/lib/useCooldown'
import { useServerErrors } from '@/lib/useServerErrors'
import { Button, Input } from '@/components/ui'
import { AuthCard, AuthIcon, OrDivider, Reveal, Shake } from './components/AuthCard'
import { GoogleButton } from './components/GoogleButton'
import { PasswordInput } from './components/PasswordInput'
import { PasswordStrength, isPasswordAcceptable } from './components/PasswordStrength'
import { HANDLE_RE, PASSWORD_HELP, suggestHandle, validateEmail } from './components/authUtils'

export default function SignUpPage() {
  const navigate = useNavigate()
  const register = useRegister()

  const [form, setForm] = useState({ name: '', email: '', handle: '', password: '' })
  const [handleEdited, setHandleEdited] = useState(false)
  const [touched, setTouched] = useState({ name: false, email: false, handle: false, password: false })
  const [google, setGoogle] = useState(false)
  const [shake, setShake] = useState(0)
  const [formError, setFormError] = useState<string>()
  const server = useServerErrors()
  const cooldown = useCooldown()

  const errors = {
    name: form.name.trim().length < 2 ? 'Tell us your name' : undefined,
    email: validateEmail(form.email),
    handle: !HANDLE_RE.test(form.handle) ? '3–30 lowercase letters, numbers or _' : undefined,
    password: !isPasswordAcceptable(form.password) ? PASSWORD_HELP : undefined,
  }
  const show = (k: keyof typeof errors) => server.get(k) ?? (touched[k] ? errors[k] : undefined)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    server.clear(k)
    setForm((f) => {
      const next = { ...f, [k]: k === 'handle' ? value.toLowerCase().replace(/\s/g, '') : value }
      if (k === 'name' && !handleEdited) next.handle = suggestHandle(value)
      return next
    })
    if (k === 'handle') setHandleEdited(true)
  }
  const blur = (k: keyof typeof touched) => () => setTouched((t) => ({ ...t, [k]: true }))

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setTouched({ name: true, email: true, handle: true, password: true })
    if (errors.name || errors.email || errors.handle || errors.password) {
      setShake((n) => n + 1)
      return
    }
    setFormError(undefined)
    server.reset()
    const email = form.email.trim()
    register.mutate(
      { name: form.name.trim(), email, handle: form.handle, password: form.password },
      {
        onSuccess: () => navigate('/verify-email', { state: { email } }),
        onError: (err) => {
          server.set(err)
          if (isApiError(err) && err.status === 429 && err.retryAfter) cooldown.start(err.retryAfter)
          if (!isApiError(err) || Object.keys(err.fields).length === 0) setFormError(errorMessage(err))
          setShake((n) => n + 1)
        },
      },
    )
  }

  const onGoogle = () => {
    setGoogle(true)
    leaveSite(googleRedirectUrl())
  }

  return (
    <AuthCard
      icon={<AuthIcon tone="violet"><Sparkles /></AuthIcon>}
      title={<>Join <span className="text-gradient">Launchpad</span></>}
      subtitle="Discover what launched today and back the makers you love."
      footer={
        <>
          Already have an account?{' '}
          <Link to="/signin" className="font-semibold text-brand-600 hover:underline dark:text-brand-400">
            Sign in
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
        <GoogleButton label="Sign up with Google" onClick={onGoogle} loading={google} />
      </Reveal>
      <Reveal>
        <OrDivider label="or with email" />
      </Reveal>
      <Reveal>
        <Shake trigger={shake}>
          <form onSubmit={onSubmit} noValidate className="space-y-4">
            <Input
              label="Full name"
              autoComplete="name"
              placeholder="Ada Lovelace"
              leading={<UserIcon className="size-4" />}
              value={form.name}
              onChange={set('name')}
              onBlur={blur('name')}
              error={show('name')}
            />
            <Input
              label="Email"
              type="email"
              inputMode="email"
              autoComplete="email"
              placeholder="you@example.com"
              leading={<Mail className="size-4" />}
              value={form.email}
              onChange={set('email')}
              onBlur={blur('email')}
              error={show('email')}
              hint="We’ll send a link to confirm it’s really you."
            />
            <Input
              label="Handle"
              autoComplete="username"
              placeholder="ada_lovelace"
              leading={<AtSign className="size-4" />}
              value={form.handle}
              onChange={set('handle')}
              onBlur={blur('handle')}
              error={show('handle')}
              hint={`launchpad.app/u/${form.handle || 'handle'}`}
            />
            <div>
              <PasswordInput
                label="Password"
                autoComplete="new-password"
                placeholder="Create a password"
                value={form.password}
                onChange={set('password')}
                onBlur={blur('password')}
                error={show('password')}
              />
              <PasswordStrength password={form.password} />
            </div>
            <Button type="submit" size="lg" className="group w-full" loading={register.isPending} disabled={google || cooldown.remaining > 0}>
              {cooldown.remaining > 0 ? `Try again in ${cooldown.remaining}s` : 'Create account'}
              {cooldown.remaining === 0 && <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />}
            </Button>
            <p className="text-center text-xs leading-relaxed text-ink-400">
              By creating an account you agree to our <a href="#" className="underline hover:text-ink-600">Terms</a> and{' '}
              <a href="#" className="underline hover:text-ink-600">Privacy Policy</a>. We never share your password or unlaunched products.
            </p>
          </form>
        </Shake>
      </Reveal>
    </AuthCard>
  )
}
