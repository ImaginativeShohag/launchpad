import { motion } from 'motion/react'
import { Lock, SearchX, Search, X } from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import type { ProductStatus } from '@/data/types'
import { Badge, Button, EmptyState, ErrorState, Modal, cn } from '@/components/ui'
import { isForbidden, isGone } from '@/api/staffErrors'

export function Kbd({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <kbd
      className={cn(
        'inline-flex h-5 min-w-5 items-center justify-center rounded-md border border-b-2 border-current/20 bg-white/60 px-1 font-sans text-[10px] font-bold uppercase tabular-nums opacity-80 dark:bg-white/10',
        className,
      )}
    >
      {children}
    </kbd>
  )
}

const statusMeta: Record<ProductStatus, { label: string; tone: Parameters<typeof Badge>[0]['tone'] }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  pending: { label: 'Pending review', tone: 'amber' },
  changes_requested: { label: 'Changes requested', tone: 'blue' },
  rejected: { label: 'Rejected', tone: 'red' },
  approved: { label: 'Approved', tone: 'green' },
  scheduled: { label: 'Scheduled', tone: 'violet' },
  live: { label: 'Live', tone: 'green' },
  taken_down: { label: 'Taken down', tone: 'red' },
}

export function StatusBadge({ status }: { status: ProductStatus }) {
  const m = statusMeta[status] ?? { label: status, tone: 'neutral' as const }
  return (
    <Badge tone={m.tone} icon={status === 'live' ? <span className="size-1.5 animate-pulse rounded-full bg-current" /> : undefined}>
      {m.label}
    </Badge>
  )
}

/** A search text for the API: control characters out (the API rejects them), trimmed. */
export function cleanQuery(text: string): string {
  return [...text].filter((c) => c.charCodeAt(0) > 31 && c.charCodeAt(0) !== 127).join('').trim()
}

/** A failed staff read: a wrong role is "not allowed", a missing or hidden thing is "not found" (the API answers both the same way), the rest has Retry. */
export function StaffLoadError({ error, onRetry, backTo = '/staff' }: { error: unknown; onRetry: () => void; backTo?: string }) {
  if (isForbidden(error)) {
    return <EmptyState icon={<Lock className="size-6" />} title="Not allowed" body="Your role does not give you access to this." action={<Link to={backTo}><Button variant="outline">Back</Button></Link>} />
  }
  if (isGone(error)) {
    return <EmptyState icon={<SearchX className="size-6" />} title="Not found" body="It may have been removed, or you cannot see it." action={<Link to={backTo}><Button variant="outline">Back</Button></Link>} />
  }
  return <ErrorState error={error} onRetry={onRetry} />
}

/** An action's error, shown where the action is. */
export function InlineError({ children }: { children: ReactNode }) {
  return (
    <p role="alert" className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
      {children}
    </p>
  )
}

/** Horizontal 0–100 meter, colored by severity. */
export function RiskMeter({ score = 0, className }: { score?: number; className?: string }) {
  const color = score >= 70 ? 'bg-red-500' : score >= 40 ? 'bg-amber-500' : 'bg-emerald-500'
  const text = score >= 70 ? 'text-red-600 dark:text-red-400' : score >= 40 ? 'text-amber-600 dark:text-amber-400' : 'text-ink-500'
  return (
    <div className={cn('flex items-center gap-2', className)} title={`Risk score ${score}/100`}>
      <div className="h-1.5 w-20 overflow-hidden rounded-full bg-ink-100 dark:bg-white/10">
        <motion.div className={cn('h-full rounded-full', color)} initial={{ width: 0 }} animate={{ width: `${Math.max(3, score)}%` }} transition={{ type: 'spring', stiffness: 120, damping: 20 }} />
      </div>
      <span className={cn('w-6 text-xs font-bold tabular-nums', text)}>{score}</span>
    </div>
  )
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-bold sm:text-[28px]">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-ink-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  )
}

