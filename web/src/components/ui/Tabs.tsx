import { motion } from 'motion/react'
import { useId, type ReactNode } from 'react'
import { cn } from './cn'

export interface TabItem<T extends string> {
  value: T
  label: ReactNode
  count?: number
}

/** Segmented control with a sliding active pill. */
export function Tabs<T extends string>({ items, value, onChange, className }: { items: TabItem<T>[]; value: T; onChange: (v: T) => void; className?: string }) {
  const id = useId()
  return (
    <div role="tablist" className={cn('no-scrollbar inline-flex max-w-full gap-1 overflow-x-auto rounded-xl bg-ink-100 p-1 dark:bg-white/5', className)}>
      {items.map((it) => {
        const active = it.value === value
        return (
          <button
            key={it.value}
            role="tab"
            aria-selected={active}
            onClick={() => onChange(it.value)}
            className={cn(
              'relative flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors',
              active ? 'text-ink-900 dark:text-white' : 'text-ink-500 hover:text-ink-800 dark:text-ink-400 dark:hover:text-ink-200',
            )}
          >
            {active && (
              <motion.span layoutId={`tab-${id}`} transition={{ type: 'spring', stiffness: 500, damping: 35 }} className="absolute inset-0 rounded-lg bg-white shadow-soft dark:bg-ink-800" />
            )}
            <span className="relative">{it.label}</span>
            {it.count !== undefined && (
              <span className={cn('relative rounded-full px-1.5 text-[11px] tabular-nums', active ? 'bg-brand-500 text-white' : 'bg-ink-200 text-ink-600 dark:bg-white/10 dark:text-ink-300')}>
                {it.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}
