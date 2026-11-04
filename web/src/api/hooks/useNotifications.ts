import type { AppNotification } from '@/data/types'
import { keys } from '../keys'
import { toNotification } from '../mappers'
import { useCursorList } from '../pagination'
import type { NotificationDto } from '../types'

/** Pages kept in memory (and re-read by an invalidation); the page stops offering "Load more" at this many so no row is dropped. */
export const MAX_NOTIFICATION_PAGES = 5

/** GET /me/notifications, newest first; `unread` sends `unread=1`. The bell asks for 5, the page for 20 and loads more by cursor. */
export function useNotifications({ unread = false, perPage = 20, enabled = true }: { unread?: boolean; perPage?: number; enabled?: boolean } = {}) {
  return useCursorList<NotificationDto, AppNotification>(keys.notifications({ unread }), '/me/notifications', { per_page: perPage, unread: unread ? 1 : undefined }, { enabled, map: toNotification, staleTime: 0, maxPages: MAX_NOTIFICATION_PAGES })
}
