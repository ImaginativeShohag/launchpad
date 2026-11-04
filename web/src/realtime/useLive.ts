import { useQueryClient, type QueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { patchCardsInCaches } from '@/api/cache'
import { isFrameBlocked } from '@/api/hooks/useVote'
import { keys } from '@/api/keys'
import type { ProductDto, RankFrame, TodayDto } from '@/api/types'
import { MARK_READ_KEY } from '@/api/hooks/useMarkRead'
import { UNREAD_CAP } from '@/api/hooks/useUnreadCount'
import type { UnreadCountDto } from '@/api/types'
import { subscribeLive, subscribeStatus } from './echo'

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)

/** Frames are hints from a public channel: anything that is not the exact shape is dropped. */
function parseCount(v: unknown): { productId: string; count: number } | null {
  if (!isRecord(v) || typeof v.product_id !== 'string' || typeof v.votes_count !== 'number' || !Number.isFinite(v.votes_count)) return null
  return { productId: v.product_id, count: Math.min(MAX_COUNT, Math.max(0, Math.trunc(v.votes_count))) }
}

const MAX_PRODUCT_CHANNELS = 50
const MAX_FRAME_ITEMS = 200
const MAX_COUNT = 1_000_000_000

export function parseRankFrame(v: unknown): RankFrame | null {
  if (!isRecord(v) || typeof v.date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(v.date) || !Array.isArray(v.items) || v.items.length > MAX_FRAME_ITEMS) return null
  const items: RankFrame['items'] = []
  for (const it of v.items) {
    if (!isRecord(it) || typeof it.product_id !== 'string' || typeof it.rank !== 'number' || !Number.isSafeInteger(it.rank) || it.rank < 1) return null
    if (typeof it.votes_count !== 'number' || !Number.isFinite(it.votes_count)) return null
    items.push({ product_id: it.product_id, rank: it.rank, votes_count: Math.min(MAX_COUNT, Math.max(0, Math.trunc(it.votes_count))) })
  }
  return { date: v.date, items }
}

/** Patches the count on every cached copy, unless this browser's own vote request for it is in flight (the response wins). */
export function applyVoteCount(qc: QueryClient, productId: string, count: number) {
  if (isFrameBlocked(productId)) return
  const safe = Math.min(MAX_COUNT, Math.max(0, count))
  patchCardsInCaches(qc, productId, (c) => (c.votes_count === safe ? c : { ...c, votes_count: safe }))
}

const MIN_GAP_MS = 5_000
const RECONNECT_GAP_MS = 30_000

type Throttle = { last: number; due: number; timer?: ReturnType<typeof setTimeout> }
type ThrottleStore = WeakMap<QueryClient, Throttle>
const todayThrottle: ThrottleStore = new WeakMap()
const notificationsThrottle: ThrottleStore = new WeakMap()

function stateOf(store: ThrottleStore, qc: QueryClient) {
  let s = store.get(qc)
  if (!s) store.set(qc, (s = { last: 0, due: 0 }))
  return s
}

/** Invalidates `queryKey` at most once per `gapMs`: the first call goes now, later ones collapse into one trailing read. */
function invalidateThrottled(store: ThrottleStore, qc: QueryClient, queryKey: readonly unknown[], { leading = true, gapMs = MIN_GAP_MS }: { leading?: boolean; gapMs?: number } = {}) {
  const s = stateOf(store, qc)
  const now = Date.now()
  const wait = leading ? Math.max(0, s.last + gapMs - now) : gapMs
  if (s.timer) {
    // The trailing read is due at the EARLIEST time anyone asked for: a 5 s frame is not held behind a 30 s reconnect gap.
    if (now + wait >= s.due) return
    clearTimeout(s.timer)
    s.timer = undefined
  }
  const run = () => {
    s.timer = undefined
    s.last = Date.now()
    void qc.invalidateQueries({ queryKey })
  }
  if (wait === 0) run()
  else {
    s.due = now + wait
    s.timer = setTimeout(run, wait)
  }
}

function cancelThrottled(store: ThrottleStore, qc: QueryClient) {
  const s = store.get(qc)
  if (s?.timer) clearTimeout(s.timer)
  if (s) s.timer = undefined
}

/** Re-reads GET /today, at most once per 5 s: the first call goes now, later ones collapse into one trailing read. */
export function refetchTodayThrottled(qc: QueryClient, opts: { leading?: boolean; gapMs?: number } = {}) {
  invalidateThrottled(todayThrottle, qc, keys.today, opts)
}

/** Drops a pending trailing read (the page that wanted it is gone). */
export function cancelTodayRefetch(qc: QueryClient) {
  cancelThrottled(todayThrottle, qc)
}

/**
 * A `rank.updated` frame. Another day, or a product the cache has never seen, means the cache is out of date: read it again.
 * Otherwise the cached list is reordered by the frame and a verification read follows (the frame is a hint).
 */
