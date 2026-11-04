import { motion } from 'motion/react'
import { Check, X } from 'lucide-react'
import type { ProductStatus } from '@/data/types'
import { cn } from '@/components/ui'

const STAGES = ['Draft', 'Review', 'Approved', 'Scheduled', 'Live'] as const

/** Index of the current stage, plus whether that stage is blocked (needs changes / rejected). */
function stageOf(status: ProductStatus): { idx: number; blocked?: 'warn' | 'stop' } {
  switch (status) {
    case 'draft':
      return { idx: 0 }
    case 'pending':
      return { idx: 1 }
    case 'changes_requested':
      return { idx: 1, blocked: 'warn' }
    case 'rejected':
      return { idx: 1, blocked: 'stop' }
    case 'approved':
      return { idx: 2 }
    case 'scheduled':
      return { idx: 3 }
    case 'live':
      return { idx: 4 }
    case 'taken_down':
      return { idx: 4, blocked: 'stop' }
  }
}

/** Draft → Review → Approved → Scheduled → Live, with the current step highlighted. */
export function StatusPipeline({ status, className }: { status: ProductStatus; className?: string }) {
  const { idx, blocked } = stageOf(status)
  return (
    <ol className={cn('flex items-center', className)} aria-label="Status pipeline">
      {STAGES.map((label, i) => {
        const done = i < idx || (i === idx && status === 'live')
        const active = i === idx
        return (
          <li key={label} className={cn('flex items-center', i < STAGES.length - 1 && 'flex-1')}>
            <div className="flex flex-col items-center gap-1">
              <motion.span
                initial={{ scale: 0.6, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                transition={{ delay: i * 0.06, type: 'spring', stiffness: 500, damping: 26 }}
                className={cn(
                  'relative flex size-5 items-center justify-center rounded-full text-[10px] font-bold',
                  done && !blocked && 'bg-emerald-500 text-white',
                  active && !done && !blocked && 'bg-brand-500 text-white',
                  active && blocked === 'warn' && 'bg-amber-500 text-white',
                  active && blocked === 'stop' && 'bg-red-500 text-white',
                  !done && !active && 'bg-ink-100 text-ink-400 dark:bg-white/8',
                  done && blocked && i < idx && 'bg-emerald-500 text-white',
                )}
              >
                {active && !done && !blocked && (
                  <motion.span className="absolute inset-0 rounded-full bg-brand-500" animate={{ scale: [1, 1.8], opacity: [0.5, 0] }} transition={{ repeat: Infinity, duration: 1.6 }} />
                )}
                {active && blocked === 'stop' ? <X className="size-3" strokeWidth={3} /> : done ? <Check className="size-3" strokeWidth={3} /> : active ? '•' : ''}
              </motion.span>
              <span
                className={cn(
                  'text-[10px] font-semibold whitespace-nowrap sm:text-[11px]',
                  active ? (blocked === 'warn' ? 'text-amber-600' : blocked === 'stop' ? 'text-red-600' : 'text-ink-900 dark:text-white') : 'text-ink-400',
                )}
              >
                {active && blocked === 'warn' ? 'Changes' : active && blocked === 'stop' ? (status === 'rejected' ? 'Rejected' : 'Removed') : label}
              </span>
            </div>
            {i < STAGES.length - 1 && (
              <div className="mx-1 mb-4 h-0.5 flex-1 overflow-hidden rounded-full bg-ink-100 dark:bg-white/8">
                <motion.div
                  className="h-full bg-emerald-500"
                  initial={{ width: 0 }}
                  animate={{ width: i < idx ? '100%' : '0%' }}
                  transition={{ delay: 0.1 + i * 0.08, duration: 0.4 }}
                />
              </div>
            )}
          </li>
        )
      })}
    </ol>
  )
}
