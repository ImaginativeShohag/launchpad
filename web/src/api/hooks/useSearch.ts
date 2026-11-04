import { useCursorList } from '../pagination'
import { keys } from '../keys'
import { toProductCard } from '../mappers'
import type { ProductDto } from '../types'

export type SearchSort = 'relevance' | 'votes' | 'newest'

export interface SearchInput {
  /** Trimmed text of at least two characters, or undefined for a topic-only search. */
  q?: string
  topic?: string
  sort: SearchSort
}

/**
 * GET /search, keyset-paged. The server needs a query or a topic (422 otherwise) and refuses `relevance` without a query,
 * so the page only enables this for a searchable input and the hook never sends `relevance` without `q`.
 */
export function useSearch(input: SearchInput, enabled: boolean) {
  const sort = input.sort === 'relevance' && !input.q ? 'votes' : input.sort
  const params = { q: input.q, topic: input.topic, sort }
  return useCursorList<ProductDto, ReturnType<typeof toProductCard>>(keys.search({ q: input.q, topic: input.topic, sort }), '/search', params, {
    enabled,
    map: toProductCard,
    keepPrevious: true,
  })
}
