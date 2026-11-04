import { AnimatePresence, motion } from 'motion/react'
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock,
  ExternalLink,
  Fingerprint,
  MessageSquareWarning,
  Send,
  ShieldAlert,
  XCircle,
} from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import type { StaffProduct } from '@/data/types'
import { useReviewDecision, useStaffProduct, useStaffQueue } from '@/api/hooks/useStaffReview'
import { isApiError } from '@/api/errors'
import { staffFieldMessage, staffMessage } from '@/api/staffErrors'
import { Avatar, Badge, Modal, Button, Skeleton, cn, useToast } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'
import { TopicChip } from '@/components/product/TopicChip'
import { timeAgo } from '@/lib/date'
import { formatWait, hoursSince, waitTone } from './components/wait'
import { InlineError, Kbd, ReasonChips, StaffLoadError, StatusBadge, appendReason } from './components/shared'

type Decision = 'approved' | 'changes_requested' | 'rejected'

const GUIDELINES = [
  'Real, working product — not just a waitlist page',
  'Name, tagline and description are accurate',
  'Screenshots show the actual product',
  'Website loads and matches the submission',
  'No spam, hype or “guaranteed results” language',
  'Topics fit the product',
]

const REASONS: Record<Exclude<Decision, 'approved'>, string[]> = {
  changes_requested: [
    'Please add real product screenshots.',
    'Make the tagline say what the product does.',
    'The website link is broken or only a waitlist.',
    'Pick topics that match the product.',
  ],
  rejected: [
    'Spam or misleading claims.',
    'Not a real, working product.',
    'Duplicate of an existing launch.',
    'Violates our community guidelines.',
  ],
}

const DECISIONS: { value: Decision; label: string; key: string; icon: typeof Check; idle: string; active: string }[] = [
  {
    value: 'approved',
    label: 'Approve',
    key: 'A',
    icon: CheckCircle2,
    idle: 'hover:border-emerald-300 hover:text-emerald-700 dark:hover:text-emerald-300',
    active: 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-4 ring-emerald-500/15 dark:bg-emerald-500/10 dark:text-emerald-300',
  },
  {
    value: 'changes_requested',
    label: 'Request changes',
    key: 'C',
    icon: MessageSquareWarning,
    idle: 'hover:border-amber-300 hover:text-amber-700 dark:hover:text-amber-300',
    active: 'border-amber-500 bg-amber-50 text-amber-700 ring-4 ring-amber-500/15 dark:bg-amber-500/10 dark:text-amber-300',
  },
  {
    value: 'rejected',
    label: 'Reject',
    key: 'R',
    icon: XCircle,
    idle: 'hover:border-red-300 hover:text-red-700 dark:hover:text-red-300',
    active: 'border-red-500 bg-red-50 text-red-700 ring-4 ring-red-500/15 dark:bg-red-500/10 dark:text-red-300',
  },
]

const decisionCopy: Record<Decision, { title: string; button: string; tone: string }> = {
  approved: { title: 'Approve this submission?', button: 'Approve & notify', tone: 'bg-emerald-600 hover:bg-emerald-500' },
  changes_requested: { title: 'Send back for changes?', button: 'Request changes', tone: 'bg-amber-500 hover:bg-amber-400' },
  rejected: { title: 'Reject this submission?', button: 'Reject & notify', tone: 'bg-red-600 hover:bg-red-500' },
}

export default function ReviewDetailPage() {
  const { id } = useParams()
  return <ReviewDetail key={id} id={id ?? ''} />
}

