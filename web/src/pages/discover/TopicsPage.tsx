import { motion } from 'motion/react'
import { Compass } from 'lucide-react'
import { Link } from 'react-router'
import { useTopics } from '@/api/hooks/useTopics'
import { TopicFollowButton } from '@/components/product/TopicFollowButton'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui'

const hues = [270, 210, 45, 320, 140, 120, 10, 190, 280, 170]

export default function TopicsPage() {
  const { data: topics, isPending, isError, error, refetch } = useTopics()

  return (
    <div>
      <header className="mb-8 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="flex items-center gap-1.5 text-xs font-semibold tracking-wider text-ink-500 uppercase">
            <Compass className="size-3.5" /> Explore
          </p>
          <h1 className="mt-1 text-3xl font-bold sm:text-4xl">
            Browse <span className="text-gradient">topics</span>
          </h1>
          <p className="mt-1 text-sm text-ink-500">Pick a topic to see its launches, most voted or newest first.</p>
        </div>
      </header>

      {isPending ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true" aria-label="Loading">
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-36 w-full rounded-2xl" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState error={error} onRetry={() => void refetch()} title="Could not load the topics" />
      ) : topics.length === 0 ? (
        <EmptyState icon={<Compass className="size-6" />} title="No topics yet" body="Topics appear here once they are created." />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {topics.map((topic, i) => {
            const hue = hues[i % hues.length]
            return (
              <motion.div
                key={topic.id}
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: i * 0.04, type: 'spring', stiffness: 260, damping: 24 }}
                whileHover={{ y: -4 }}
                className="group surface relative overflow-hidden p-5 transition-shadow hover:shadow-lift"
              >
                <div className="pointer-events-none absolute -top-16 -right-16 size-44 rounded-full opacity-30 blur-2xl transition-opacity duration-500 group-hover:opacity-60" style={{ background: `hsl(${hue} 90% 65%)` }} />
                <Link to={`/topics/${topic.slug}`} aria-label={topic.name} className="absolute inset-0 z-0" />
                <div className="relative flex items-start justify-between gap-3">
                  <motion.span
                    className="flex size-14 items-center justify-center rounded-2xl text-3xl shadow-soft"
                    style={{ background: `linear-gradient(140deg, hsl(${hue} 95% 92%), hsl(${(hue + 40) % 360} 90% 82%))` }}
                    whileHover={{ rotate: [0, -12, 10, -4, 0] }}
                    transition={{ duration: 0.5 }}
                  >
                    {topic.emoji}
                  </motion.span>
                  <TopicFollowButton topic={topic} size="sm" className="relative z-10" />
                </div>
                <h2 className="relative mt-4 text-lg font-bold group-hover:text-brand-600">{topic.name}</h2>
                {topic.description && <p className="relative mt-1 line-clamp-2 text-sm text-ink-500">{topic.description}</p>}
              </motion.div>
            )
          })}
        </div>
      )}
    </div>
  )
}
