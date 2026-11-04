import { useCursorList } from '../pagination'
import { keys } from '../keys'
import { toFeedCard } from '../mappers'
import type { ProductDto } from '../types'

/** GET /me/feed, keyset-paged; `hasFollows` (first page meta) tells "follows nothing" from "nothing new yet". */
export function useFeed() {
  const list = useCursorList<ProductDto, ReturnType<typeof toFeedCard>>(keys.feed, '/me/feed', {}, { map: toFeedCard })
  return { ...list, hasFollows: list.data?.pages[0]?.meta?.has_follows }
}
