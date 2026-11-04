import { Button } from './Button'

/** "Load more" under a keyset list. A failed page keeps the list and offers an inline retry. */
export function LoadMore({ hasNextPage, loading, failed, onClick }: { hasNextPage: boolean; loading: boolean; failed: boolean; onClick: () => void }) {
  if (!hasNextPage && !failed) return null
  return (
    <div className="mt-6 flex flex-col items-center gap-2">
      {failed && <p className="text-sm text-red-600 dark:text-red-400">Could not load more.</p>}
      <Button variant="outline" loading={loading} onClick={onClick}>
        {failed ? 'Retry' : 'Load more'}
      </Button>
    </div>
  )
}
