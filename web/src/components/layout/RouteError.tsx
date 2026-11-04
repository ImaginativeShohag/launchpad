import { TriangleAlert } from 'lucide-react'
import { useRouteError } from 'react-router'

/** Route-level error screen. */
export function RouteError() {
  const error = useRouteError()
  const message = error instanceof Error ? error.message : String(error)
  return (
    <div className="flex min-h-dvh items-center justify-center bg-ink-50 p-6 dark:bg-ink-950">
      <div className="surface w-full max-w-md p-8 text-center">
        <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-red-50 text-red-500 dark:bg-red-500/10">
          <TriangleAlert className="size-6" />
        </div>
        <h1 className="text-xl font-bold">Something broke on this screen</h1>
        <p className="mt-2 text-sm text-ink-500">Reload to try again.</p>
        <pre className="mt-4 overflow-x-auto rounded-xl bg-ink-100 p-3 text-left text-xs text-ink-600 dark:bg-white/5 dark:text-ink-300">{message}</pre>
        <div className="mt-6 flex justify-center">
          <button onClick={() => location.reload()} className="h-10 cursor-pointer rounded-xl bg-brand-500 px-4 text-sm font-semibold text-white">
            Reload
          </button>
        </div>
      </div>
    </div>
  )
}
