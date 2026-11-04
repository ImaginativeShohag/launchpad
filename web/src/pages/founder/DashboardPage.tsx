import { motion } from 'motion/react'
import {
  ArrowLeft,
  AtSign,
  BarChart3,
  Bell,
  CalendarClock,
  CheckCircle2,
  ChevronUp,
  Download,
  ExternalLink,
  Eye,
  FileSpreadsheet,
  Globe,
  Link2,
  Loader2,
  Lock,
  MessageCircle,
  MousePointerClick,
  RefreshCw,
  Search,
  TriangleAlert,
  Trophy,
} from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { useDashboardRank, useDashboardReferrers, useDashboardSeries, useDashboardSummary } from '@/api/hooks/useDashboard'
import { isInFlight, useExport } from '@/api/hooks/useExport'
import { errorMessage, isApiError } from '@/api/errors'
import { founderMessage } from '@/api/founderErrors'
import type { DashboardSummaryDto, ExportDto } from '@/api/types'
import { categoryColor, compactDay, formatBytes, rankSlots, sourceLabel, votesPerSlot } from '@/lib/dashboard'
import { formatDay, timeAgo } from '@/lib/date'
import { AnimatedNumber, Badge, Button, EmptyState, ErrorState, Skeleton, Tabs, cn, useToast } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'
import { hueFromId } from '@/lib/hue'
import { looksLikeUlid } from '@/lib/productForm'
import { RankStepChart, ReferrerBars, VotesChart, type BarDatum } from './components/Charts'

type Granularity = 'hour' | 'day'

const CATEGORY_ICONS: Record<string, ReactNode> = {
  direct: <MousePointerClick className="size-4 text-brand-500" />,
  search: <Search className="size-4 text-sky-500" />,
  social: <AtSign className="size-4 text-pink-500" />,
  internal: <Link2 className="size-4 text-violet-500" />,
  other: <Link2 className="size-4 text-ink-400" />,
}