export function SearchField({ value, onChange, placeholder, className }: { value: string; onChange: (v: string) => void; placeholder: string; className?: string }) {
  return (
    <div className={cn('relative', className)}>
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-400" />
      <input
        type="search"
        maxLength={100}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="h-10 w-full rounded-xl border border-ink-200 bg-white pr-9 pl-9 text-sm placeholder:text-ink-400 transition focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none dark:border-white/10 dark:bg-ink-900 [&::-webkit-search-cancel-button]:hidden"
      />
      {value && (
        <button onClick={() => onChange('')} aria-label="Clear search" className="absolute top-1/2 right-2 -translate-y-1/2 cursor-pointer rounded-md p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-white/5">
          <X className="size-3.5" />
        </button>
      )}
    </div>
  )
}

export function SelectField<T extends string>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: { value: T; label: string }[]; label: string }) {
  return (
    <select
      aria-label={label}
      value={value}
      onChange={(e) => onChange(e.target.value as T)}
      className="h-10 cursor-pointer rounded-xl border border-ink-200 bg-white px-3 text-sm font-medium transition focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none dark:border-white/10 dark:bg-ink-900"
    >
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  )
}

const actionTones = {
  green: 'bg-emerald-600 text-white hover:bg-emerald-500 shadow-[0_6px_16px_-6px_rgb(5_150_105/0.6)]',
  amber: 'bg-amber-500 text-white hover:bg-amber-400 shadow-[0_6px_16px_-6px_rgb(245_158_11/0.6)]',
  red: 'bg-red-600 text-white hover:bg-red-500 shadow-[0_6px_16px_-6px_rgb(220_38_38/0.6)]',
  violet: 'bg-violet-600 text-white hover:bg-violet-500 shadow-[0_6px_16px_-6px_rgb(124_58_237/0.6)]',
  soft: 'border border-ink-200 bg-white text-ink-700 hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-200 dark:hover:bg-ink-800',
  softRed: 'border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:border-red-500/30 dark:bg-ink-900 dark:text-red-400 dark:hover:bg-red-500/10',
  softViolet: 'border border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-100 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300 dark:hover:bg-violet-500/20',
} as const

/** Compact colored action button used across staff tools. */
export function ActionButton({
  tone = 'soft',
  icon,
  children,
  onClick,
  disabled,
  title,
  className,
  size = 'sm',
}: {
  tone?: keyof typeof actionTones
  icon?: ReactNode
  children?: ReactNode
  onClick?: () => void
  disabled?: boolean
  title?: string
  className?: string
  size?: 'sm' | 'md'
}) {
  return (
    <motion.button
      type="button"
      whileTap={disabled ? undefined : { scale: 0.95 }}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={cn(
        'inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-45 [&_svg]:size-4',
        size === 'sm' ? 'h-8 rounded-lg px-3 text-sm' : 'h-10 rounded-xl px-4 text-sm',
        actionTones[tone],
        className,
      )}
    >
      {icon}
      {children}
    </motion.button>
  )
}

export function ReasonChips({ reasons, onPick, active }: { reasons: string[]; onPick: (r: string) => void; active?: string }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {reasons.map((r) => (
        <motion.button
          key={r}
          type="button"
          whileTap={{ scale: 0.94 }}
          onClick={() => onPick(r)}
          className={cn(
            'cursor-pointer rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
            active?.includes(r)
              ? 'border-violet-300 bg-violet-50 text-violet-700 dark:border-violet-500/40 dark:bg-violet-500/15 dark:text-violet-300'
              : 'border-ink-200 bg-white text-ink-600 hover:border-violet-300 hover:text-violet-700 dark:border-white/10 dark:bg-white/5 dark:text-ink-300',
          )}
        >
          {r}
        </motion.button>
      ))}
    </div>
  )
}

/** Appends a canned reason to a note, one per line, without duplicates. */
export function appendReason(note: string, reason: string): string {
  if (note.includes(reason)) return note
  return note.trim() ? `${note.trimEnd()}\n${reason}` : reason
}

interface ConfirmProps {
  open: boolean
  onClose: () => void
  title: ReactNode
  description?: ReactNode
  confirmLabel: string
  tone?: 'danger' | 'primary'
  /** When set, a note field is shown. */
  note?: { label: string; required?: boolean; placeholder?: string; reasons?: string[]; maxLength?: number; minLength?: number }
  children?: ReactNode
  /** The request is running: the confirm button waits and the dialog cannot be confirmed twice. */
  pending?: boolean
  /** What the API said, shown inside the dialog; the dialog stays open so the reason is not lost. */
  error?: ReactNode
  onConfirm: (note: string) => void
}

