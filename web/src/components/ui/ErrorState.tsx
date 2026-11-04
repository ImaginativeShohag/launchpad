import { RefreshCw, TriangleAlert } from 'lucide-react'
import { useEffect } from 'react'
import { errorMessage, isApiError } from '@/api/errors'
import { useCooldown } from '@/lib/useCooldown'
import { Button } from './Button'
import { EmptyState } from './EmptyState'

/** A failed read: the normalised message and a Retry that waits out a `Retry-After`. */
export function ErrorState({ error, onRetry, title = 'Could not load this' }: { error: unknown; onRetry: () => void; title?: string }) {
  const wait = useCooldown()
  const retryAfter = isApiError(error) ? error.retryAfter : undefined
  const start = wait.start
  useEffect(() => {
    if (retryAfter) start(retryAfter)
  }, [error, retryAfter, start])
  return (
    <EmptyState
      icon={<TriangleAlert className="size-6" />}
      title={title}
      body={errorMessage(error)}
      action={
        <Button variant="outline" disabled={wait.remaining > 0} icon={<RefreshCw className="size-4" />} onClick={onRetry}>
          {wait.remaining > 0 ? `Retry in ${wait.remaining} s` : 'Retry'}
        </Button>
      }
    />
  )
}
