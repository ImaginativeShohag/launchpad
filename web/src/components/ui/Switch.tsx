import { motion } from 'motion/react'
import { cn } from './cn'

export function Switch({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={() => onChange(!checked)}
      className={cn(
        'relative inline-flex h-7 w-12 shrink-0 cursor-pointer items-center rounded-full p-1 transition-colors',
        checked ? 'justify-end bg-brand-500' : 'justify-start bg-ink-200 dark:bg-ink-700',
      )}
    >
      <motion.span layout transition={{ type: 'spring', stiffness: 700, damping: 35 }} className="size-5 rounded-full bg-white shadow-md" />
    </button>
  )
}
