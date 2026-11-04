import { AnimatePresence, motion } from 'motion/react'
import { ArrowUpDown, CornerDownRight, Flag, MessageCircle, Pencil, Reply, Send, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Link } from 'react-router'
import { isApiError } from '@/api/errors'
import { useComments, type CommentSort } from '@/api/hooks/useComments'
import { useCreateComment, useDeleteComment, useEditComment } from '@/api/hooks/useCommentMutations'
import { useReplies } from '@/api/hooks/useReplies'
import { isTempId } from '@/api/mappers'
import type { Comment, ProductDetail } from '@/data/types'
import { useAuthGate } from '@/components/layout/AuthGate'
import { Avatar, Badge, Button, cn, ErrorState, LoadMore, Modal, Skeleton, Tabs, useToast } from '@/components/ui'
import { timeAgo } from '@/lib/date'
import { useCooldown } from '@/lib/useCooldown'
import { useServerErrors } from '@/lib/useServerErrors'
import { useSession } from '@/session/SessionProvider'
import { ReportModal } from './ReportModal'

const FRESH_MS = 8000
const MAX_BODY = 1000
const chars = (text: string) => Array.from(text).length

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

/** First occurrence of each id, in order (a refetch can briefly repeat a row the cache already holds). */
function unique(list: Comment[]): Comment[] {
  const seen = new Set<string>()
  return list.filter((c) => (seen.has(c.id) ? false : (seen.add(c.id), true)))
}

type ReplyTarget = { rootId: string; target: Comment }

