import { AnimatePresence, motion } from 'motion/react'
import {
  Ban,
  CheckCircle2,
  Download,
  Flag,
  Lock,
  MessageSquareWarning,
  RotateCcw,
  ScrollText,
  Trash2,
  UserCheck,
  UserCog,
  UserX,
  XCircle,
  type LucideIcon,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { useAuditLog } from '@/api/hooks/useAuditLog'
import type { AuditLogDto } from '@/api/types'
import { Avatar, EmptyState, LoadMore, Skeleton, cn, useToast } from '@/components/ui'
import { CSV_ROW_CAP, auditCsv } from '@/lib/auditCsv'
import { hueFromId } from '@/lib/hue'
import { timeAgo } from '@/lib/date'
import { useDebouncedValue } from '@/lib/useDebouncedValue'
import { ActionButton, PageHeader, SelectField, StaffLoadError } from './components/shared'

const tones = {
  green: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
  amber: 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
  red: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300',
  sky: 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300',
  neutral: 'bg-ink-100 text-ink-500 dark:bg-white/10 dark:text-ink-300',
  violet: 'bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300',
}

const actionMeta: Record<string, { label: string; icon: LucideIcon; tone: string }> = {
  'product.reviewed': { label: 'reviewed', icon: CheckCircle2, tone: tones.violet },
  'product.taken_down': { label: 'took down', icon: Ban, tone: tones.red },
  'product.restored': { label: 'restored', icon: RotateCcw, tone: tones.green },
  'report.resolved': { label: 'resolved a report on', icon: Flag, tone: tones.sky },
  'report.dismissed': { label: 'dismissed a report on', icon: Flag, tone: tones.neutral },
  'comment.removed': { label: 'removed', icon: Trash2, tone: tones.red },
  'user.suspended': { label: 'suspended', icon: UserX, tone: tones.red },
  'user.restored': { label: 'restored', icon: UserCheck, tone: tones.green },
  'user.role_changed': { label: 'changed the role of', icon: UserCog, tone: tones.violet },
  'vote.voided': { label: 'voided a vote on', icon: XCircle, tone: tones.amber },
  'member.votes_voided': { label: 'voided votes of', icon: XCircle, tone: tones.amber },
  'risk_signal.reviewed': { label: 'reviewed a risk signal on', icon: MessageSquareWarning, tone: tones.amber },
}
const fallbackMeta = { icon: ScrollText, tone: tones.neutral }

/** Meta keys the page prints as "key: value"; every other key goes into the collapsed JSON. */
const KNOWN_META = ['decision', 'action', 'type', 'reports', 'role', 'from', 'to', 'product_id', 'review_id', 'outcome', 'voided'] as const
/** Staff-written text, shown as a quote. */
const NOTE_META = ['reason', 'note', 'previous_reason'] as const

const ACTION_RE = /^[A-Za-z0-9._-]{1,64}$/
const DAY_RE = /^\d{4}-\d{2}-\d{2}$/

const absolute = (iso: string) => new Date(iso).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })

const dayLabel = (iso: string) => {
  const d = new Date(iso)
  const todayD = new Date()
  const yest = new Date(Date.now() - 86_400_000)
  if (d.toDateString() === todayD.toDateString()) return 'Today'
  if (d.toDateString() === yest.toDateString()) return 'Yesterday'
  return d.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })
}

