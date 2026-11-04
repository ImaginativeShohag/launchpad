import { useMemo } from 'react'
import { keepPreviousData, useInfiniteQuery, type QueryKey } from '@tanstack/react-query'
import { api } from './client'
import type { ApiPage } from './types'

type Params = Record<string, string | number | boolean | undefined>

/** One hook for every cursor-paginated endpoint: reads `meta.next_cursor`, sends `cursor`. */
export function useCursorList<TDto, TItem = TDto>(
  key: QueryKey,
  path: string,
  params: Params = {},
  options: { enabled?: boolean; map?: (dto: TDto) => TItem; staleTime?: number; /** Keep at most this many pages: an invalidation then re-reads no more than that. */ maxPages?: number; /** Keep showing the previous params' rows while the next ones load (search-as-you-type). */ keepPrevious?: boolean } = {},
) {
  const query = useInfiniteQuery({
    queryKey: [...key, params],
    queryFn: ({ pageParam, signal }) => api.get<ApiPage<TDto>>(path, { query: { ...params, cursor: pageParam }, signal }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
    enabled: options.enabled,
    staleTime: options.staleTime,
    maxPages: options.maxPages,
    placeholderData: options.keepPrevious ? keepPreviousData : undefined,
  })
  const map = options.map
  const pages = query.data?.pages
  const items = useMemo(() => (pages?.flatMap((p) => p.data) ?? []).map((d) => (map ? map(d) : (d as unknown as TItem))), [pages, map])
  return { ...query, items }
}
