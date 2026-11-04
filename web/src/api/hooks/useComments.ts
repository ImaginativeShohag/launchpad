import { useCursorList } from '../pagination'
import { keys } from '../keys'
import { toComment } from '../mappers'
import type { CommentDto } from '../types'

export type CommentSort = 'top' | 'newest'

/** GET /products/{slug}/comments: top-level comments with the first three replies embedded, keyset-paged per sort. */
export function useComments(slug: string, sort: CommentSort) {
  return useCursorList<CommentDto, ReturnType<typeof toComment>>(keys.comments(slug, sort), `/products/${encodeURIComponent(slug)}/comments`, { sort, per_page: 20 }, { map: toComment })
}
