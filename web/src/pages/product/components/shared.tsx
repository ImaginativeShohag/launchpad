import { motion } from 'motion/react'
import type { ReactNode } from 'react'

/** Full-page empty/blocked state (not found, removed, suspended…). */
export function PageState({ icon, title, body, action }: { icon: ReactNode; title: string; body: string; action?: ReactNode }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      className="surface relative mx-auto mt-6 max-w-lg overflow-hidden px-6 py-16 text-center"
    >
      <div aria-hidden className="pointer-events-none absolute -top-24 left-1/2 size-64 -translate-x-1/2 rounded-full bg-brand-500/10 blur-3xl" />
      <motion.div
        initial={{ scale: 0.6, rotate: -12 }}
        animate={{ scale: 1, rotate: 0 }}
        transition={{ type: 'spring', stiffness: 300, damping: 14 }}
        className="relative mx-auto mb-5 flex size-16 items-center justify-center rounded-3xl bg-ink-100 text-ink-500 dark:bg-white/5 dark:text-ink-300 [&>svg]:size-7"
      >
        {icon}
      </motion.div>
      <h1 className="relative text-2xl font-bold">{title}</h1>
      <p className="relative mx-auto mt-2 max-w-sm text-ink-500">{body}</p>
      {action && <div className="relative mt-6 flex justify-center gap-2">{action}</div>}
    </motion.div>
  )
}
