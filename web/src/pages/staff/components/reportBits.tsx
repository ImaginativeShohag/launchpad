import { MessageSquare, Package } from 'lucide-react'
import type { ReportTypeDto } from '@/api/types'
import { cn } from '@/components/ui'

export function TypeIcon({ type }: { type: ReportTypeDto }) {
  return (
    <span className={cn('flex size-6 items-center justify-center rounded-lg', type === 'comment' ? 'bg-sky-100 text-sky-600 dark:bg-sky-500/15' : 'bg-violet-100 text-violet-600 dark:bg-violet-500/15')}>
      {type === 'comment' ? <MessageSquare className="size-3.5" /> : <Package className="size-3.5" />}
    </span>
  )
}

