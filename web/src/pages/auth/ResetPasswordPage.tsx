import { AnimatePresence, motion } from 'motion/react'
import { ArrowRight, LinkIcon, LockKeyhole, TriangleAlert } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { useResetPassword } from '@/api/hooks/useAuthMutations'
import { errorMessage, isApiError } from '@/api/errors'
import { useCooldown } from '@/lib/useCooldown'
import { useServerErrors } from '@/lib/useServerErrors'
import { useStripQuery } from '@/lib/useStripQuery'
import { Button } from '@/components/ui'
import { AuthCard, AuthIcon, Reveal, Shake, Stagger } from './components/AuthCard'
import { PasswordInput } from './components/PasswordInput'
import { PasswordStrength, isPasswordAcceptable } from './components/PasswordStrength'
import { SuccessBurst } from './components/SuccessBurst'
import { PASSWORD_HELP } from './components/authUtils'

export default function ResetPasswordPage() {
  const navigate = useNavigate()
  // The emailed link carries `token` and `email`; read once, then gone from the address bar.
  const link = useStripQuery()
  const token = link.get('token') ?? ''
  const email = link.get('email') ?? ''
  const reset = useResetPassword()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [touched, setTouched] = useState({ password: false, confirm: false })
  const [done, setDone] = useState(false)
  const [shake, setShake] = useState(0)
  const [invalidLink, setInvalidLink] = useState(!token || !email)
  const [formError, setFormError] = useState<string>()
  const server = useServerErrors()
  const cooldown = useCooldown()

  const errors = {
    password: !isPasswordAcceptable(password) ? PASSWORD_HELP : undefined,
    confirm: !confirm ? 'Type your new password again' : confirm !== password ? 'Passwords don’t match' : undefined,
  }
  const matches = !!confirm && confirm === password

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setTouched({ password: true, confirm: true })
    if (errors.password || errors.confirm) {
      setShake((n) => n + 1)
      return
    }
    setFormError(undefined)
    server.reset()
    reset.mutate(
      { email, token, password },
      {
        onSuccess: () => setDone(true),
        onError: (err) => {
          server.set(err)
          if (isApiError(err) && err.status === 429 && err.retryAfter) cooldown.start(err.retryAfter)
          // The API answers a bad, expired or reused token with one 422 on `email`.
          if (isApiError(err) && err.fields.email) setInvalidLink(true)
          else if (!isApiError(err) || !err.fields.password) setFormError(errorMessage(err))
          setShake((n) => n + 1)
        },
      },
    )
  }

  if (invalidLink) {
    return (
      <AuthCard icon={<AuthIcon tone="violet"><LinkIcon /></AuthIcon>} title="This link doesn’t work" subtitle="It is invalid or has expired. Reset links work once and last one hour.">
        <Link to="/forgot-password">
          <Button size="lg" className="w-full">Send me a new link</Button>
        </Link>
      </AuthCard>
    )
  }

  return (
    <AnimatePresence mode="wait">
      {!done ? (
        <motion.div key="form" exit={{ opacity: 0, scale: 0.96 }}>
          <AuthCard icon={<AuthIcon><LockKeyhole /></AuthIcon>} title="Choose a new password" subtitle="Make it something you haven’t used here before.">
            {formError && (
              <Reveal>
                <p role="alert" className="mb-4 flex items-start gap-2 rounded-xl bg-red-50 p-3 text-sm font-medium text-red-600 dark:bg-red-500/10 dark:text-red-400">
                  <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {formError}
                </p>
              </Reveal>
            )}
            <Reveal>
              <Shake trigger={shake}>
                <form onSubmit={onSubmit} noValidate className="space-y-4">
                  <div>
                    <PasswordInput
                      label="New password"
                      autoComplete="new-password"
                      autoFocus
                      value={password}
                      onChange={(e) => { setPassword(e.target.value); server.clear('password') }}
                      onBlur={() => setTouched((t) => ({ ...t, password: true }))}
                      error={server.get('password') ?? (touched.password ? errors.password : undefined)}
                    />
                    <PasswordStrength password={password} />
                  </div>
                  <PasswordInput
                    label="Confirm new password"
                    autoComplete="new-password"
                    value={confirm}
                    onChange={(e) => setConfirm(e.target.value)}
                    onBlur={() => setTouched((t) => ({ ...t, confirm: true }))}
                    error={touched.confirm ? errors.confirm : undefined}
                    hint={matches ? '✓ Passwords match' : undefined}
                  />
                  <Button type="submit" size="lg" className="w-full" loading={reset.isPending} disabled={cooldown.remaining > 0}>
                    {cooldown.remaining > 0 ? `Try again in ${cooldown.remaining}s` : 'Update password'}
                  </Button>
                </form>
              </Shake>
            </Reveal>
          </AuthCard>
        </motion.div>
      ) : (
        <motion.div key="done" initial={{ opacity: 0, scale: 0.96 }} animate={{ opacity: 1, scale: 1 }} transition={{ type: 'spring', stiffness: 300, damping: 26 }}>
          <div className="surface p-6 text-center sm:p-8">
            <SuccessBurst />
            <Stagger>
              <Reveal>
                <h1 className="mt-6 text-[1.75rem] font-extrabold">Password updated</h1>
                <p className="mt-1.5 text-sm text-ink-500">For your safety we signed you out everywhere else. Sign in with your new password.</p>
              </Reveal>
              <Reveal>
                <Button size="lg" className="group mt-6 w-full" onClick={() => navigate('/signin', { replace: true })}>
                  Continue to sign in
                  <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                </Button>
              </Reveal>
            </Stagger>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
