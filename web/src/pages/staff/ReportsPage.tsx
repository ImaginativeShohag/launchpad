import { AnimatePresence, motion } from 'motion/react'
import { ChevronRight, CheckCheck, Flag, MessageSquare, Package, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { useReportHistory, useReportQueue } from '@/api/hooks/useStaffReports'
import type { ReportTypeDto, StaffReportHistoryDto, StaffReportRowDto } from '@/api/types'
import { Badge, EmptyState, LoadMore, Skeleton, Tabs, cn } from '@/components/ui'
import { timeAgo } from '@/lib/date'
import { TypeIcon } from './components/reportBits'
import { reasonTone } from './components/reportTone'
import { PageHeader, StaffLoadError } from './components/shared'

type Tab = 'open' | 'closed'
type Kind = 'all' | ReportTypeDto

export default function ReportsPage() {
  const [tab, setTab] = useState<Tab>('open')
  const [kind, setKind] = useState<Kind>('all')
  const type = kind === 'all' ? undefined : kind
  const open = useReportQueue(type)
  const history = useReportHistory(type, tab === 'closed')

  return (
    <div>
      <PageHeader title="Reports" subtitle="Comments and products flagged by members. The most reported first." />

      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <Tabs<Tab>
          value={tab}
          onChange={setTab}
          items={[
            { value: 'open', label: 'Open', count: open.data?.meta.total_targets },
            { value: 'closed', label: 'Resolved' },
          ]}
        />
        <div className="flex gap-1.5">
          {(
            [
              { v: 'all', label: 'All', icon: Flag },
              { v: 'comment', label: 'Comments', icon: MessageSquare },
              { v: 'product', label: 'Products', icon: Package },
            ] as const
          ).map(({ v, label, icon: Icon }) => (
            <button
              key={v}
              onClick={() => setKind(v)}
              aria-pressed={kind === v}
              className={cn(
                'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                kind === v
                  ? 'border-violet-300 bg-violet-50 text-violet-700 dark:border-violet-500/40 dark:bg-violet-500/15 dark:text-violet-300'
                  : 'border-ink-200 bg-white text-ink-600 hover:border-ink-300 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300',
              )}
            >
              <Icon className="size-3.5" /> {label}
            </button>
          ))}
        </div>
      </div>

      {tab === 'open' ? <OpenList query={open} /> : <ClosedList query={history} />}
    </div>
  )
}

function OpenList({ query }: { query: ReturnType<typeof useReportQueue> }) {
  if (query.isPending) return <Skeleton className="h-40 w-full" />
  if (query.isError) return <StaffLoadError error={query.error} onRetry={() => void query.refetch()} />
  const { data: rows, meta } = query.data
  if (rows.length === 0) return <EmptyState icon={<CheckCheck className="size-6" />} title="No open reports" body="The community is behaving. New reports will appear here." />
  return (
    <>
      {meta.truncated && (
        <p className="mb-3 flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
          <TriangleAlert className="size-4 shrink-0" /> Showing {rows.length} of {meta.total_targets} reported items. Resolve some and the list refills.
        </p>
      )}
      <ul className="space-y-3">
        <AnimatePresence initial={false} mode="popLayout">
          {rows.map((r) => (
            <motion.li
              key={`${r.type}:${r.target.id}`}
              layout
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, x: 60, scale: 0.96, transition: { duration: 0.22 } }}
              transition={{ type: 'spring', stiffness: 380, damping: 34 }}
            >
              <OpenCard row={r} />
            </motion.li>
          ))}
        </AnimatePresence>
      </ul>
    </>
  )
}

