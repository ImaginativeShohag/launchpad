import { AnimatePresence, motion } from 'motion/react'
import { useRef } from 'react'
import { cn } from './cn'

/** Rolls each changed digit up or down, like an odometer. */
export function AnimatedNumber({ value, className }: { value: number; className?: string }) {
  const prev = useRef(value)
  const dir = value >= prev.current ? 1 : -1
  prev.current = value
  const digits = value.toLocaleString().split('')
  return (
    <span className={cn('inline-flex overflow-hidden tabular-nums', className)} aria-label={String(value)}>
      {digits.map((d, i) => (
        <span key={digits.length - i} className="relative inline-block">
          <AnimatePresence mode="popLayout" initial={false}>
            <motion.span
              key={d}
              initial={{ y: dir * 14, opacity: 0 }}
              animate={{ y: 0, opacity: 1 }}
              exit={{ y: dir * -14, opacity: 0 }}
              transition={{ type: 'spring', stiffness: 500, damping: 32 }}
              className="inline-block"
            >
              {d}
            </motion.span>
          </AnimatePresence>
        </span>
      ))}
    </span>
  )
}
