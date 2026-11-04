import { motion } from 'motion/react'
import { MessageCircle } from 'lucide-react'
import { Link } from 'react-router'
import type { ProductCard } from '@/data/types'
import { cn } from '@/components/ui'
import { cardTopics } from './cardTopics'
import { ProductLogo } from './ProductLogo'
import { TopicChip } from './TopicChip'
import { VoteButton } from './VoteButton'

const medal = ['from-amber-300 to-amber-500', 'from-slate-200 to-slate-400', 'from-orange-300 to-orange-600']

export interface ProductCardProps {
  product: ProductCard
  rank?: number
  /** False for lists whose API gives no vote numbers (a member's products). */
  showVote?: boolean
}

/** Card used in every product list. `rank` shows a position badge. */
export function ProductRow({ product, rank, showVote = true }: ProductCardProps) {
  const commentCount = product.commentsCount

  return (
    <motion.div layout="position" transition={{ type: 'spring', stiffness: 350, damping: 32 }}>
      <div
        className="group surface relative flex items-center gap-3 p-3 transition-all hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lift sm:gap-4 sm:p-4 dark:hover:border-brand-500/30"
      >
        <Link to={`/p/${product.slug}`} aria-label={product.name} className="absolute inset-0 z-0 rounded-2xl" />
        {rank !== undefined && (
          <span
            className={cn(
              'hidden w-7 shrink-0 text-center font-display text-lg font-bold sm:block',
              rank <= 3 ? 'bg-gradient-to-b bg-clip-text text-transparent ' + medal[rank - 1] : 'text-ink-300 dark:text-ink-600',
            )}
          >
            {rank}
          </span>
        )}
        <div className="transition-transform duration-300 ease-[cubic-bezier(.34,1.56,.64,1)] group-hover:-rotate-6 group-hover:scale-105">
          <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="truncate font-sans text-[15px] font-bold tracking-normal group-hover:text-brand-600 dark:group-hover:text-brand-400">{product.name}</h3>
          </div>
          <p className="mt-0.5 line-clamp-1 text-sm text-ink-500 dark:text-ink-400">{product.tagline}</p>
          <div className="pointer-events-none relative z-10 mt-2 flex flex-wrap items-center gap-1.5 [&_a]:pointer-events-auto">
            {commentCount !== undefined && (
              <>
                <span className="inline-flex items-center gap-1 text-xs text-ink-400">
                  <MessageCircle className="size-3.5" />
                  {commentCount}
                </span>
                <span className="text-ink-300">·</span>
              </>
            )}
            {cardTopics(product).map((t) => (
              <TopicChip key={t.id} topic={t} />
            ))}
          </div>
        </div>
        {showVote && (
          <div className="relative z-10">
            <VoteButton product={product} />
          </div>
        )}
      </div>
    </motion.div>
  )
}
