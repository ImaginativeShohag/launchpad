import { useMutation, useQueryClient, type InfiniteData, type QueryClient } from '@tanstack/react-query'
import { useToast } from '@/components/ui'
import { errorMessage } from '../errors'
import { markAllRead, markRead } from '../endpoints/notifications'
import { keys } from '../keys'
import type { ApiPage, NotificationDto, UnreadCountDto } from '../types'

type Page = InfiniteData<ApiPage<NotificationDto>>
export type MarkReadVars = { ids: string[] } | { all: true }

export const MARK_READ_KEY = ['notifications', 'mark-read'] as const

/** Applies `fn` to every cached notification row, in every list (bell, all, unread). Untouched pages keep their identity. */
function patchRows(qc: QueryClient, fn: (n: NotificationDto) => NotificationDto) {
  qc.setQueriesData<Page>({ queryKey: keys.notificationsRoot }, (old) => {
    if (!old?.pages) return old
    return { ...old, pages: old.pages.map((p) => {
      const data = p.data.map(fn)
      return data.some((n, i) => n !== p.data[i]) ? { ...p, data } : p
    }) }
  })
}

/**
 * Optimistic mark-read. Only the fields it touches change (`read_at` of rows that were unread, and the count); a failure puts back
 * exactly those and leaves anything a live frame or another request changed in between.
 */
export function useMarkRead() {
  const qc = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationKey: MARK_READ_KEY,
    mutationFn: (v: MarkReadVars) => ('all' in v ? markAllRead() : markRead(v.ids)),
    onMutate: async (v) => {
      await Promise.all([qc.cancelQueries({ queryKey: keys.notificationsRoot }), qc.cancelQueries({ queryKey: keys.unreadCount })])
      const stamp = new Date().toISOString()
      const wanted = 'all' in v ? null : new Set(v.ids)
      const before = new Map<string, string | null>()
      patchRows(qc, (n) => {
        if (n.read_at !== null || (wanted && !wanted.has(n.id))) return n
        before.set(n.id, n.read_at)
        return { ...n, read_at: stamp }
      })
      const prevCount = qc.getQueryData<UnreadCountDto>(keys.unreadCount)
      let nextCount: UnreadCountDto | undefined
      if (prevCount) {
        // A capped count ("99+") cannot be lowered by a known number; the re-read after the request fixes it.
        if ('all' in v) nextCount = { unread_count: 0, capped: false }
        else if (!prevCount.capped && before.size > 0) nextCount = { unread_count: Math.max(0, prevCount.unread_count - before.size), capped: false }
        if (nextCount) qc.setQueryData(keys.unreadCount, nextCount)
      }
      return { stamp, before, prevCount, nextCount }
    },
    onError: (e, v, ctx) => {
      // Here and not in the caller: a click that closes the popover or navigates away has already unmounted the caller.
      toast({ tone: 'error', title: 'all' in v ? 'Could not mark all as read' : 'Could not mark it as read', body: errorMessage(e) })
      if (!ctx) return
      patchRows(qc, (n) => (ctx.before.has(n.id) && n.read_at === ctx.stamp ? { ...n, read_at: ctx.before.get(n.id) ?? null } : n))
      const now = qc.getQueryData<UnreadCountDto>(keys.unreadCount)
      if (ctx.prevCount && ctx.nextCount && now && now.unread_count === ctx.nextCount.unread_count && now.capped === ctx.nextCount.capped) qc.setQueryData(keys.unreadCount, ctx.prevCount)
    },
    onSettled: () => {
      // The last of several quick clicks re-reads once for all of them.
      if (qc.isMutating({ mutationKey: MARK_READ_KEY }) > 1) return
      void qc.invalidateQueries({ queryKey: keys.unreadCount })
      void qc.invalidateQueries({ queryKey: keys.notificationsRoot })
    },
  })
}
