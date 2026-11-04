import type { NotificationType } from '@/data/types'

export const NOTIFICATION_TYPES: readonly NotificationType[] = ['review_approved', 'review_rejected', 'review_changes_requested', 'launch_reminder', 'product_live', 'new_comment', 'export_ready']

export const isNotificationType = (v: unknown): v is NotificationType => typeof v === 'string' && (NOTIFICATION_TYPES as readonly string[]).includes(v)

export type NotificationParams = Record<string, string | number | boolean | null>

/** Keeps scalar values only; the API already sends nothing else, but a bad row must not reach the renderer. */
export function cleanParams(raw: unknown): NotificationParams {
  const out: NotificationParams = {}
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return out
  for (const [k, v] of Object.entries(raw)) {
    if (v === null || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') out[k] = typeof v === 'string' ? v.slice(0, 300) : v
  }
  return out
}

const SLUG = '[A-Za-z0-9][A-Za-z0-9_-]{0,99}'
const ID = '[A-Za-z0-9-]{1,64}'

/**
 * The API emits exactly three paths (`NotificationDrafts`): `/products/{slug}`, `/me/products/{id}` and
 * `/me/products/{id}/dashboard`. Only those become links, mapped to their web routes. Anything else (a query or fragment, a web
 * path, `//host`, a scheme, a backslash) gives null and the row is not a link.
 */
export function appHref(raw: unknown): string | null {
  if (typeof raw !== 'string' || raw.length > 300) return null
  const product = new RegExp(`^/products/(${SLUG})$`).exec(raw)
  if (product) return `/p/${product[1]}`
  const dashboard = new RegExp(`^/me/products/(${ID})/dashboard$`).exec(raw)
  if (dashboard) return `/my/products/${dashboard[1]}/dashboard`
  if (new RegExp(`^/me/products/${ID}$`).test(raw)) return '/my/products'
  return null
}

export interface NotificationText {
  title: string
  body: string
}

const GENERIC: NotificationText = { title: 'You have a new notification', body: '' }

const name = (p: NotificationParams) => (typeof p.product_name === 'string' && p.product_name.trim() ? p.product_name : 'your product')
const days = (p: NotificationParams) => (typeof p.days === 'number' && Number.isSafeInteger(p.days) && p.days > 0 && p.days <= 365 ? p.days : null)

/** One entry per `message_key` the API sends (`notifications.<type>`). Plain strings: the caller renders them as text. */
const catalog: Record<string, (p: NotificationParams) => NotificationText> = {
  'notifications.review_approved': (p) => ({ title: `${name(p)} was approved`, body: 'Choose a launch day when you are ready.' }),
  'notifications.review_rejected': (p) => ({ title: `${name(p)} was not approved`, body: 'Open the product to read the reviewer’s note.' }),
  'notifications.review_changes_requested': (p) => ({ title: `${name(p)} needs changes`, body: 'A reviewer left a note. Update the product and send it again.' }),
  'notifications.launch_reminder': (p) => ({ title: `${name(p)} launches tomorrow`, body: 'Get your launch post and team ready.' }),
  'notifications.product_live': (p) => ({ title: `${name(p)} is live`, body: 'Your product is on the front page today.' }),
  'notifications.new_comment': (p) =>
    p.kind === 'reply' ? { title: `New reply on ${name(p)}`, body: 'Someone replied to your comment.' } : { title: `New comment on ${name(p)}`, body: 'Someone commented on your product.' },
  'notifications.export_ready': (p) => {
    const d = days(p)
    return { title: `Your export of ${name(p)} is ready`, body: d ? `Download it within ${d} ${d === 1 ? 'day' : 'days'}.` : 'Download it from the product dashboard.' }
  },
}

export function notificationText(messageKey: string, params: NotificationParams): NotificationText {
  if (!Object.hasOwn(catalog, messageKey)) return GENERIC
  return catalog[messageKey](params)
}