const isPlain = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)
const scalar = (v: unknown) => (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' ? String(v) : null)

function download(csv: string) {
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = `launchpad-audit-log-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export default function AuditLogPage() {
  const toast = useToast()
  const [actor, setActor] = useState('all')
  const [actionText, setActionText] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const action = useDebouncedValue(actionText.trim(), 300)

  const actionOk = action === '' || ACTION_RE.test(action)
  const fromOk = from === '' || DAY_RE.test(from)
  const toOk = to === '' || (DAY_RE.test(to) && (from === '' || to >= from))

  const list = useAuditLog({
    actor: actor === 'all' ? undefined : actor,
    action: action && actionOk ? action : undefined,
    from: from && fromOk ? from : undefined,
    to: to && toOk ? to : undefined,
  })

  // Staff who appeared in any list so far: the filter must keep offering them after it narrows the list to one.
  const [seen, setSeen] = useState<Record<string, string>>({})
  const unseen = list.items.filter((e) => e.actor && !(e.actor.id in seen))
  if (unseen.length) setSeen((s) => ({ ...s, ...Object.fromEntries(unseen.map((e) => [e.actor!.id, e.actor!.handle])) }))

  const grouped = useMemo(() => {
    const map = new Map<string, AuditLogDto[]>()
    for (const e of list.items) {
      const k = new Date(e.created_at).toDateString()
      const bucket = map.get(k)
      if (bucket) bucket.push(e)
      else map.set(k, [e])
    }
    return [...map.values()]
  }, [list.items])

  const filtersOn = actor !== 'all' || actionText || from || to

  return (
    <div>
      <PageHeader
        title="Audit log"
        subtitle="Every staff action, who took it and when."
        actions={
          <ActionButton
            tone="violet"
            size="md"
            icon={<Download />}
            disabled={!list.items.length}
            onClick={() => {
              download(auditCsv(list.items))
              const n = Math.min(list.items.length, CSV_ROW_CAP)
              toast({ tone: 'success', title: 'CSV exported', body: `${n} entr${n === 1 ? 'y' : 'ies'} downloaded.` })
            }}
          >
            Export CSV
          </ActionButton>
        }
      />

      <div className="mb-4 flex items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50/70 px-4 py-3 text-sm text-violet-900 dark:border-violet-500/25 dark:bg-violet-500/10 dark:text-violet-200">
        <Lock className="mt-0.5 size-4 shrink-0" />
        <p>
          <strong className="font-semibold">Immutable record.</strong> Entries can’t be edited or deleted, by anyone, including admins. Timestamps are recorded server-side. The CSV holds the entries loaded below (up to {CSV_ROW_CAP}).
        </p>
      </div>

      <div className="mb-5 grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto]">
        <SelectField
          label="Filter by staff member"
          value={actor}
          onChange={setActor}
          options={[{ value: 'all', label: 'All staff' }, ...Object.entries(seen).map(([id, handle]) => ({ value: id, label: `@${handle}` }))]}
        />
        <input
          type="text"
          aria-label="Filter by action"
          list="audit-actions"
          maxLength={64}
          value={actionText}
          onChange={(e) => setActionText(e.target.value)}
          placeholder="Action, e.g. product.reviewed"
          aria-invalid={!actionOk}
          className="h-10 rounded-xl border border-ink-200 bg-white px-3 text-sm placeholder:text-ink-400 transition focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none aria-[invalid=true]:border-red-400 dark:border-white/10 dark:bg-ink-900"
        />
        <datalist id="audit-actions">
          {Object.keys(actionMeta).map((a) => (
            <option key={a} value={a} />
          ))}
        </datalist>
        <input
          type="date"
          aria-label="From date"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
          className="h-10 rounded-xl border border-ink-200 bg-white px-3 text-sm focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none dark:border-white/10 dark:bg-ink-900"
        />
        <input
          type="date"
          aria-label="To date"
          value={to}
          onChange={(e) => setTo(e.target.value)}
          aria-invalid={!toOk}
          className="h-10 rounded-xl border border-ink-200 bg-white px-3 text-sm focus:border-violet-400 focus:ring-4 focus:ring-violet-500/15 focus:outline-none aria-[invalid=true]:border-red-400 dark:border-white/10 dark:bg-ink-900"
        />
      </div>
      {!actionOk && <p className="mb-3 text-xs text-red-500">Actions use letters, digits, dots, dashes and underscores only.</p>}
      {!toOk && <p className="mb-3 text-xs text-red-500">The end date must not be before the start date.</p>}

      <div className="mb-3 flex items-center justify-between text-xs text-ink-500">
        <span className="tabular-nums">
          {list.items.length} entr{list.items.length === 1 ? 'y' : 'ies'} loaded{list.hasNextPage ? ', more available' : ''}
        </span>
        {filtersOn && (
          <button
            className="cursor-pointer font-semibold text-violet-600 hover:underline"
            onClick={() => {
              setActor('all')
              setActionText('')
              setFrom('')
              setTo('')
            }}
          >
            Clear filters
          </button>
        )}
      </div>

      {list.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : list.isError && list.items.length === 0 ? (
        <StaffLoadError error={list.error} onRetry={() => void list.refetch()} />
      ) : list.items.length === 0 ? (
        <EmptyState icon={<ScrollText className="size-6" />} title="No matching entries" body="Try a different filter." />
      ) : (
        <>
          <div className="space-y-6">
            {grouped.map((entries) => (
              <section key={entries[0].id}>
                <h2 className="sticky top-[7.5rem] z-10 mb-2 inline-block rounded-full bg-ink-50/90 px-2 py-0.5 font-sans text-xs font-bold tracking-wide text-ink-500 uppercase backdrop-blur dark:bg-ink-950/90">
                  {dayLabel(entries[0].created_at)}
                </h2>
                <ol className="surface divide-y divide-ink-100 overflow-hidden dark:divide-white/5">
                  <AnimatePresence initial={false}>
                    {entries.map((e) => (
                      <AuditRow key={e.id} entry={e} onPickActor={(id) => setActor(id)} />
                    ))}
                  </AnimatePresence>
                </ol>
              </section>
            ))}
          </div>
          <LoadMore hasNextPage={!!list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}
    </div>
  )
}

function AuditRow({ entry: e, onPickActor }: { entry: AuditLogDto; onPickActor: (id: string) => void }) {
  const meta = actionMeta[e.action] ?? { ...fallbackMeta, label: e.action }
  const Icon = meta.icon
  const bag = isPlain(e.meta) ? e.meta : {}
  const known = KNOWN_META.flatMap((k) => (scalar(bag[k]) !== null ? [[k, scalar(bag[k])!] as const] : []))
  const notes = NOTE_META.flatMap((k) => (scalar(bag[k]) ? [[k, scalar(bag[k])!] as const] : []))
  const shown = new Set<string>([...KNOWN_META.filter((k) => scalar(bag[k]) !== null), ...NOTE_META.filter((k) => scalar(bag[k]))])
  const rest = Object.fromEntries(Object.entries(bag).filter(([k]) => !shown.has(k)))
  const hasRest = Object.keys(rest).length > 0 || (Array.isArray(e.meta) && e.meta.length > 0)

  return (
    <motion.li layout="position" initial={{ opacity: 0, backgroundColor: 'rgb(139 92 246 / 0.12)' }} animate={{ opacity: 1, backgroundColor: 'rgb(139 92 246 / 0)' }} transition={{ duration: 0.8 }} className="flex gap-3 px-4 py-3">
      <span className={cn('mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl', meta.tone)}>
        <Icon className="size-4" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm leading-6">
          {e.actor ? (
            <button type="button" onClick={() => onPickActor(e.actor!.id)} title="Show only this person" className="inline-flex cursor-pointer items-center gap-1.5 align-middle hover:text-violet-600">
              <Avatar name={e.actor.handle} hue={hueFromId(e.actor.id)} size="xs" className="size-5 text-[8px] ring-0" />
              <strong className="font-semibold">@{e.actor.handle}</strong>
            </button>
          ) : (
            <strong className="font-semibold">System</strong>
          )}{' '}
          <span className="text-ink-500">{meta.label}</span>{' '}
          {e.subject_type && (
            <strong className="font-semibold break-words">
              {e.subject_type} <span className="font-mono text-xs font-normal">{e.subject_id}</span>
            </strong>
          )}
        </p>
        {known.length > 0 && (
          <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-ink-500">
            {known.map(([k, v]) => (
              <span key={k}>
                {k}: <span className="font-medium text-ink-700 dark:text-ink-200">{v}</span>
              </span>
            ))}
          </p>
        )}
        {notes.map(([k, v]) => (
          <p key={k} className="mt-1 rounded-lg border-l-2 border-ink-200 bg-ink-50 px-2.5 py-1 text-sm whitespace-pre-line text-ink-600 dark:border-white/10 dark:bg-white/5 dark:text-ink-300">
            {k === 'previous_reason' ? `Previous reason: ${v}` : v}
          </p>
        ))}
        {hasRest && (
          <details className="mt-1 text-xs text-ink-500">
            <summary className="cursor-pointer select-none">More details</summary>
            <pre className="mt-1 overflow-x-auto rounded-lg bg-ink-50 p-2 font-mono text-[11px] whitespace-pre-wrap dark:bg-white/5">{JSON.stringify(Array.isArray(e.meta) ? e.meta : rest, null, 2)}</pre>
          </details>
        )}
        <p className="mt-1 text-xs text-ink-400 sm:hidden">
          {absolute(e.created_at)} · {timeAgo(e.created_at)}
        </p>
      </div>
      <div className="hidden shrink-0 text-right sm:block">
        <time dateTime={e.created_at} className="block text-xs font-medium text-ink-600 tabular-nums dark:text-ink-300">
          {absolute(e.created_at)}
        </time>
        <span className="text-xs text-ink-400">{timeAgo(e.created_at)}</span>
      </div>
    </motion.li>
  )
}