/** Confirmation dialog with an optional (or required) reason. */
export function ConfirmModal(props: ConfirmProps) {
  // While it closes the callers have already cleared what the title is built from: keep showing the last content.
  const [shown, setShown] = useState(props)
  if (props.open && shown !== props) setShown(props)
  const p = props.open ? props : shown
  // Esc, the backdrop and the X do nothing while the request runs: a second dialog could start a second request.
  const close = props.pending ? () => {} : props.onClose
  return (
    <Modal open={props.open} onClose={close} title={p.title} description={p.description} size="md">
      <ConfirmBody {...p} open={props.open} pending={props.pending} error={props.error} onClose={close} onConfirm={props.onConfirm} />
    </Modal>
  )
}

function ConfirmBody({ onClose, confirmLabel, tone = 'primary', note, children, pending, error, onConfirm }: ConfirmProps) {
  const [text, setText] = useState('')
  const [touched, setTouched] = useState(false)
  const ref = useRef<HTMLTextAreaElement>(null)
  const missing = !!note?.required && text.trim().length < (note.minLength ?? 1)

  useEffect(() => {
    const t = setTimeout(() => ref.current?.focus(), 120)
    return () => clearTimeout(t)
  }, [])

  const submit = () => {
    setTouched(true)
    if (missing || pending) return
    onConfirm(text.trim())
  }

  return (
    <div className="space-y-4">
      {children}
      {note && (
        <div className="space-y-2">
          <label className="flex items-baseline justify-between text-sm font-semibold">
            {note.label}
            <span className="text-xs font-normal text-ink-400">{note.required ? 'Required · saved to audit log' : 'Optional · saved to audit log'}</span>
          </label>
          {note.reasons && <ReasonChips reasons={note.reasons} active={text} onPick={(r) => setText((t) => appendReason(t, r))} />}
          <textarea
            ref={ref}
            aria-label={note.label}
            maxLength={note.maxLength}
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submit()
            }}
            placeholder={note.placeholder}
            aria-invalid={touched && missing}
            className="min-h-24 w-full resize-y rounded-xl border border-ink-200 bg-white px-3.5 py-3 text-sm leading-relaxed transition placeholder:text-ink-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none aria-[invalid=true]:border-red-400 dark:border-white/10 dark:bg-ink-950"
          />
          {touched && missing && (
            <motion.p initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0 }} className="text-xs font-medium text-red-500">
              {note?.minLength && note.minLength > 1 ? `Add a reason of at least ${note.minLength} characters.` : 'Add a reason before continuing.'}
            </motion.p>
          )}
        </div>
      )}
      {error && <InlineError>{error}</InlineError>}
      <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
        <Button variant="ghost" onClick={onClose} disabled={pending}>
          Cancel
        </Button>
        <Button variant={tone === 'danger' ? 'danger' : 'secondary'} onClick={submit} loading={pending} disabled={touched && missing}>
          {confirmLabel}
        </Button>
      </div>
    </div>
  )
}

/** Small stat tile used at the top of staff pages. */
export function StatTile({ label, value, tone = 'neutral', icon }: { label: string; value: ReactNode; tone?: 'neutral' | 'red' | 'amber' | 'violet'; icon?: ReactNode }) {
  const tones = {
    neutral: 'text-ink-500 bg-ink-100 dark:bg-white/5',
    red: 'text-red-600 bg-red-50 dark:bg-red-500/10 dark:text-red-400',
    amber: 'text-amber-600 bg-amber-50 dark:bg-amber-500/10 dark:text-amber-400',
    violet: 'text-violet-600 bg-violet-50 dark:bg-violet-500/10 dark:text-violet-300',
  }
  return (
    <div className="surface flex items-center gap-3 p-3 sm:p-4">
      {icon && <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-xl [&_svg]:size-4.5', tones[tone])}>{icon}</span>}
      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-ink-500">{label}</p>
        <p className="font-display text-xl font-bold tabular-nums">{value}</p>
      </div>
    </div>
  )
}
