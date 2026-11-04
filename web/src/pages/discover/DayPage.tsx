import { AnimatePresence, motion } from 'motion/react'
import { CalendarClock, CalendarX2, ChevronLeft, ChevronRight, Lock, Trophy } from 'lucide-react'
import { useEffect } from 'react'
import { Link, Navigate, useLocation, useNavigate, useParams } from 'react-router'
import { useDay } from '@/api/hooks/useDay'
import { isApiError } from '@/api/errors'
import { addDays, formatDay, parseISODate, relativeDay, toISODate, today } from '@/lib/date'
import { AnimatedNumber, Badge, Button, EmptyState, ErrorState, Skeleton, cn } from '@/components/ui'
import { RankedList } from './components/RankedList'

/** A real calendar day: 2026-02-31 would roll over to March in `Date`, so the round trip must give the same text back. */
const isValidDate = (s: string | undefined): s is string => !!s && /^\d{4}-\d{2}-\d{2}$/.test(s) && toISODate(parseISODate(s)) === s

export default function DayPage() {
  const { date } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const dir = (location.state as { dir?: number } | null)?.dir ?? 0
  const valid = isValidDate(date)
  const t = today()
  const futureByClock = valid && date > t
  // The server is the judge of "not yet": its 404 for a later day reads the same as a date after the clock's today.
  const dayQ = useDay(valid ? date : '', valid && date !== t && !futureByClock)
  const products = dayQ.data?.products ?? []

  const go = (d: string, direction: number) => {
    if (d === t) navigate('/')
    else navigate(`/day/${d}`, { state: { dir: direction } })
  }

  useEffect(() => {
    if (!valid) return
    const onKey = (e: KeyboardEvent) => {
      if (e.metaKey || e.altKey || e.ctrlKey || e.shiftKey) return
      const el = e.target as HTMLElement
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable) return
      if (e.key === 'ArrowLeft') go(addDays(date, -1), -1)
      if (e.key === 'ArrowRight') go(addDays(date, 1), 1)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [date, valid])

  if (!valid) {
    return <EmptyState icon={<CalendarX2 className="size-6" />} title="That date doesn't look right" body="Day pages use the format YYYY-MM-DD." action={<Button onClick={() => navigate('/')}>Back to today</Button>} />
  }
  if (date === t) return <Navigate to="/" replace />

  const future = futureByClock || (dayQ.isError && isApiError(dayQ.error) && dayQ.error.status === 404)
  const prev = addDays(date, -1)
  const next = addDays(date, 1)
  const totalVotes = products.reduce((s, p) => s + p.votes, 0)
  const strip = Array.from({ length: 7 }, (_, i) => addDays(date, i - 3))

  return (
    <div className="mx-auto max-w-3xl">
      {/* Header */}
      <motion.header initial={{ opacity: 0, x: dir * 40 }} animate={{ opacity: 1, x: 0 }} transition={{ type: 'spring', stiffness: 300, damping: 30 }} className="mb-6">
        <div className="flex items-center justify-between gap-3">
          <motion.button whileTap={{ scale: 0.9 }} whileHover={{ x: -2 }} onClick={() => go(prev, -1)} aria-label="Previous day" className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-xl border border-ink-200 bg-white transition hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-ink-900">
            <ChevronLeft className="size-5" />
          </motion.button>
          <div className="min-w-0 text-center">
            <p className="text-xs font-semibold tracking-wider text-ink-500 uppercase">{relativeDay(date)}</p>
            <h1 className="truncate text-2xl font-bold sm:text-3xl">{formatDay(date, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}</h1>
          </div>
          <motion.button whileTap={{ scale: 0.9 }} whileHover={{ x: 2 }} onClick={() => go(next, 1)} aria-label="Next day" className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-xl border border-ink-200 bg-white transition hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-ink-900">
            <ChevronRight className="size-5" />
          </motion.button>
        </div>

        {/* Week strip */}
        <div className="mt-5 grid grid-cols-7 gap-1 rounded-2xl bg-ink-100 p-1 dark:bg-white/5">
          {strip.map((d) => {
            const active = d === date
            const isToday = d === t
            return (
              <button key={d} onClick={() => go(d, d < date ? -1 : 1)} className={cn('relative cursor-pointer rounded-xl py-1.5 text-center transition-colors', active ? 'text-ink-900 dark:text-white' : 'text-ink-500 hover:text-ink-800 dark:hover:text-ink-200', d > t && 'opacity-50')}>
                {active && <motion.span layoutId="day-strip" className="absolute inset-0 rounded-xl bg-white shadow-soft dark:bg-ink-800" transition={{ type: 'spring', stiffness: 500, damping: 36 }} />}
                <span className="relative block text-[10px] font-semibold uppercase">{isToday ? 'Today' : formatDay(d, { weekday: 'short' })}</span>
                <span className="relative block font-display text-base font-bold">{formatDay(d, { day: 'numeric' })}</span>
              </button>
            )
          })}
        </div>

        {dayQ.data && !future && (
          <div className="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm text-ink-500">
            <Badge tone="neutral" icon={<Lock className="size-3" />}>
              {dayQ.data.frozen ? 'Final results — this order never changes' : 'Results are being finalised'}
            </Badge>
            {products.length > 0 && (
              <span>
                <AnimatedNumber value={products.length} className="font-bold text-ink-800 dark:text-ink-100" /> launches ·{' '}
                <AnimatedNumber value={totalVotes} className="font-bold text-ink-800 dark:text-ink-100" /> votes
              </span>
            )}
          </div>
        )}
      </motion.header>

      <AnimatePresence mode="wait">
        <motion.div key={date} initial={{ opacity: 0, x: dir * 30 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.25 }}>
          {future ? (
            <EmptyState
              icon={<CalendarClock className="size-6" />}
              title="Nothing to see yet"
              body="Products stay secret until their launch day. Come back then to see what ships."
              action={<Button onClick={() => navigate('/')}>See today&apos;s launches</Button>}
            />
          ) : dayQ.isPending ? (
            <div className="space-y-3" aria-busy="true" aria-label="Loading">
              {Array.from({ length: 4 }, (_, i) => (
                <Skeleton key={i} className="h-24 w-full rounded-2xl" />
              ))}
            </div>
          ) : dayQ.isError ? (
            <ErrorState error={dayQ.error} onRetry={() => void dayQ.refetch()} title="Could not load this day" />
          ) : products.length ? (
            <>
              {products[0] && (
                <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-amber-600 dark:text-amber-400">
                  <Trophy className="size-4" /> Product of the day: <Link to={`/p/${products[0].slug}`} className="underline-offset-2 hover:underline">{products[0].name}</Link>
                </div>
              )}
              <RankedList id={`day-${date}`} products={products} />
            </>
          ) : (
            <EmptyState icon={<CalendarX2 className="size-6" />} title="A quiet day" body="No products launched on this day. Try the day before." action={<Button variant="outline" icon={<ChevronLeft className="size-4" />} onClick={() => go(prev, -1)}>Previous day</Button>} />
          )}
        </motion.div>
      </AnimatePresence>
    </div>
  )
}
