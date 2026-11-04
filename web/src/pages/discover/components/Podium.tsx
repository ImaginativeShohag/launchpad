import { motion } from 'motion/react'
import { Crown } from 'lucide-react'
import { Link } from 'react-router'
import type { ProductCard } from '@/data/types'
import { ProductLogo } from '@/components/product/ProductLogo'
import { AnimatedNumber, cn } from '@/components/ui'

const places = [
  { place: 2, height: 'h-20 sm:h-24', medal: 'from-slate-200 to-slate-400', ring: 'ring-slate-300/60', delay: 0.15 },
  { place: 1, height: 'h-28 sm:h-36', medal: 'from-amber-300 to-amber-500', ring: 'ring-amber-300/70', delay: 0 },
  { place: 3, height: 'h-14 sm:h-16', medal: 'from-orange-300 to-orange-600', ring: 'ring-orange-300/60', delay: 0.3 },
]

/** Top three as a podium (2 · 1 · 3) with springy medals and rising pedestals. */
export function Podium({ products }: { products: ProductCard[] }) {
  return (
    <div className="grid grid-cols-3 items-end gap-2 sm:gap-4">
      {places.map(({ place, height, medal, ring, delay }) => {
        const p = products[place - 1]
        if (!p) return <div key={place} />
        return (
          <motion.div
            key={p.id}
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ type: 'spring', stiffness: 260, damping: 24, delay }}
            className="flex min-w-0 flex-col items-center"
          >
            <Link to={`/p/${p.slug}`} className="group flex w-full min-w-0 flex-col items-center px-1 text-center">
              <div className="relative">
                {place === 1 && (
                  <motion.span
                    initial={{ y: -10, opacity: 0, rotate: -20 }}
                    animate={{ y: 0, opacity: 1, rotate: 0 }}
                    transition={{ type: 'spring', stiffness: 300, damping: 14, delay: 0.5 }}
                    className="absolute -top-6 left-1/2 -translate-x-1/2 text-amber-400 drop-shadow"
                  >
                    <Crown className="size-6 fill-amber-300" />
                  </motion.span>
                )}
                <motion.div whileHover={{ rotate: -6, scale: 1.08 }} transition={{ type: 'spring', stiffness: 400, damping: 14 }}>
                  <ProductLogo name={p.name} src={p.logoUrl} hue={p.hue} size={place === 1 ? 'lg' : 'md'} className={cn('ring-4', ring)} />
                </motion.div>
                <motion.span
                  initial={{ scale: 0, rotate: -180 }}
                  animate={{ scale: 1, rotate: 0 }}
                  transition={{ type: 'spring', stiffness: 400, damping: 15, delay: delay + 0.35 }}
                  className={cn('absolute -right-2 -bottom-2 flex size-7 items-center justify-center rounded-full bg-gradient-to-br font-display text-sm font-bold text-white shadow-lift ring-2 ring-white dark:ring-ink-900', medal)}
                >
                  {place}
                </motion.span>
              </div>
              <p className="mt-3 w-full truncate text-sm font-bold group-hover:text-brand-600 sm:text-base">{p.name}</p>
              <p className="hidden w-full truncate text-xs text-ink-500 sm:block">{p.tagline}</p>
              <p className="mt-1 text-xs font-semibold text-ink-500">
                <AnimatedNumber value={p.votes} /> votes
              </p>
            </Link>
            <motion.div
              initial={{ scaleY: 0 }}
              animate={{ scaleY: 1 }}
              transition={{ type: 'spring', stiffness: 160, damping: 20, delay: delay + 0.1 }}
              style={{ originY: 1 }}
              className={cn(
                'mt-3 flex w-full items-start justify-center rounded-t-2xl bg-gradient-to-b pt-2 font-display text-2xl font-bold text-white/90',
                height,
                place === 1 ? 'from-brand-400 to-pink-500 shadow-glow' : 'from-ink-200 to-ink-100 text-ink-400 dark:from-white/10 dark:to-white/5 dark:text-ink-500',
              )}
            >
              {place}
            </motion.div>
          </motion.div>
        )
      })}
    </div>
  )
}