function ReviewDetail({ id }: { id: string }) {
  const navigate = useNavigate()
  const toast = useToast()
  const detail = useStaffProduct(id)
  const queueQuery = useStaffQueue()
  const decide = useReviewDecision()
  const product = detail.data
  const maker = product?.maker

  const queue = queueQuery.items
  const position = queue.findIndex((p) => p.id === id)

  const [checked, setChecked] = useState<boolean[]>(() => GUIDELINES.map(() => false))
  const [decision, setDecision] = useState<Decision | null>(null)
  const [note, setNote] = useState('')
  const [noteError, setNoteError] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [shot, setShot] = useState(0)
  const noteRef = useRef<HTMLTextAreaElement>(null)

  const decided = !!product && product.status !== 'pending'
  const noteMessage = staffFieldMessage(decide.error, 'note')
  const needsNote = decision === 'changes_requested' || decision === 'rejected'

  // After a refused decision the product is read again: nothing can be decided until the fresh content is on screen.
  const locked = decide.isError && detail.isFetching

  const choose = (d: Decision) => {
    if (locked) return
    decide.reset()
    setDecision(d)
    setNoteError(false)
    if (d !== 'approved') setTimeout(() => noteRef.current?.focus(), 80)
  }

  const requestSubmit = () => {
    if (!decision || locked) return
    if (needsNote && !note.trim()) {
      setNoteError(true)
      noteRef.current?.focus()
      return
    }
    setConfirming(true)
  }

  const apply = () => {
    if (!product || !decision || !product.submittedAt || decide.isPending) return
    // The next item is picked before the queue is read again: the one after this, else the first other one.
    const rest = queue.filter((p) => p.id !== product.id)
    const next = queue[position + 1] ?? rest[0]
    // `submittedAt` goes back exactly as the API sent it: that is how it tells a resubmission from the version we looked at.
    decide.mutate(
      { id: product.id, decision, note: note.trim() || null, submittedAt: product.submittedAt },
      {
        onSuccess: () => {
          setConfirming(false)
          toast({ tone: 'success', title: 'Founder notified', body: `${maker?.name ?? 'The maker'} will get an email and an in-app notification.` })
          navigate(next ? `/staff/review/${next.id}` : '/staff')
        },
        onError: (e) => {
          // The message shows outside the dialog, next to the decision, where the refreshed content is.
          if (!isApiError(e) || (e.code !== 'stale_submission' && e.code !== 'invalid_status')) return
          setConfirming(false)
          if (e.code === 'stale_submission') setDecision(null)
        },
      },
    )
  }

  // Keyboard shortcuts: A / C / R pick a decision, ⌘/Ctrl+Enter sends.
  const submitRef = useRef(requestSubmit)
  submitRef.current = requestSubmit
  const busyRef = useRef(false)
  busyRef.current = decide.isPending || locked
  useEffect(() => {
    if (decided || confirming || !product) return
    const onKey = (e: KeyboardEvent) => {
      if (e.isComposing || e.repeat || busyRef.current) return
      if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
        if ((e.target as HTMLElement).closest('[role="dialog"]')) return
        e.preventDefault()
        submitRef.current()
        return
      }
      const el = e.target as HTMLElement
      if (e.metaKey || e.ctrlKey || e.altKey || el.closest('input, textarea, select, [contenteditable="true"], [role="dialog"]')) return
      const k = e.key.toLowerCase()
      if (k === 'a') choose('approved')
      else if (k === 'c') {
        e.preventDefault()
        choose('changes_requested')
      } else if (k === 'r') {
        e.preventDefault()
        choose('rejected')
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [decided, confirming, product])

  if (detail.isPending) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-96 w-full" />
      </div>
    )
  }
  if (!product) {
    return <StaffLoadError error={detail.error} onRetry={() => void detail.refetch()} />
  }

  const prev = position > 0 ? queue[position - 1] : undefined
  const nextItem = position >= 0 ? queue[position + 1] : undefined
  const wait = hoursSince(product.submittedAt)
  const unchecked = checked.filter((c) => !c).length

  return (
    <div>
      {/* Top bar */}
      <div className="mb-4 flex items-center justify-between gap-2">
        <Link to="/staff" className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-semibold text-ink-500 transition hover:bg-ink-100 hover:text-ink-900 dark:hover:bg-white/5 dark:hover:text-white">
          <ArrowLeft className="size-4" /> Queue
        </Link>
        {position >= 0 && (
          <div className="flex items-center gap-1">
            <span className="mr-1 text-sm text-ink-500 tabular-nums">
              {position + 1} of {queue.length}
            </span>
            <NavArrow to={prev && `/staff/review/${prev.id}`} label="Previous submission">
              <ChevronLeft className="size-4" />
            </NavArrow>
            <NavArrow to={nextItem && `/staff/review/${nextItem.id}`} label="Next submission">
              <ChevronRight className="size-4" />
            </NavArrow>
          </div>
        )}
      </div>

      {decided && (
        <motion.div initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }} className="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-800 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200">
          <CheckCircle2 className="size-4" />
          This submission already has a decision: <StatusBadge status={product.status} />
          {product.reviews[0]?.note && <span className="text-violet-700/80 dark:text-violet-300/80">“{product.reviews[0].note}”</span>}
          {product.takedownReason && <span className="text-violet-700/80 dark:text-violet-300/80">Taken down: “{product.takedownReason}”</span>}
        </motion.div>
      )}

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        {/* Preview */}
        <article className="surface overflow-hidden">
          <div className="flex items-center justify-between border-b border-ink-100 px-4 py-2.5 text-xs font-semibold tracking-wide text-ink-400 uppercase dark:border-white/5">
            Submission preview
            {!decided && (
              <span className={cn('inline-flex items-center gap-1 normal-case tracking-normal', { green: 'text-emerald-600', amber: 'text-amber-600', red: 'text-red-600' }[waitTone(wait)])}>
                <Clock className="size-3.5" /> waiting {formatWait(wait)}
              </span>
            )}
          </div>
          <div className="p-4 sm:p-6">
            <div className="flex items-start gap-4">
              <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="lg" />
              <div className="min-w-0 flex-1">
                <h2 className="text-2xl font-bold sm:text-3xl">{product.name}</h2>
                <p className="mt-1 text-ink-600 dark:text-ink-300">{product.tagline}</p>
                <div className="mt-3 flex flex-wrap items-center gap-1.5">
                  {product.topics.map((t) => (
                    <TopicChip key={t.id} topic={t} asLink={false} />
                  ))}
                </div>
              </div>
            </div>

            {/^https?:\/\//i.test(product.website) && (
              <a
                href={product.website}
                target="_blank"
                rel="noreferrer noopener"
                className="mt-4 inline-flex max-w-full items-center gap-1.5 truncate rounded-lg bg-ink-100 px-2.5 py-1.5 text-sm font-medium text-ink-700 transition hover:bg-violet-100 hover:text-violet-700 dark:bg-white/5 dark:text-ink-200 dark:hover:bg-violet-500/15"
              >
                <ExternalLink className="size-3.5 shrink-0" /> {product.website.replace(/^https?:\/\//, '')}
              </a>
            )}

            {product.screenshots.length > 0 ? (
              <div className="mt-5">
                <AnimatePresence mode="wait">
                  <motion.div key={shot} initial={{ opacity: 0, scale: 0.98 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.18 }}>
                    <img src={product.screenshots[shot]?.url} alt={product.screenshots[shot]?.alt || `Screenshot ${shot + 1}`} className="aspect-[16/10] w-full rounded-2xl border border-ink-200 bg-ink-50 object-contain dark:border-white/10 dark:bg-ink-950" />
                  </motion.div>
                </AnimatePresence>
                {product.screenshots.length > 1 && (
                  <div className="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-5">
                    {product.screenshots.map((s, i) => (
                      <button
                        key={s.id}
                        onClick={() => setShot(i)}
                        aria-label={`Screenshot ${i + 1}`}
                        aria-current={i === shot}
                        className={cn('cursor-pointer overflow-hidden rounded-xl transition', i === shot ? 'ring-2 ring-violet-500 ring-offset-2 dark:ring-offset-ink-900' : 'opacity-60 hover:opacity-100')}
                      >
                        <img src={s.url} alt="" className="aspect-[16/10] w-full object-cover" />
                      </button>
                    ))}
                  </div>
                )}
              </div>
            ) : (
              <div className="mt-5 rounded-2xl border border-dashed border-ink-200 p-8 text-center text-sm text-ink-500 dark:border-white/10">No screenshots provided</div>
            )}

            <h3 className="mt-6 mb-2 text-sm font-bold tracking-wide text-ink-400 uppercase">Description</h3>
            <p className="text-[15px] leading-relaxed whitespace-pre-line text-ink-700 dark:text-ink-200">{product.description || <em className="text-ink-400">No description</em>}</p>

            {product.links.length > 0 && (
              <>
                <h3 className="mt-6 mb-2 text-sm font-bold tracking-wide text-ink-400 uppercase">Links</h3>
                <ul className="space-y-1 text-sm">
                  {product.links.map((l) => (
                    <li key={`${l.kind}-${l.url}`} className="flex gap-2">
                      <span className="w-20 shrink-0 text-ink-400">{l.kind}</span>
                      <span className="min-w-0 break-all text-ink-700 dark:text-ink-200">{l.url}</span>
                    </li>
                  ))}
                </ul>
              </>
            )}

            {product.reviews.length > 0 && (
              <>
                <h3 className="mt-6 mb-2 text-sm font-bold tracking-wide text-ink-400 uppercase">Review history</h3>
                <ul className="space-y-2">
                  {product.reviews.map((r) => (
                    <li key={r.id} className="rounded-xl border border-ink-200/70 bg-ink-50/60 p-3 text-sm dark:border-white/5 dark:bg-white/[0.03]">
                      <p className="flex flex-wrap items-center gap-2 text-xs text-ink-500">
                        <Badge tone={r.decision === 'approved' ? 'green' : r.decision === 'rejected' ? 'red' : 'amber'}>{r.decision.replace('_', ' ')}</Badge>
                        {r.reviewer && <span>by @{r.reviewer}</span>}
                        <span>{timeAgo(r.createdAt)}</span>
                      </p>
                      {r.note && <p className="mt-1.5 whitespace-pre-line text-ink-700 dark:text-ink-200">{r.note}</p>}
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </article>

        {/* Sidebar */}
        <aside className="space-y-4 lg:sticky lg:top-36 lg:self-start">
          <MakerCard product={product} />

          <section className="surface p-4">
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-sm font-bold">Guideline checklist</h3>
              <span className="text-xs font-semibold text-ink-500 tabular-nums">
                {GUIDELINES.length - unchecked}/{GUIDELINES.length}
              </span>
            </div>
            <div className="mb-3 h-1 overflow-hidden rounded-full bg-ink-100 dark:bg-white/10">
              <motion.div className="h-full rounded-full bg-violet-500" animate={{ width: `${((GUIDELINES.length - unchecked) / GUIDELINES.length) * 100}%` }} />
            </div>
            <ul className="space-y-0.5">
              {GUIDELINES.map((g, i) => (
                <li key={g}>
                  <button
                    type="button"
                    disabled={decided}
                    onClick={() => setChecked((c) => c.map((v, j) => (j === i ? !v : v)))}
                    className="flex w-full cursor-pointer items-start gap-2.5 rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-ink-50 disabled:cursor-default dark:hover:bg-white/5"
                  >
                    <span
                      className={cn(
                        'mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded-md border transition-colors',
                        checked[i] ? 'border-violet-600 bg-violet-600 text-white' : 'border-ink-300 dark:border-ink-600',
                      )}
                    >
                      <AnimatePresence>
                        {checked[i] && (
                          <motion.span initial={{ scale: 0 }} animate={{ scale: 1 }} exit={{ scale: 0 }} transition={{ type: 'spring', stiffness: 600, damping: 20 }}>
                            <Check className="size-3" strokeWidth={3.5} />
                          </motion.span>
                        )}
                      </AnimatePresence>
                    </span>
                    <span className={cn(checked[i] ? 'text-ink-500 line-through decoration-ink-300' : 'text-ink-700 dark:text-ink-200')}>{g}</span>
                  </button>
                </li>
              ))}
            </ul>
          </section>

          <section aria-busy={locked} className={cn('surface p-4', (decided || locked) && 'pointer-events-none opacity-50')}>
            <h3 className="mb-3 text-sm font-bold">Decision</h3>
            <div className="grid grid-cols-3 gap-2">
              {DECISIONS.map((d) => {
                const active = decision === d.value
                const Icon = d.icon
                return (
                  <motion.button
                    key={d.value}
                    type="button"
                    whileTap={{ scale: 0.95 }}
                    onClick={() => choose(d.value)}
                    aria-pressed={active}
                    className={cn(
                      'relative flex cursor-pointer flex-col items-center gap-1 rounded-xl border px-1 py-2.5 text-center text-xs font-semibold transition-all',
                      active ? d.active : cn('border-ink-200 text-ink-600 dark:border-white/10 dark:text-ink-300', d.idle),
                    )}
                  >
                    <Icon className="size-5" />
                    <span className="leading-tight">{d.label}</span>
                    <Kbd className="mt-0.5">{d.key}</Kbd>
                  </motion.button>
                )
              })}
            </div>

            <AnimatePresence initial={false}>
              {decision && (
                <motion.div key="note" initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} className="overflow-hidden">
                  <div className="space-y-2 pt-4">
                    <label className="flex items-baseline justify-between text-sm font-semibold">
                      Note to founder
                      <span className={cn('text-xs font-normal', needsNote ? 'text-red-500' : 'text-ink-400')}>{needsNote ? 'Required' : 'Optional'}</span>
                    </label>
                    {decision !== 'approved' && (
                      <ReasonChips
                        reasons={REASONS[decision]}
                        active={note}
                        onPick={(r) => {
                          setNote((n) => appendReason(n, r))
                          setNoteError(false)
                        }}
                      />
                    )}
                    <textarea
                      ref={noteRef}
                      value={note}
                      onChange={(e) => {
                        setNote(e.target.value)
                        setNoteError(false)
                      }}
                      placeholder={decision === 'approved' ? 'Anything the founder should know? (optional)' : 'Explain what needs to change…'}
                      aria-label="Note to founder"
                      maxLength={2000}
                      aria-invalid={noteError}
                      className="min-h-24 w-full resize-y rounded-xl border border-ink-200 bg-white px-3 py-2.5 text-sm leading-relaxed transition placeholder:text-ink-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none aria-[invalid=true]:border-red-400 aria-[invalid=true]:ring-4 aria-[invalid=true]:ring-red-500/10 dark:border-white/10 dark:bg-ink-950"
                    />
                    {noteMessage && <p className="text-xs font-medium text-red-500">{noteMessage}</p>}
                    <AnimatePresence>
                      {noteError && (
                        <motion.p initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0, x: [0, -6, 6, -3, 3, 0] }} exit={{ opacity: 0 }} className="text-xs font-medium text-red-500">
                          The founder needs to know why. Add a note or pick a reason.
                        </motion.p>
                      )}
                    </AnimatePresence>
                  </div>
                </motion.div>
              )}
            </AnimatePresence>

            {decide.isError && !confirming && <div className="mt-3"><InlineError>{staffMessage(decide.error)}</InlineError></div>}

            <motion.button
              type="button"
              whileTap={decision ? { scale: 0.97 } : undefined}
              disabled={!decision || decide.isPending}
              onClick={requestSubmit}
              className={cn(
                'mt-4 flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-xl text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:bg-ink-200 disabled:text-ink-500 dark:disabled:bg-white/5',
                decision ? decisionCopy[decision].tone : '',
              )}
            >
              <Send className="size-4" />
              {decision ? decisionCopy[decision].button : 'Pick a decision'}
              {decision && (
                <Kbd className="ml-1 border-white/40 bg-white/15 text-white">⌘↵</Kbd>
              )}
            </motion.button>
            <p className="mt-2 text-center text-[11px] text-ink-400">
              Shortcuts: <Kbd>A</Kbd> approve · <Kbd>C</Kbd> changes · <Kbd>R</Kbd> reject
            </p>
          </section>
        </aside>
      </div>

      <Modal open={confirming} onClose={() => !decide.isPending && setConfirming(false)} title={decision ? decisionCopy[decision].title : ''} description={`${maker?.name ?? 'The maker'} will be notified by email and in-app.`} size="sm">
        {decision && (
          <div className="space-y-4">
            <div className="flex items-center gap-3 rounded-2xl bg-ink-50 p-3 dark:bg-white/5">
              <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="sm" />
              <div className="min-w-0">
                <p className="truncate font-semibold">{product.name}</p>
                <p className="truncate text-xs text-ink-500">{product.tagline}</p>
              </div>
            </div>
            {note.trim() && <blockquote className="rounded-xl border-l-4 border-violet-400 bg-violet-50/60 px-3 py-2 text-sm whitespace-pre-line text-ink-700 dark:bg-violet-500/10 dark:text-ink-200">{note.trim()}</blockquote>}
            {decision === 'approved' && unchecked > 0 && (
              <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                {unchecked} checklist item{unchecked > 1 ? 's are' : ' is'} not ticked.
              </p>
            )}
            {decide.isError && <InlineError>{staffMessage(decide.error)}</InlineError>}
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button variant="ghost" disabled={decide.isPending} onClick={() => setConfirming(false)}>
                Cancel
              </Button>
              <motion.button
                autoFocus
                whileTap={{ scale: 0.96 }}
                disabled={decide.isPending}
                onClick={apply}
                className={cn('inline-flex h-10 cursor-pointer items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold text-white disabled:opacity-60', decisionCopy[decision].tone)}
              >
                {decide.isPending ? 'Sending…' : decisionCopy[decision].button}
              </motion.button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}

function NavArrow({ to, label, children }: { to?: string; label: string; children: ReactNode }) {
  const cls = 'flex size-8 items-center justify-center rounded-lg border border-ink-200 bg-white transition dark:border-white/10 dark:bg-ink-900'
  if (!to)
    return (
      <span aria-disabled className={cn(cls, 'opacity-40')}>
        {children}
      </span>
    )
  return (
    <Link to={to} aria-label={label} className={cn(cls, 'hover:border-violet-300 hover:text-violet-600')}>
      {children}
    </Link>
  )
}

function MakerCard({ product }: { product: StaffProduct }) {
  const maker = product.maker
  return (
    <section className="surface p-4">
      <h3 className="mb-3 text-sm font-bold">Maker</h3>
      {maker ? (
        <div className="flex items-center gap-3">
          <Avatar name={maker.name} hue={maker.hue} />
          <div className="min-w-0 flex-1">
            <Link to={`/u/${maker.handle}`} className="block truncate font-semibold hover:text-violet-600">
              {maker.name}
            </Link>
            <p className="truncate text-xs text-ink-500">@{maker.handle}</p>
          </div>
        </div>
      ) : (
        <p className="text-sm text-ink-500">Unknown maker</p>
      )}

      <div className="mt-3 space-y-1.5 border-t border-ink-100 pt-3 text-sm dark:border-white/5">
        {maker && (
          <Link to={`/staff/risk/members/${maker.id}`} className="flex items-center gap-2 font-semibold text-violet-700 hover:underline dark:text-violet-300">
            <Fingerprint className="size-4" /> Maker risk summary
          </Link>
        )}
        <Link to={`/staff/products/${product.id}/integrity`} className="flex items-center gap-2 font-semibold text-violet-700 hover:underline dark:text-violet-300">
          <ShieldAlert className="size-4" /> Vote integrity
        </Link>
      </div>
    </section>
  )
}
