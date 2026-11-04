import { useMutation, useQueryClient, type InfiniteData, type QueryClient } from '@tanstack/react-query'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { useToast } from '@/components/ui'
import { useSession } from '@/session/SessionProvider'
import { patchCardsInCaches } from '../cache'
import { createComment, deleteComment, editComment } from '../endpoints/comments'
import { errorMessage, isApiError } from '../errors'
import { TEMP_PREFIX } from '../mappers'
import type { ApiPage, CommentDto } from '../types'

type Pages = InfiniteData<ApiPage<CommentDto>>

const isThread = (key: readonly unknown[]) => key[0] === 'comments' || key[0] === 'replies'

/** Runs `fn` over every cached comment list (the product's top-level lists and every loaded replies list). */
function eachList(qc: QueryClient, slug: string, fn: (data: Pages, key: readonly unknown[]) => Pages) {
  for (const [key, data] of qc.getQueriesData<Pages>({ predicate: (q) => isThread(q.queryKey) && (q.queryKey[0] !== 'comments' || q.queryKey[1] === slug) })) {
    if (!data || !Array.isArray(data.pages)) continue
    const next = fn(data, key)
    if (next !== data) qc.setQueryData(key, next)
  }
}

/** Applies `fn` to every comment in a cache, replies included; `fn` returns the same object when nothing changes. */
function mapAll(data: Pages, fn: (c: CommentDto) => CommentDto): Pages {
  let changed = false
  const pages = data.pages.map((page) => {
    const list = page.data.map((c) => {
      const replies = c.replies?.map(fn)
      const inner = replies && replies.some((r, i) => r !== c.replies?.[i]) ? { ...c, replies } : c
      const out = fn(inner)
      if (out !== c) changed = true
      return out
    })
    return list.some((c, i) => c !== page.data[i]) ? { ...page, data: list } : page
  })
  return changed ? { ...data, pages } : data
}

const patchById = (qc: QueryClient, slug: string, id: string, patch: (c: CommentDto) => CommentDto) =>
  eachList(qc, slug, (data) => mapAll(data, (c) => (c.id === id ? patch(c) : c)))

/** The first copy of a comment found in any cache (to remember what to put back). */
function findComment(qc: QueryClient, slug: string, id: string): CommentDto | undefined {
  let found: CommentDto | undefined
  eachList(qc, slug, (data) => {
    mapAll(data, (c) => {
      if (c.id === id && !found) found = c
      return c
    })
    return data
  })
  return found
}

const placeholder = (c: CommentDto): CommentDto => ({ ...c, state: 'deleted', body: null, author: null, reply_to: null, can_edit: false, can_delete: false })

/** Own fields only: `replies` of the cached row are kept (a single-comment answer has none). */
const withServer = (current: CommentDto, server: CommentDto): CommentDto => ({ ...server, replies: current.replies, replies_count: current.replies_count })

/** Takes a temporary row back out: from a list, or from the thread it joined (whose reply count then drops again). */
function removeTemp(data: Pages, tempId: string): Pages {
  const pages = data.pages.map((page) => {
    let touched = false
    const list: CommentDto[] = []
    for (const c of page.data) {
      if (c.id === tempId) {
        touched = true
      } else if (c.replies?.some((r) => r.id === tempId)) {
        touched = true
        list.push({ ...c, replies: c.replies.filter((r) => r.id !== tempId), replies_count: Math.max(0, c.replies_count - 1) })
      } else {
        list.push(c)
      }
    }
    return touched ? { ...page, data: list } : page
  })
  return pages.some((p, i) => p !== data.pages[i]) ? { ...data, pages } : data
}

let tempCounter = 0

export interface CreateInput {
  slug: string
  productId: string
  makerId: string
  body: string
  /** The comment being answered (any comment of the thread). */
  parentId?: string
  /** The thread's top-level comment. */
  rootId?: string
  /** `reply_to` of the new row: the author of the answered comment when that is itself a reply. */
  replyTo?: { id: string; handle: string; name: string } | null
}

function commentFailure(err: unknown, toast: ReturnType<typeof useToast>, promptVerify: () => void, what: 'post' | 'edit' | 'delete') {
  if (isApiError(err) && err.code === 'email_unverified') return promptVerify()
  if (isApiError(err) && err.code === 'edit_window_closed') {
    return toast({ tone: 'info', title: 'The edit window has closed', body: 'Comments can only be edited for a few minutes after posting.' })
  }
  if (isApiError(err) && err.status === 404) {
    return toast({ tone: 'info', title: what === 'post' ? 'This discussion is no longer available' : 'This comment is no longer available', body: 'It may have been removed. The list is being refreshed.' })
  }
  if (isApiError(err) && err.status === 401) return toast({ tone: 'error', title: 'Please sign in again', body: 'Your session ended.' })
  toast({ tone: 'error', title: what === 'post' ? 'Could not post your comment' : what === 'edit' ? 'Could not save your edit' : 'Could not delete the comment', body: errorMessage(err) })
}

/**
 * Optimistic post: a temporary row (`tmp-n`) goes into the first page of the list (or the end of the thread) at once and the
 * product's comment count goes up. The server's comment replaces the row; an error removes it again. 422 field errors are not
 * toasted: the composer shows them under the box.
 */
