import { StrictMode } from 'react'
import { act, render } from '@testing-library/react'
import { createMemoryRouter, matchRoutes, RouterProvider, type RouteObject } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

const DRAFT_ID = '01JSMOKE000000000000000001'
const PENDING_ID = '01JSMOKE000000000000000002'
const LIVE_ID = '01JSMOKE000000000000000003'
const SCHEDULED_ID = '01JSMOKE000000000000000004'
const STAFF_ID = '01JSMOKE000000000000000005'

// Every route of routes.tsx, visited with the route-table fetch mock (no legacy store is left). A test below fails when a route is missing here.
const allPaths = [
  '/signin', '/signup', '/verify-email', '/forgot-password', '/reset-password', '/settings', '/settings?tab=account', '/settings?tab=email', '/confirm-email-change', '/cancel-email-change', '/unsubscribe',
  // discovery (phase 2)
  '/', '/leaderboard', '/leaderboard?range=month', '/leaderboard?range=all&topic=ai', '/topics', '/topics/ai', '/topics/ai?sort=newest', '/topics/nope', '/search', '/search?q=lumen', '/search?topic=ai&sort=newest', '/search?q=x', '/feed',
  // product page, profiles (phase 3)
  '/p/lumen-notes', '/p/missing', '/u/rafi', '/u/sofia', '/u/nobody', '/u/banned',
  // account
  '/notifications',
  // staff area (phase 6)
  '/staff', `/staff/review/${STAFF_ID}`, '/staff/review/01JNOBODY00000000000000000', '/staff/reports', `/staff/reports/comment/${STAFF_ID}`, `/staff/reports/product/${STAFF_ID}`, '/staff/reports/widget/x', '/staff/products', `/staff/products/${STAFF_ID}/integrity`,
  '/staff/risk', `/staff/risk/members/${STAFF_ID}`, '/staff/members', '/staff/audit',
  '/submit', `/submit/${DRAFT_ID}`, `/submit/${PENDING_ID}`, '/submit/01JNOBODY00000000000000000', '/submit/not-an-id', '/my/products', `/my/products/${LIVE_ID}/dashboard`, `/my/products/${SCHEDULED_ID}/dashboard`, '/my/products/01JNOBODY00000000000000000/dashboard', '/my/products/not-an-id/dashboard',
]

afterEach(() => vi.useRealTimers())

const publicUserToFollowed = (u: { id: string; handle: string; name: string }) => ({ id: u.id, handle: u.handle, name: u.name, headline: null, avatar_url: null, is_suspended: false, followed_at: '2026-10-20T10:00:00+00:00' })

