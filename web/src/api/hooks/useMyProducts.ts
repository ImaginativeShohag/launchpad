import { useQuery } from '@tanstack/react-query'
import type { FounderProduct } from '@/data/types'
import { getMyProduct } from '../endpoints/founder'
import { keys } from '../keys'
import { toFounderProduct } from '../mappers'
import { useCursorList } from '../pagination'
import type { FounderProductDto } from '../types'

/** GET /me/products, newest first, optionally one status (the API takes a single one). A page holds up to 100 rows. */
export function useMyProducts(status?: string, enabled = true) {
  return useCursorList<FounderProductDto, FounderProduct>(keys.myProducts(status), '/me/products', { status, per_page: 100 }, { enabled, map: toFounderProduct })
}

/** GET /me/products/{id}: one product with its links, topics, screenshots and review history. A foreign or unknown id is a 404. */
export function useMyProduct(id: string | undefined, enabled = true) {
  return useQuery({ queryKey: keys.myProduct(id ?? ''), queryFn: ({ signal }) => getMyProduct(id ?? '', signal), select: toFounderProduct, enabled: enabled && !!id })
}
