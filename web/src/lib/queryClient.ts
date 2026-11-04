import { QueryClient } from '@tanstack/react-query'
import { isApiError } from '@/api/errors'

/** Reads retry once, never on a 4xx; mutations never retry. */
export function createQueryClient(overrides: { retry?: boolean } = {}): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: true,
        retry: overrides.retry === false ? false : (count, error) => count < 1 && !(isApiError(error) && error.status >= 400 && error.status < 500),
      },
      mutations: { retry: 0 },
    },
  })
}

export const queryClient = createQueryClient()
