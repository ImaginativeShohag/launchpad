import { AnimatePresence, motion } from 'motion/react'
import { ChevronRight, Clock, Hourglass, Inbox, PartyPopper, Timer } from 'lucide-react'
import { Link } from 'react-router'
import { useStaffQueue } from '@/api/hooks/useStaffReview'
import { Avatar, EmptyState, LoadMore, Skeleton, cn } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'
import { TopicChip } from '@/components/product/TopicChip'
import { formatWait, hoursSince, waitTone } from './components/wait'
import { PageHeader, StaffLoadError, StatTile } from './components/shared'

const waitStyles = {
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
  red: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20',
}

export default function ReviewQueuePage() {
  const q = useStaffQueue()

  // The API already orders by submission time (oldest first); the wait is derived from `submitted_at`.
  const queue = q.items.map((product) => ({ product, wait: hoursSince(product.submittedAt) }))
  const more = q.hasNextPage ? '+' : ''
  const oldest = queue[0]?.wait ?? 0
  const overdue = queue.filter((x) => x.wait >= 24).length
  const aging = queue.filter((x) => x.wait >= 12 && x.wait < 24).length

  return (
    <div>
      <PageHeader title="Review queue" subtitle="Oldest submissions first. Aim to decide within 24 hours." />

      {q.isPending ? (
        <div className="space-y-2.5">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-24 w-full" />
        </div>
      ) : q.isError && queue.length === 0 ? (
        <StaffLoadError error={q.error} onRetry={() => void q.refetch()} />
      ) : queue.length === 0 ? (
        <EmptyState icon={<PartyPopper className="size-6" />} title="Queue clear 🎉" body="Every submission has a decision. New ones will show up here, oldest first." />
      ) : (
        <>
          <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile label="Waiting" value={`${queue.length}${more}`} icon={<Inbox />} tone="violet" />
            <StatTile label="Oldest" value={formatWait(oldest)} icon={<Hourglass />} tone={oldest >= 24 ? 'red' : 'neutral'} />
            <StatTile label="Over 24h" value={`${overdue}${more}`} icon={<Clock />} tone={overdue ? 'red' : 'neutral'} />
            <StatTile label="12 to 24h" value={`${aging}${more}`} icon={<Timer />} tone={aging ? 'amber' : 'neutral'} />
          </div>
          <ol className="space-y-2.5">
            <AnimatePresence initial={false}>
              {queue.map(({ product, wait }, i) => {
                const tone = waitTone(wait)
                return (
                  <motion.li
                    key={product.id}
                    layout
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0, transition: { delay: Math.min(i, 10) * 0.04 } }}
                    exit={{ opacity: 0, x: 40, height: 0, marginTop: 0 }}
                  >
                    <Link
                      to={`/staff/review/${product.id}`}
                      className="group surface flex items-start gap-3 p-3 transition-all hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-lift sm:items-center sm:gap-4 sm:p-4 dark:hover:border-violet-500/30"
                    >
                      <span className="mt-1 hidden w-5 shrink-0 text-center font-display text-sm font-bold text-ink-300 sm:mt-0 sm:block dark:text-ink-600">{i + 1}</span>
                      <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="sm" className="sm:size-12" />
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                          <h3 className="truncate font-sans text-[15px] font-bold tracking-normal group-hover:text-violet-700 dark:group-hover:text-violet-300">{product.name}</h3>
                          <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 sm:hidden', waitStyles[tone])}>
                            <Clock className="size-3" /> {formatWait(wait)}
                          </span>
                        </div>
                        <p className="line-clamp-1 text-sm text-ink-500">{product.tagline}</p>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                          {product.maker && (
                            <span className="mr-1 inline-flex items-center gap-1.5 text-xs font-medium text-ink-600 dark:text-ink-300">
                              <Avatar name={product.maker.name} hue={product.maker.hue} size="xs" className="ring-0" />
                              {product.maker.name}
                            </span>
                          )}
                          {product.topics.map((t) => (
                            <TopicChip key={t.id} topic={t} asLink={false} />
                          ))}
                        </div>
                      </div>
                      <div className="hidden shrink-0 flex-col items-end gap-1 sm:flex">
                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ring-1', waitStyles[tone])}>
                          {tone === 'red' && <span className="size-1.5 animate-pulse rounded-full bg-current" />}
                          waiting {formatWait(wait)}
                        </span>
                        <span className="text-[11px] text-ink-400">submitted {new Date(product.submittedAt ?? '').toLocaleString(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' })}</span>
                      </div>
                      <ChevronRight className="mt-1 size-5 shrink-0 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-violet-500 sm:mt-0" />
                    </Link>
                  </motion.li>
                )
              })}
            </AnimatePresence>
          </ol>
          <LoadMore hasNextPage={!!q.hasNextPage} loading={q.isFetchingNextPage} failed={q.isFetchNextPageError} onClick={() => void q.fetchNextPage()} />
        </>
      )}
    </div>
  )
}
