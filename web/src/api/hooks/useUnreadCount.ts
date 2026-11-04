import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { subscribeStatus } from '@/realtime/echo'
import { getUnreadCount } from '../endpoints/notifications'
import { keys } from '../keys'
import type { UnreadCountDto } from '../types'

/** The API stops counting at this many; the badge then reads "99+". */
export const UNREAD_CAP = 100
export const FALLBACK_MS = 2 * 60_000
/** Used instead while Reverb is configured but the socket is down. */
export const SOCKET_DOWN_MS = 60_000

/** Safe integer 0..100000, otherwise 0 (a bad body must not print NaN on the badge). */
export function normalizeCount(dto: Partial<UnreadCountDto> | null | undefined): UnreadCountDto {
  const n = dto?.unread_count
  const count = typeof n === 'number' && Number.isSafeInteger(n) && n >= 0 ? Math.min(n, 100_000) : 0
  return { unread_count: count, capped: dto?.capped === true || count >= UNREAD_CAP }
}

/** "99+" once the count is capped or above 99. */
export function unreadLabel(c: UnreadCountDto | undefined): string {
  if (!c) return '0'
  return c.capped || c.unread_count > 99 ? '99+' : String(c.unread_count)
}

/**
 * True only when Echo is configured and its socket reports "not connected". With Reverb off nothing is reported, so this stays
 * false and the plain 2-minute re-read applies.
 */
function useSocketDown(on: boolean): boolean {
  const [down, setDown] = useState(false)
  useEffect(() => {
    if (!on) return
    const off = subscribeStatus((up) => setDown(!up))
    return () => {
      off()
      setDown(false)
    }
  }, [on])
  return down
}

/**
 * The bell's number: re-read on focus and every 2 minutes (every minute while the live socket is down); live frames patch it in
 * between. `live` says this caller is a member who also listens on the socket (it is what the poll rate follows).
 */
export function useUnreadCount(enabled = true, live = false) {
  const down = useSocketDown(enabled && live)
  return useQuery({
    queryKey: keys.unreadCount,
    queryFn: async ({ signal }) => normalizeCount(await getUnreadCount(signal)),
    enabled,
    staleTime: 0,
    refetchOnWindowFocus: true,
    refetchInterval: down ? SOCKET_DOWN_MS : FALLBACK_MS,
  })
}
