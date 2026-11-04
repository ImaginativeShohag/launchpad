import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { keys } from '@/api/keys'
import type { UnreadCountDto } from '@/api/types'
import { dropSession } from '@/session/SessionProvider'
import { toUser } from '@/api/mappers'
import { FALLBACK_MS, SOCKET_DOWN_MS } from '@/api/hooks/useUnreadCount'
import { createQueryClient } from '@/lib/queryClient'
import { applyNotificationFrame, parseNotificationFrame, refetchNotificationsThrottled } from '@/realtime/useLive'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { installFakeEcho, removeFakeEcho } from '../helpers/fakeEcho'
import { apiError, json } from '../helpers/mockApi'
import { renderApp } from '../helpers/renderApp'
import { apiNotification, notificationsBody } from '../fixtures/notification'
import { topicsBody } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const USER = '01JAMINA0000000000000000000'
const CHANNEL = `private-user.${USER}`
const EVENT = '.notification.count'

let echo: ReturnType<typeof installFakeEcho>
beforeEach(() => {
  echo = installFakeEcho()
})
afterEach(() => {
  removeFakeEcho()
  vi.useRealTimers()
})

const base = (n = 0) => ({ 'GET /topics': json(topicsBody()), 'GET /me/follows/topics': json({ data: [] }), 'GET /me/notifications/unread-count': json({ unread_count: n, capped: false }), 'GET /me/notifications': json(notificationsBody([apiNotification()])) })

describe('the private channel', () => {
  it('joins private-user.{id} once for a verified member, even under StrictMode', async () => {
    renderApp({ path: '/topics', user: apiUser(), routes: base() })

    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    expect(echo.factory).toHaveBeenCalledTimes(1)
  })

  it('stays off for a guest and for an unverified member', async () => {
    const guest = renderApp({ path: '/topics', routes: base() })
    await screen.findByRole('link', { name: 'Sign in' })
    guest.unmount()

    renderApp({ path: '/topics', user: apiUser({ email_verified: false }), routes: base() })
    await screen.findByRole('button', { name: /^Notifications/ })

    expect(echo.channels()).toEqual([])
    expect(echo.factory).not.toHaveBeenCalled()
  })

  it('leaves the channel on sign-out', async () => {
    const { client } = renderApp({ path: '/topics', user: apiUser(), routes: base() })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))

    await act(() => dropSession(client))

    await waitFor(() => expect(echo.left).toContain(CHANNEL))
    expect(echo.channels()).toEqual([])
  })

  it('does nothing without Reverb configured (no factory, no key)', async () => {
    removeFakeEcho()
    renderApp({ path: '/topics', user: apiUser(), routes: base(2) })

    expect(await screen.findByRole('button', { name: 'Notifications, 2 unread' })).toBeTruthy()
    expect(echo.channels()).toEqual([])
  })

  it('sets the badge from a valid frame and re-reads the list', async () => {
    const { client, api } = renderApp({ path: '/topics', user: apiUser(), routes: base(1) })
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    const reads = api.callsTo('GET /me/notifications/unread-count').length

    act(() => echo.emit(CHANNEL, EVENT, { unread_count: 5, notification_id: '01JNOTIF000000000000000009' }))

    expect(client.getQueryData<UnreadCountDto>(keys.unreadCount)).toEqual({ unread_count: 5, capped: false })
    expect(await screen.findByRole('button', { name: 'Notifications, 5 unread' })).toBeTruthy()
    // the frame is a hint, not a re-read of the count
    expect(api.callsTo('GET /me/notifications/unread-count')).toHaveLength(reads)
  })

  it('joins once under StrictMode, and again after a real remount (one join per mount, one leave per unmount)', async () => {
    const view = renderApp({ path: '/topics', user: apiUser(), routes: base() })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    // the StrictMode double mount cleaned up before Echo resolved: one join, no leave
    expect(echo.listens.filter((c) => c === CHANNEL)).toHaveLength(1)
    expect(echo.left).toEqual([])
    view.unmount()
    await waitFor(() => expect(echo.left).toEqual([CHANNEL]))

    renderApp({ path: '/topics', user: apiUser(), routes: base() })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    expect(echo.listens.filter((c) => c === CHANNEL)).toHaveLength(2)
    expect(echo.factory).toHaveBeenCalledTimes(1)
  })

  it('leaves the first member’s channel and joins the next one’s when the session changes', async () => {
    const { client } = renderApp({ path: '/topics', user: apiUser(), routes: base() })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    const other = '01JBOB000000000000000000000'

    act(() => client.setQueryData(keys.me, toUser(apiUser({ id: other }))))

    await waitFor(() => expect(echo.count(`private-user.${other}`, EVENT)).toBe(1))
    expect(echo.left).toContain(CHANNEL)
    expect(echo.channels()).toEqual([`private-user.${other}`])
  })

  it('polls the count every minute while the socket is down, every 2 minutes when it is up or Reverb is off', async () => {
    const interval = (client: ReturnType<typeof createQueryClient>) => client.getQueryCache().find({ queryKey: keys.unreadCount })!.observers.map((o) => o.options.refetchInterval)

    const { client } = renderApp({ path: '/topics', user: apiUser(), routes: base(1) })
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    expect(interval(client)).toEqual([FALLBACK_MS])

    act(() => echo.setStatus('unavailable'))
    await waitFor(() => expect(interval(client)).toEqual([SOCKET_DOWN_MS]))

    act(() => echo.setStatus('connected'))
    await waitFor(() => expect(interval(client)).toEqual([FALLBACK_MS]))
  })

  it('keeps the 2-minute poll without Reverb', async () => {
    removeFakeEcho()
    const { client } = renderApp({ path: '/topics', user: apiUser(), routes: base(1) })
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })
    expect(client.getQueryCache().find({ queryKey: keys.unreadCount })!.observers.map((o) => o.options.refetchInterval)).toEqual([FALLBACK_MS])
  })

  it('re-reads the count after a reconnect', async () => {
    const { api } = renderApp({ path: '/topics', user: apiUser(), routes: base(1) })
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    const reads = api.callsTo('GET /me/notifications/unread-count').length

    act(() => echo.setStatus('unavailable'))
    act(() => echo.setStatus('connected'))

    await waitFor(() => expect(api.callsTo('GET /me/notifications/unread-count').length).toBeGreaterThan(reads))
  })
})

