import type { CommentAuthorDto, CommentDto, FollowStateDto, ReportDto } from '@/api/types'

let n = 0
/** A valid ULID-shaped comment id that sorts by creation order. */
export const commentId = () => `01JCOMNT${String(++n).padStart(18, '0')}`

export const commentAuthor = (o: Partial<CommentAuthorDto> = {}): CommentAuthorDto => ({
  id: '01JCARL00000000000000000000',
  handle: 'carl',
  name: 'Carl Commenter',
  avatar_url: null,
  is_suspended: false,
  is_maker: false,
  ...o,
})

/** A visible comment as `CommentResource` returns it. `can_edit` / `can_delete` only when given (a guest has neither key). */
export const apiComment = (o: Partial<CommentDto> = {}): CommentDto => ({
  id: commentId(),
  parent_id: null,
  state: 'visible',
  body: 'Nice work on this.',
  author: commentAuthor(),
  reply_to: null,
  created_at: '2026-10-21T09:00:00.123456Z',
  edited_at: null,
  replies_count: 0,
  replies: [],
  ...o,
})

/** The placeholder the API shows for a removed or deleted comment. */
export const placeholderComment = (state: 'removed' | 'deleted', o: Partial<CommentDto> = {}): CommentDto =>
  apiComment({ state, body: null, author: null, reply_to: null, ...o })

export const commentsBody = (data: CommentDto[], next: string | null = null) => ({
  data,
  links: { next: next ? `http://api.test/api/v1/comments?cursor=${next}` : null },
  meta: { next_cursor: next, per_page: 20 },
})

export const followState = (following: boolean, followers_count: number): { data: FollowStateDto } => ({ data: { following, followers_count } })

export const apiReport = (o: Partial<ReportDto> = {}): { data: ReportDto } => ({
  data: { id: '01JREPORT0000000000000000A', type: 'product', target_id: '01JTARGET', reason: 'spam', status: 'open', created_at: '2026-10-21T10:00:00+00:00', ...o },
})
