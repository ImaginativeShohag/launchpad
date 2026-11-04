import { motion } from 'motion/react'
import { Check } from 'lucide-react'
import { cn } from '@/components/ui'

export interface StepDef {
  label: string
  hint: string
}

/** Animated wizard stepper with a gradient progress rail. Steps up to `maxReached` are clickable. */
export function Stepper({ steps, current, maxReached, onStep }: { steps: StepDef[]; current: number; maxReached: number; onStep: (i: number) => void }) {
  const pct = (current / (steps.length - 1)) * 100
  return (
    <div className="relative">
      <div className="absolute top-4 right-4 left-4 h-1 rounded-full bg-ink-100 dark:bg-white/8 sm:right-6 sm:left-6">
        <motion.div
          className="h-full rounded-full bg-gradient-to-r from-brand-500 via-pink-500 to-violet-500"
          initial={false}
          animate={{ width: `${pct}%` }}
          transition={{ type: 'spring', stiffness: 160, damping: 24 }}
        />
      </div>
      <ol aria-label="Steps" className="relative flex justify-between">
        {steps.map((s, i) => {
          const done = i < current
          const active = i === current
          const reachable = i <= maxReached
          return (
            <li key={s.label} className="flex flex-col items-center">
              <button
                type="button"
                disabled={!reachable}
                onClick={() => onStep(i)}
                aria-current={active ? 'step' : undefined}
                className={cn('group flex flex-col items-center gap-1.5', reachable ? 'cursor-pointer' : 'cursor-not-allowed')}
              >
                <motion.span
                  initial={false}
                  animate={{ scale: active ? 1.12 : 1 }}
                  whileHover={reachable ? { scale: active ? 1.18 : 1.08 } : undefined}
                  transition={{ type: 'spring', stiffness: 500, damping: 26 }}
                  className={cn(
                    'relative flex size-9 items-center justify-center rounded-full border-2 text-sm font-bold transition-colors',
                    done && 'border-transparent bg-brand-500 text-white',
                    active && 'border-brand-500 bg-white text-brand-600 shadow-glow dark:bg-ink-900',
                    !done && !active && 'border-ink-200 bg-white text-ink-400 dark:border-white/10 dark:bg-ink-900',
                  )}
                >
                  {active && (
                    <motion.span
                      className="absolute inset-0 rounded-full border-2 border-brand-400"
                      animate={{ scale: [1, 1.5], opacity: [0.6, 0] }}
                      transition={{ repeat: Infinity, duration: 1.8, ease: 'easeOut' }}
                    />
                  )}
                  {done ? (
                    <motion.span initial={{ scale: 0, rotate: -45 }} animate={{ scale: 1, rotate: 0 }} transition={{ type: 'spring', stiffness: 600, damping: 20 }}>
                      <Check className="size-4" strokeWidth={3} />
                    </motion.span>
                  ) : (
                    i + 1
                  )}
                </motion.span>
                <span className={cn('text-xs font-semibold transition-colors', active ? 'text-ink-900 dark:text-white' : 'text-ink-400')}>{s.label}</span>
              </button>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