describe('notification frames', () => {
  it('accepts the shape the API sends and nothing else', () => {
    expect(parseNotificationFrame({ unread_count: 3, notification_id: 'abc' })).toEqual({ unreadCount: 3, notificationId: 'abc' })
    expect(parseNotificationFrame({ unread_count: 0, notification_id: null })).toEqual({ unreadCount: 0, notificationId: null })
    expect(parseNotificationFrame({ unread_count: 100_000, notification_id: 'x' })).not.toBeNull()
    for (const bad of [null, 5, 'x', [], {}, { unread_count: '3', notification_id: 'a' }, { unread_count: -1, notification_id: 'a' }, { unread_count: 1.5, notification_id: 'a' }, { unread_count: Number.NaN, notification_id: 'a' }, { unread_count: Infinity, notification_id: 'a' }, { unread_count: 100_001, notification_id: 'a' }, { unread_count: 2 ** 60, notification_id: 'a' }, { unread_count: 1, notification_id: 7 }, { unread_count: 1 }]) {
      expect(parseNotificationFrame(bad), JSON.stringify(bad)).toBeNull()
    }
  })

  it('ignores an invalid frame: no change to the count, no re-read', () => {
    const qc = createQueryClient({ retry: false })
    qc.setQueryData<UnreadCountDto>(keys.unreadCount, { unread_count: 2, capped: false })
    const spy = vi.spyOn(qc, 'invalidateQueries')

    applyNotificationFrame(qc, { unread_count: -4, notification_id: 'a' })
    applyNotificationFrame(qc, 'nope')

    expect(qc.getQueryData(keys.unreadCount)).toEqual({ unread_count: 2, capped: false })
    expect(spy).not.toHaveBeenCalled()
  })

  it('marks a count of 100 as capped', () => {
    const qc = createQueryClient({ retry: false })
    applyNotificationFrame(qc, { unread_count: 100, notification_id: 'a' })
    expect(qc.getQueryData(keys.unreadCount)).toEqual({ unread_count: 100, capped: true })
  })

  it('throttles the list re-read: the first goes now, a burst collapses into one trailing read', () => {
    vi.useFakeTimers()
    const qc = createQueryClient({ retry: false })
    const spy = vi.spyOn(qc, 'invalidateQueries')
    const frame = (n: number) => applyNotificationFrame(qc, { unread_count: n, notification_id: 'a' })

    frame(1)
    expect(spy).toHaveBeenCalledTimes(1)
    frame(2)
    frame(3)
    frame(4)
    expect(spy).toHaveBeenCalledTimes(1)
    // the number itself is never throttled
    expect(qc.getQueryData<UnreadCountDto>(keys.unreadCount)?.unread_count).toBe(4)

    vi.advanceTimersByTime(5_000)
    expect(spy).toHaveBeenCalledTimes(2)
    vi.advanceTimersByTime(60_000)
    expect(spy).toHaveBeenCalledTimes(2)
    expect(spy).toHaveBeenLastCalledWith({ queryKey: keys.notificationsRoot })
  })

  it('leaves the number to a mark-read request that is in flight and only re-reads', async () => {
    vi.useFakeTimers()
    const qc = createQueryClient({ retry: false })
    qc.setQueryData<UnreadCountDto>(keys.unreadCount, { unread_count: 1, capped: false })
    const mutation = qc.getMutationCache().build(qc, { mutationKey: ['notifications', 'mark-read'], mutationFn: () => new Promise(() => {}) })
    void mutation.execute(undefined).catch(() => {})
    await vi.advanceTimersByTimeAsync(0)
    const spy = vi.spyOn(qc, 'invalidateQueries')

    applyNotificationFrame(qc, { unread_count: 9, notification_id: 'a' })

    expect(qc.getQueryData<UnreadCountDto>(keys.unreadCount)?.unread_count).toBe(1)
    expect(spy).toHaveBeenCalledWith({ queryKey: keys.unreadCount })
  })
})

