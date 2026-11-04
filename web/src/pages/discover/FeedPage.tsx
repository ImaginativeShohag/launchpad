import { AnimatePresence, motion } from 'motion/react'
import { Compass, Rss, Sparkles, UserRound, X } from 'lucide-react'
import { useMemo } from 'react'
import { Link } from 'react-router'
import { useFeed } from '@/api/hooks/useFeed'
import { useFollowTopic, useFollowUser } from '@/api/hooks/useFollow'
import { useFollowedTopics, useFollowedUsers } from '@/api/hooks/useMyFollows'
import { useTopics } from '@/api/hooks/useTopics'
import type { FeedCard } from '@/data/types'
import { relativeDay } from '@/lib/date'
import { ProductRow } from '@/components/product/ProductRow'
import { TopicFollowButton } from '@/components/product/TopicFollowButton'
import { Avatar, Badge, Button, EmptyState, ErrorState, LoadMore, Skeleton } from '@/components/ui'

/** "Amina launched" and "Because you follow AI, Design": the member reason first, then the followed topics. */
function Reasons({ card }: { card: FeedCard }) {
  const member = card.reasons.find((r) => r.type === 'member')
  const topicNames = card.reasons.flatMap((r) => (r.type === 'topic' ? [r.name] : []))
  return (
    <div className="mb-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 pl-1 text-xs font-semibold text-ink-500">
      {member && (
        <span className="inline-flex items-center gap-1.5">
          <UserRound className="size-3.5 text-violet-500" />
          {member.name} launched
        </span>
      )}
      {topicNames.length > 0 && (
        <span className="inline-flex items-center gap-1.5">
          <Compass className="size-3.5 text-brand-500" />
          Because you follow {topicNames.join(', ')}
        </span>
      )}
    </div>
  )
}

/** "You follow": the makers and topics behind the feed, each with a one-click unfollow. */
function FollowingStrip() {
  const users = useFollowedUsers()
  const topics = useFollowedTopics()
  const unfollowUser = useFollowUser()
  const unfollowTopic = useFollowTopic()
  const makers = users.items
  const followedTopics = topics.data ?? []
  if (!makers.length && !followedTopics.length && !users.hasNextPage) return null

  const chip = 'inline-flex items-center gap-1.5 rounded-full border border-ink-200 bg-white py-1 pr-1 pl-2.5 text-sm font-medium dark:border-white/10 dark:bg-white/5'
  const x = 'flex size-5 cursor-pointer items-center justify-center rounded-full text-ink-400 transition hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-red-500/10'
  return (
    <section aria-label="You follow" className="surface mb-6 p-4">
      <h2 className="mb-3 text-xs font-semibold tracking-wider text-ink-500 uppercase">You follow</h2>
      <ul className="flex flex-wrap gap-2">
        {followedTopics.map((t) => (
          <li key={`t-${t.id}`} className={chip}>
            <Link to={`/topics/${t.slug}`} className="inline-flex items-center gap-1 hover:text-brand-600">
              <span>{t.emoji}</span>
              {t.name}
            </Link>
            <button aria-label={`Unfollow ${t.name}`} disabled={unfollowTopic.isPending} onClick={() => unfollowTopic.mutate({ topic: t, follow: false })} className={x}>
              <X className="size-3.5" />
            </button>
          </li>
        ))}
        {makers.map((u) => (
          <li key={`u-${u.id}`} className={chip}>
            <Link to={`/u/${u.handle}`} className="inline-flex items-center gap-1.5 hover:text-brand-600">
              <Avatar name={u.name} hue={u.hue} src={u.avatarUrl} size="xs" />
              {u.name}
            </Link>
            <button aria-label={`Unfollow ${u.name}`} disabled={unfollowUser.isPending} onClick={() => unfollowUser.mutate({ handle: u.handle, follow: false })} className={x}>
              <X className="size-3.5" />
            </button>
          </li>
        ))}
      </ul>
      {users.hasNextPage && (
        <Button size="sm" variant="ghost" className="mt-2" loading={users.isFetchingNextPage} onClick={() => void users.fetchNextPage()}>
          Show more makers
        </Button>
      )}
    </section>
  )
}