/** Comments + one level of replies for a product, from the API: sorted and paged by the server. */
export function CommentsSection({ product }: { product: ProductDetail }) {
  const [sort, setSort] = useState<CommentSort>('top')
  const [replyTo, setReplyTo] = useState<ReplyTarget | null>(null)
  const [reporting, setReporting] = useState<Comment | null>(null)
  const [deleting, setDeleting] = useState<Comment | null>(null)
  const list = useComments(product.slug, sort)
  const remove = useDeleteComment()
  const toast = useToast()
  const roots = useMemo(() => unique(list.items), [list.items])
  const count = product.commentsCount

  return (
    <section aria-labelledby="comments-title" className="surface p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="comments-title" className="flex items-center gap-2 text-xl font-bold">
          Discussion
          <motion.span key={count} initial={{ scale: 1.4 }} animate={{ scale: 1 }} aria-label={`${count} comments`} className="rounded-full bg-ink-100 px-2 py-0.5 font-sans text-sm tabular-nums text-ink-600 dark:bg-white/10 dark:text-ink-300">
            {count}
          </motion.span>
        </h2>
        {roots.length > 1 && (
          <div className="flex items-center gap-2">
            <ArrowUpDown className="size-4 text-ink-400" />
            <Tabs<CommentSort>
              items={[
                { value: 'top', label: 'Top' },
                { value: 'newest', label: 'Newest' },
              ]}
              value={sort}
              onChange={setSort}
            />
          </div>
        )}
      </div>

      <div className="mt-5">
        <CommentComposer product={product} placeholder={`What do you think of ${product.name}?`} />
      </div>

      {list.isPending ? (
        <div className="mt-6 space-y-4" aria-busy="true" aria-label="Loading comments">
          {Array.from({ length: 2 }, (_, i) => (
            <Skeleton key={i} className="h-16 w-full rounded-2xl" />
          ))}
        </div>
      ) : list.isError && !list.data ? (
        <div className="mt-6">
          <ErrorState error={list.error} onRetry={() => void list.refetch()} title="Could not load the discussion" />
        </div>
      ) : roots.length === 0 ? (
        <div className="flex flex-col items-center py-10 text-center">
          <motion.div animate={{ y: [0, -5, 0] }} transition={{ repeat: Infinity, duration: 3 }} className="mb-3 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10">
            <MessageCircle className="size-6" />
          </motion.div>
          <p className="font-semibold">No comments yet</p>
          <p className="text-sm text-ink-500">Be the first to share feedback with the maker.</p>
        </div>
      ) : (
        <>
          <ul className="mt-6 space-y-1">
            <AnimatePresence initial={false}>
              {roots.map((c) => (
                <Thread key={c.id} root={c} product={product} replyTo={replyTo} setReplyTo={setReplyTo} onReport={setReporting} onDelete={setDeleting} />
              ))}
            </AnimatePresence>
          </ul>
          <LoadMore hasNextPage={list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}

      <ReportModal
        open={!!reporting}
        onClose={() => setReporting(null)}
        kind="comment"
        targetId={reporting?.id ?? ''}
        subject={`this comment by ${reporting?.author?.name ?? 'a member'}`}
      />
      <Modal
        open={!!deleting}
        onClose={() => setDeleting(null)}
        size="sm"
        title="Delete this comment?"
        description="It will be replaced by a note that it was deleted. This cannot be undone."
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              onClick={() => {
                if (!deleting) return
                remove.mutate({ slug: product.slug, productId: product.id, commentId: deleting.id }, { onSuccess: () => toast({ tone: 'success', title: 'Comment deleted' }) })
                setDeleting(null)
              }}
            >
              Delete
            </Button>
          </>
        }
      >
        <p className="line-clamp-3 text-sm whitespace-pre-wrap text-ink-600 dark:text-ink-300">{deleting?.body}</p>
      </Modal>
    </section>
  )
}

/** A top-level comment, its replies (the first few embedded, the rest on request) and the reply box. */
function Thread({
  root,
  product,
  replyTo,
  setReplyTo,
  onReport,
  onDelete,
}: {
  root: Comment
  product: ProductDetail
  replyTo: ReplyTarget | null
  setReplyTo: (t: ReplyTarget | null) => void
  onReport: (c: Comment) => void
  onDelete: (c: Comment) => void
}) {
  const [expanded, setExpanded] = useState(false)
  // A reply that is being sent keeps its box (and so its draft rollback) until the server has answered.
  const [sending, setSending] = useState(false)
  const full = useReplies(root.id, expanded)
  // Until the thread's own list arrives the embedded replies stay on screen.
  const replies = useMemo(() => unique(expanded && full.data ? full.items : root.replies), [expanded, full.data, full.items, root.replies])
  const hidden = Math.max(0, root.repliesCount - replies.length)
  const composing = replyTo?.rootId === root.id ? replyTo : null
  const reply = (target: Comment) => () => {
    if (sending) return
    setReplyTo(composing?.target.id === target.id ? null : { rootId: root.id, target })
  }

  return (
    <CommentItem comment={root} product={product} onReply={isTempId(root.id) ? undefined : reply(root)} onReport={onReport} onDelete={onDelete}>
      <ul className="space-y-1">
        <AnimatePresence initial={false}>
          {replies.map((r) => (
            <CommentItem key={r.id} comment={r} product={product} isReply onReply={isTempId(r.id) ? undefined : reply(r)} onReport={onReport} onDelete={onDelete} />
          ))}
        </AnimatePresence>
      </ul>
      {!expanded && hidden > 0 && (
        <Button size="sm" variant="ghost" className="mt-1" onClick={() => setExpanded(true)}>
          Show {hidden} more {hidden === 1 ? 'reply' : 'replies'}
        </Button>
      )}
      {expanded && full.isError && (
        <p role="alert" className="mt-1 text-sm text-red-600 dark:text-red-400">
          Could not load the replies.{' '}
          <button className="cursor-pointer font-semibold underline" onClick={() => void full.refetch()}>
            Retry
          </button>
        </p>
      )}
      {expanded && !full.data && !full.isError && <p className="mt-1 text-xs text-ink-400">Loading replies…</p>}
      {expanded && full.hasNextPage && (
        <Button size="sm" variant="ghost" className="mt-1" loading={full.isFetchingNextPage} onClick={() => void full.fetchNextPage()}>
          Show more replies
        </Button>
      )}
      <AnimatePresence initial={false}>
        {composing && (
          <motion.div
            key="reply"
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ type: 'spring', stiffness: 400, damping: 36 }}
            className="overflow-hidden"
          >
            <div className="flex gap-2 pt-2 pb-1">
              <CornerDownRight className="mt-3 size-4 shrink-0 text-ink-300" />
              <div className="flex-1">
                <CommentComposer
                  product={product}
                  reply={composing}
                  autoFocus
                  compact
                  placeholder={`Reply to ${composing.target.author?.name.split(' ')[0] ?? 'comment'}…`}
                  onDone={() => setReplyTo(null)}
                  onCancel={() => setReplyTo(null)}
                  onBusy={setSending}
                />
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </CommentItem>
  )
}

function CommentItem({
  comment,
  product,
  isReply,
  onReply,
  onReport,
  onDelete,
  children,
}: {
  comment: Comment
  product: ProductDetail
  isReply?: boolean
  onReply?: () => void
  onReport: (c: Comment) => void
  onDelete: (c: Comment) => void
  children?: ReactNode
}) {
  const { user } = useSession()
  const gate = useAuthGate()
  const [editing, setEditing] = useState(false)
  const [shownAt] = useState(Date.now)
  const author = comment.author
  const placeholder = comment.state !== 'visible'
  const isMine = !!user && author?.id === user.id
  const fresh = isMine && shownAt - new Date(comment.createdAt).getTime() < FRESH_MS
  const profile = author ? `/u/${author.handle}` : undefined

  return (
    <motion.li
      layout="position"
      initial={{ opacity: 0, y: -10, scale: 0.98 }}
      animate={{ opacity: comment.pending ? 0.7 : 1, y: 0, scale: 1 }}
      exit={{ opacity: 0, scale: 0.98, transition: { duration: 0.15 } }}
      transition={{ type: 'spring', stiffness: 420, damping: 34 }}
      className={cn('relative', isReply ? 'pl-5' : 'pt-3')}
    >
      {isReply && <span aria-hidden className="absolute top-0 bottom-3 left-[7px] w-px bg-ink-200 dark:bg-white/10" />}
      <div className="relative flex gap-3 rounded-2xl p-2 -mx-2">
        {fresh && (
          <motion.span
            aria-hidden
            className="pointer-events-none absolute inset-0 rounded-2xl bg-brand-500/10 ring-1 ring-brand-500/30"
            initial={{ opacity: 1 }}
            animate={{ opacity: 0 }}
            transition={{ duration: 2.4, delay: 0.8 }}
          />
        )}
        {profile ? (
          <Link to={profile} className="relative shrink-0 transition hover:scale-105">
            <Avatar name={author?.name ?? '?'} hue={author?.hue ?? 0} src={author?.avatarUrl} size={isReply ? 'sm' : 'md'} />
          </Link>
        ) : (
          <span className="relative shrink-0">
            <Avatar name="?" hue={220} size={isReply ? 'sm' : 'md'} className="opacity-50" />
          </span>
        )}
        <div className="relative min-w-0 flex-1">
          {placeholder ? (
            <p className="py-1.5 text-sm text-ink-400 italic">{comment.state === 'removed' ? 'This comment was removed by a moderator.' : 'This comment was deleted.'}</p>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                {profile ? (
                  <Link to={profile} className="text-sm font-semibold hover:text-brand-600 dark:hover:text-brand-400">
                    {author?.name ?? 'Former member'}
                  </Link>
                ) : (
                  <span className="text-sm font-semibold">Former member</span>
                )}
                {author?.isMaker && <Badge tone="brand">Maker</Badge>}
                {author?.suspended && <Badge tone="neutral">Suspended</Badge>}
                <span className="text-xs text-ink-400" title={new Date(comment.createdAt).toLocaleString()}>
                  {comment.pending ? 'sending…' : timeAgo(comment.createdAt)}
                </span>
                {comment.editedAt && <span className="text-xs text-ink-400">· edited</span>}
              </div>
              {editing ? (
                <EditBox comment={comment} slug={product.slug} onClose={() => setEditing(false)} />
              ) : (
                <p className="mt-1 text-[15px] leading-relaxed break-words whitespace-pre-wrap text-ink-700 dark:text-ink-200">
                  {comment.replyTo && (
                    <Link to={`/u/${comment.replyTo.handle}`} className="mr-1 font-semibold text-brand-600 hover:underline dark:text-brand-400">
                      @{comment.replyTo.handle}
                    </Link>
                  )}
                  {comment.body}
                </p>
              )}
              {!editing && (
                <div className="mt-1.5 flex flex-wrap items-center gap-1">
                  {onReply && (
                    <button
                      onClick={() => gate('reply to comments', onReply)}
                      className="inline-flex cursor-pointer items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-ink-500 transition hover:bg-ink-100 hover:text-ink-800 dark:hover:bg-white/5 dark:hover:text-white"
                    >
                      <Reply className="size-3.5" /> Reply
                    </button>
                  )}
                  {comment.canEdit && !comment.pending && (
                    <button
                      onClick={() => setEditing(true)}
                      className="inline-flex cursor-pointer items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-ink-500 transition hover:bg-ink-100 hover:text-ink-800 dark:hover:bg-white/5 dark:hover:text-white"
                    >
                      <Pencil className="size-3.5" /> Edit
                    </button>
                  )}
                  {comment.canDelete && !comment.pending && (
                    <button
                      onClick={() => onDelete(comment)}
                      className="inline-flex cursor-pointer items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-ink-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                    >
                      <Trash2 className="size-3.5" /> Delete
                    </button>
                  )}
                  {!isMine && !comment.pending && (
                    <button
                      onClick={() => gate('report comments', () => onReport(comment))}
                      className="inline-flex cursor-pointer items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-ink-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                    >
                      <Flag className="size-3.5" /> Report
                    </button>
                  )}
                </div>
              )}
            </>
          )}
        </div>
      </div>
      {children && <div className="ml-5 sm:ml-6">{children}</div>}
    </motion.li>
  )
}

/** In-place editor for the member's own comment, inside the server's edit window. */
function EditBox({ comment, slug, onClose }: { comment: Comment; slug: string; onClose: () => void }) {
  const edit = useEditComment()
  const errors = useServerErrors()
  const wait = useCooldown()
  const [text, setText] = useState(comment.body ?? '')
  const ref = useRef<HTMLTextAreaElement>(null)
  const over = chars(text) > MAX_BODY
  const changed = text.trim() !== (comment.body ?? '')

  useEffect(() => {
    ref.current?.focus()
  }, [])

  const save = () => {
    const body = text.trim()
    if (!body || over || !changed || edit.isPending || wait.remaining > 0) return
    edit.mutate(
      { slug, commentId: comment.id, body },
      {
        onSuccess: onClose,
        onError: (e) => {
          errors.set(e)
          // The window closed or the comment is gone: nothing left to edit (the hook has toasted).
          if (isApiError(e) && (e.code === 'edit_window_closed' || e.status === 404)) onClose()
          else if (isApiError(e) && e.status === 429) wait.start(e.retryAfter ?? 10)
        },
      },
    )
  }
  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
      e.preventDefault()
      save()
    }
    if (e.key === 'Escape') onClose()
  }
  const error = errors.get('body')

  return (
    <div className="mt-1">
      <textarea
        ref={ref}
        value={text}
        aria-label="Edit comment"
        aria-invalid={!!error}
        rows={3}
        onChange={(e) => {
          setText(e.target.value)
          errors.clear('body')
        }}
        onKeyDown={onKeyDown}
        className="block w-full resize-y rounded-xl border border-ink-200 bg-white p-2.5 text-[15px] leading-relaxed text-ink-900 focus:border-brand-400 focus:ring-4 focus:ring-brand-500/15 focus:outline-none dark:border-white/10 dark:bg-ink-950 dark:text-white"
      />
      {error && (
        <p role="alert" className="mt-1 text-xs font-medium text-red-500">
          {error}
        </p>
      )}
      <div className="mt-2 flex items-center justify-between gap-2">
        <span className={cn('text-xs tabular-nums', over ? 'text-red-500' : 'text-ink-400')}>
          {chars(text)}/{MAX_BODY}
        </span>
        <div className="flex gap-2">
          <Button size="sm" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button size="sm" loading={edit.isPending} disabled={!text.trim() || over || !changed || wait.remaining > 0} onClick={save}>
            {wait.remaining > 0 ? `Try again in ${wait.remaining} s` : 'Save'}
          </Button>
        </div>
      </div>
    </div>
  )
}

