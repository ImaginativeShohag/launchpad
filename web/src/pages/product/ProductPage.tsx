import { AnimatePresence, motion } from 'motion/react'
import {
  ArrowLeft,
  BarChart3,
  CalendarDays,
  Check,
  ChevronUp,
  ExternalLink,
  Flag,
  Images,
  LayoutDashboard,
  Maximize2,
  MessageCircle,
  SearchX,
  Share2,
  Trophy,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { useDay } from '@/api/hooks/useDay'
import { isApiError } from '@/api/errors'
import { useProduct, useViewBeacon } from '@/api/hooks/useProduct'
import { useSearch } from '@/api/hooks/useSearch'
import { useToday } from '@/api/hooks/useToday'
import { useUserProfile } from '@/api/hooks/useUserProfile'
import type { ProductCard, ProductDetail, User } from '@/data/types'
import { useAuthGate } from '@/components/layout/AuthGate'
import { LinkPill } from '@/components/product/LinkPill'
import { ProductLogo } from '@/components/product/ProductLogo'
import { ProductRow } from '@/components/product/ProductRow'
import { TopicChip } from '@/components/product/TopicChip'
import { UserFollowButton } from '@/components/product/UserFollowButton'
import { VoteButton } from '@/components/product/VoteButton'
import { AnimatedNumber, Avatar, Button, cn, ErrorState, Skeleton, useToast } from '@/components/ui'
import { formatDay, relativeDay, today } from '@/lib/date'
import { useProductLive } from '@/realtime/useLive'
import { useSession } from '@/session/SessionProvider'
import { CommentsSection } from './components/Comments'
import { Lightbox } from './components/Lightbox'
import { ReportModal } from './components/ReportModal'
import { PageState } from './components/shared'

const medal = ['from-amber-300 to-amber-500 text-amber-950', 'from-slate-200 to-slate-400 text-slate-900', 'from-orange-300 to-orange-500 text-orange-950']

export default function ProductPage() {
  const { slug } = useParams()
  const query = useProduct(slug)
  const { user } = useSession()

  if (query.isPending) {
    return (
      <div className="space-y-6" aria-busy="true" aria-label="Loading product">
        <Skeleton className="h-56 w-full rounded-3xl" />
        <Skeleton className="h-40 w-full rounded-2xl" />
      </div>
    )
  }

  if (query.isError) {
    // A hidden, unknown, taken-down or not yet launched product is the same 404 by design.
    if (isApiError(query.error) && query.error.status === 404) {
      return (
        <PageState
          icon={<SearchX />}
          title="Product not found"
          body={
            user
              ? 'This product doesn’t exist, hasn’t launched yet, or was removed. If it is yours, you can follow its progress in My products.'
              : 'This product doesn’t exist, hasn’t launched yet, or was removed. Check back on launch day.'
          }
          action={
            <>
              <Link to="/">
                <Button>See today’s launches</Button>
              </Link>
              {user && (
                <Link to="/my/products">
                  <Button variant="outline">My products</Button>
                </Link>
              )}
            </>
          }
        />
      )
    }
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} title="Could not load this product" />
  }

  return <ProductView key={query.data.id} product={query.data} />
}

function useProductRank(product: ProductDetail): { n: number; live: boolean } | null {
  const day = today()
  const date = product.launchDate
  const isToday = date === day
  const isPast = !!date && date < day
  const todays = useToday(isToday)
  const past = useDay(date ?? day, isPast)
  const list: ProductCard[] | undefined = isToday ? todays.data?.products : isPast ? past.data?.products : undefined
  const idx = list?.findIndex((p) => p.id === product.id) ?? -1
  if (!list || idx < 0) return null
  return { n: list[idx].rank ?? idx + 1, live: isToday }
}

