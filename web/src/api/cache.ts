import type { QueryClient } from '@tanstack/react-query'
import type { ProductDto } from './types'

/** Every cache that holds product cards. The vote and the live frames patch these in place. */
const CARD_KEYS = new Set(['today', 'day', 'leaderboard', 'search', 'feed', 'product', 'user-products'])

const isCard = (v: unknown, id: string): v is ProductDto => typeof v === 'object' && v !== null && (v as { id?: unknown }).id === id

/** Walks an API envelope (`{data: card[]}`, `{data: card}` or an infinite query's `{pages}`) and patches the card with `id`. */
function patchValue<T>(value: T, id: string, patch: (card: ProductDto) => ProductDto): T {
  if (typeof value !== 'object' || value === null) return value
  const v = value as Record<string, unknown>
  if (Array.isArray(v.pages)) {
    const pages = v.pages.map((p) => patchValue(p, id, patch))
    return pages.some((p, i) => p !== (v.pages as unknown[])[i]) ? ({ ...v, pages } as T) : value
  }
  if (Array.isArray(v.data)) {
    let changed = false
    const data = v.data.map((c) => {
      if (!isCard(c, id)) return c
      changed = true
      return patch(c)
    })
    return changed ? ({ ...v, data } as T) : value
  }
  if (isCard(v.data, id)) return { ...v, data: patch(v.data) } as T
  return value
}

export function patchCardsInCaches(qc: QueryClient, productId: string, patch: (card: ProductDto) => ProductDto) {
  qc.setQueriesData({ predicate: (q) => CARD_KEYS.has(String(q.queryKey[0])) }, (old: unknown) => patchValue(old, productId, patch))
}

export const isCardQuery = (key: readonly unknown[]) => CARD_KEYS.has(String(key[0]))
