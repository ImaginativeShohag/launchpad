import { Link } from 'react-router'
import type { Topic } from '@/data/types'
import { cn } from '@/components/ui'

/** A topic pill. */
export function TopicChip({ topic: t, className, asLink = true }: { topic?: Topic; className?: string; asLink?: boolean }) {
  if (!t) return null
  const cls = cn(
    'inline-flex items-center gap-1 rounded-full border border-ink-200 bg-white px-2 py-0.5 text-xs font-medium text-ink-600 transition hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-white/5 dark:text-ink-300',
    className,
  )
  const inner = (
    <>
      <span>{t.emoji}</span>
      {t.name}
    </>
  )
  return asLink ? (
    <Link to={`/topics/${t.slug}`} onClick={(e) => e.stopPropagation()} className={cls}>
      {inner}
    </Link>
  ) : (
    <span className={cls}>{inner}</span>
  )
}
