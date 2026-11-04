import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { isApiError } from '../errors'
import { downloadExport, getExport, listExports, startExport } from '../endpoints/founder'
import { keys } from '../keys'
import type { ExportDto } from '../types'

export const DEFAULT_POLL_SECONDS = 5

export interface ExportSnapshot {
  export: ExportDto | null
  /** Seconds the server asked for before the next poll (`Retry-After`). */
  retryAfter?: number
}

export const isInFlight = (e: ExportDto | null | undefined) => e?.status === 'queued' || e?.status === 'processing'

/**
 * The newest export of a product. On mount it reads the latest list (so a build started earlier, or in another tab, is picked up);
 * while one is queued or processing it polls that one export at the pace `Retry-After` asks for, and stops at ready, failed or expired.
 */
export function useExport(productId: string | undefined) {
  const qc = useQueryClient()
  const key = keys.exports(productId ?? '')

  const query = useQuery({
    queryKey: key,
    enabled: !!productId,
    staleTime: 0,
    queryFn: async ({ signal }): Promise<ExportSnapshot> => {
      const prev = qc.getQueryData<ExportSnapshot>(key)?.export
      if (prev && isInFlight(prev)) return getExport(productId ?? '', prev.id, signal)
      const list = await listExports(productId ?? '', signal)
      return { export: list[0] ?? null }
    },
    refetchInterval: (q) => {
      // A 4xx (the export was swept, the product is gone) is final: stop, and let the card offer Retry.
      const err = q.state.error
      if (isApiError(err) && err.status >= 400 && err.status < 500) return false
      const snap = q.state.data
      return isInFlight(snap?.export) ? (snap?.retryAfter ?? DEFAULT_POLL_SECONDS) * 1000 : false
    },
  })

  /** 202 (a new export) and 200 (the one already running) are the same to the page. */
  const start = useMutation({
    mutationFn: () => startExport(productId ?? ''),
    onSuccess: (exp) => qc.setQueryData<ExportSnapshot>(key, { export: exp, retryAfter: DEFAULT_POLL_SECONDS }),
  })

  const download = useMutation({ mutationFn: (exportId: string) => downloadExport(productId ?? '', exportId) })

  /** Retry after an error: forget the old export and read the latest list again. */
  const retry = () => {
    qc.setQueryData<ExportSnapshot>(key, { export: null })
    return query.refetch()
  }

  return { retry, query, current: query.data?.export ?? null, start, download, markExpired: () => qc.setQueryData<ExportSnapshot>(key, (s) => (s?.export ? { export: { ...s.export, status: 'expired' } } : s)) }
}
