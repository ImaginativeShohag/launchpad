import { AnimatePresence, motion } from 'motion/react'
import { ArrowRight, CalendarDays, ChevronRight, HelpCircle, Rocket, Sparkles, Star, TrendingUp, X, Zap } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useDay } from '@/api/hooks/useDay'
import { useToday } from '@/api/hooks/useToday'
import { useTopics } from '@/api/hooks/useTopics'
import type { ProductCard } from '@/data/types'
import { addDays, formatDay, today } from '@/lib/date'
import { useRankMoves } from '@/lib/rankMoves'
import { useProductLive, useTodayLive } from '@/realtime/useLive'
import { useCurrentUser } from '@/session/SessionProvider'
import { useAuthGate } from '@/components/layout/AuthGate'
import { cardTopics } from '@/components/product/cardTopics'
import { ProductLogo } from '@/components/product/ProductLogo'
import { TopicChip } from '@/components/product/TopicChip'
import { VoteButton } from '@/components/product/VoteButton'
import { AnimatedNumber, Badge, Button, EmptyState, ErrorState, Popover, Skeleton, cn } from '@/components/ui'
import { RankedList } from './components/RankedList'
import { useCountdownToMidnight } from './components/hooks'

const pad = (n: number) => String(n).padStart(2, '0')