export default function DashboardPage() {
  const params = useParams()
  // A malformed id is the same "not found" as a foreign one, without asking the API.
  const id = params.id && looksLikeUlid(params.id) ? params.id : undefined
  const summary = useDashboardSummary(id)
  const s = summary.data
  const live = !!s && s.phase !== 'before_launch'
  const [gran, setGran] = useState<Granularity>('hour')
  const series = useDashboardSeries(id, gran, live)
  const rank = useDashboardRank(id, live)
  const referrers = useDashboardReferrers(id, live)
  const exp = useExport(id)
  const toast = useToast()

  const refreshing = summary.isFetching || series.isFetching || rank.isFetching || referrers.isFetching
  const refresh = () => {
    void summary.refetch()
    if (live) {
      void series.refetch()
      void rank.refetch()
      void referrers.refetch()
    }
  }

  const votes = useMemo(() => (series.data ? votesPerSlot(series.data.points) : []), [series.data])
  const ranks = useMemo(() => (rank.data?.launch_date ? rankSlots(rank.data.points, rank.data.launch_date) : []), [rank.data])
  const bars: BarDatum[] = useMemo(
    () => (referrers.data?.sources ?? []).map((r) => ({ label: sourceLabel(r), color: categoryColor(r.category), icon: CATEGORY_ICONS[r.category] ?? CATEGORY_ICONS.other, value: r.visits })),
    [referrers.data],
  )

  if (!id) {
    return <EmptyState icon={<Search className="size-6" />} title="Product not found" body="It may have been removed, or it isn’t yours: product numbers are private to the maker." action={<Link to="/my/products"><Button>My products</Button></Link>} />
  }
  if (summary.isPending) {
    return (
      <div className="mx-auto max-w-5xl space-y-4">
        <Skeleton className="h-10 w-72" />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }
  if (summary.isError || !s) {
    if (isApiError(summary.error) && summary.error.status === 404) {
      return <EmptyState icon={<Search className="size-6" />} title="Product not found" body="It may have been removed, or it isn’t yours: product numbers are private to the maker." action={<Link to="/my/products"><Button>My products</Button></Link>} />
    }
    return <ErrorState error={summary.error} onRetry={() => void summary.refetch()} title="Could not load the dashboard" />
  }

  if (s.phase === 'before_launch') {
    return (
      <EmptyState
        icon={<CalendarClock className="size-6" />}
        title="Numbers appear on launch day"
        body={s.launch_date ? `${s.name} goes live ${formatDay(s.launch_date)}. Come back then to watch votes roll in.` : `${s.name} hasn’t launched yet. Pick a launch day from My products.`}
        action={<Link to="/my/products"><Button>My products</Button></Link>}
      />
    )
  }

  const isToday = s.phase === 'launch_day'
  const shownRank = isToday ? s.current_rank : s.final_rank
  const conversion = s.conversion === null ? '0' : (s.conversion * 100).toFixed(1)
  const hasVotes = votes.some((v) => v > 0)

  const download = async (exportId: string) => {
    try {
      const { blob, filename } = await exp.download.mutateAsync(exportId)
      const name = filename ?? `launchpad-${s.slug}-${compactDay()}.zip`
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      document.body.appendChild(a)
      a.click()
      a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
      toast({ tone: 'success', title: 'Download started', body: name })
    } catch (e) {
      if (isApiError(e) && e.code === 'export_expired') exp.markExpired()
      if (isApiError(e) && e.code === 'export_not_ready') void exp.query.refetch()
      toast({ tone: 'error', title: 'Could not download the file', body: founderMessage(e) })
    }
  }
  const request = async () => {
    try {
      await exp.start.mutateAsync()
    } catch (e) {
      toast({ tone: 'error', title: 'Could not start the export', body: founderMessage(e) })
    }
  }

  return (
    <div className="mx-auto max-w-5xl">
      <Link to="/my/products" className="mb-3 inline-flex items-center gap-1 text-sm font-medium text-ink-500 hover:text-ink-800 dark:hover:text-ink-200">
        <ArrowLeft className="size-4" /> My products
      </Link>

      <div className="mb-6 flex flex-wrap items-center gap-4">
        <motion.div initial={{ scale: 0.6, rotate: -15 }} animate={{ scale: 1, rotate: 0 }} transition={{ type: 'spring', stiffness: 300, damping: 15 }}>
          <ProductLogo name={s.name} hue={hueFromId(s.product_id)} size="lg" />
        </motion.div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold sm:text-3xl">{s.name}</h1>
            {s.taken_down ? (
              <Badge tone="red">Taken down</Badge>
            ) : isToday ? (
              <Badge tone="brand" icon={<span className="relative flex size-1.5"><span className="absolute inline-flex size-full animate-ping rounded-full bg-brand-500 opacity-75" /><span className="relative inline-flex size-1.5 rounded-full bg-brand-500" /></span>}>
                Live now
              </Badge>
            ) : (
              <Badge>Launched {s.launch_date ? formatDay(s.launch_date, { month: 'short', day: 'numeric', year: 'numeric' }) : ''}</Badge>
            )}
          </div>
          <p className="mt-0.5 flex items-center gap-1.5 text-sm text-ink-500">
            <Lock className="size-3.5" /> Only you can see this dashboard · updated {timeAgo(s.as_of)}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" loading={refreshing} onClick={refresh} icon={<RefreshCw className="size-4" />}>Refresh</Button>
          {!s.taken_down && (
            <Link to={`/p/${s.slug}`}>
              <Button variant="outline" icon={<ExternalLink className="size-4" />}>Public page</Button>
            </Link>
          )}
        </div>
      </div>

      {s.taken_down && (
        <div role="alert" className="mb-4 flex gap-2.5 rounded-2xl bg-red-50 p-4 text-sm text-red-900 dark:bg-red-500/10 dark:text-red-200">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          <p>This product was taken down by staff and is no longer public. Your numbers up to that point stay here.</p>
        </div>
      )}

      {/* KPIs */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi i={0} icon={<Eye className="size-4" />} label="Views" value={s.views} tint="from-sky-500/15 to-sky-500/0 text-sky-600" sub={isToday ? 'Counted once per visitor per day' : 'All time'} />
        <Kpi i={1} icon={<ChevronUp className="size-4" strokeWidth={3} />} label="Votes" value={s.votes} tint="from-brand-500/15 to-brand-500/0 text-brand-600" sub={s.views ? `${conversion}% of viewers voted` : 'No views yet'} />
        <Kpi i={2} icon={<MessageCircle className="size-4" />} label="Comments" value={s.comments} tint="from-pink-500/15 to-pink-500/0 text-pink-600" sub="Reply to keep momentum" />
        <Kpi i={3} icon={<Trophy className="size-4" />} label={isToday ? 'Rank today' : 'Final rank'} value={shownRank ?? 0} prefix="#" tint="from-amber-500/20 to-amber-500/0 text-amber-600" sub={rankSub(s, isToday)} />
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <Card
          className="lg:col-span-2"
          title="Votes over time"
          icon={<BarChart3 className="size-4" />}
          aside={
            <div className="flex items-center gap-2">
              {isToday && gran === 'hour' && <span className="text-xs font-medium text-brand-600">● Live</span>}
              <Tabs
                items={[
                  { value: 'hour', label: 'By hour' },
                  { value: 'day', label: 'By day' },
                ]}
                value={gran}
                onChange={setGran}
              />
            </div>
          }
        >
          {series.isPending ? (
            <Skeleton className="h-52 w-full" />
          ) : series.isError ? (
            <CardError error={series.error} onRetry={() => void series.refetch()} />
          ) : hasVotes ? (
            gran === 'hour' ? (
              <VotesChart timeline={votes} live={isToday} />
            ) : (
              <VotesChart
                timeline={votes}
                slots={Math.max(2, votes.length)}
                ticks={dayTicks(votes.length)}
                tickAt={(i) => dayLabel(series.data?.points[i]?.t)}
                tipTitle={(i) => dayLabel(series.data?.points[i]?.t)}
              />
            )
          ) : (
            <p className="py-10 text-center text-sm text-ink-500">No votes yet.</p>
          )}
        </Card>
        <Card title="Rank through the day" icon={<Trophy className="size-4" />} aside={<span className="text-xs text-ink-400">Lower is better</span>}>
          {rank.isPending ? (
            <Skeleton className="h-32 w-full" />
          ) : rank.isError ? (
            <CardError error={rank.error} onRetry={() => void rank.refetch()} />
          ) : !rank.data.timeline_available ? (
            <p className="py-6 text-sm text-ink-500">
              The hour-by-hour rank is no longer kept for this launch day.
              {rank.data.final_rank ? ` It finished #${rank.data.final_rank}.` : ''}
            </p>
          ) : rank.data.points.length === 0 ? (
            <p className="py-6 text-sm text-ink-500">Rank shows once votes come in. Only the top {rank.data.outside_top} are tracked.</p>
          ) : (
            <>
              <RankStepChart ranks={ranks} endLabel={isToday ? 'now' : '11:50pm'} />
              <p className="mt-3 text-sm text-ink-500">
                {rank.data.best_rank === 1 ? 'You reached the top. Nice.' : rank.data.best_rank ? `Best: #${rank.data.best_rank}. Early votes count extra, so the first hours matter most.` : ''}
              </p>
            </>
          )}
        </Card>
        <Card className="lg:col-span-2" title="Where visitors came from" icon={<Globe className="size-4" />} aside={referrers.data ? <span className="text-xs text-ink-400">{referrers.data.total.toLocaleString()} visits</span> : undefined}>
          {referrers.isPending ? (
            <Skeleton className="h-40 w-full" />
          ) : referrers.isError ? (
            <CardError error={referrers.error} onRetry={() => void referrers.refetch()} />
          ) : bars.length === 0 ? (
            <p className="py-10 text-center text-sm text-ink-500">No visits recorded yet.</p>
          ) : (
            <ReferrerBars data={bars} />
          )}
        </Card>
        <ExportCard
          export={exp.current}
          loading={exp.query.isPending}
          error={exp.query.isError ? exp.query.error : null}
          onRetry={() => void exp.retry()}
          starting={exp.start.isPending}
          downloading={exp.download.isPending}
          onRequest={() => void request()}
          onDownload={(exportId) => void download(exportId)}
        />
      </div>
    </div>
  )
}

function rankSub(s: DashboardSummaryDto, isToday: boolean) {
  if (isToday) return s.best_rank ? `Best so far #${s.best_rank} · still moving` : 'Still moving'
  return s.final_rank ? 'Frozen at day end' : s.best_rank ? `Best #${s.best_rank}` : 'Not ranked'
}

const dayLabel = (t?: string) => (t ? new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' }) : '')
const dayTicks = (n: number) => Array.from(new Set([0, Math.floor((n - 1) / 2), Math.max(0, n - 1)]))

function CardError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  return (
    <div className="flex flex-col items-start gap-2 py-4 text-sm">
      <p className="text-red-600 dark:text-red-400">{errorMessage(error)}</p>
      <Button size="sm" variant="outline" onClick={onRetry} icon={<RefreshCw className="size-3.5" />}>Retry</Button>
    </div>
  )
}

function Kpi({ i, icon, label, value, sub, tint, prefix }: { i: number; icon: ReactNode; label: string; value: number; sub: string; tint: string; prefix?: string }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 14 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: i * 0.07, type: 'spring', stiffness: 300, damping: 26 }}
      whileHover={{ y: -3 }}
      className="surface relative overflow-hidden p-4"
    >
      <div className={cn('pointer-events-none absolute inset-0 bg-gradient-to-br opacity-70', tint.split(' ').slice(0, 2).join(' '))} />
      <div className="relative">
        <span className={cn('inline-flex size-8 items-center justify-center rounded-xl bg-white shadow-soft dark:bg-ink-800', tint.split(' ')[2])}>{icon}</span>
        <p className="mt-3 text-xs font-semibold tracking-wide text-ink-500 uppercase">{label}</p>
        <p className="mt-0.5 font-display text-3xl font-bold">
          {prefix && value ? prefix : ''}
          {value ? <AnimatedNumber value={value} /> : '—'}
        </p>
        <p className="mt-1 truncate text-xs text-ink-500">{sub}</p>
      </div>
    </motion.div>
  )
}

