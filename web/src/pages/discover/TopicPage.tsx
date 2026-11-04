import { motion } from 'motion/react'
import { ArrowLeft, Compass, Rocket } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router'
import { useSearch, type SearchSort } from '@/api/hooks/useSearch'
import { useTopics } from '@/api/hooks/useTopics'
import { TopicFollowButton } from '@/components/product/TopicFollowButton'
import { Button, EmptyState, ErrorState, LoadMore, Skeleton, Tabs, type TabItem } from '@/components/ui'
import { RankedList } from './components/RankedList'

type TopicSort = Exclude<SearchSort, 'relevance'>
const tabs: TabItem<TopicSort>[] = [
  { value: 'votes', label: 'Most voted' },
  { value: 'newest', label: 'Newest' },
]
const hues = [270, 210, 45, 320, 140, 120, 10, 190, 280, 170]

export default function TopicPage() {
  const { slug } = useParams()
  const topics = useTopics()
  const topic = topics.data?.find((t) => t.slug === slug)
  const [params, setParams] = useSearchParams()
  const sort: TopicSort = params.get('sort') === 'newest' ? 'newest' : 'votes'
  // The topic's launches are a topic-only search. An unknown slug would be a 422, so nothing is asked until the topic is known.
  const list = useSearch({ topic: topic?.slug, sort }, !!topic)

  if (topics.isPending) {
    return <Skeleton className="mx-auto h-40 max-w-3xl rounded-2xl" />
  }
  if (topics.isError) return <ErrorState error={topics.error} onRetry={() => void topics.refetch()} title="Could not load this topic" />
  if (!topic) {
    return <EmptyState icon={<Compass className="size-6" />} title="Topic not found" body="That topic doesn't exist (yet)." action={<Link to="/topics"><Button>Browse topics</Button></Link>} />
  }

  const hue = hues[topics.data.indexOf(topic) % hues.length]

  return (
    <div className="mx-auto max-w-3xl">
      <Link to="/topics" className="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink-900 dark:hover:text-white">
        <ArrowLeft className="size-4" /> All topics
      </Link>

      <motion.header initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="surface relative mb-6 overflow-hidden p-5 sm:p-6">
        <div className="pointer-events-none absolute -top-20 -right-20 size-64 rounded-full opacity-40 blur-3xl" style={{ background: `hsl(${hue} 90% 65%)` }} />
        <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center">
          <motion.span
            initial={{ scale: 0.4, rotate: -20 }}
            animate={{ scale: 1, rotate: 0 }}
            transition={{ type: 'spring', stiffness: 300, damping: 14 }}
            className="flex size-16 shrink-0 items-center justify-center rounded-2xl text-4xl shadow-soft"
            style={{ background: `linear-gradient(140deg, hsl(${hue} 95% 92%), hsl(${(hue + 40) % 360} 90% 80%))` }}
          >
            {topic.emoji}
          </motion.span>
          <div className="min-w-0 flex-1">
            <h1 className="text-3xl font-bold">{topic.name}</h1>
            {topic.description && <p className="text-sm text-ink-500">{topic.description}</p>}
          </div>
          <TopicFollowButton topic={topic} />
        </div>
      </motion.header>

      <Tabs
        items={tabs}
        value={sort}
        onChange={(v) => {
          const next = new URLSearchParams(params)
          if (v === 'votes') next.delete('sort')
          else next.set('sort', v)
          setParams(next, { replace: true })
        }}
        className="mb-5"
      />

      <div key={sort}>
        {list.isPending ? (
          <div className="space-y-3" aria-busy="true" aria-label="Loading">
            {Array.from({ length: 4 }, (_, i) => (
              <Skeleton key={i} className="h-24 w-full rounded-2xl" />
            ))}
          </div>
        ) : list.isError && !list.data ? (
          <ErrorState error={list.error} onRetry={() => void list.refetch()} title="Could not load these launches" />
        ) : list.items.length ? (
          <>
            <RankedList id={`topic-${sort}`} products={list.items} ranked={sort === 'votes'} />
            <LoadMore hasNextPage={list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
          </>
        ) : (
          <EmptyState icon={<Rocket className="size-6" />} title={`No ${topic.name} launches yet`} body="Be the first to launch in this topic." />
        )}
      </div>
    </div>
  )
}