function LivePulse({ live }: { live: boolean }) {
  return (
    <span className={cn('inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-bold', live ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-ink-100 text-ink-500 dark:bg-white/5')}>
      <span className="relative flex size-2">
        {live && <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />}
        <span className={cn('relative inline-flex size-2 rounded-full', live ? 'bg-emerald-500' : 'bg-ink-400')} />
      </span>
      {live ? 'Live ranking' : 'Updates every 2 min'}
    </span>
  )
}

function Countdown() {
  const { h, m, s } = useCountdownToMidnight()
  return (
    <div className="flex items-center gap-3 rounded-2xl bg-ink-900 px-4 py-3 text-white shadow-lift dark:bg-white/5">
      <div>
        <p className="text-[10px] font-semibold tracking-wider text-white/60 uppercase">Day closes in</p>
        <p className="font-display text-2xl font-bold tabular-nums">
          {pad(h)}
          <span className="animate-pulse text-white/40">:</span>
          {pad(m)}
          <span className="animate-pulse text-white/40">:</span>
          <AnimatePresence mode="popLayout" initial={false}>
            <motion.span key={s} initial={{ y: -10, opacity: 0 }} animate={{ y: 0, opacity: 1 }} exit={{ y: 10, opacity: 0 }} transition={{ duration: 0.2 }} className="inline-block">
              {pad(s)}
            </motion.span>
          </AnimatePresence>
        </p>
      </div>
    </div>
  )
}

function WhyThisOrder() {
  return (
    <Popover
      align="left"
      className="w-80 max-w-[calc(100vw-2rem)] p-4"
      trigger={(open) => (
        <button className={cn('inline-flex cursor-pointer items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold transition-colors', open ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/10' : 'text-ink-500 hover:text-brand-600')}>
          <HelpCircle className="size-3.5" /> Why this order?
        </button>
      )}
    >
      {() => (
        <div className="space-y-3 text-sm">
          <p className="font-bold text-ink-900 dark:text-white">Momentum beats totals</p>
          <p className="text-ink-600 dark:text-ink-300">We rank by <b>vote velocity</b>, not just the vote count. Each hour&apos;s votes lose weight as they age, and the first two hours of a launch count extra.</p>
          <div className="space-y-2 rounded-xl bg-ink-50 p-3 dark:bg-white/5">
            <div className="flex items-center gap-2">
              <Zap className="size-4 text-brand-500" />
              <span className="flex-1">20 votes in the first hour</span>
              <Badge tone="green">Higher</Badge>
            </div>
            <div className="flex items-center gap-2">
              <TrendingUp className="size-4 text-ink-400" />
              <span className="flex-1">30 votes across the day</span>
              <Badge>Lower</Badge>
            </div>
          </div>
          <p className="text-xs text-ink-500">Counts update live and the order is recalculated about every minute. At midnight UTC it freezes and never changes again.</p>
        </div>
      )}
    </Popover>
  )
}

/** The day's number one (the API has no featured placements, so the hero is rank 1). */
function TopSpotlight({ product }: { product: ProductCard }) {
  return (
    <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ type: 'spring', stiffness: 260, damping: 26 }} className="group surface relative overflow-hidden p-4 sm:p-5">
      <div className="pointer-events-none absolute -top-24 -right-24 size-64 rounded-full opacity-40 blur-3xl transition-opacity group-hover:opacity-70" style={{ background: `hsl(${product.hue} 90% 70%)` }} />
      <Link to={`/p/${product.slug}`} aria-label={product.name} className="absolute inset-0 z-0" />
      <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center">
        <div className="flex min-w-0 flex-1 items-start gap-4">
          <motion.div whileHover={{ rotate: -8, scale: 1.06 }} transition={{ type: 'spring', stiffness: 400, damping: 12 }}>
            <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="lg" />
          </motion.div>
          <div className="min-w-0 flex-1">
            <Badge tone="violet" icon={<Star className="size-3 fill-current" />}>#1 today</Badge>
            <h2 className="mt-1.5 text-xl font-bold group-hover:text-brand-600 sm:text-2xl">{product.name}</h2>
            <p className="mt-0.5 line-clamp-2 text-sm text-ink-500">{product.tagline}</p>
            <div className="pointer-events-none relative z-10 mt-2 flex flex-wrap gap-1.5 [&_a]:pointer-events-auto">
              {cardTopics(product).map((t) => (
                <TopicChip key={t.id} topic={t} />
              ))}
            </div>
          </div>
          <div className="relative z-10 sm:hidden">
            <VoteButton product={product} />
          </div>
        </div>
        <div className="relative z-10 hidden sm:block">
          <VoteButton product={product} size="lg" />
        </div>
      </div>
    </motion.div>
  )
}

function SignUpBanner() {
  const [hidden, setHidden] = useState(() => sessionStorage.getItem('lp-hide-banner') === '1')
  const navigate = useNavigate()
  return (
    <AnimatePresence initial={false}>
      {!hidden && (
        <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }} className="overflow-hidden">
          <div className="relative mb-5 flex flex-col gap-3 overflow-hidden rounded-2xl bg-gradient-to-r from-brand-500 via-pink-500 to-violet-500 p-4 text-white shadow-glow sm:flex-row sm:items-center">
            <motion.div animate={{ rotate: [0, 12, -8, 0] }} transition={{ repeat: Infinity, duration: 4, ease: 'easeInOut' }} className="hidden size-10 shrink-0 items-center justify-center rounded-xl bg-white/20 sm:flex">
              <Sparkles className="size-5" />
            </motion.div>
            <div className="flex-1 pr-6">
              <p className="font-bold">Discover tomorrow&apos;s favorite products today</p>
              <p className="text-sm text-white/85">Join free to vote, comment and follow the makers you love.</p>
            </div>
            <div className="flex gap-2">
              <Button size="sm" variant="secondary" className="bg-white text-ink-900 hover:bg-white/90 dark:bg-white dark:text-ink-900" onClick={() => navigate('/signup')}>
                Join free
              </Button>
              <Button size="sm" variant="ghost" className="text-white hover:bg-white/15 hover:text-white dark:text-white" onClick={() => navigate('/signin')}>
                Sign in
              </Button>
            </div>
            <button
              aria-label="Dismiss"
              onClick={() => {
                sessionStorage.setItem('lp-hide-banner', '1')
                setHidden(true)
              }}
              className="absolute top-2 right-2 cursor-pointer rounded-full p-1 text-white/80 hover:bg-white/15 hover:text-white"
            >
              <X className="size-4" />
            </button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}

function LaunchCta({ compact }: { compact?: boolean }) {
  const gate = useAuthGate()
  const navigate = useNavigate()
  return (
    <div className={cn('relative overflow-hidden rounded-2xl bg-ink-900 p-5 text-white dark:bg-white/5 dark:ring-1 dark:ring-white/10', compact && 'p-4')}>
      <div className="pointer-events-none absolute -top-10 -right-10 size-40 rounded-full bg-gradient-to-br from-brand-500 to-pink-500 opacity-50 blur-2xl" />
      <motion.div animate={{ y: [0, -4, 0] }} transition={{ repeat: Infinity, duration: 2.6, ease: 'easeInOut' }} className="relative mb-3 flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-pink-500 shadow-glow">
        <Rocket className="size-5" />
      </motion.div>
      <p className="relative font-display text-lg font-bold">Built something?</p>
      <p className="relative mt-0.5 text-sm text-white/70">Pick your day and launch it to thousands of early adopters.</p>
      <Button className="relative mt-4 w-full" icon={<Rocket className="size-4" />} onClick={() => gate('launch your product', () => navigate('/submit'))}>
        Launch your product
      </Button>
    </div>
  )
}

function Sidebar({ yesterdayTop }: { yesterdayTop: ProductCard[] }) {
  const yesterday = addDays(today(), -1)
  const { data: topics = [] } = useTopics()
  return (
    <aside className="hidden space-y-5 lg:block">
      <div className="surface p-4">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-bold">Yesterday&apos;s top 3</h3>
          <Link to={`/day/${yesterday}`} className="text-xs font-semibold text-brand-600 hover:underline">
            See all
          </Link>
        </div>
        {yesterdayTop.length ? (
          <ul className="space-y-1">
            {yesterdayTop.map((p, i) => (
              <motion.li key={p.id} initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.1 + i * 0.08 }}>
                <Link to={`/p/${p.slug}`} className="group flex items-center gap-3 rounded-xl p-2 transition hover:bg-ink-50 dark:hover:bg-white/5">
                  <span className="w-4 text-center font-display font-bold text-ink-300">{i + 1}</span>
                  <ProductLogo name={p.name} src={p.logoUrl} hue={p.hue} size="sm" className="transition-transform group-hover:-rotate-6" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-bold group-hover:text-brand-600">{p.name}</p>
                    <p className="text-xs text-ink-500">{p.votes} votes</p>
                  </div>
                  <ChevronRight className="size-4 text-ink-300 transition-transform group-hover:translate-x-0.5" />
                </Link>
              </motion.li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-ink-500">No launches yesterday.</p>
        )}
      </div>

      <div className="surface p-4">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-bold">Browse topics</h3>
          <Link to="/topics" className="text-xs font-semibold text-brand-600 hover:underline">
            All topics
          </Link>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {topics.map((t) => (
            <Link
              key={t.id}
              to={`/topics/${t.slug}`}
              className="inline-flex items-center gap-1 rounded-full border border-ink-200 px-2.5 py-1 text-xs font-semibold text-ink-600 transition hover:-translate-y-0.5 hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:text-ink-300"
            >
              {t.emoji} {t.name}
            </Link>
          ))}
        </div>
      </div>

      <LaunchCta />
    </aside>
  )
}

function ListSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="space-y-3" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-24 w-full rounded-2xl" />
      ))}
    </div>
  )
}

