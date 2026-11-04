import { api } from '../client'
import type { ApiItem, CommentDto } from '../types'

const seg = encodeURIComponent

export const createComment = (productId: string, input: { body: string; parent_id?: string }) =>
  api.post<ApiItem<CommentDto>>(`/products/${seg(productId)}/comments`, { body: input }).then((r) => r.data)

export const editComment = (commentId: string, body: string) => api.patch<ApiItem<CommentDto>>(`/comments/${seg(commentId)}`, { body: { body } }).then((r) => r.data)

/** Idempotent; answers with the placeholder. */
export const deleteComment = (commentId: string) => api.delete<ApiItem<CommentDto>>(`/comments/${seg(commentId)}`).then((r) => r.data)