describe('throttle and races', () => {
  it('does not hold a 5 s frame behind a 30 s reconnect gap', () => {
    vi.useFakeTimers()
    const qc = createQueryClient({ retry: false })
    const spy = vi.spyOn(qc, 'invalidateQueries')
    applyNotificationFrame(qc, { unread_count: 1, notification_id: 'a' })
    expect(spy).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(1_000)
    refetchNotificationsThrottled(qc, { gapMs: 30_000 })
    applyNotificationFrame(qc, { unread_count: 2, notification_id: 'a' })
    expect(spy).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(4_000)
    expect(spy).toHaveBeenCalledTimes(2)
    vi.advanceTimersByTime(60_000)
    expect(spy).toHaveBeenCalledTimes(2)
  })

  it('keeps the count a live frame set while a mark-read request was in flight, when that request then fails', async () => {
    let server = 2
    let release!: (v: ReturnType<typeof apiError>) => void
    const pending = new Promise<ReturnType<typeof apiError>>((r) => (release = r))
    const plain = (id: string) => apiNotification({ id, data: { message_key: 'notifications.product_live', params: { product_name: 'Plain' } } })
    renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: {
        ...base(),
        'GET /me/notifications/unread-count': () => json({ unread_count: server, capped: false }),
        'GET /me/notifications': json(notificationsBody([plain('01JNOTIF000000000000000001'), plain('01JNOTIF000000000000000002')])),
        'POST /me/notifications/read': () => pending,
      },
    })
    await waitFor(() => expect(echo.count(CHANNEL, EVENT)).toBe(1))
    fireEvent.click((await screen.findAllByRole('button', { name: /Plain is live/ }))[0])
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })

    // two more arrive while the request is out; the server (and the frame) say 4
    server = 4
    act(() => echo.emit(CHANNEL, EVENT, { unread_count: 4, notification_id: 'x' }))
    await screen.findByRole('button', { name: 'Notifications, 4 unread' })
    act(() => release(apiError(500, 'boom')))

    expect(await screen.findByText('Could not mark it as read')).toBeTruthy()
    await waitFor(() => expect(screen.getAllByRole('img', { name: 'Unread' })).toHaveLength(2))
    expect(screen.getByRole('button', { name: 'Notifications, 4 unread' })).toBeTruthy()
  })
})