function Card({ title, icon, aside, children, className }: { title: string; icon: ReactNode; aside?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <motion.section initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }} className={cn('surface p-4 sm:p-5', className)}>
      <div className="mb-4 flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 font-sans text-base font-bold tracking-normal">
          <span className="text-ink-400">{icon}</span>
          {title}
        </h2>
        {aside}
      </div>
      {children}
    </motion.section>
  )
}

const EXPORT_ERRORS: Record<string, string> = {
  too_large: 'Your data was too large to export. Contact support.',
  stalled: 'The export stalled and was stopped. Please try again.',
}

function ExportCard({
  export: exp,
  loading,
  error,
  onRetry,
  starting,
  downloading,
  onRequest,
  onDownload,
}: {
  export: ExportDto | null
  loading: boolean
  error: unknown
  onRetry: () => void
  starting: boolean
  downloading: boolean
  onRequest: () => void
  onDownload: (exportId: string) => void
}) {
  const state = loading ? 'loading' : error ? 'error' : !exp ? 'idle' : isInFlight(exp) ? 'preparing' : exp.status
  return (
    <motion.section initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.3 }} className="surface relative overflow-hidden p-4 sm:p-5">
      <h2 className="mb-1 flex items-center gap-2 font-sans text-base font-bold tracking-normal">
        <FileSpreadsheet className="size-4 text-ink-400" /> Export
      </h2>
      <p className="mb-4 text-sm text-ink-500">All of your numbers in one zip of CSV files: summary, hourly votes and views, referrers and the rank timeline.</p>
      <motion.div key={state} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}>
        {state === 'loading' && <Skeleton className="h-10 w-full" />}
        {state === 'error' && (
          <div className="space-y-2 text-sm">
            <p className="text-red-600 dark:text-red-400">{errorMessage(error)}</p>
            <Button size="sm" variant="outline" onClick={onRetry} icon={<RefreshCw className="size-3.5" />}>Retry</Button>
          </div>
        )}
        {state === 'idle' && (
          <Button className="w-full" variant="secondary" loading={starting} onClick={onRequest} icon={<Download className="size-4" />}>
            Download all data (zip)
          </Button>
        )}
        {state === 'preparing' && (
          <div className="rounded-2xl bg-ink-50 p-4 dark:bg-white/5">
            <p className="flex items-center gap-2 text-sm font-semibold">
              <Loader2 className="size-4 animate-spin text-brand-500" /> Preparing your file…
            </p>
            <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-ink-200 dark:bg-white/10">
              <motion.div className="h-full rounded-full bg-gradient-to-r from-brand-500 to-pink-500" initial={{ width: '4%' }} animate={{ width: '92%' }} transition={{ duration: 6, ease: 'easeOut' }} />
            </div>
            <p className="mt-3 flex items-start gap-1.5 text-xs text-ink-500">
              <Bell className="mt-0.5 size-3.5 shrink-0" /> You can leave this page, we’ll notify you when it’s ready.
            </p>
          </div>
        )}
        {state === 'ready' && exp && (
          <div className="space-y-3">
            <p className="flex items-center gap-2 rounded-2xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
              <CheckCircle2 className="size-4" /> Your file is ready{exp.file_size ? ` (${formatBytes(exp.file_size)})` : ''}
            </p>
            {exp.expires_at && <p className="text-xs text-ink-500">Kept until {formatDay(exp.expires_at.slice(0, 10), { month: 'short', day: 'numeric' })}.</p>}
            <Button className="w-full" loading={downloading} onClick={() => onDownload(exp.id)} icon={<Download className="size-4" />}>
              Download zip
            </Button>
            <button type="button" disabled={starting} onClick={onRequest} className="w-full cursor-pointer text-center text-xs font-medium text-ink-500 hover:text-ink-800 disabled:opacity-50 dark:hover:text-ink-200">
              Prepare a fresh copy
            </button>
          </div>
        )}
        {state === 'failed' && (
          <div className="space-y-3">
            <p role="alert" className="flex items-start gap-2 rounded-2xl bg-red-50 p-3 text-sm font-medium text-red-700 dark:bg-red-500/10 dark:text-red-300">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {(exp?.error && EXPORT_ERRORS[exp.error]) || 'The export failed. Please try again.'}
            </p>
            <Button className="w-full" variant="secondary" loading={starting} onClick={onRequest} icon={<RefreshCw className="size-4" />}>
              Try again
            </Button>
          </div>
        )}
        {state === 'expired' && (
          <div className="space-y-3">
            <p className="rounded-2xl bg-ink-50 p-3 text-sm text-ink-600 dark:bg-white/5 dark:text-ink-300">This export has expired. Files are kept for a few days.</p>
            <Button className="w-full" variant="secondary" loading={starting} onClick={onRequest} icon={<Download className="size-4" />}>
              Prepare a new copy
            </Button>
          </div>
        )}
      </motion.div>
    </motion.section>
  )
}

