import { api } from '../client'
import type { ApiItem, ProductDto, PublicUserDto } from '../types'

const seg = encodeURIComponent

/** Public read of a live product by slug. A hidden, unknown or taken-down product is the same 404. */
export const getProduct = (slug: string, signal?: AbortSignal) => api.get<ApiItem<ProductDto>>(`/products/${seg(slug)}`, { signal })

/** The view beacon: fire and forget, the caller ignores the outcome. */
export const recordView = (productId: string, referrer?: string) =>
  api.post<void>(`/products/${seg(productId)}/view`, referrer ? { body: { referrer } } : undefined)

export const getUser = (handle: string, signal?: AbortSignal) => api.get<ApiItem<PublicUserDto>>(`/users/${seg(handle)}`, { signal })