export function useCreateComment() {
  const qc = useQueryClient()
  const toast = useToast()
  const promptVerify = useVerifyPrompt()
  const { user } = useSession()

  return useMutation({
    mutationFn: (input: CreateInput) => createComment(input.productId, { body: input.body, ...(input.parentId ? { parent_id: input.parentId } : {}) }),
    onMutate: async (input) => {
      await qc.cancelQueries({ predicate: (q) => isThread(q.queryKey) && (q.queryKey[0] !== 'comments' || q.queryKey[1] === input.slug) })
      const tempId = `${TEMP_PREFIX}${++tempCounter}`
      const temp: CommentDto = {
        id: tempId,
        parent_id: input.rootId ?? null,
        state: 'visible',
        body: input.body,
        author: user ? { id: user.id, handle: user.handle, name: user.name, avatar_url: user.avatarUrl ?? null, is_suspended: false, is_maker: user.id === input.makerId } : null,
        reply_to: input.replyTo ?? null,
        created_at: new Date().toISOString(),
        edited_at: null,
        replies_count: 0,
        replies: input.rootId ? undefined : [],
        can_edit: false,
        can_delete: false,
      }
      eachList(qc, input.slug, (data, key) => {
        if (!input.rootId) {
          // A new top-level comment leads the first page of every sort.
          if (key[0] !== 'comments') return data
          const [first, ...rest] = data.pages
          return first ? { ...data, pages: [{ ...first, data: [temp, ...first.data] }, ...rest] } : data
        }
        if (key[0] === 'replies') {
          // The thread's own list: the newest reply is last, so it can only join once the last page is loaded.
          if (key[1] !== input.rootId) return data
          const last = data.pages[data.pages.length - 1]
          return last && !last.meta?.next_cursor ? { ...data, pages: [...data.pages.slice(0, -1), { ...last, data: [...last.data, temp] }] } : data
        }
        return mapAll(data, (c) => (c.id === input.rootId ? { ...c, replies: [...(c.replies ?? []), temp], replies_count: c.replies_count + 1 } : c))
      })
      patchCardsInCaches(qc, input.productId, (c) => ({ ...c, comments_count: (c.comments_count ?? 0) + 1 }))
      return { tempId }
    },
    onSuccess: (server, input, ctx) => {
      eachList(qc, input.slug, (data) => mapAll(data, (c) => (c.id === ctx.tempId ? withServer(c, server) : c)))
    },
    onError: (err, input, ctx) => {
      if (ctx) eachList(qc, input.slug, (data) => removeTemp(data, ctx.tempId))
      patchCardsInCaches(qc, input.productId, (c) => ({ ...c, comments_count: Math.max(0, (c.comments_count ?? 0) - 1) }))
      if (isApiError(err) && err.status === 422) return
      commentFailure(err, toast, promptVerify, 'post')
      if (isApiError(err) && err.status === 404) void qc.invalidateQueries({ queryKey: ['comments', input.slug] })
    },
  })
}

export interface EditInput {
  slug: string
  commentId: string
  body: string
}

/** The text changes at once; the server's row confirms it. A closed window or a vanished comment puts the old text back. */
export function useEditComment() {
  const qc = useQueryClient()
  const toast = useToast()
  const promptVerify = useVerifyPrompt()

  return useMutation({
    mutationFn: (input: EditInput) => editComment(input.commentId, input.body),
    onMutate: async (input) => {
      await qc.cancelQueries({ predicate: (q) => isThread(q.queryKey) })
      const prev = findComment(qc, input.slug, input.commentId)
      patchById(qc, input.slug, input.commentId, (c) => ({ ...c, body: input.body, edited_at: new Date().toISOString() }))
      return { prev }
    },
    onSuccess: (server, input) => patchById(qc, input.slug, input.commentId, (c) => withServer(c, server)),
    onError: (err, input, ctx) => {
      const prev = ctx?.prev
      if (prev) patchById(qc, input.slug, input.commentId, (c) => ({ ...c, body: prev.body, edited_at: prev.edited_at }))
      if (isApiError(err) && err.status === 422) return
      commentFailure(err, toast, promptVerify, 'edit')
      if (isApiError(err) && (err.status === 404 || err.code === 'edit_window_closed')) void qc.invalidateQueries({ queryKey: ['comments', input.slug] })
    },
  })
}

export interface DeleteInput {
  slug: string
  productId: string
  commentId: string
}

/** The row turns into the "deleted" placeholder at once and the product's count drops; an error puts it back. */
export function useDeleteComment() {
  const qc = useQueryClient()
  const toast = useToast()
  const promptVerify = useVerifyPrompt()

  return useMutation({
    mutationFn: (input: DeleteInput) => deleteComment(input.commentId),
    onMutate: async (input) => {
      await qc.cancelQueries({ predicate: (q) => isThread(q.queryKey) })
      const prev = findComment(qc, input.slug, input.commentId)
      patchById(qc, input.slug, input.commentId, placeholder)
      if (prev?.state === 'visible') patchCardsInCaches(qc, input.productId, (c) => ({ ...c, comments_count: Math.max(0, (c.comments_count ?? 0) - 1) }))
      return { prev }
    },
    onSuccess: (server, input) => patchById(qc, input.slug, input.commentId, (c) => withServer(c, server)),
    onError: (err, input, ctx) => {
      const prev = ctx?.prev
      if (prev) {
        patchById(qc, input.slug, input.commentId, (c) => ({ ...c, state: prev.state, body: prev.body, author: prev.author, reply_to: prev.reply_to, can_edit: prev.can_edit, can_delete: prev.can_delete }))
        if (prev.state === 'visible') patchCardsInCaches(qc, input.productId, (c) => ({ ...c, comments_count: (c.comments_count ?? 0) + 1 }))
      }
      commentFailure(err, toast, promptVerify, 'delete')
      if (isApiError(err) && err.status === 404) void qc.invalidateQueries({ queryKey: ['comments', input.slug] })
    },
  })
}
