import { AnimatePresence, motion } from 'motion/react'
import { Check } from 'lucide-react'
import { cn } from '@/components/ui'

/** Mirrors the API (Password::min(12)); the server also rejects known-breached passwords. */
export const MIN_PASSWORD = 12

const rules = [
  { label: `${MIN_PASSWORD}+ characters`, test: (p: string) => p.length >= MIN_PASSWORD },
  { label: 'Upper & lower case', test: (p: string) => /[a-z]/.test(p) && /[A-Z]/.test(p) },
  { label: 'A number', test: (p: string) => /\d/.test(p) },
  { label: 'A symbol', test: (p: string) => /[^A-Za-z0-9]/.test(p) },
]

const levels = [
  { label: 'Too weak', bar: 'bg-red-500', text: 'text-red-500' },
  { label: 'Weak', bar: 'bg-orange-500', text: 'text-orange-500' },
  { label: 'Okay', bar: 'bg-amber-400', text: 'text-amber-500' },
  { label: 'Strong', bar: 'bg-lime-500', text: 'text-lime-600' },
  { label: 'Excellent', bar: 'bg-emerald-500', text: 'text-emerald-500' },
]

/** 0–4. Length gates everything: short passwords never score above 1. */
export function scorePassword(p: string): number {
  if (!p) return 0
  const passed = rules.filter((r) => r.test(p)).length
  if (p.length < MIN_PASSWORD) return Math.min(1, passed)
  return Math.min(4, passed + (p.length >= 14 ? 1 : 0))
}

export function isPasswordAcceptable(p: string): boolean {
  return p.length >= MIN_PASSWORD && scorePassword(p) >= 2
}

/** Four-segment meter with a live checklist. */
export function PasswordStrength({ password }: { password: string }) {
  const score = scorePassword(password)
  const level = levels[score]
  return (
    <AnimatePresence initial={false}>
      {password && (
        <motion.div
          initial={{ opacity: 0, height: 0 }}
          animate={{ opacity: 1, height: 'auto' }}
          exit={{ opacity: 0, height: 0 }}
          transition={{ type: 'spring', stiffness: 400, damping: 34 }}
          className="overflow-hidden"
          aria-live="polite"
        >
          <div className="pt-2.5">
            <div className="flex gap-1.5">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="h-1.5 flex-1 overflow-hidden rounded-full bg-ink-100 dark:bg-white/10">
                  <motion.div
                    className={cn('h-full rounded-full', level.bar)}
                    initial={false}
                    animate={{ width: score >= i ? '100%' : '0%' }}
                    transition={{ type: 'spring', stiffness: 300, damping: 30, delay: (i - 1) * 0.04 }}
                  />
                </div>
              ))}
            </div>
            <div className="mt-2 flex items-center justify-between">
              <AnimatePresence mode="wait" initial={false}>
                <motion.span
                  key={level.label}
                  initial={{ y: 6, opacity: 0 }}
                  animate={{ y: 0, opacity: 1 }}
                  exit={{ y: -6, opacity: 0 }}
                  transition={{ duration: 0.15 }}
                  className={cn('text-xs font-bold', level.text)}
                >
                  {level.label}
                </motion.span>
              </AnimatePresence>
            </div>
            <ul className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
              {rules.map((r) => {
                const ok = r.test(password)
                return (
                  <li key={r.label} className={cn('flex items-center gap-1.5 text-xs transition-colors', ok ? 'text-ink-700 dark:text-ink-200' : 'text-ink-400')}>
                    <motion.span
                      animate={{ scale: ok ? [1, 1.35, 1] : 1, backgroundColor: ok ? 'rgb(16 185 129)' : 'rgba(0,0,0,0)' }}
                      transition={{ duration: 0.3 }}
                      className={cn('flex size-3.5 items-center justify-center rounded-full border', ok ? 'border-emerald-500 text-white' : 'border-ink-300 dark:border-ink-600')}
                    >
                      {ok && <Check className="size-2.5" strokeWidth={4} />}
                    </motion.span>
                    {r.label}
                  </li>
                )
              })}
            </ul>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
