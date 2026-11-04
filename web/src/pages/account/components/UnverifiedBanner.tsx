import { MailWarning } from 'lucide-react'
import { useResendVerification } from '@/api/hooks/useAuthMutations'
import { errorMessage, isApiError } from '@/api/errors'
import { useCooldown } from '@/lib/useCooldown'
import { Button, useToast } from '@/components/ui'

/** Shown on Settings tabs whose writes the API refuses until the email is confirmed (verified.api). */
export function UnverifiedBanner({ email }: { email?: string }) {
  const resend = useResendVerification()
  const toast = useToast()
  const cooldown = useCooldown()
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
    <div role="status" className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
      <MailWarning className="size-5 shrink-0" />
      <p className="min-w-0 flex-1">
        Confirm your email{email ? <> (<strong className="font-semibold">{email}</strong>)</> : null} to edit your profile, photo and email address.
      </p>
      <Button type="button" size="sm" variant="outline" loading={resend.isPending} disabled={cooldown.remaining > 0} onClick={onResend}>
        {cooldown.remaining > 0 ? `Resend in ${cooldown.remaining}s` : 'Resend link'}
      </Button>
    </div>
  )
}
