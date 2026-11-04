import { CircleCheck, TriangleAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { AuthCard, AuthIcon } from './AuthCard'

/** Shared frame for pages that act on an emailed link: working, done, or failed. */
export function LinkResult({ phase, title, message, children }: { phase: 'pending' | 'done' | 'failed'; title: string; message: ReactNode; children?: ReactNode }) {
  return (
    <AuthCard
      icon={phase === 'pending' ? undefined : <AuthIcon tone={phase === 'done' ? 'green' : 'brand'}>{phase === 'done' ? <CircleCheck /> : <TriangleAlert />}</AuthIcon>}
      title={title}
      subtitle={message}
    >
      {phase === 'pending' ? <div className="h-2 animate-pulse rounded-full bg-ink-100 dark:bg-white/10" /> : children}
    </AuthCard>
  )
}