async function run() {
  vi.resetModules()
  localStorage.clear()
  const errors: string[] = []
  const spy = vi.spyOn(console, 'error').mockImplementation((...a: unknown[]) => errors.push(a.map(String).join(' ').slice(0, 400)))
  const { json, apiError, mockApi, noContent, takeUnmatched, validation } = await import('./helpers/mockApi')
  const { apiUser, apiPrefs, publicUser } = await import('./fixtures/user')
  const { apiNotification, notificationsBody } = await import('./fixtures/notification')
  const { apiComment, commentsBody } = await import('./fixtures/comment')
  const { followedUsersBody, profileBody } = await import('./fixtures/profile')
  const { addDays, today } = await import('@/lib/date')
  const { completeDraft, founderProduct, listRow, myProductsBody } = await import('./fixtures/founder')
  const dash = await import('./fixtures/dashboard')
  const st = await import('./fixtures/staff')
  const { apiProduct, dayBody, leaderboardBody, memberReason, pageBody, rankedList, todayBody, topicsBody } = await import('./fixtures/product')
  // A fake Echo, so the live hooks (Today, products, the private notification channel) run too.
  const { installFakeEcho, removeFakeEcho } = await import('./helpers/fakeEcho')
  const echo = installFakeEcho()
  const { createQueryClient } = await import('@/lib/queryClient')
  const { AppProviders } = await import('@/AppProviders')
  mockApi({
    // An admin session, so the staff and member pages open.
    'GET /me': json({ data: apiUser({ role: 'admin', id: '01JSOFIA0000000000000000000', handle: 'sofia', name: 'Sofia Marques' }) }),
    'GET /me/email-preferences': json(apiPrefs()),
    'GET /me/notifications/unread-count': json({ unread_count: 3, capped: false }),
    'GET /me/notifications': json(notificationsBody([apiNotification(), apiNotification({ id: '01JNOTIF000000000000000002', type: 'new_comment', data: { message_key: 'notifications.mystery', params: {} }, read_at: '2026-10-20T10:00:00+00:00' })], 'next')),
    'POST /auth/email/verify': apiError(403, 'Invalid signature.'),
    'POST /auth/email/change/confirm': apiError(422, 'This link is invalid or has expired.'),
    'POST /auth/email/change/cancel': json({ message: 'There is no pending email change.', code: 'no_pending_change' }),
    'POST /email/unsubscribe': apiError(403, 'Invalid signature.'),
    // discovery
    'GET /today': json(todayBody(rankedList(3))),
    'GET /days/:date': (_call, params) => json(dayBody(rankedList(2, () => ({ launch_date: params.date })), params.date)),
    'GET /leaderboards': json(leaderboardBody(rankedList(5))),
    'GET /topics': json(topicsBody([{}, {}])),
    'GET /search': (call) => (call.query.get('q') === 'x' ? validation({ q: ['Enter at least two letters or digits.'] }) : json(pageBody([apiProduct()], 'next', { query: call.query.get('q') ?? null }))),
    'GET /me/feed': json({ ...pageBody([{ ...apiProduct(), reasons: [memberReason()] }]), meta: { next_cursor: null, has_follows: true } }),
    // community (phase 3)
    'GET /products/:slug': (_call, params) => (params.slug === 'missing' ? apiError(404, 'Not found.') : json({ data: apiProduct({ slug: params.slug, topics: [{ id: '01JTOPIC0000000000000000000', slug: 'ai', name: 'AI', emoji: '✨', description: null, position: 1 }] }) })),
    'GET /products/:slug/comments': json(commentsBody([apiComment({ replies_count: 4, replies: [apiComment()] }), apiComment({ state: 'deleted', body: null, author: null })], 'next')),
    'GET /comments/:id/replies': json(commentsBody([apiComment()])),
    'POST /products/:id/view': noContent(),
    'GET /users/:handle': (_call, params) =>
      params.handle === 'nobody' ? apiError(404, 'Not found.') : json(params.handle === 'banned' ? profileBody({ handle: 'banned', is_suspended: true, headline: null, links: [] }) : profileBody({ handle: params.handle })),
    'GET /users/:handle/products': json(pageBody([apiProduct()], 'next')),
    'GET /me/follows/users': json(followedUsersBody([publicUserToFollowed(publicUser())])),
    'GET /me/follows/topics': json({ data: topicsBody().data }),
    // staff area (phase 6)
    'GET /staff/review-queue': json(st.page([st.staffProduct({ id: STAFF_ID })], 'next')),
    'GET /staff/products': json(st.page([st.staffRow({ id: STAFF_ID }), st.staffRow({ status: 'taken_down', takedown_reason: 'Scam' })], 'next')),
    'GET /staff/products/:id': (_call, params) => (params.id === STAFF_ID ? json({ data: st.staffProduct({ id: STAFF_ID, reviews: [{ id: 'r1', decision: 'changes_requested', note: 'Add screenshots', created_at: '2026-10-20T10:00:00+00:00' }] }) }) : apiError(404, 'Not found.')),
    'GET /staff/products/:id/vote-integrity': json({ data: st.integrity() }),
    'GET /staff/products/:id/votes': json(st.page([st.staffVote()], 'next')),
    'GET /staff/reports': json(st.reportsBody([st.reportRow(), st.reportRow({ type: 'product', target: { id: STAFF_ID, name: 'Crypto Moon', status: 'live', maker: { id: 'u3', handle: 'lee' } } })], { total_targets: 120, truncated: true })),
    'GET /staff/reports/history': json(st.page([st.historyRow()], 'next')),
    'GET /staff/reports/:type/:id': (_call, params) => (params.type === 'comment' ? json({ data: st.commentReport(params.id) }) : json({ data: st.productReport(params.id) })),
    'GET /staff/risk-signals': json(st.page([st.riskSignal()], 'next')),
    'GET /staff/risk-signals/:id': json({ data: st.signalDetail() }),
    'GET /staff/risk/members': json(st.page([st.riskMember()], 'next')),
    'GET /staff/risk/members/:id': json({ data: st.memberSummary() }),
    'GET /admin/users': json(st.page([st.adminMember(), st.adminMember({ is_suspended: true, suspended_reason: 'Vote ring' })], 'next')),
    'GET /admin/audit-logs': json(st.page([st.auditRow(), st.auditRow({ action: 'odd.action', meta: { nested: { a: 1 } } })], 'next')),
    // founder area (phase 4)
    ...(() => {
      const mine = [completeDraft({ id: DRAFT_ID }), founderProduct({ id: PENDING_ID, status: 'pending' }), founderProduct({ id: LIVE_ID, status: 'live', launch_date: today() }), founderProduct({ id: SCHEDULED_ID, status: 'scheduled', launch_date: addDays(today(), 3) })]
      return {
        'GET /me/products': json(myProductsBody(mine.map(listRow))),
        'GET /me/products/:id': (_call: unknown, params: Record<string, string>) => {
          const found = mine.find((p) => p.id === params.id)
          return found ? json({ data: found }) : apiError(404, 'Not found.')
        },
        'POST /products': json({ data: founderProduct() }, 201),
        'GET /me/products/:id/dashboard': (_call: unknown, params: Record<string, string>) =>
          params.id === LIVE_ID
            ? json(dash.summary({ product_id: LIVE_ID }))
            : params.id === SCHEDULED_ID
              ? json(dash.summary({ product_id: SCHEDULED_ID, phase: 'before_launch', status: 'scheduled' }))
              : apiError(404, 'Not found.'),
        'GET /me/products/:id/dashboard/series': json(dash.series(dash.hourly([4, 2]))),
        'GET /me/products/:id/dashboard/rank': json(dash.rank()),
        'GET /me/products/:id/dashboard/referrers': json(dash.referrers()),
        'GET /me/products/:id/exports': json({ data: [] }),
      }
    })(),
  })
  const { router: real } = await import('@/routes')
  const router = createMemoryRouter(real.routes, { initialEntries: ['/'] })
  const r = render(<StrictMode><AppProviders client={createQueryClient({ retry: false })}><RouterProvider router={router} /></AppProviders></StrictMode>)
  const all = [
    ...allPaths,
    '/no/such/page',
    ...[-4, -3, -2, -1, 1].map((d) => `/day/${addDays(today(), d)}`),
    // Signed-link pages with a query, as an email would open them
    '/verify-email?id=1&hash=2&expires=3&signature=4', '/reset-password?token=t&email=a%40b.co', '/confirm-email-change?id=1&hash=2&signature=3', '/unsubscribe?user=1&type=daily_top5&signature=3',
  ]
  const visited = new Set<RouteObject>()
  for (const path of all) {
    for (const m of matchRoutes(real.routes, path.split('?')[0]) ?? []) visited.add(m.route)
    await act(async () => { await router.navigate(path) })
    await act(async () => { await new Promise((res) => setTimeout(res, 60)) })
  }
  // Every route of the table (a leaf is a route without children) must have been opened at least once.
  const leaves: RouteObject[] = []
  const walk = (rs: RouteObject[]) => rs.forEach((r) => (r.children?.length ? walk(r.children) : leaves.push(r)))
  walk(real.routes)
  const unvisited = leaves.filter((r) => !visited.has(r)).map((r) => r.path ?? '(index)')
  const joinedPrivate = echo.listens.includes('private-user.01JSOFIA0000000000000000000')
  r.unmount()
  removeFakeEcho()
  spy.mockRestore()
  const unmatched = takeUnmatched()
  return { errors: errors.filter((e) => !e.includes('not wrapped in act')), unmatched, unvisited, joinedPrivate }
}

describe('smoke', () => {
  it('visits every route with the API mocked, without a console error or an unmatched request', async () => {
    const { errors, unmatched, unvisited, joinedPrivate } = await run()
    if (errors.length) console.log(errors.slice(0, 3).join('\n---\n'))
    expect(errors).toEqual([])
    expect(unmatched).toEqual([])
    expect(unvisited).toEqual([])
    expect(joinedPrivate).toBe(true)
  })
})
