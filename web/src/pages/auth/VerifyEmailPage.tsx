import { AnimatePresence, motion } from 'motion/react'
import { ArrowRight, ExternalLink, MailX, RotateCw, ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { verifyEmail } from '@/api/endpoints/auth'
import { useResendVerification } from '@/api/hooks/useAuthMutations'
import { errorMessage, isApiError } from '@/api/errors'
import { useCooldown } from '@/lib/useCooldown'
import { useEmailedLink } from '@/lib/useStripQuery'
import { signInPath } from '@/lib/nextPath'
import { useSession } from '@/session/SessionProvider'
import { Button, useToast } from '@/components/ui'
import { AuthCard, Reveal, Stagger } from './components/AuthCard'
import { SuccessBurst } from './components/SuccessBurst'
import { maskEmail } from './components/authUtils'

/** The API allows one resend a minute. */
const COOLDOWN = 60

/** Floating envelope with a letter that peeks out. */
function Envelope() {
  return (
    <motion.div
      className="relative mx-auto h-24 w-32"
      animate={{ y: [0, -8, 0] }}
      transition={{ repeat: Infinity, duration: 3.2, ease: 'easeInOut' }}
      aria-hidden
    >
      <motion.div
        className="absolute inset-x-3 top-1 h-20 rounded-lg bg-white p-2 shadow-soft ring-1 ring-ink-200 dark:bg-ink-100"
        initial={{ y: 18 }}
        animate={{ y: [18, -14, -10] }}
        transition={{ duration: 1.2, delay: 0.4, ease: 'easeOut' }}
      >
        <div className="h-1.5 w-3/5 rounded-full bg-brand-300" />
        <div className="mt-1.5 h-1.5 w-4/5 rounded-full bg-ink-200" />
        <div className="mt-1.5 h-1.5 w-2/5 rounded-full bg-ink-200" />
      </motion.div>
      <div className="absolute inset-x-0 bottom-0 h-16 overflow-hidden rounded-xl bg-gradient-to-br from-brand-400 to-pink-500 shadow-glow">
        <div className="absolute -top-px left-0 h-0 w-0 border-t-[34px] border-r-[64px] border-l-[64px] border-t-white/25 border-r-transparent border-l-transparent" />
      </div>
      <motion.span
        className="absolute -top-1 -right-1 flex size-6 items-center justify-center rounded-full bg-emerald-500 text-[11px] font-bold text-white ring-4 ring-white dark:ring-ink-900"
        initial={{ scale: 0 }}
        animate={{ scale: 1 }}
        transition={{ type: 'spring', stiffness: 600, damping: 14, delay: 1.3 }}
      >
        1
      </motion.span>
    </motion.div>
  )
}

function VerifiedCard({ name }: { name?: string }) {
  const navigate = useNavigate()
  return (
    <motion.div key="done" initial={{ opacity: 0, scale: 0.96 }} animate={{ opacity: 1, scale: 1 }} transition={{ type: 'spring', stiffness: 300, damping: 26 }}>
      <div className="surface p-6 text-center sm:p-8">
        <SuccessBurst />
        <Stagger>
          <Reveal>
            <h1 className="mt-6 text-[1.75rem] font-extrabold">You’re <span className="text-gradient">verified</span>!</h1>
            <p className="mt-1.5 text-sm text-ink-500">{name ? `Thanks, ${name.split(' ')[0]}. ` : ''}Your votes now count on the front page.</p>
          </Reveal>
          <Reveal>
            <Button size="lg" className="group mt-6 w-full" onClick={() => navigate('/', { replace: true })}>
              See today’s launches
              <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
            </Button>
          </Reveal>
        </Stagger>
      </div>
    </motion.div>
  )
}

/** The member opened the emailed link: the signed query goes to the API unchanged, then leaves the address bar. */
function VerifyFromLink() {
  const session = useSession()
  const location = useLocation()
  const { state, search } = useEmailedLink(verifyEmail, { keepOn401: true })

  const { refresh } = session
  useEffect(() => {
    if (state.phase === 'done') void refresh()
  }, [state.phase, refresh])

  if (state.phase === 'pending') {
    return (
      <AuthCard title="Confirming your email…" subtitle="One moment.">
        <div className="h-2 animate-pulse rounded-full bg-ink-100 dark:bg-white/10" />
      </AuthCard>
    )
  }
  if (state.phase === 'done') return <VerifiedCard name={state.data.name} />

  const err = state.error
  if (isApiError(err) && err.status === 401) {
    return (
      <AuthCard icon={<MailX className="size-8 text-brand-500" />} title="Sign in to confirm" subtitle="Open this link while signed in to the account you registered, so we know whose email this is.">
        <Link to={signInPath(`${location.pathname}${search}`)}>
          <Button size="lg" className="w-full">Sign in</Button>
        </Link>
      </AuthCard>
    )
  }
  const invalid = isApiError(err) && err.status === 403 && err.code !== 'account_unavailable'
  return (
    <AuthCard
      icon={<MailX className="size-8 text-brand-500" />}
      title="We couldn’t confirm that link"
      subtitle={invalid ? 'It is invalid, expired, or belongs to a different account or address. Ask for a new one.' : errorMessage(err)}
    >
      <Link to="/verify-email" state={{ fresh: true }}>
        <Button size="lg" className="w-full">Get a new link</Button>
      </Link>
    </AuthCard>
  )
}

/** Where sign-up lands: "check your inbox", with a resend. */
function VerifyPending() {
  const session = useSession()
  const user = session.user
  const navigate = useNavigate()
  const toast = useToast()
  const location = useLocation()
  const justRegistered = !!(location.state as { email?: string } | null)?.email
  const email = (location.state as { email?: string } | null)?.email ?? user?.email ?? ''
  const cooldown = useCooldown(justRegistered ? COOLDOWN : 0)
  const resend = useResendVerification()

  if (session.status === 'loading') return <AuthCard title="One moment…"><div className="h-2 animate-pulse rounded-full bg-ink-100 dark:bg-white/10" /></AuthCard>

  if (!user) {
    return (
      <AuthCard title="No account in progress" subtitle="Create an account first and we’ll send you a confirmation link.">
        <Link to="/signup">
          <Button size="lg" className="w-full">Create an account</Button>
        </Link>
      </AuthCard>
    )
  }

  const onResend = () =>
    resend.mutate(undefined, {
      onSuccess: () => {
        cooldown.start(COOLDOWN)
        toast({ tone: 'info', title: 'Link sent again', body: `Check ${maskEmail(email)} (and your spam folder).` })
      },
      onError: (e) => {
        if (isApiError(e) && e.retryAfter) cooldown.start(e.retryAfter)
        toast({ tone: 'error', title: 'Could not send the link', body: errorMessage(e) })
      },
    })

  return (
    <AnimatePresence mode="wait">
      {user.emailVerified ? (
        <VerifiedCard name={user.name} />
      ) : (
        <motion.div key="pending" exit={{ opacity: 0, scale: 0.96 }}>
          <AuthCard
            icon={<Envelope />}
            className="text-center"
            title="Check your inbox"
            subtitle={
              <>
                We sent a confirmation link to <strong className="font-semibold text-ink-800 dark:text-ink-100">{maskEmail(email)}</strong>. Tap it to activate your account.
              </>
            }
            footer={
              <>
                Wrong email?{' '}
                <button onClick={() => void session.signOut().then(() => navigate('/signup'))} className="cursor-pointer font-semibold text-brand-600 hover:underline dark:text-brand-400">
                  Start over
                </button>
              </>
            }
          >
            <Reveal className="flex gap-3 rounded-xl bg-brand-50 p-3.5 text-left dark:bg-brand-500/10">
              <ShieldCheck className="mt-0.5 size-5 shrink-0 text-brand-500" />
              <p className="text-sm text-ink-700 dark:text-ink-200">
                <span className="font-semibold">Why we ask:</span> only real people should vote. Confirming your email keeps fake accounts from gaming the rankings.
              </p>
            </Reveal>
            <Reveal className="mt-5 grid gap-2 sm:grid-cols-2">
              <Button variant="outline" className="w-full" icon={<ExternalLink className="size-4" />} onClick={() => (window.location.href = 'mailto:')}>
                Open mail app
              </Button>
              <Button variant="ghost" className="w-full" onClick={onResend} loading={resend.isPending} disabled={cooldown.remaining > 0} icon={<RotateCw className="size-4" />}>
                {cooldown.remaining > 0 ? (
                  <span>
                    Resend in <span className="inline-block w-6 text-left tabular-nums">{cooldown.remaining}s</span>
                  </span>
                ) : (
                  'Resend link'
                )}
              </Button>
            </Reveal>
          </AuthCard>
        </motion.div>
      )}
    </AnimatePresence>
  )
}

export default function VerifyEmailPage() {
  const location = useLocation()
  // Decided once: the query leaves the address bar as soon as the link was used, and the page must not switch modes then.
  const [opened] = useState(() => new URLSearchParams(location.search).has('signature'))
  const fresh = !!(location.state as { fresh?: boolean } | null)?.fresh
  return opened && !fresh ? <VerifyFromLink /> : <VerifyPending />
}
