import { motion } from 'motion/react'
import { Flag } from 'lucide-react'
import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { errorMessage, firstFieldError, isApiError } from '@/api/errors'
import { useReport } from '@/api/hooks/useReport'
import type { ReportReason } from '@/data/types'
import { useCooldown } from '@/lib/useCooldown'
import { Button, cn, Modal, Textarea, useToast } from '@/components/ui'

const reasons: { value: ReportReason; label: string; hint: string }[] = [
  { value: 'spam', label: 'Spam', hint: 'Ads, scams, or repeated content' },
  { value: 'abuse', label: 'Abuse', hint: 'Harassment, hate, or threats' },
  { value: 'misleading', label: 'Misleading', hint: 'False claims or impersonation' },
  { value: 'inappropriate', label: 'Inappropriate', hint: 'Content that does not belong here' },
  { value: 'other', label: 'Other', hint: 'Something else is wrong' },
]

const MAX_DETAILS = 1000

/** What the API's report rules mean to a reporter. */
function reportProblem(e: unknown): string {
  if (isApiError(e)) {
    if (e.code === 'own_content') return 'You cannot report your own content.'
    if (e.code === 'already_reviewed') return 'Our team already reviewed your earlier report on this and found nothing to act on.'
    if (e.code === 'report_limit') return 'You have too many open reports. Please wait until some are reviewed.'
    if (e.code === 'email_unverified') return 'Verify your email to send reports.'
    if (e.status === 404) return 'This is no longer available to report.'
  }
  return errorMessage(e)
}

/** Report a product or a comment. Caller is responsible for auth-gating before opening. */
export function ReportModal({ open, onClose, kind, targetId, subject }: { open: boolean; onClose: () => void; kind: 'product' | 'comment'; targetId: string; subject: string }) {
  const report = useReport()
  const toast = useToast()
  const wait = useCooldown()
  const [reason, setReason] = useState<ReportReason | null>(null)
  const [details, setDetails] = useState('')

  const resetTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const radios = useRef<(HTMLButtonElement | null)[]>([])
  const reset = report.reset
  // The form is cleared after the close animation; reopening or leaving the page cancels that.
  useEffect(() => {
    if (open) clearTimeout(resetTimer.current)
  }, [open])
  useEffect(() => () => clearTimeout(resetTimer.current), [])

  const close = () => {
    onClose()
    clearTimeout(resetTimer.current)
    resetTimer.current = setTimeout(() => {
      setReason(null)
      setDetails('')
      reset()
    }, 200)
  }

  // Arrow keys move the choice, like a native radio group.
  const onRadioKey = (e: KeyboardEvent, i: number) => {
    const step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0
    if (!step) return
    e.preventDefault()
    const next = (i + step + reasons.length) % reasons.length
    setReason(reasons[next].value)
    radios.current[next]?.focus()
  }

  const submit = () => {
    if (!reason) return
    report.mutate(
      { kind, targetId, reason, details: details.trim() || undefined },
      {
        onSuccess: () => {
          toast({ tone: 'success', title: 'Thanks for the report', body: 'A reviewer will take a look shortly.' })
          close()
        },
        onError: (e) => isApiError(e) && e.status === 429 && wait.start(e.retryAfter ?? 5),
      },
    )
  }

  const needsDetails = (reason === 'other' && !details.trim()) || Array.from(details).length > MAX_DETAILS
  const fieldError = firstFieldError(report.error, 'details') ?? firstFieldError(report.error, 'reason')
  const failure = report.isError && !fieldError ? reportProblem(report.error) : undefined

  return (
    <Modal
      open={open}
      onClose={close}
      title={
        <span className="inline-flex items-center gap-2">
          <Flag className="size-5 text-red-500" /> Report {kind}
        </span>
      }
      description={<>What’s wrong with {subject}? Reports are private.</>}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            Cancel
          </Button>
          <Button variant="danger" loading={report.isPending} disabled={!reason || needsDetails || wait.remaining > 0} onClick={submit}>
            {wait.remaining > 0 ? `Try again in ${wait.remaining} s` : 'Send report'}
          </Button>
        </>
      }
    >
      <div role="radiogroup" aria-label="Reason" className="grid gap-2 sm:grid-cols-2">
        {reasons.map((r, i) => {
          const active = reason === r.value
          return (
            <motion.button
              key={r.value}
              type="button"
              role="radio"
              aria-checked={active}
              tabIndex={active || (!reason && i === 0) ? 0 : -1}
              ref={(el) => {
                radios.current[i] = el
              }}
              onKeyDown={(e) => onRadioKey(e, i)}
              whileTap={{ scale: 0.97 }}
              onClick={() => setReason(r.value)}
              className={cn(
                'relative cursor-pointer rounded-2xl border p-3 text-left transition-colors',
                active ? 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10' : 'border-ink-200 hover:border-ink-300 dark:border-white/10 dark:hover:border-white/20',
              )}
            >
              <span className="flex items-center gap-2 text-sm font-semibold">
                <span className={cn('flex size-4 items-center justify-center rounded-full border-2 transition-colors', active ? 'border-red-500' : 'border-ink-300 dark:border-ink-600')}>
                  {active && <motion.span layoutId="report-dot" className="size-2 rounded-full bg-red-500" />}
                </span>
                {r.label}
              </span>
              <span className="mt-0.5 block pl-6 text-xs text-ink-500">{r.hint}</span>
            </motion.button>
          )
        })}
      </div>
      <div className="mt-4">
        <Textarea
          label="Details"
          hint={reason === 'other' ? 'Required for “Other”.' : 'Optional, but it helps reviewers.'}
          placeholder="Tell us a little more…"
          value={details}
          counter={{ value: Array.from(details).length, max: MAX_DETAILS }}
          error={fieldError}
          onChange={(e) => setDetails(e.target.value)}
          className="min-h-24"
        />
      </div>
      {failure && (
        <p role="alert" className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
          {failure}
        </p>
      )}
    </Modal>
  )
}
