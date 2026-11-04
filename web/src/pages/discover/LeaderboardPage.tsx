import { motion } from 'motion/react'
import { Trophy } from 'lucide-react'
import { useSearchParams } from 'react-router'
import { useLeaderboard } from '@/api/hooks/useLeaderboard'
import { useTopics } from '@/api/hooks/useTopics'
import { EmptyState, ErrorState, Skeleton, Tabs, type TabItem } from '@/components/ui'
import { Podium } from './components/Podium'
import { RankedList } from './components/RankedList'
import { TopicFilter } from './components/TopicFilter'

type BoardRange = 'week' | 'month' | 'all'
const tabs: TabItem<BoardRange>[] = [
  { value: 'week', label: 'This week' },
  { value: 'month', label: 'This month' },
  { value: 'all', label: 'All time' },
]
const subtitle: Record<BoardRange, string> = {
  week: 'The most loved launches of the last 7 days',
  month: 'The most loved launches of the last 30 days',
  all: 'The hall of fame. Every launch, ever.',
}

export default function LeaderboardPage() {
  const [params, setParams] = useSearchParams()
  const rawRange = params.get('range')
  const range: BoardRange = rawRange === 'month' || rawRange === 'all' ? rawRange : 'week'
  const topicSlug = params.get('topic') ?? ''
  const topics = useTopics()
  // A slug the API does not know would be a 422: once the topics are known, an unknown one is simply "all topics".
  const topic = topics.data?.find((t) => t.slug === topicSlug)
  const topicKnown = !topicSlug || !!topic || topics.isError
  const topicParam = topic ? topic.slug : topics.isError && topicSlug ? topicSlug : undefined
  const board = useLeaderboard(range, topicParam, !topicSlug || topics.isSuccess || topics.isError)
  const ranked = board.data ?? []

  const set = (key: string, value: string, fallback: string) => {
    const next = new URLSearchParams(params)
    if (!value || value === fallback) next.delete(key)
    else next.set(key, value)
    setParams(next, { replace: true })
  }

  return (
    <div className="mx-auto max-w-3xl">
      <header className="mb-6 text-center">
        <motion.div initial={{ scale: 0, rotate: -30 }} animate={{ scale: 1, rotate: 0 }} transition={{ type: 'spring', stiffness: 300, damping: 15 }} className="mx-auto mb-3 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-300 to-orange-500 text-white shadow-glow">
          <Trophy className="size-7" />
        </motion.div>
        <h1 className="text-3xl font-bold sm:text-4xl">
          Leader<span className="text-gradient">boards</span>
        </h1>
        <p className="mt-1 text-sm text-ink-500">
          {subtitle[range]}
          {topic && <> in <b className="text-ink-700 dark:text-ink-200">{topic.emoji} {topic.name}</b></>}
        </p>
        <div className="mt-5 flex justify-center">
          <Tabs items={tabs} value={range} onChange={(v) => set('range', v, 'week')} />
        </div>
      </header>

      <TopicFilter value={topicKnown ? topicSlug : ''} onChange={(s) => set('topic', s, '')} className="mb-8 sm:justify-center" />

      {board.isPending ? (
        <div className="space-y-3" aria-busy="true" aria-label="Loading">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-24 w-full rounded-2xl" />
          ))}
        </div>
      ) : board.isError ? (
        <ErrorState error={board.error} onRetry={() => void board.refetch()} title="Could not load the leaderboard" />
      ) : ranked.length ? (
        <div key={`${range}-${topicSlug}`}>
          <div className="surface mb-6 px-3 pt-10 pb-0 sm:px-8">
            <Podium products={ranked.slice(0, 3)} />
          </div>
          {ranked.length > 3 && <RankedList id={`board-${range}-${topicSlug}`} products={ranked.slice(3)} startRank={4} />}
        </div>
      ) : (
        <EmptyState icon={<Trophy className="size-6" />} title="No launches here yet" body="Nothing launched in this topic during this period. Try a wider range." />
      )}
    </div>
  )
}
