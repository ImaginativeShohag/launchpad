import type { NotificationDto } from '@/api/types'

export const NOTIF_PRODUCT_ID = '01JPROD0000000000000000001'

/** A `GET /me/notifications` row. `data` is replaced as a whole when passed. */
export function apiNotification(over: Partial<NotificationDto> = {}): NotificationDto {
  return {
    id: '01JNOTIF000000000000000001',
    type: 'product_live',
    data: { message_key: 'notifications.product_live', product_id: NOTIF_PRODUCT_ID, params: { product_name: 'Lumen Notes' }, href: '/products/lumen-notes' },
    read_at: null,
    created_at: new Date().toISOString(),
    ...over,
  }
}

export const notificationsBody = (rows: NotificationDto[], next: string | null = null) => ({ data: rows, links: { next: next ? `http://api.test/api/v1/me/notifications?cursor=${next}` : null, prev: null }, meta: { next_cursor: next, per_page: 20 } })
