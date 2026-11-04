import { motion } from 'motion/react'
import { useId } from 'react'
import { useTopics } from '@/api/hooks/useTopics'
import { cn } from '@/components/ui'

/** Horizontally scrollable topic chips: "All" + every topic. Value is a topic slug or ''. */
export function TopicFilter({ value, onChange, className }: { value: string; onChange: (slug: string) => void; className?: string }) {
  const id = useId()
  const { data: topics = [] } = useTopics()
  const items = [{ slug: '', name: 'All', emoji: '🌐' }, ...topics]
  return (
    <div className={cn('no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0', className)}>
      {items.map((t) => {
        const active = t.slug === value
        return (
          <motion.button
            key={t.slug || 'all'}
            whileTap={{ scale: 0.94 }}
            whileHover={{ y: -1 }}
            onClick={() => onChange(t.slug)}
            aria-pressed={active}
            className={cn(
              'relative inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors',
              active
                ? 'border-transparent text-white'
                : 'border-ink-200 bg-white text-ink-600 hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-white/5 dark:text-ink-300',
            )}
          >
            {active && (
              <motion.span
                layoutId={`topic-pill-${id}`}
                transition={{ type: 'spring', stiffness: 500, damping: 36 }}
                className="absolute inset-0 rounded-full bg-gradient-to-r from-brand-500 to-pink-500 shadow-glow"
              />
            )}
            <span className="relative">{t.emoji}</span>
            <span className="relative">{t.name}</span>
          </motion.button>
        )
      })}
    </div>
  )
}
