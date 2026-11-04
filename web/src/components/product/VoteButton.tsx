import { AnimatePresence, motion } from 'motion/react'
import { ChevronUp } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useId, useState } from 'react'
import { isApiError } from '@/api/errors'
import { useVote, hasVoteInFlight } from '@/api/hooks/useVote'
import { keys } from '@/api/keys'
import type { TodayDto } from '@/api/types'
import type { ProductCard } from '@/data/types'
import { today } from '@/lib/date'
import { useCooldown } from '@/lib/useCooldown'
import { useCurrentUser } from '@/session/SessionProvider'
import { useAuthGate } from '@/components/layout/AuthGate'
import { AnimatedNumber, cn, useToast } from '@/components/ui'

const burst = Array.from({ length: 8 }, (_, i) => (i / 8) * Math.PI * 2)

/** Upvote with optimistic count, confetti burst, and undo by tapping again. */
export function VoteButton({ product, size = 'md', disabled }: { product: ProductCard; size?: 'md' | 'lg'; disabled?: boolean }) {
  const user = useCurrentUser()
  const voted = product.hasVoted ?? false
  const gate = useAuthGate()
  const vote = useVote()
  const wait = useCooldown()
  const toast = useToast()
  const [pop, setPop] = useState(0)
  const qc = useQueryClient()
  const tipId = useId()
  const own = !!user && product.makerId === user.id
  // The server takes votes only on the launch day (UTC) and stays the judge. Its own date wins over this clock when Today is loaded.
  const serverDay = qc.getQueryData<TodayDto>(keys.today)?.meta.date ?? today()
  const closed = !!product.launchDate && product.launchDate !== serverDay
  const blocked = disabled || closed || wait.remaining > 0
  const tip = own
    ? 'You can’t vote on your own product'
    : closed
      ? 'Voting is only open on the launch day'
      : wait.remaining > 0
        ? `Try again in ${wait.remaining} s`
        : undefined

  const onClick = (e: React.MouseEvent) => {
    e.preventDefault()
    e.stopPropagation()
    // The in-flight check is shared across instances: the hero and its row are two buttons for one product.
    if (blocked || vote.isPending || hasVoteInFlight(product.id)) return
    gate('vote for products you love', () => {
      if (own) {
        toast({ tone: 'info', title: 'This one is yours', body: 'Makers can’t vote on their own product.' })
        return
      }
      if (!voted) setPop((n) => n + 1)
      vote.mutate(
        { productId: product.id, voted: !voted },
        { onError: (err) => isApiError(err) && err.status === 429 && wait.start(err.retryAfter ?? 5) },
      )
    })
  }

  return (
    <motion.button
      onClick={onClick}
      whileTap={{ scale: 0.88 }}
      whileHover={{ y: -2 }}
      aria-pressed={voted}
      aria-disabled={own || blocked || undefined}
      aria-label={`Upvote ${product.name}`}
      aria-describedby={tip ? tipId : undefined}
      title={tip}
      className={cn(
        'relative flex shrink-0 cursor-pointer flex-col items-center justify-center rounded-2xl border font-bold transition-colors',
        size === 'lg' ? 'h-20 w-20 text-lg' : 'h-16 w-14 text-sm',
        voted
          ? 'border-transparent bg-gradient-to-b from-brand-500 to-brand-600 text-white shadow-glow'
          : 'border-ink-200 bg-white text-ink-700 hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-ink-900 dark:text-ink-200',
        (own || blocked) && 'opacity-60',
      )}
    >
      <motion.span key={`a${pop}`} initial={pop ? { y: 6 } : false} animate={{ y: 0 }} transition={{ type: 'spring', stiffness: 600, damping: 15 }}>
        <ChevronUp className={size === 'lg' ? 'size-6' : 'size-5'} strokeWidth={2.75} />
      </motion.span>
      <AnimatedNumber value={product.votes} />
      {tip && (
        <span id={tipId} className="sr-only">
          {tip}
        </span>
      )}
      <AnimatePresence>
        {pop > 0 && voted && (
          <motion.span key={pop} className="pointer-events-none absolute inset-0" initial={{ opacity: 1 }} animate={{ opacity: 0 }} transition={{ duration: 0.7 }}>
            {burst.map((a, i) => (
              <motion.span
                key={i}
                className="absolute top-1/2 left-1/2 size-1.5 rounded-full"
                style={{ background: ['#ff5112', '#ec4899', '#8b5cf6', '#f59e0b'][i % 4] }}
                initial={{ x: 0, y: 0, scale: 1 }}
                animate={{ x: Math.cos(a) * 36, y: Math.sin(a) * 36, scale: 0 }}
                transition={{ duration: 0.6, ease: 'easeOut' }}
              />
            ))}
          </motion.span>
        )}
      </AnimatePresence>
    </motion.button>
  )
}