function ProductView({ product }: { product: ProductDetail }) {
  const { user } = useSession()
  const gate = useAuthGate()
  const toast = useToast()
  const slug = product.slug

  const [shot, setShot] = useState<number | null>(null)
  const [reportOpen, setReportOpen] = useState(false)
  const [copied, setCopied] = useState(false)
  const [showBar, setShowBar] = useState(false)
  const heroRef = useRef<HTMLDivElement>(null)

  const isMaker = !!user && user.id === product.maker.id
  const rank = useProductRank(product)
  const firstTopic = product.topics[0]
  const relatedQuery = useSearch({ topic: firstTopic?.slug, sort: 'votes' }, !!firstTopic)
  const related = useMemo(() => relatedQuery.items.filter((p) => p.id !== product.id).slice(0, 3), [relatedQuery.items, product.id])

  useViewBeacon(product.id)
  useProductLive([product.id])

  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [slug])

  // Reveal the sticky mobile vote bar once the hero scrolls off-screen.
  useEffect(() => {
    const el = heroRef.current
    if (!el) return
    const io = new IntersectionObserver(([e]) => setShowBar(!e.isIntersecting && e.boundingClientRect.top < 0), { threshold: 0 })
    io.observe(el)
    return () => io.disconnect()
  }, [product.id])

  const copiedTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const linkRef = useRef<HTMLInputElement>(null)
  const [manualCopy, setManualCopy] = useState(false)
  useEffect(() => () => clearTimeout(copiedTimer.current), [])
  useEffect(() => {
    if (manualCopy) linkRef.current?.select()
  }, [manualCopy])

  const share = async () => {
    try {
      await navigator.clipboard.writeText(window.location.href)
    } catch {
      // The browser refused (no permission, insecure page): let the member copy it by hand.
      setManualCopy(true)
      return
    }
    setManualCopy(false)
    setCopied(true)
    toast({ tone: 'success', title: 'Link copied', body: 'Share it with your friends.' })
    clearTimeout(copiedTimer.current)
    copiedTimer.current = setTimeout(() => setCopied(false), 1800)
  }

  const websiteHref = (() => {
    try {
      const u = new URL(product.website)
      if (u.protocol !== 'http:' && u.protocol !== 'https:') return undefined
      u.searchParams.set('ref', 'launchpad')
      return u.toString()
    } catch {
      return undefined
    }
  })()

  const commentCount = product.commentsCount
  const shots = product.screenshots
  const maker = product.maker

  return (
    <div className="pb-24 md:pb-0">
      <Link to={product.launchDate ? `/day/${product.launchDate}` : '/'} className="group mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900 dark:hover:text-white">
        <ArrowLeft className="size-4 transition-transform group-hover:-translate-x-1" />
        {product.launchDate ? `${relativeDay(product.launchDate)}’s launches` : 'All launches'}
      </Link>

      {/* Hero */}
      <motion.section
        ref={heroRef}
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ type: 'spring', stiffness: 260, damping: 30 }}
        className="surface relative overflow-hidden p-5 sm:p-8"
      >
        <div
          aria-hidden
          className="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full opacity-60 blur-3xl dark:opacity-40"
          style={{ background: `radial-gradient(circle, hsl(${product.hue} 95% 70% / 0.45), transparent 65%)` }}
        />
        <div
          aria-hidden
          className="pointer-events-none absolute -bottom-40 -left-24 size-80 rounded-full opacity-50 blur-3xl dark:opacity-30"
          style={{ background: `radial-gradient(circle, hsl(${(product.hue + 50) % 360} 90% 65% / 0.35), transparent 65%)` }}
        />

        <div className="relative flex flex-col gap-6 sm:flex-row sm:items-start">
          <motion.div
            initial={{ scale: 0.6, rotate: -12, opacity: 0 }}
            animate={{ scale: 1, rotate: 0, opacity: 1 }}
            whileHover={{ rotate: [-4, 4, -2, 0], scale: 1.04 }}
            transition={{ type: 'spring', stiffness: 260, damping: 14 }}
            className="self-start"
          >
            <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="xl" />
          </motion.div>

          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">{rank && <RankBadge rank={rank.n} live={rank.live} date={product.launchDate} />}</div>
            <h1 className="mt-2 text-3xl leading-tight font-bold tracking-tight sm:text-4xl">{product.name}</h1>
            <p className="mt-1.5 text-lg text-ink-600 sm:text-xl dark:text-ink-300">{product.tagline}</p>
            <div className="mt-3 flex flex-wrap items-center gap-1.5">
              {product.topics.map((t) => (
                <TopicChip key={t.id} topic={t} className="px-2.5 py-1 text-sm" />
              ))}
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-500">
              {product.launchDate && (
                <span className="inline-flex items-center gap-1.5">
                  <CalendarDays className="size-4" />
                  Launched {formatDay(product.launchDate, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}
                </span>
              )}
              <a href="#comments-title" className="inline-flex items-center gap-1.5 hover:text-ink-800 dark:hover:text-ink-200">
                <MessageCircle className="size-4" /> {commentCount} {commentCount === 1 ? 'comment' : 'comments'}
              </a>
            </div>
          </div>

          <div className="hidden sm:block">
            <VoteButton product={product} size="lg" />
          </div>
        </div>

        <div className="relative mt-6 flex flex-wrap items-center gap-2">
          <div className="sm:hidden">
            <VoteButton product={product} size="lg" />
          </div>
          <div className="flex flex-1 flex-wrap items-center gap-2">
            {websiteHref && (
              <a href={websiteHref} target="_blank" rel="noopener noreferrer" className="flex-1 sm:flex-none">
                <Button size="lg" className="w-full" icon={<ExternalLink className="size-4" />}>
                  Visit website
                </Button>
              </a>
            )}
            <Button size="lg" variant="outline" onClick={share} aria-label="Copy link" className="px-4">
              <AnimatePresence mode="popLayout" initial={false}>
                <motion.span
                  key={copied ? 'c' : 's'}
                  initial={{ scale: 0.4, opacity: 0, rotate: -45 }}
                  animate={{ scale: 1, opacity: 1, rotate: 0 }}
                  exit={{ scale: 0.4, opacity: 0 }}
                  className="inline-flex items-center gap-2"
                >
                  {copied ? <Check className="size-4 text-emerald-500" strokeWidth={3} /> : <Share2 className="size-4" />}
                  <span className="hidden sm:inline">{copied ? 'Copied' : 'Share'}</span>
                </motion.span>
              </AnimatePresence>
            </Button>
            {!isMaker && (
              <Button
                size="lg"
                variant="ghost"
                aria-label="Report product"
                className="px-4 hover:text-red-600"
                icon={<Flag className="size-4" />}
                onClick={() => gate('report products', () => setReportOpen(true))}
              >
                <span className="hidden sm:inline">Report</span>
              </Button>
            )}
          </div>
        </div>
        {manualCopy && (
          <div className="relative mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-500">
            <input ref={linkRef} readOnly aria-label="Page link" value={window.location.href} onFocus={(e) => e.currentTarget.select()} className="min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-3 py-2 text-ink-700 dark:border-white/10 dark:bg-ink-950 dark:text-ink-200" />
            <span>Press Ctrl/Cmd+C to copy</span>
          </div>
        )}
      </motion.section>

      <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="min-w-0 space-y-6">
          {shots.length > 0 && (
            <section aria-label="Screenshots">
              <div className="mb-3 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-lg font-bold">
                  <Images className="size-5 text-ink-400" /> Gallery
                </h2>
                <span className="text-xs text-ink-400">Tap to enlarge</span>
              </div>
              <div className="no-scrollbar -mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-px-4 px-4 pb-2 sm:mx-0 sm:scroll-px-0 sm:px-0">
                {shots.map((s, i) => (
                  <motion.button
                    key={s.id}
                    initial={{ opacity: 0, x: 24 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ delay: 0.08 * i, type: 'spring', stiffness: 260, damping: 28 }}
                    whileHover={{ y: -4 }}
                    whileTap={{ scale: 0.98 }}
                    onClick={() => setShot(i)}
                    aria-label={`Open screenshot ${i + 1}: ${s.alt}`}
                    className="group relative w-[85%] shrink-0 cursor-zoom-in snap-start sm:w-[72%]"
                  >
                    <img src={s.url} alt={s.alt} loading="lazy" className="aspect-[16/10] w-full rounded-2xl object-cover ring-1 ring-black/5 transition-shadow group-hover:shadow-lift" />
                    <span className="absolute right-3 bottom-3 flex size-9 items-center justify-center rounded-full bg-white/90 text-ink-700 opacity-0 shadow-soft transition group-hover:opacity-100 dark:bg-ink-900/90 dark:text-ink-100">
                      <Maximize2 className="size-4" />
                    </span>
                  </motion.button>
                ))}
              </div>
            </section>
          )}

          <section className="surface p-5 sm:p-6">
            <h2 className="text-lg font-bold">About {product.name}</h2>
            <p className="mt-3 text-[15px] leading-relaxed whitespace-pre-line text-ink-700 dark:text-ink-200">{product.description || 'No description yet.'}</p>
            {product.links.length > 0 && (
              <div className="mt-4 flex flex-wrap gap-2">
                {product.links.map((l, i) => (
                  <LinkPill key={l.id} link={{ label: l.kind, url: l.url }} index={i} rel="noopener noreferrer" />
                ))}
              </div>
            )}
          </section>

          <div className="space-y-6 lg:hidden">
            <MakerCard maker={maker} />
            {isMaker && <OwnerTools productId={product.id} />}
          </div>

          <CommentsSection product={product} />
        </div>

        <aside className="hidden lg:block">
          <div className="sticky top-24 space-y-6">
            <MakerCard maker={maker} />
            {isMaker && <OwnerTools productId={product.id} />}
            {related.length > 0 && firstTopic && <RelatedList topicName={firstTopic.name} topicSlug={firstTopic.slug} products={related} compact />}
          </div>
        </aside>
      </div>

      {related.length > 0 && firstTopic && (
        <div className="mt-8 lg:hidden">
          <RelatedList topicName={firstTopic.name} topicSlug={firstTopic.slug} products={related} />
        </div>
      )}

      {/* Sticky mobile vote bar */}
      <AnimatePresence>
        {showBar && (
          <motion.div
            initial={{ y: 80, opacity: 0 }}
            animate={{ y: 0, opacity: 1 }}
            exit={{ y: 80, opacity: 0 }}
            transition={{ type: 'spring', stiffness: 420, damping: 34 }}
            className="fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-20 flex items-center gap-3 rounded-2xl border border-ink-200/70 bg-white/90 p-2.5 shadow-lift backdrop-blur-xl md:hidden dark:border-white/10 dark:bg-ink-900/90"
          >
            <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="sm" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-bold">{product.name}</p>
              {rank && <p className="text-xs text-ink-500">#{rank.n} {rank.live ? 'today' : 'of the day'}</p>}
            </div>
            <VoteButton product={product} />
          </motion.div>
        )}
      </AnimatePresence>

      <Lightbox open={shot !== null} index={shot ?? 0} onIndex={setShot} onClose={() => setShot(null)} shots={shots} title={product.name} />
      <ReportModal open={reportOpen} onClose={() => setReportOpen(false)} kind="product" targetId={product.id} subject={product.name} />
    </div>
  )
}

