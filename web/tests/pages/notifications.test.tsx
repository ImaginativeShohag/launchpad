import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { apiError, json, type MockResult, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { apiNotification, notificationsBody } from '../fixtures/notification'
import { topicsBody } from '../fixtures/product'
import { keys } from '@/api/keys'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const N1 = '01JNOTIF000000000000000001'
const N2 = '01JNOTIF000000000000000002'
const N3 = '01JNOTIF000000000000000003'
const unread = apiNotification({ id: N1 })
const read = apiNotification({ id: N2, type: 'review_approved', data: { message_key: 'notifications.review_approved', params: { product_name: 'Pixel Pal' }, href: '/me/products/01JP' }, read_at: '2026-10-20T10:00:00+00:00' })

/** A row without a link renders as a button, so a click marks it read and stays on the page. */
const plain = (id: string) => apiNotification({ id, data: { message_key: 'notifications.product_live', params: { product_name: 'Plain' } } })

const count = (n: number, capped = false) => json({ unread_count: n, capped })
const shell = { 'GET /topics': json(topicsBody()), 'GET /me/follows/topics': json({ data: [] }) }

function deferred() {
  let release!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (release = r))
  return { promise, release }
}

describe('the bell', () => {
  it('shows no badge for zero, the number for a few, 99+ when capped', async () => {
    const zero = renderApp({ path: '/topics', user: apiUser(), routes: { ...shell, 'GET /me/notifications/unread-count': count(0) } })
    expect(await screen.findByRole('button', { name: 'Notifications, 0 unread' })).toBeTruthy()
    expect(zero.api.callsTo('GET /me/notifications/unread-count').length).toBeGreaterThanOrEqual(1)
    zero.unmount()

    renderApp({ path: '/topics', user: apiUser(), routes: { ...shell, 'GET /me/notifications/unread-count': count(7) } })
    expect(await screen.findByRole('button', { name: 'Notifications, 7 unread' })).toBeTruthy()
    expect(screen.getByText('7')).toBeTruthy()
  })

  it('reads 99+ for a capped count (the API stops at 100)', async () => {
    renderApp({ path: '/topics', user: apiUser(), routes: { ...shell, 'GET /me/notifications/unread-count': count(100, true) } })

    const bell = await screen.findByRole('button', { name: 'Notifications, 99+ unread' })
    expect(within(bell).getByText('99+')).toBeTruthy()
  })

  it('is hidden for a guest, which makes no notification request', async () => {
    const { api } = renderApp({ path: '/topics', routes: shell })

    await screen.findByRole('link', { name: 'Sign in' })
    expect(screen.queryByRole('button', { name: /^Notifications/ })).toBeNull()
    expect(api.calls.filter((c) => c.path.startsWith('/me/notifications'))).toHaveLength(0)
  })

  it('re-reads the count when the window regains focus', async () => {
    let n = 1
    const { api } = renderApp({ path: '/topics', user: apiUser(), routes: { ...shell, 'GET /me/notifications/unread-count': () => count(n) } })
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })

    n = 4
    fireEvent.focus(window)
    window.dispatchEvent(new Event('visibilitychange'))

    expect(await screen.findByRole('button', { name: 'Notifications, 4 unread' })).toBeTruthy()
    expect(api.callsTo('GET /me/notifications/unread-count').length).toBeGreaterThanOrEqual(2)
  })

  it('lists the latest 5 in the popover and marks one read on click, then opens its page', async () => {
    const hold = deferred()
    const { api, router } = renderApp({
      path: '/topics',
      user: apiUser(),
      routes: {
        ...shell,
        'GET /me/notifications/unread-count': count(1),
        'GET /me/notifications': json(notificationsBody([unread, read])),
        'POST /me/notifications/read': () => hold.promise,
      },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }))
    const item = await screen.findByRole('link', { name: /Lumen Notes is live/ })
    expect(api.callsTo('GET /me/notifications')[0].query.get('per_page')).toBe('5')
    expect(screen.getByRole('link', { name: /Pixel Pal was approved/ }).getAttribute('href')).toBe('/my/products')

    fireEvent.click(item)

    await waitFor(() => expect(router.state.location.pathname).toBe('/p/lumen-notes'))
    expect(await screen.findByRole('button', { name: 'Notifications, 0 unread' })).toBeTruthy()
    expect(api.callsTo('POST /me/notifications/read')[0].body).toEqual({ ids: [N1] })
    hold.release(json({ marked: 1, unread_count: 0 }))
  })

  it('toasts and rolls back even when the click already closed the popover', async () => {
    const hold = deferred()
    renderApp({
      path: '/topics',
      user: apiUser(),
      routes: { ...shell, 'GET /me/notifications/unread-count': count(1), 'GET /me/notifications': json(notificationsBody([plain(N1)])), 'POST /me/notifications/read': () => hold.promise },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }))
    fireEvent.click(await screen.findByRole('button', { name: /Plain is live/ }))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(await screen.findByRole('button', { name: 'Notifications, 0 unread' })).toBeTruthy()

    hold.release(apiError(500, 'boom'))

    expect(await screen.findByText('Could not mark it as read')).toBeTruthy()
    expect(await screen.findByRole('button', { name: 'Notifications, 1 unread' })).toBeTruthy()
  })

  it('is a labelled dialog: focus goes in on open, Esc closes and returns focus, Tab out closes', async () => {
    renderApp({ path: '/topics', user: apiUser(), routes: { ...shell, 'GET /me/notifications/unread-count': count(1), 'GET /me/notifications': json(notificationsBody([plain(N1)])) } })

    const bell = await screen.findByRole('button', { name: 'Notifications, 1 unread' })
    expect(bell.getAttribute('aria-haspopup')).toBe('dialog')
    expect(bell.getAttribute('aria-expanded')).toBe('false')
    bell.focus()
    fireEvent.click(bell)

    const dialog = await screen.findByRole('dialog', { name: 'Notifications' })
    expect(bell.getAttribute('aria-expanded')).toBe('true')
    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true))

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(document.activeElement).toBe(bell)

    fireEvent.click(bell)
    await screen.findByRole('dialog', { name: 'Notifications' })
    const outside = screen.getAllByRole('link', { name: 'Today' })[0]
    act(() => outside.focus())
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(document.activeElement).toBe(outside)
  })

  it('announces a change of the count politely, but not the first load', async () => {
    let n = 1
    renderApp({ path: '/topics', user: apiUser(), routes: { ...shell, 'GET /me/notifications/unread-count': () => count(n) } })
    await screen.findByRole('button', { name: 'Notifications, 1 unread' })
    const status = screen.getAllByRole('status').find((el) => el.className.includes('sr-only'))!
    expect(status.textContent).toBe('')
    expect(status.getAttribute('aria-live')).toBe('polite')

    n = 3
    fireEvent.focus(window)
    window.dispatchEvent(new Event('visibilitychange'))

    await waitFor(() => expect(status.textContent).toBe('3 unread notifications'))
  })

  it('says all caught up when empty, and offers Try again after a failed read', async () => {
    let fail = true
    renderApp({
      path: '/topics',
      user: apiUser(),
      routes: { ...shell, 'GET /me/notifications/unread-count': count(0), 'GET /me/notifications': () => (fail ? apiError(500, 'x') : json(notificationsBody([]))) },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Notifications, 0 unread' }))
    const retry = await screen.findByRole('button', { name: 'Try again' })
    fail = false
    fireEvent.click(retry)

    expect(await screen.findByText('You’re all caught up ✨')).toBeTruthy()
  })

  it('marks all read from the popover', async () => {
    let n = 2
    const { api } = renderApp({
      path: '/topics',
      user: apiUser(),
      routes: {
        ...shell,
        'GET /me/notifications/unread-count': () => count(n),
        'GET /me/notifications': json(notificationsBody([unread, apiNotification({ id: N3 })])),
        'POST /me/notifications/read': () => ((n = 0), json({ marked: 2, unread_count: 0 })),
      },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Notifications, 2 unread' }))
    await screen.findAllByRole('link', { name: /Lumen Notes is live/ })
    fireEvent.click(screen.getByRole('button', { name: /Mark all read/ }))

    await waitFor(() => expect(api.callsTo('POST /me/notifications/read')[0].body).toEqual({ all: true }))
    expect(await screen.findByRole('button', { name: 'Notifications, 0 unread' })).toBeTruthy()
  })
})

describe('the notifications page', () => {
  const page = (extra: Record<string, RouteValue> = {}) => ({
    ...shell,
    'GET /me/notifications/unread-count': count(1),
    'GET /me/notifications': json(notificationsBody([unread, read])),
    ...extra,
  })

  it('renders each row from its message key and params, with the right link', async () => {
    renderApp({ path: '/notifications', user: apiUser(), routes: page() })

    const live = await screen.findByRole('link', { name: /Lumen Notes is live/ })
    expect(live.getAttribute('href')).toBe('/p/lumen-notes')
    expect(screen.getByRole('link', { name: /Pixel Pal was approved/ }).getAttribute('href')).toBe('/my/products')
    expect(await screen.findByText(/You have/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'email settings' }).getAttribute('href')).toBe('/settings?tab=email')
  })

  it('renders params as text, never as markup', async () => {
    const evil = apiNotification({ id: N3, data: { message_key: 'notifications.product_live', params: { product_name: '<img src=x onerror=alert(1)>' }, href: '/products/x' } })
    const { container } = renderApp({ path: '/notifications', user: apiUser(), routes: page({ 'GET /me/notifications': json(notificationsBody([evil])) }) })

    expect(await screen.findByText('<img src=x onerror=alert(1)> is live')).toBeTruthy()
    expect(container.querySelector('main img[src="x"]')).toBeNull()
  })

  it('shows a generic line for an unknown message key and for a type this build does not know', async () => {
    const odd = apiNotification({ id: N3, type: 'future_thing', data: { message_key: 'notifications.future_thing', params: { product_name: 'Nope' }, href: '/products/x' } })
    renderApp({ path: '/notifications', user: apiUser(), routes: page({ 'GET /me/notifications': json(notificationsBody([odd])) }) })

    expect(await screen.findByText('You have a new notification')).toBeTruthy()
    expect(screen.queryByText(/Nope/)).toBeNull()
  })

  it('never links to an address that leaves the app', async () => {
    const rows = [
      apiNotification({ id: N1, data: { message_key: 'notifications.product_live', params: { product_name: 'Slash' }, href: '//evil.com/x' } }),
      apiNotification({ id: N2, data: { message_key: 'notifications.product_live', params: { product_name: 'Script' }, href: 'javascript:alert(1)' } }),
      apiNotification({ id: N3, data: { message_key: 'notifications.product_live', params: { product_name: 'Absolute' }, href: 'https://evil.com' } }),
    ]
    const { router } = renderApp({ path: '/notifications', user: apiUser(), routes: page({ 'GET /me/notifications': json(notificationsBody(rows)), 'POST /me/notifications/read': json({ marked: 1, unread_count: 0 }) }) })

    const slash = await screen.findByRole('button', { name: /Slash is live/ })
    expect(screen.queryAllByRole('link').filter((a) => /evil|javascript/.test(a.getAttribute('href') ?? ''))).toHaveLength(0)
    expect(screen.getByRole('button', { name: /Script is live/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /Absolute is live/ })).toBeTruthy()

    fireEvent.click(slash)
    expect(router.state.location.pathname).toBe('/notifications')
  })

  it('renders anything but the three known API paths as a row without a link', async () => {
    const hrefs = ['/products/lumen?x=1', '/products/lumen#top', '/p/lumen', '/staff/review/1', '/notifications', '/me/products/1/other']
    const rows = hrefs.map((href, i) => apiNotification({ id: `01JNOTIF00000000000000010${i}`, data: { message_key: 'notifications.product_live', params: { product_name: `Row${i}` }, href } }))
    renderApp({ path: '/notifications', user: apiUser(), routes: page({ 'GET /me/notifications': json(notificationsBody(rows)) }) })

    for (let i = 0; i < hrefs.length; i++) expect(await screen.findByRole('button', { name: new RegExp(`Row${i} is live`) })).toBeTruthy()
    expect(screen.queryByRole('link', { name: /is live/ })).toBeNull()
  })

  it('stops offering Load more at 5 pages and re-reads no more than 5 pages', async () => {
    const { api, client } = renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: page({
        'GET /me/notifications': ((call: { query: URLSearchParams }) => {
          const at = Number(call.query.get('cursor') ?? 0)
          return json(notificationsBody([plain(`01JNOTIF0000000000000002${String(at).padStart(2, '0')}`)], `${at + 1}`))
        }) as never,
      }),
    })

    for (let i = 1; i <= 4; i++) {
      fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))
      await waitFor(() => expect(screen.getAllByRole('button', { name: /Plain is live/ })).toHaveLength(i + 1))
    }
    expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull()

    const before = api.callsTo('GET /me/notifications').length
    await act(() => client.invalidateQueries({ queryKey: keys.notificationsRoot }))

    await waitFor(() => expect(api.callsTo('GET /me/notifications').length).toBe(before + 5))
    await new Promise((r) => setTimeout(r, 100))
    expect(api.callsTo('GET /me/notifications').length).toBe(before + 5)
  })

  it('loads more with the cursor, and filters with unread=1', async () => {
    const { api } = renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: page({
        'GET /me/notifications': (call: { query: URLSearchParams }) =>
          json(call.query.get('unread') === '1' ? notificationsBody([unread]) : call.query.get('cursor') === 'c2' ? notificationsBody([apiNotification({ id: N3, data: { message_key: 'notifications.launch_reminder', params: { product_name: 'Formcraft' }, href: '/me/products/1' } })]) : notificationsBody([unread, read], 'c2')),
      }),
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('Formcraft launches tomorrow')).toBeTruthy()
    expect(api.calls.some((c) => c.path === '/me/notifications' && c.query.get('cursor') === 'c2')).toBe(true)
    expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull()

    fireEvent.click(screen.getByRole('tab', { name: /Unread/ }))

    await waitFor(() => expect(api.calls.some((c) => c.path === '/me/notifications' && c.query.get('unread') === '1')).toBe(true))
    await waitFor(() => expect(screen.queryByText('Pixel Pal was approved')).toBeNull())
    expect(screen.getByText('Lumen Notes is live')).toBeTruthy()
  })

  it('shows Inbox zero on an empty Unread tab and an error with Retry', async () => {
    let fail = true
    renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: page({ 'GET /me/notifications': ((call: { query: URLSearchParams }) => (fail ? apiError(500, 'x') : json(notificationsBody(call.query.get('unread') ? [] : [read])))) }),
    })

    const retry = await screen.findByRole('button', { name: 'Retry' })
    fail = false
    fireEvent.click(retry)
    await screen.findByText('Pixel Pal was approved')
    fireEvent.click(screen.getByRole('tab', { name: /Unread/ }))

    expect(await screen.findByText('Inbox zero')).toBeTruthy()
  })

  it('marks one read optimistically (only that row and the count), then keeps it', async () => {
    const hold = deferred()
    const { api } = renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: page({ 'GET /me/notifications/unread-count': count(2), 'GET /me/notifications': json(notificationsBody([plain(N1), plain(N3)])), 'POST /me/notifications/read': (() => hold.promise) }),
    })

    const rows = await screen.findAllByRole('button', { name: /Plain is live/ })
    expect(screen.getAllByLabelText('Unread')).toHaveLength(2)
    fireEvent.click(rows[0])

    await waitFor(() => expect(screen.getAllByLabelText('Unread')).toHaveLength(1))
    expect(await screen.findByText('1 unread', { selector: 'span.font-semibold' })).toBeTruthy()
    expect(api.callsTo('POST /me/notifications/read')[0].body).toEqual({ ids: [N1] })
    hold.release(json({ marked: 1, unread_count: 1 }))
    await waitFor(() => expect(api.callsTo('GET /me/notifications/unread-count').length).toBeGreaterThanOrEqual(2))
  })

  it('puts back only the touched row and the count when marking one fails', async () => {
    const { api } = renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: page({
        'GET /me/notifications/unread-count': count(2),
        'GET /me/notifications': json(notificationsBody([plain(N1), plain(N3), read])),
        'POST /me/notifications/read': apiError(500, 'boom'),
      }),
    })

    fireEvent.click((await screen.findAllByRole('button', { name: /Plain is live/ }))[0])

    expect(await screen.findByText('Could not mark it as read')).toBeTruthy()
    await waitFor(() => expect(screen.getAllByLabelText('Unread')).toHaveLength(2))
    expect(screen.getByText('2 unread', { selector: 'span.font-semibold' })).toBeTruthy()
    expect(api.callsTo('POST /me/notifications/read')).toHaveLength(1)
  })

  it('marks all read, and rolls that back when it fails', async () => {
    let ok = false
    let n = 2
    const { api } = renderApp({
      path: '/notifications',
      user: apiUser(),
      routes: page({
        'GET /me/notifications/unread-count': (() => count(n)),
        'GET /me/notifications': (() => json(notificationsBody([plain(N1), plain(N3)].map((r) => (n === 0 ? { ...r, read_at: '2026-10-22T10:00:00+00:00' } : r))))),
        'POST /me/notifications/read': (() => (ok ? ((n = 0), json({ marked: 2, unread_count: 0 })) : apiError(500, 'boom'))),
      }),
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Mark all read' }))
    expect(await screen.findByText('Could not mark all as read')).toBeTruthy()
    await waitFor(() => expect(screen.getAllByLabelText('Unread')).toHaveLength(2))
    expect(screen.getByText('2 unread', { selector: 'span.font-semibold' })).toBeTruthy()

    ok = true
    fireEvent.click(screen.getByRole('button', { name: 'Mark all read' }))
    await waitFor(() => expect(screen.queryAllByLabelText('Unread')).toHaveLength(0))
    expect(api.callsTo('POST /me/notifications/read').map((c) => c.body)).toEqual([{ all: true }, { all: true }])
    expect(await screen.findByText('You’re all caught up')).toBeTruthy()
  })

  it('sends a guest to sign in without calling the notification routes', async () => {
    const { api, router } = renderApp({ path: '/notifications', routes: shell })

    await waitFor(() => expect(router.state.location.pathname).toBe('/signin'))
    expect(api.calls.filter((c) => c.path.startsWith('/me/notifications'))).toHaveLength(0)
  })
})