function OpenCard({ row: r }: { row: StaffReportRowDto }) {
  const t = r.target
  return (
    <Link to={`/staff/reports/${r.type}/${t.id}`} className="group surface block overflow-hidden transition-all hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-lift dark:hover:border-violet-500/30">
      <div className="flex flex-wrap items-center gap-2 border-b border-ink-100 px-4 py-2.5 text-sm dark:border-white/5">
        <TypeIcon type={r.type} />
        {Object.entries(r.reasons).map(([reason, n]) => (
          <Badge key={reason} tone={reasonTone(reason)}>
            {reason} · {n}
          </Badge>
        ))}
        <span className="text-ink-500">
          {r.open_reports} open report{r.open_reports === 1 ? '' : 's'}
        </span>
        <span className="text-ink-300">·</span>
        <time className="text-ink-500" dateTime={r.oldest_report_at} title={new Date(r.oldest_report_at).toLocaleString()}>
          since {timeAgo(r.oldest_report_at)}
        </time>
        <ChevronRight className="ml-auto size-4 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-violet-500" />
      </div>
      <div className="p-4">
        {r.type === 'comment' ? (
          <div className="rounded-xl border border-ink-200/70 bg-ink-50/60 p-3 dark:border-white/5 dark:bg-white/[0.03]">
            <p className="mb-1.5 text-xs text-ink-500">
              <span className="font-semibold text-ink-700 dark:text-ink-200">{t.author ? `@${t.author.handle}` : 'unknown author'}</span>
              {t.product && <> on {t.product.name}</>}
              {t.state && t.state !== 'visible' && <> · {t.state}</>}
            </p>
            <p className="text-sm leading-relaxed text-ink-800 dark:text-ink-100">{t.body_excerpt || <em className="text-ink-400">No text</em>}</p>
          </div>
        ) : (
          <div className="rounded-xl border border-ink-200/70 bg-ink-50/60 p-3 dark:border-white/5 dark:bg-white/[0.03]">
            <p className="font-semibold">{t.name}</p>
            <p className="text-xs text-ink-500">
              {t.status?.replace('_', ' ')} · by {t.maker ? `@${t.maker.handle}` : 'unknown maker'}
            </p>
          </div>
        )}
      </div>
    </Link>
  )
}

function ClosedList({ query }: { query: ReturnType<typeof useReportHistory> }) {
  if (query.isPending) return <Skeleton className="h-40 w-full" />
  if (query.isError && query.items.length === 0) return <StaffLoadError error={query.error} onRetry={() => void query.refetch()} />
  if (query.items.length === 0) return <EmptyState icon={<CheckCheck className="size-6" />} title="Nothing resolved yet" body="Handled reports will be listed here." />
  return (
    <>
      <ul className="space-y-3">
        {query.items.map((r) => (
          <li key={r.id}>
            <ClosedCard report={r} />
          </li>
        ))}
      </ul>
      <LoadMore hasNextPage={!!query.hasNextPage} loading={query.isFetchingNextPage} failed={query.isFetchNextPageError} onClick={() => void query.fetchNextPage()} />
    </>
  )
}

function ClosedCard({ report: r }: { report: StaffReportHistoryDto }) {
  return (
    <div className="surface p-4">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <TypeIcon type={r.type} />
        <Badge tone={reasonTone(r.reason)}>{r.reason}</Badge>
        <span className="text-ink-500">
          reported by <span className="font-semibold text-ink-700 dark:text-ink-200">@{r.reporter.handle}</span>
        </span>
        <Badge tone={r.status === 'resolved' ? 'green' : 'neutral'} className="ml-auto">
          {r.status === 'resolved' ? 'Resolved' : 'Dismissed'}
        </Badge>
      </div>
      {r.details && <p className="mt-2 text-sm text-ink-600 dark:text-ink-300">“{r.details}”</p>}
      <p className="mt-2 text-xs text-ink-500">
        {r.handled_by ? <>handled by @{r.handled_by.handle}</> : 'closed by the system'}
        {r.handled_at && <> {timeAgo(r.handled_at)}</>}
        {' · '}
        <Link to={`/staff/reports/${r.type}/${r.target_id}`} className="font-semibold text-violet-600 hover:underline">
          View {r.type}
        </Link>
      </p>
      {r.resolution_note && <p className="mt-2 rounded-lg border-l-2 border-ink-200 bg-ink-50 px-2.5 py-1 text-sm whitespace-pre-line text-ink-600 dark:border-white/10 dark:bg-white/5 dark:text-ink-300">{r.resolution_note}</p>}
    </div>
  )
}