function RankBadge({ rank, live, date }: { rank: number; live: boolean; date?: string }) {
  const top = rank <= 3
  const body = (
    <motion.span
      initial={{ scale: 0.6, opacity: 0 }}
      animate={{ scale: 1, opacity: 1 }}
      transition={{ type: 'spring', stiffness: 400, damping: 16, delay: 0.15 }}
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold',
        top ? 'bg-gradient-to-b shadow-soft ' + medal[rank - 1] : 'bg-ink-100 text-ink-700 dark:bg-white/10 dark:text-ink-200',
      )}
    >
      <Trophy className="size-3.5" />#
      <AnimatedNumber value={rank} />
      <span className="font-semibold">{live ? 'today' : date ? `on ${relativeDay(date)}` : 'of the day'}</span>
      {live && (
        <span className="relative ml-0.5 flex size-1.5">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60" />
          <span className="relative inline-flex size-1.5 rounded-full bg-current" />
        </span>
      )}
    </motion.span>
  )
  return date ? (
    <Link to={live ? '/' : `/day/${date}`} title={live ? 'Live rank — updates as votes come in' : 'Final rank for the day'}>
      {body}
    </Link>
  ) : (
    body
  )
}

/** The maker's card. Follower numbers and "am I following" come from the public profile (the product read has none). */
function MakerCard({ maker }: { maker: User }) {
  const profile = useUserProfile(maker.handle)
  const followers = profile.data?.followersCount

  return (
    <section className="surface group relative overflow-hidden p-5">
      <div
        aria-hidden
        className="absolute inset-x-0 top-0 h-16 opacity-80 transition-opacity group-hover:opacity-100"
        style={{ background: `linear-gradient(120deg, hsl(${maker.hue} 85% 85%), hsl(${(maker.hue + 40) % 360} 85% 78%))` }}
      />
      <div className="relative">
        <p className="text-[11px] font-semibold tracking-wider text-ink-700/70 uppercase">Maker</p>
        <div className="mt-4 flex items-end justify-between gap-3">
          <Link to={`/u/${maker.handle}`} className="transition hover:scale-105">
            <Avatar name={maker.name} hue={maker.hue} src={maker.avatarUrl} size="lg" className="ring-4" />
          </Link>
          {profile.data && <UserFollowButton size="sm" profile={profile.data} />}
        </div>
        <Link to={`/u/${maker.handle}`} className="mt-3 block">
          <p className="font-bold hover:text-brand-600 dark:hover:text-brand-400">{maker.name}</p>
          <p className="text-sm text-ink-500">@{maker.handle}</p>
        </Link>
        {maker.headline && <p className="mt-2 text-sm text-ink-600 dark:text-ink-300">{maker.headline}</p>}
        {followers !== undefined && (
          <p className="mt-3 text-xs text-ink-500">
            <span className="font-semibold text-ink-800 tabular-nums dark:text-ink-100">
              <AnimatedNumber value={followers} />
            </span>{' '}
            {followers === 1 ? 'follower' : 'followers'}
          </p>
        )}
      </div>
    </section>
  )
}

