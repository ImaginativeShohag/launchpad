import { motion } from 'motion/react'
import { Lock } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge, Switch } from '@/components/ui'

interface Props {
  icon: ReactNode
  title: string
  description: string
  checked: boolean
  onChange?: (v: boolean) => void
  /** Locked rows are always on and cannot be toggled. */
  locked?: boolean
}

/** One email-notification preference with a toggle. */
export function PrefRow({ icon, title, description, checked, onChange, locked }: Props) {
  return (
    <motion.div layout className="flex items-center gap-3.5 py-3.5 first:pt-0 last:pb-0">
      <motion.span
        animate={{ opacity: checked ? 1 : 0.45, scale: checked ? 1 : 0.92 }}
        className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-ink-100 text-ink-600 dark:bg-white/5 dark:text-ink-300 [&>svg]:size-5"
      >
        {icon}
      </motion.span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold">{title}</p>
        <p className="text-xs text-ink-500 dark:text-ink-400">{description}</p>
      </div>
      {locked ? (
        <Badge icon={<Lock className="size-3" />}>Always on</Badge>
      ) : (
        <Switch checked={checked} onChange={(v) => onChange?.(v)} label={title} />
      )}
    </motion.div>
  )
}
