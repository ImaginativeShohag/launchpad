import { useCursorList } from '../pagination'
import { keys } from '../keys'
import { toComment } from '../mappers'
import type { CommentDto } from '../types'

/** GET /comments/{id}/replies: every visible reply of a thread, oldest first. Enabled when the member asks for the rest. */
export function useReplies(commentId: string, enabled: boolean) {
  return useCursorList<CommentDto, ReturnType<typeof toComment>>(keys.replies(commentId), `/comments/${encodeURIComponent(commentId)}/replies`, { per_page: 20 }, { enabled, map: toComment })
}