export default function FeedPage() {
  const feed = useFeed()
  const topics = useTopics()
  const items = feed.items

  // Consecutive cards of the same launch day share a heading (the feed is newest launch first).
  const groups = useMemo(() => {
    const out: [string, FeedCard[]][] = []
    for (const card of items) {
      const day = card.launchDate ?? ''
      const last = out[out.length - 1]
      if (last && last[0] === day) last[1].push(card)
      else out.push([day, [card]])
    }
    return out
  }, [items])

  const followed = useFollowedTopics()
  const followsNothing = feed.hasFollows === false
  // Suggestions: the first topics of the list that the member does not follow yet.
  const suggestedTopics = (topics.data ?? []).filter((t) => !followed.data?.some((f) => f.slug === t.slug)).slice(0, 6)

  return (
    <div className="mx-auto max-w-3xl">
      <header className="mb-6">
        <p className="flex items-center gap-1.5 text-xs font-semibold tracking-wider text-ink-500 uppercase">
          <Rss className="size-3.5" /> Your feed
        </p>
        <h1 className="mt-1 text-3xl font-bold sm:text-4xl">
          <span className="text-gradient">Following</span>
        </h1>
        <p className="mt-1 text-sm text-ink-500">Launches from the makers and topics you follow.</p>
      </header>

      {feed.hasFollows !== false && <FollowingStrip />}

      {feed.isPending ? (
        <div className="space-y-4" aria-busy="true" aria-label="Loading">
          {Array.from({ length: 3 }, (_, i) => (
            <Skeleton key={i} className="h-24 w-full rounded-2xl" />
          ))}
        </div>
      ) : feed.isError && !feed.data ? (
        <ErrorState error={feed.error} onRetry={() => void feed.refetch()} title="Could not load your feed" />
      ) : items.length ? (
        <div className="space-y-8">
          {groups.map(([day, list], gi) => (
            <section key={day || gi}>
              <motion.h2 initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: gi * 0.05 }} className="mb-3 flex items-center gap-2 text-sm font-bold tracking-wide text-ink-500 uppercase">
                <span className="size-1.5 rounded-full bg-brand-500" />
                {day ? relativeDay(day) : 'Earlier'}
              </motion.h2>
              <ul className="space-y-4">
                <AnimatePresence mode="popLayout" initial={false}>
                  {list.map((card, i) => (
                    <motion.li
                      key={card.id}
                      layout
                      initial={{ opacity: 0, y: 12 }}
                      animate={{ opacity: 1, y: 0, transition: { delay: Math.min(i, 8) * 0.05 } }}
                      exit={{ opacity: 0, x: -20, transition: { duration: 0.2 } }}
                    >
                      <Reasons card={card} />
                      <ProductRow product={card} />
                    </motion.li>
                  ))}
                </AnimatePresence>
              </ul>
            </section>
          ))}
          <LoadMore hasNextPage={feed.hasNextPage} loading={feed.isFetchingNextPage} failed={feed.isFetchNextPageError} onClick={() => void feed.fetchNextPage()} />
        </div>
      ) : (
        <EmptyState
          icon={<Sparkles className="size-6" />}
          title={followsNothing ? 'Your feed is waiting' : 'Nothing new yet'}
          body={followsNothing ? 'Follow a few topics or makers and their launches will appear here.' : 'The makers and topics you follow have no launches yet.'}
        />
      )}

      {feed.isSuccess && (followsNothing || items.length < 4) && (
        <section className="mt-10 space-y-6">
          <h2 className="text-lg font-bold">Suggestions for you</h2>
          {suggestedTopics.length > 0 && (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {suggestedTopics.map((t, i) => (
                <motion.div key={t.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }} className="surface flex items-center gap-3 p-3">
                  <span className="flex size-10 items-center justify-center rounded-xl bg-ink-100 text-xl dark:bg-white/5">{t.emoji}</span>
                  <Link to={`/topics/${t.slug}`} className="min-w-0 flex-1 truncate font-semibold hover:text-brand-600">
                    {t.name}
                  </Link>
                  <TopicFollowButton topic={t} size="sm" />
                </motion.div>
              ))}
            </div>
          )}
          <div className="flex justify-center">
            <Link to="/topics">
              <Button variant="outline" icon={<Compass className="size-4" />}>
                Browse all topics
              </Button>
            </Link>
          </div>
          {followsNothing && <p className="text-center text-xs text-ink-400"><Badge tone="brand">Tip</Badge> Following a maker also brings their future launches here.</p>}
        </section>
      )}
    </div>
  )
}
