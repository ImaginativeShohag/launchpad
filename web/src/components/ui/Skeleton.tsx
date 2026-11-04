import { cn } from './cn'

export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        'animate-shimmer rounded-lg bg-[linear-gradient(90deg,var(--color-ink-100)_25%,var(--color-ink-200)_50%,var(--color-ink-100)_75%)] bg-[length:200%_100%] dark:bg-[linear-gradient(90deg,var(--color-ink-800)_25%,var(--color-ink-700)_50%,var(--color-ink-800)_75%)]',
        className,
      )}
    />
  )
}