export function applyRankFrame(qc: QueryClient, raw: unknown) {
  const frame = parseRankFrame(raw)
  if (!frame) return
  const cached = qc.getQueryData<TodayDto>(keys.today)
  if (!cached || cached.meta.date !== frame.date) {
    refetchTodayThrottled(qc)
    return
  }
  const byId = new Map(cached.data.map((p) => [p.id, p]))
  if (frame.items.some((i) => !byId.has(i.product_id))) {
    refetchTodayThrottled(qc)
    return
  }
  const ordered: ProductDto[] = []
  const seen = new Set<string>()
  for (const item of [...frame.items].sort((a, b) => a.rank - b.rank)) {
    const card = byId.get(item.product_id)
    if (!card || seen.has(card.id)) continue
    seen.add(card.id)
    ordered.push({ ...card, votes_count: isFrameBlocked(card.id) ? card.votes_count : item.votes_count })
  }
  for (const card of cached.data) if (!seen.has(card.id)) ordered.push(card)
  qc.setQueryData<TodayDto>(keys.today, { ...cached, data: ordered.map((c, i) => ({ ...c, rank: i + 1 })) })
  for (const card of ordered) {
    // The same count belongs on the copies in other lists (search, a product page ...).
    if (card.votes_count !== undefined) applyVoteCount(qc, card.id, card.votes_count)
  }
  refetchTodayThrottled(qc, { leading: false })
}

/** Live counts for the products on screen: one public channel per product, `.vote.count`. */
export function useProductLive(ids: readonly string[]) {
  const qc = useQueryClient()
  // Sorted and unique, so a reorder (same products, new positions) keeps the same key and never re-joins a channel.
  const key = [...new Set(ids)].slice(0, MAX_PRODUCT_CHANNELS).sort().join(',')
  useEffect(() => {
    if (!key) return
    const offs = key.split(',').map((id) => subscribeLive(`product.${id}`, '.vote.count', (p) => {
      const frame = parseCount(p)
      // The channel names the product; a payload for another one is not ours to apply.
      if (frame && frame.productId === id) applyVoteCount(qc, frame.productId, frame.count)
    }))
    return () => offs.forEach((off) => off())
  }, [key, qc])
}

const msToUtcMidnight = () => {
  const now = new Date()
  return Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + 1) - now.getTime()
}

/**
 * Today's order: `today` / `.rank.updated`, a re-read after a reconnect and when the UTC day turns. (The 2-minute re-read is
 * the query's `refetchInterval`.) Returns whether the socket is connected, for the "Live" dot.
 */
export function useTodayLive(): boolean {
  const qc = useQueryClient()
  const [connected, setConnected] = useState(false)

  useEffect(() => {
    const off = subscribeLive('today', '.rank.updated', (p) => applyRankFrame(qc, p))
    return () => {
      off()
      cancelTodayRefetch(qc)
    }
  }, [qc])

  useEffect(() => {
    let seen = false
    return subscribeStatus((up) => {
      setConnected(up)
      if (up && seen) refetchTodayThrottled(qc, { gapMs: RECONNECT_GAP_MS })
      if (up) seen = true
    })
  }, [qc])

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout>
    const arm = () => {
      timer = setTimeout(() => {
        void qc.invalidateQueries({ queryKey: keys.today })
        arm()
      }, msToUtcMidnight() + 1000)
    }
    arm()
    return () => clearTimeout(timer)
  }, [qc])

  return connected
}

const MAX_UNREAD_FRAME = 100_000

/** A `notification.count` frame: `unread_count` a safe integer 0..100000, `notification_id` a string (null when notifications were marked read). */
export function parseNotificationFrame(v: unknown): { unreadCount: number; notificationId: string | null } | null {
  if (!isRecord(v)) return null
  const n = v.unread_count
  if (typeof n !== 'number' || !Number.isSafeInteger(n) || n < 0 || n > MAX_UNREAD_FRAME) return null
  if (v.notification_id !== null && typeof v.notification_id !== 'string') return null
  return { unreadCount: n, notificationId: v.notification_id }
}

/** Re-reads the notification lists (bell and page) at most every 5 s. */
export function refetchNotificationsThrottled(qc: QueryClient, opts: { leading?: boolean; gapMs?: number } = {}) {
  invalidateThrottled(notificationsThrottle, qc, keys.notificationsRoot, opts)
}

/**
 * A frame is a hint: a valid one sets the badge number right away and the lists are re-read (throttled). While this browser's own
 * mark-read request is in flight the number is left to that request's result and only re-read.
 */
export function applyNotificationFrame(qc: QueryClient, raw: unknown) {
  const frame = parseNotificationFrame(raw)
  if (!frame) return
  if (qc.isMutating({ mutationKey: MARK_READ_KEY }) > 0) void qc.invalidateQueries({ queryKey: keys.unreadCount })
  else qc.setQueryData<UnreadCountDto>(keys.unreadCount, { unread_count: frame.unreadCount, capped: frame.unreadCount >= UNREAD_CAP })
  refetchNotificationsThrottled(qc)
}

/**
 * The member's private channel `user.{id}`, `.notification.count`; a re-read after a reconnect. Pass null (guest, unverified)
 * to stay off. The 2-minute and focus re-reads are the count query's own.
 */
export function useUserChannel(userId: string | null) {
  const qc = useQueryClient()
  useEffect(() => {
    if (!userId) return
    const off = subscribeLive(`user.${userId}`, '.notification.count', (p) => applyNotificationFrame(qc, p), { private: true })
    let seen = false
    const offStatus = subscribeStatus((up) => {
      if (up && seen) {
        void qc.invalidateQueries({ queryKey: keys.unreadCount })
        refetchNotificationsThrottled(qc, { gapMs: RECONNECT_GAP_MS })
      }
      if (up) seen = true
    })
    return () => {
      off()
      offStatus()
      cancelThrottled(notificationsThrottle, qc)
    }
  }, [userId, qc])
}
