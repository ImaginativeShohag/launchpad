import { LogIn, MailCheck, Sparkles } from 'lucide-react'
import { createContext, use, useCallback, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { useResendVerification } from '@/api/hooks/useAuthMutations'
import { errorMessage, isApiError } from '@/api/errors'
import { useSession } from '@/session/SessionProvider'
import { useCooldown } from '@/lib/useCooldown'
import { Button, Modal, useToast } from '@/components/ui'

type Gate = (reason: string, run?: () => void) => boolean
const AuthGateContext = createContext<{ gate: Gate; promptVerify: () => void } | null>(null)

/** Wraps member-only actions. Guests get a sign-in prompt, unverified members a "verify your email" prompt. */
export function AuthGateProvider({ children }: { children: ReactNode }) {
  const { status, user } = useSession()
  const navigate = useNavigate()
  const toast = useToast()
  const [reason, setReason] = useState<string | null>(null)
  const [verifyOpen, setVerifyOpen] = useState(false)
  const resend = useResendVerification()
  const cooldown = useCooldown()

  const promptVerify = useCallback(() => setVerifyOpen(true), [])

  const gate = useCallback<Gate>(
    (why, run) => {
      if (status === 'loading') return false
      if (!user) {
        setReason(why)
        return false
      }
      if (!user.emailVerified) {
        setVerifyOpen(true)
        return false
      }
      run?.()
      return true
    },
    [status, user],
  )

  const value = useMemo(() => ({ gate, promptVerify }), [gate, promptVerify])

  const onResend = () =>
    resend.mutate(undefined, {
      onSuccess: () => {
        cooldown.start(60)
        toast({ tone: 'info', title: 'Link sent', body: 'Check your inbox (and your spam folder).' })
      },
      onError: (e) => {
        if (isApiError(e) && e.retryAfter) cooldown.start(e.retryAfter)
        toast({ tone: 'error', title: 'Could not send the link', body: errorMessage(e) })
      },
    })

  return (
    <AuthGateContext value={value}>
      {children}
      <Modal
        open={!!reason}
        onClose={() => setReason(null)}
        size="sm"
        footer={
          <>
            <Button variant="outline" onClick={() => { setReason(null); navigate('/signup') }}>Create account</Button>
            <Button icon={<LogIn className="size-4" />} onClick={() => { setReason(null); navigate('/signin') }}>Sign in</Button>
          </>
        }
      >
        <div className="flex flex-col items-center pt-2 text-center">
          <div className="mb-4 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-400 to-pink-500 text-white shadow-glow">
            <Sparkles className="size-6" />
          </div>
          <h2 className="text-xl font-bold">Join Launchpad</h2>
          <p className="mt-1 text-sm text-ink-500">Sign in to {reason}. It takes 10 seconds.</p>
        </div>
      </Modal>
      <Modal
        open={verifyOpen}
        onClose={() => setVerifyOpen(false)}
        size="sm"
        footer={
          <>
            <Button variant="outline" loading={resend.isPending} disabled={cooldown.remaining > 0} onClick={onResend}>
              {cooldown.remaining > 0 ? `Resend in ${cooldown.remaining}s` : 'Resend link'}
            </Button>
            <Button onClick={() => setVerifyOpen(false)}>Got it</Button>
          </>
        }
      >
        <div className="flex flex-col items-center pt-2 text-center">
          <div className="mb-4 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-400 to-pink-500 text-white shadow-glow">
            <MailCheck className="size-6" />
          </div>
          <h2 className="text-xl font-bold">Verify your email</h2>
          <p className="mt-1 text-sm text-ink-500">Open the link we emailed you to unlock voting, comments and following. Only confirmed members count toward the rankings.</p>
        </div>
      </Modal>
    </AuthGateContext>
  )
}

export function useAuthGate(): Gate {
  const ctx = use(AuthGateContext)
  if (!ctx) throw new Error('useAuthGate must be used inside AuthGateProvider')
  return ctx.gate
}

/** Opens the "verify your email" prompt, for pages that get `email_unverified` from the API. */
export function useVerifyPrompt(): () => void {
  const ctx = use(AuthGateContext)
  if (!ctx) throw new Error('useVerifyPrompt must be used inside AuthGateProvider')
  return ctx.promptVerify
}