export default function HomePage() {
  const user = useCurrentUser()
  const todayQ = useToday()
  const yesterday = addDays(today(), -1)
  const yesterdayQ = useDay(yesterday)
  const list = todayQ.data?.products
  const all = list ?? []
  const live = useTodayLive()
  useProductLive(all.map((p) => p.id))
  const moves = useRankMoves(all)

  const hero = all[0]
  const ranked = all.slice(1)
  const totalVotes = all.reduce((s, p) => s + p.votes, 0)
  const yesterdayRanked = yesterdayQ.data?.products ?? []
  const previousDays = Array.from({ length: 7 }, (_, i) => addDays(today(), -(i + 2)))

  return (
    <div>
      {!user && <SignUpBanner />}

      {/* Hero strip */}
      <motion.section initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="surface relative mb-6 overflow-hidden p-5 sm:p-6">
        <div className="pointer-events-none absolute -top-24 -left-16 size-72 rounded-full bg-brand-300/30 blur-3xl dark:bg-brand-500/10" />
        <div className="pointer-events-none absolute -right-10 -bottom-28 size-72 rounded-full bg-violet-300/30 blur-3xl dark:bg-violet-500/10" />
        <div className="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="flex items-center gap-1.5 text-xs font-semibold tracking-wider text-ink-500 uppercase">
              <CalendarDays className="size-3.5" /> {formatDay(todayQ.data?.date ?? today())}
            </p>
            <h1 className="mt-1 text-3xl font-bold sm:text-4xl">
              Launching <span className="text-gradient">today</span>
            </h1>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-600 dark:text-ink-300">
              <span className="font-semibold">
                <AnimatedNumber value={all.length} className="font-bold text-ink-900 dark:text-white" /> launches today
              </span>
              <span className="text-ink-300">·</span>
              <span>
                <AnimatedNumber value={totalVotes} className="font-bold text-ink-900 dark:text-white" /> votes so far
              </span>
              <LivePulse live={live} />
            </div>
          </div>
          <Countdown />
        </div>
      </motion.section>

      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="min-w-0 space-y-8">
          {hero && <TopSpotlight product={hero} />}

          <section>
            <div className="mb-3 flex items-center justify-between gap-2">
              <h2 className="text-xl font-bold">Today&apos;s ranking</h2>
              <WhyThisOrder />
            </div>
            {todayQ.isPending ? (
              <ListSkeleton />
            ) : todayQ.isError ? (
              <ErrorState error={todayQ.error} onRetry={() => void todayQ.refetch()} title="Could not load today's launches" />
            ) : ranked.length ? (
              <RankedList id="today" products={ranked} startRank={2} moves={moves} />
            ) : hero ? null : (
              <EmptyState icon={<Rocket className="size-6" />} title="No launches yet today" body="Check back soon, or browse yesterday's winners below." />
            )}
          </section>

          <div className="lg:hidden">
            <LaunchCta compact />
          </div>

          <section>
            <div className="mb-3 flex items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <h2 className="text-xl font-bold">Yesterday</h2>
                <Badge tone="neutral">{yesterdayQ.data && !yesterdayQ.data.frozen ? 'Finalising' : 'Final'}</Badge>
              </div>
              <Link to={`/day/${yesterday}`} className="group inline-flex items-center gap-1 text-sm font-semibold text-brand-600">
                Full results <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
              </Link>
            </div>
            {yesterdayQ.isPending ? (
              <ListSkeleton rows={3} />
            ) : yesterdayQ.isError ? (
              <ErrorState error={yesterdayQ.error} onRetry={() => void yesterdayQ.refetch()} title="Could not load yesterday's results" />
            ) : yesterdayRanked.length ? (
              <RankedList id="yesterday" products={yesterdayRanked.slice(0, 5)} />
            ) : (
              <p className="surface p-6 text-center text-sm text-ink-500">Nothing launched yesterday.</p>
            )}

            <h3 className="mt-8 mb-3 text-sm font-bold tracking-wide text-ink-500 uppercase">Previous days</h3>
            <div className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0">
              {previousDays.map((d, i) => (
                <motion.div key={d} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }} whileHover={{ y: -3 }}>
                  <Link
                    to={`/day/${d}`}
                    className="flex w-24 shrink-0 flex-col items-center rounded-2xl border border-ink-200 bg-white px-3 py-3 text-center transition hover:border-brand-300 hover:shadow-lift dark:border-white/10 dark:bg-ink-900"
                  >
                    <span className="text-[11px] font-semibold text-ink-400 uppercase">{formatDay(d, { weekday: 'short' })}</span>
                    <span className="font-display text-2xl font-bold">{formatDay(d, { day: 'numeric' })}</span>
                    <span className="text-[11px] text-ink-500">{formatDay(d, { month: 'short' })}</span>
                  </Link>
                </motion.div>
              ))}
            </div>
          </section>
        </div>

        <Sidebar yesterdayTop={yesterdayRanked.slice(0, 3)} />
      </div>
    </div>
  )
}