/** Editing lives in My products; the product page itself is read-only for its maker. */
function OwnerTools({ productId }: { productId: string }) {
  return (
    <section className="relative overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 to-pink-50 p-5 dark:border-brand-500/20 dark:from-brand-500/10 dark:to-pink-500/5">
      <p className="text-[11px] font-semibold tracking-wider text-brand-700 uppercase dark:text-brand-300">Your product</p>
      <div className="mt-4 grid gap-2">
        <Link to={`/my/products/${productId}/dashboard`}>
          <Button className="w-full" icon={<BarChart3 className="size-4" />}>
            Open dashboard
          </Button>
        </Link>
        <Link to="/my/products">
          <Button variant="secondary" className="w-full" icon={<LayoutDashboard className="size-4" />}>
            Manage in My products
          </Button>
        </Link>
      </div>
    </section>
  )
}

function RelatedList({ topicName, topicSlug, products, compact }: { topicName: string; topicSlug: string; products: ProductCard[]; compact?: boolean }) {
  return (
    <section>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-bold">More in {topicName}</h2>
        <Link to={`/topics/${topicSlug}`} className="text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">
          See all
        </Link>
      </div>
      {compact ? (
        <div className="surface divide-y divide-ink-100 overflow-hidden dark:divide-white/5">
          {products.map((p) => (
            <Link key={p.id} to={`/p/${p.slug}`} className="group flex items-center gap-3 p-3 transition hover:bg-ink-50 dark:hover:bg-white/5">
              <span className="transition-transform group-hover:-rotate-6">
                <ProductLogo name={p.name} src={p.logoUrl} hue={p.hue} size="sm" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold group-hover:text-brand-600 dark:group-hover:text-brand-400">{p.name}</span>
                <span className="block truncate text-xs text-ink-500">{p.tagline}</span>
              </span>
              <span className="inline-flex items-center gap-0.5 text-xs font-semibold text-ink-500 tabular-nums">
                <ChevronUp className="size-3.5" />
                {p.votes}
              </span>
            </Link>
          ))}
        </div>
      ) : (
        <div className="space-y-3">
          {products.map((p) => (
            <ProductRow key={p.id} product={p} />
          ))}
        </div>
      )}
    </section>
  )
}
