import { AnimatePresence, LayoutGroup, motion } from 'motion/react'
import { ArrowDown, ArrowUp } from 'lucide-react'
import type { ProductCard } from '@/data/types'
import { ProductRow } from '@/components/product/ProductRow'
import { cn } from '@/components/ui'

const spring = { type: 'spring', stiffness: 350, damping: 32 } as const

/**
 * Product list with staggered entrance and smooth re-ordering. `moves` (product id -> places gained, from `useRankMoves`)
 * flashes a small up/down pill on the rows that changed position.
 */
export function RankedList({
  products,
  ranked = true,
  startRank = 1,
  moves,
  id,
}: {
  products: ProductCard[]
  ranked?: boolean
  startRank?: number
  moves?: Record<string, number>
  id?: string
}) {
  return (
    <LayoutGroup id={id}>
      <ol className="space-y-3">
        {products.map((p, i) => {
          const move = moves?.[p.id]
          return (
            <motion.li
              key={p.id}
              layout="position"
              initial={{ opacity: 0, y: 14 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: Math.min(i, 12) * 0.045, duration: 0.35, ease: 'easeOut', layout: spring }}
              className="relative"
            >
              <ProductRow product={p} rank={ranked ? startRank + i : undefined} />
              <AnimatePresence>
                {move && (
                  <motion.span
                    initial={{ opacity: 0, scale: 0.6, y: 4 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={{ opacity: 0, scale: 0.6 }}
                    transition={{ type: 'spring', stiffness: 600, damping: 26 }}
                    className={cn(
                      'pointer-events-none absolute -top-2 left-3 z-20 inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-bold text-white shadow-soft',
                      move > 0 ? 'bg-emerald-500' : 'bg-ink-400',
                    )}
                  >
                    {move > 0 ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />}
                    {Math.abs(move)}
                  </motion.span>
                )}
              </AnimatePresence>
            </motion.li>
          )
        })}
      </ol>
    </LayoutGroup>
  )
}