/** Autosizing comment box. ⌘/Ctrl+Enter posts. Gated for guests and unverified members. */
export function CommentComposer({
  product,
  reply,
  placeholder,
  autoFocus,
  compact,
  onDone,
  onCancel,
  onBusy,
}: {
  product: ProductDetail
  /** Set for a reply: the thread and the comment being answered. */
  reply?: ReplyTarget
  placeholder: string
  autoFocus?: boolean
  compact?: boolean
  onDone?: () => void
  onCancel?: () => void
  /** Tells the parent while a post is in flight, so it does not remove this box before the outcome is handled. */
  onBusy?: (busy: boolean) => void
}) {
  const { user } = useSession()
  const gate = useAuthGate()
  const create = useCreateComment()
  const errors = useServerErrors()
  const wait = useCooldown()
  const [body, setBody] = useState('')
  const [focused, setFocused] = useState(false)
  const ref = useRef<HTMLTextAreaElement>(null)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 320)}px`
  }, [body])

  useEffect(() => {
    if (autoFocus) ref.current?.focus()
  }, [autoFocus])

  const pending = create.isPending
  useEffect(() => {
    onBusy?.(pending)
    return () => onBusy?.(false)
  }, [pending, onBusy])

  const length = chars(body)
  const over = length > MAX_BODY

  const submit = () => {
    const text = body.trim()
    if (!text || over || create.isPending || wait.remaining > 0) return
    gate('join the discussion', () => {
      errors.reset()
      setBody('')
      create.mutate(
        {
          slug: product.slug,
          productId: product.id,
          makerId: product.maker.id,
          body: text,
          parentId: reply?.target.id,
          rootId: reply?.rootId,
          // Answering a reply names that reply's author; answering the top-level comment names nobody.
          replyTo: reply?.target.parentId && reply.target.author ? { id: reply.target.author.id, handle: reply.target.author.handle, name: reply.target.author.name } : null,
        },
        {
          onSuccess: () => onDone?.(),
          onError: (e) => {
            // The draft comes back unless the member already started something new.
            setBody((current) => current || text)
            errors.set(e)
            if (isApiError(e) && e.status === 429) wait.start(e.retryAfter ?? 10)
          },
        },
      )
    })
  }

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
      e.preventDefault()
      submit()
    }
    if (e.key === 'Escape' && onCancel && !pending) onCancel()
  }

  const expanded = focused || body.length > 0 || compact
  const error = errors.get('body')

  return (
    <div
      className={cn(
        'flex gap-3 rounded-2xl border bg-white p-3 transition-all dark:bg-ink-950',
        focused ? 'border-brand-400 ring-4 ring-brand-500/15' : 'border-ink-200 dark:border-white/10',
      )}
    >
      {!compact && <Avatar name={user?.name ?? 'You'} hue={user?.hue ?? 220} src={user?.avatarUrl} size="sm" className="mt-0.5" />}
      <div className="min-w-0 flex-1">
        <textarea
          ref={ref}
          rows={1}
          value={body}
          aria-label={placeholder}
          aria-invalid={!!error}
          placeholder={placeholder}
          onChange={(e) => {
            setBody(e.target.value)
            errors.clear('body')
          }}
          onKeyDown={onKeyDown}
          onFocus={() => {
            if (!gate('join the discussion')) {
              ref.current?.blur()
              return
            }
            setFocused(true)
          }}
          onBlur={() => setFocused(false)}
          className="block w-full resize-none bg-transparent py-1.5 text-[15px] leading-relaxed text-ink-900 placeholder:text-ink-400 focus:outline-none dark:text-white"
        />
        {error && (
          <p role="alert" className="text-xs font-medium text-red-500">
            {error}
          </p>
        )}
        <AnimatePresence initial={false}>
          {(expanded || !!error) && (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: 'auto', opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              transition={{ type: 'spring', stiffness: 500, damping: 38 }}
              className="overflow-hidden"
            >
              <div className="flex items-center justify-between gap-2 pt-2">
                <span className={cn('hidden text-xs sm:inline', over ? 'text-red-500' : 'text-ink-400')}>
                  {over || length > MAX_BODY * 0.8 ? (
                    `${length}/${MAX_BODY}`
                  ) : (
                    <>
                      <kbd className="rounded border border-ink-200 px-1 font-sans dark:border-white/10">{isMac ? '⌘' : 'Ctrl'}</kbd> + <kbd className="rounded border border-ink-200 px-1 font-sans dark:border-white/10">Enter</kbd> to post
                    </>
                  )}
                </span>
                <div className="ml-auto flex gap-2">
                  {onCancel && (
                    <Button size="sm" variant="ghost" disabled={pending} onClick={onCancel}>
                      Cancel
                    </Button>
                  )}
                  <Button size="sm" disabled={!body.trim() || over || create.isPending || wait.remaining > 0} onClick={submit} icon={<Send className="size-3.5" />}>
                    {wait.remaining > 0 ? `Try again in ${wait.remaining} s` : reply ? 'Reply' : 'Post'}
                  </Button>
                </div>
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </div>
  )
}
