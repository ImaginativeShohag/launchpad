import { AnimatePresence, motion } from 'motion/react'
import { Check } from 'lucide-react'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { cn } from '@/components/ui'

/** Titled card section used across settings tabs. */
export function SettingsCard({ title, description, children, tone = 'default', className }: { title: ReactNode; description?: ReactNode; children: ReactNode; tone?: 'default' | 'danger'; className?: string }) {
  return (
    <motion.section
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ type: 'spring', stiffness: 380, damping: 32 }}
      className={cn('surface p-5 sm:p-6', tone === 'danger' && 'border-red-200 dark:border-red-500/20', className)}
    >
      <h2 className={cn('text-lg font-bold', tone === 'danger' && 'text-red-600 dark:text-red-400')}>{title}</h2>
      {description && <p className="mt-0.5 text-sm text-ink-500 dark:text-ink-400">{description}</p>}
      <div className="mt-5">{children}</div>
    </motion.section>
  )
}

/** Returns a `flash()` trigger plus a counter key to drive `<SavedPill>`. */
export function useSavedFlash() {
  const [key, setKey] = useState(0)
  const flash = useCallback(() => setKey((k) => k + 1), [])
  return { savedKey: key, flash }
}

/** Small floating "Saved" confirmation that appears for ~1.6s whenever `trigger` changes. */
export function SavedPill({ trigger }: { trigger: number }) {
  const [visible, setVisible] = useState(false)
  useEffect(() => {
    if (trigger === 0) return
    setVisible(true)
    const t = setTimeout(() => setVisible(false), 1600)
    return () => clearTimeout(t)
  }, [trigger])
  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex justify-center md:bottom-8" aria-live="polite">
      <AnimatePresence>
        {visible && (
          <motion.div
            key={trigger}
            initial={{ opacity: 0, y: 16, scale: 0.9 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 8, scale: 0.95 }}
            transition={{ type: 'spring', stiffness: 520, damping: 30 }}
            className="flex items-center gap-1.5 rounded-full bg-ink-900 py-2 pr-4 pl-2.5 text-sm font-semibold text-white shadow-lift dark:bg-white dark:text-ink-900"
          >
            <motion.span
              initial={{ scale: 0, rotate: -90 }}
              animate={{ scale: 1, rotate: 0 }}
              transition={{ type: 'spring', stiffness: 600, damping: 15, delay: 0.05 }}
              className="flex size-5 items-center justify-center rounded-full bg-emerald-500 text-white"
            >
              <Check className="size-3" strokeWidth={3.5} />
            </motion.span>
            Saved
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}
