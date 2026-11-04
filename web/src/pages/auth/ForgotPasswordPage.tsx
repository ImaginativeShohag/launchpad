import { AnimatePresence, motion } from 'motion/react'
import { ArrowLeft, KeyRound, Mail, MailCheck, TriangleAlert } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useForgotPassword } from '@/api/hooks/useAuthMutations'
import { errorMessage, isApiError } from '@/api/errors'
import { useCooldown } from '@/lib/useCooldown'
import { useServerErrors } from '@/lib/useServerErrors'
import { Button, Input } from '@/components/ui'
import { AuthCard, AuthIcon, Reveal, Shake } from './components/AuthCard'
import { maskEmail, validateEmail } from './components/authUtils'

export default function ForgotPasswordPage() {
  const forgot = useForgotPassword()
  const [email, setEmail] = useState('')
  const [touched, setTouched] = useState(false)
  const [sent, setSent] = useState(false)
  const [shake, setShake] = useState(0)
  const [formError, setFormError] = useState<string>()
  const server = useServerErrors()
  const cooldown = useCooldown()
  const error = server.get('email') ?? (touched ? validateEmail(email) : undefined)

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setTouched(true)
    if (validateEmail(email)) {
      setShake((n) => n + 1)
      return
    }
    setFormError(undefined)
    server.reset()
    // The answer is the same for every address, so the page never says whether the account exists.
    forgot.mutate(email.trim(), {
      onSuccess: () => setSent(true),
      onError: (err) => {
        server.set(err)
        if (isApiError(err) && err.status === 429 && err.retryAfter) cooldown.start(err.retryAfter)
        if (!isApiError(err) || Object.keys(err.fields).length === 0) setFormError(errorMessage(err))
        setShake((n) => n + 1)
      },
    })
  }

  const back = (
    <Link to="/signin" className="inline-flex items-center gap-1 font-semibold text-ink-600 transition hover:gap-2 hover:text-brand-600 dark:text-ink-300">
      <ArrowLeft className="size-4" /> Back to sign in
    </Link>
  )

  return (
    <AnimatePresence mode="wait">
      {!sent ? (
        <motion.div key="form" exit={{ opacity: 0, x: -20 }}>
          <AuthCard
            icon={<AuthIcon><KeyRound /></AuthIcon>}
            title="Forgot your password?"
            subtitle="No worries. Enter the email you signed up with and we’ll send you a reset link."
            footer={back}
          >
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
                  <Input
                    label="Email"
                    type="email"
                    inputMode="email"
                    autoComplete="email"
                    autoFocus
                    placeholder="you@example.com"
                    leading={<Mail className="size-4" />}
                    value={email}
                    onChange={(e) => { setEmail(e.target.value); server.clear('email') }}
                    onBlur={() => setTouched(true)}
                    error={error}
                  />
                  <Button type="submit" size="lg" className="w-full" loading={forgot.isPending} disabled={cooldown.remaining > 0}>
                    {cooldown.remaining > 0 ? `Try again in ${cooldown.remaining}s` : 'Send reset link'}
                  </Button>
                </form>
              </Shake>
            </Reveal>
          </AuthCard>
        </motion.div>
      ) : (
        <motion.div key="sent" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }}>
          <AuthCard
            icon={<AuthIcon tone="green"><MailCheck /></AuthIcon>}
            title="Check your email"
            subtitle={
              <>
                If an account exists for <strong className="font-semibold text-ink-800 dark:text-ink-100">{maskEmail(email.trim())}</strong>, a reset link is on its way. It expires in 1 hour. Didn’t get it? You can ask again in a minute.
              </>
            }
            footer={back}
          >
            <Reveal className="space-y-2">
              <Button variant="outline" size="lg" className="w-full" onClick={() => (window.location.href = 'mailto:')}>
                Open mail app
              </Button>
              <Button variant="ghost" className="w-full" onClick={() => setSent(false)}>
                Use a different email
              </Button>
            </Reveal>
          </AuthCard>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
