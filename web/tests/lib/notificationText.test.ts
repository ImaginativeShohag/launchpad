import { describe, expect, it } from 'vitest'
import { toNotification } from '@/api/mappers'
import { appHref, cleanParams, notificationText, NOTIFICATION_TYPES } from '@/lib/notificationText'
import { apiNotification } from '../fixtures/notification'

describe('notificationText', () => {
  it('has a line for every notification type the API sends', () => {
    for (const type of NOTIFICATION_TYPES) {
      const t = notificationText(`notifications.${type}`, { product_name: 'Lumen Notes', days: 7, kind: 'comment' })
      expect(t.title, type).not.toBe('You have a new notification')
      expect(t.title).toContain('Lumen Notes')
    }
  })

  it('tells a reply from a comment and pluralises the export deadline', () => {
    expect(notificationText('notifications.new_comment', { product_name: 'X', kind: 'reply' }).title).toBe('New reply on X')
    expect(notificationText('notifications.new_comment', { product_name: 'X', kind: 'comment' }).title).toBe('New comment on X')
    expect(notificationText('notifications.export_ready', { product_name: 'X', days: 1 }).body).toBe('Download it within 1 day.')
    expect(notificationText('notifications.export_ready', { product_name: 'X', days: 7 }).body).toBe('Download it within 7 days.')
    expect(notificationText('notifications.export_ready', { product_name: 'X', days: 'soon' }).body).toBe('Download it from the product dashboard.')
  })

  it('falls back for an unknown, empty or prototype-named key, and for a missing name', () => {
    for (const key of ['notifications.mystery', '', '__proto__', 'constructor', 'toString']) expect(notificationText(key, {})).toEqual({ title: 'You have a new notification', body: '' })
    expect(notificationText('notifications.product_live', {}).title).toBe('your product is live')
    expect(notificationText('notifications.product_live', { product_name: '   ' }).title).toBe('your product is live')
  })

  it('keeps markup as plain characters', () => {
    expect(notificationText('notifications.product_live', { product_name: '<b>x</b>' }).title).toBe('<b>x</b> is live')
  })
})

describe('appHref', () => {
  it('maps the API paths to the web routes', () => {
    expect(appHref('/products/lumen-notes')).toBe('/p/lumen-notes')
    expect(appHref('/me/products/01J123')).toBe('/my/products')
    expect(appHref('/me/products/01J123/dashboard')).toBe('/my/products/01J123/dashboard')
  })

  it('rejects anything that could leave the app', () => {
    for (const bad of ['//evil.com', '//evil.com/x', '/\\evil.com', 'https://evil.com', 'javascript:alert(1)', 'data:text/html,x', 'evil.com', '', ' /x', '/x y', '/x\n', '/a\\b', '\\\\evil.com', '/\u0000', '/products/x?y=1', '/products/x#y', '/p/x', '/staff/review/1', '/notifications', '/me/products/', '/me/products/1/other', '/products/', null, undefined, 5, {}, []]) {
      expect(appHref(bad), String(bad)).toBeNull()
    }
    expect(appHref(`/${'a'.repeat(400)}`)).toBeNull()
  })
})

describe('mapping a row', () => {
  it('survives odd data', () => {
    const n = toNotification({ ...apiNotification(), type: 'mystery', data: { message_key: 5, params: ['x'], href: '//evil.com' } as never })
    expect(n).toMatchObject({ type: 'unknown', messageKey: '', params: {}, href: null, read: false })
    expect(toNotification({ ...apiNotification(), data: null as never }).messageKey).toBe('')
  })

  it('keeps scalar params only and reads read_at', () => {
    expect(cleanParams({ a: 'x', b: 2, c: null, d: { e: 1 }, f: [1], g: true })).toEqual({ a: 'x', b: 2, c: null, g: true })
    const n = toNotification(apiNotification({ read_at: '2026-10-20T10:00:00+00:00' }))
    expect(n.read).toBe(true)
    expect(n.href).toBe('/p/lumen-notes')
  })
})
