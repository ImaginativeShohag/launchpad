import { useCursorList } from '../pagination'
import { keys } from '../keys'
import { toProfileCard } from '../mappers'
import type { ProductDto } from '../types'

/** GET /users/{handle}/products (live launches, newest first, keyset). The list carries no vote or comment counts. */
export function useUserProducts(handle: string | undefined, enabled = true) {
  return useCursorList<ProductDto, ReturnType<typeof toProfileCard>>(keys.userProducts(handle ?? ''), `/users/${encodeURIComponent(handle ?? '')}/products`, {}, { enabled: enabled && !!handle, map: toProfileCard })
}
