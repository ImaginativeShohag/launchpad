import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { addDays, today } from '@/lib/date'
import { apiError, json, validation, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { apiProduct, dayBody, leaderboardBody, memberReason, pageBody, rankedList, todayBody, topicReason, topicsBody } from '../fixtures/product'
import { noFollows } from '../fixtures/profile'
import { apiUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.restoreAllMocks())

const yesterday = () => addDays(today(), -1)
const names = (n: number) => rankedList(n, (i) => ({ name: ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'][i] }))

/** Everything the Today page asks for. */
const homeRoutes = (list = names(3), extra: Record<string, never> = {}) => ({
  'GET /today': json(todayBody(list)),
  'GET /days/:date': (_c: unknown, p: Record<string, string>) => json(dayBody([], p.date)),
  'GET /topics': json(topicsBody([{}, {}])),
  ...extra,
})

describe('Today', () => {
  it('shows rank 1 as the hero and the rest in the API order with their ranks', async () => {
    const { api } = renderApp({ path: '/', routes: homeRoutes(), strict: false })

    expect(await screen.findByRole('heading', { level: 2, name: 'Alpha' })).toBeTruthy()
    expect(screen.getByText('#1 today')).toBeTruthy()
    const rows = await screen.findAllByRole('heading', { level: 3 })
    expect(rows.map((r) => r.textContent).filter((t) => ['Beta', 'Gamma'].includes(t ?? ''))).toEqual(['Beta', 'Gamma'])
    expect(screen.getAllByText('2', { selector: 'span' }).length).toBeGreaterThan(0)
    expect(api.callsTo('GET /today')).toHaveLength(1)
    expect(api.callsTo('GET /today')[0].query.size).toBe(0)
    expect(screen.getByText(/launches today/)).toBeTruthy()
  })

  it('shows yesterday from /days/{yesterday} and links the previous days', async () => {
    const { api } = renderApp({
      path: '/',
      routes: homeRoutes(names(2), { 'GET /days/:date': (_c: unknown, p: Record<string, string>) => json(dayBody(rankedList(2, (i) => ({ name: ['Old One', 'Old Two'][i] })), p.date)) } as never),
    })

    expect(await screen.findAllByText('Old One')).not.toHaveLength(0)
    expect(api.callsTo('GET /days/:date')[0].path).toBe(`/days/${yesterday()}`)
    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href'))
    expect(hrefs).toContain(`/day/${yesterday()}`)
    for (let i = 2; i <= 8; i++) expect(hrefs).toContain(`/day/${addDays(today(), -i)}`)
  })

  it('has an empty state when nothing launched', async () => {
    renderApp({ path: '/', routes: homeRoutes([]) })

    expect(await screen.findByText('No launches yet today')).toBeTruthy()
    expect(await screen.findByText('Nothing launched yesterday.')).toBeTruthy()
  })

  it('shows an error with Retry that recovers', async () => {
    let fail = true
    const { api } = renderApp({
      path: '/',
      routes: { ...homeRoutes(), 'GET /today': () => (fail ? apiError(500, 'boom') : json(todayBody(names(2)))) },
    })

    expect(await screen.findByText("Could not load today's launches")).toBeTruthy()
    fail = false
    fireEvent.click(screen.getAllByRole('button', { name: 'Retry' })[0])

    expect(await screen.findByRole('heading', { level: 2, name: 'Alpha' })).toBeTruthy()
    expect(api.callsTo('GET /today').length).toBeGreaterThanOrEqual(2)
  })

  it('opens the sign-in prompt for a guest who votes, without calling the API', async () => {
    const { api } = renderApp({ path: '/', routes: homeRoutes() })

    fireEvent.click((await screen.findAllByRole('button', { name: /^Upvote/ }))[0])

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0)
  })

  it('disables the vote on a product that is not on its launch day', async () => {
    renderApp({ path: '/', routes: homeRoutes([...names(1), ...rankedList(1, () => ({ name: 'Old', launch_date: yesterday() }))]), user: apiUser() })

    await screen.findByRole('heading', { level: 3, name: 'Old' })
    const old = screen.getByRole('heading', { level: 3, name: 'Old' }).closest('.group') as HTMLElement
    expect(within(old).getByRole('button', { name: /^Upvote/ }).getAttribute('title')).toBe('Voting is only open on the launch day')
  })
})

describe('Day page', () => {
  const date = addDays(today(), -3)

  it('shows a frozen day with its final order', async () => {
    const { api } = renderApp({ path: `/day/${date}`, routes: { 'GET /days/:date': json(dayBody(rankedList(2, (i) => ({ name: ['First', 'Second'][i] })), date, true)) } })

    expect(await screen.findByText(/Final results/)).toBeTruthy()
    expect(screen.getByText(/Product of the day/)).toBeTruthy()
    expect(api.callsTo('GET /days/:date')[0].path).toBe(`/days/${date}`)
    expect(screen.getByRole('heading', { level: 3, name: 'Second' })).toBeTruthy()
  })

  it('says so while the day is not frozen yet', async () => {
    renderApp({ path: `/day/${yesterday()}`, routes: { 'GET /days/:date': json(dayBody(rankedList(1), yesterday(), false)) } })

    expect(await screen.findByText('Results are being finalised')).toBeTruthy()
  })

  it('shows a quiet day when the list is empty', async () => {
    renderApp({ path: `/day/${date}`, routes: { 'GET /days/:date': json(dayBody([], date)) } })

    expect(await screen.findByText('A quiet day')).toBeTruthy()
  })

  it('shows "Nothing to see yet" for a future date without calling the API', async () => {
    const { api } = renderApp({ path: `/day/${addDays(today(), 2)}` })

    expect(await screen.findByText('Nothing to see yet')).toBeTruthy()
    expect(api.calls.filter((c) => c.path.startsWith('/days'))).toHaveLength(0)
  })

  it('shows the same state when the server answers 404 for a day the clock thought was past', async () => {
    renderApp({ path: `/day/${date}`, routes: { 'GET /days/:date': apiError(404, 'Not found.') } })

    expect(await screen.findByText('Nothing to see yet')).toBeTruthy()
  })

  it('sends today to the front page and rejects a bad date without a request', async () => {
    const todayRoute = renderApp({ path: `/day/${today()}`, routes: homeRoutes() })
    await waitFor(() => expect(todayRoute.router.state.location.pathname).toBe('/'))
    todayRoute.unmount()

    const { api } = renderApp({ path: '/day/2026-02-31' })
    expect(await screen.findByText("That date doesn't look right")).toBeTruthy()
    expect(api.calls.filter((c) => c.path.startsWith('/days'))).toHaveLength(0)
  })

  it('shows an error with Retry for a failed day', async () => {
    let fail = true
    renderApp({ path: `/day/${date}`, routes: { 'GET /days/:date': () => (fail ? apiError(500, 'boom') : json(dayBody(rankedList(1, () => ({ name: 'Back' })), date))) } })

    expect(await screen.findByText('Could not load this day')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByRole('heading', { level: 3, name: 'Back' })).toBeTruthy()
  })
})

describe('Leaderboards', () => {
  it('maps range and topic of the URL to period and topic of the API and shows a podium', async () => {
    const { api } = renderApp({
      path: '/leaderboard?range=month&topic=ai',
      routes: { 'GET /topics': json(topicsBody()), 'GET /leaderboards': json(leaderboardBody(names(5), 'month', 'ai')) },
    })

    expect(await screen.findByText('Alpha')).toBeTruthy()
    expect(api.callsTo('GET /leaderboards')).toHaveLength(1)
    const q = api.callsTo('GET /leaderboards')[0].query
    expect(q.get('period')).toBe('month')
    expect(q.get('topic')).toBe('ai')
    expect(q.has('range')).toBe(false)
    expect(screen.getByText('Delta')).toBeTruthy()
  })

  it('defaults to the week and changes the period from the tabs', async () => {
    const { api, router } = renderApp({ path: '/leaderboard', routes: { 'GET /topics': json(topicsBody()), 'GET /leaderboards': json(leaderboardBody(names(3))) } })

    await screen.findByText('Alpha')
    expect(api.callsTo('GET /leaderboards')[0].query.get('period')).toBe('week')
    expect(api.callsTo('GET /leaderboards')[0].query.has('topic')).toBe(false)

    fireEvent.click(screen.getByRole('tab', { name: 'All time' }))
    await waitFor(() => expect(api.callsTo('GET /leaderboards').some((c) => c.query.get('period') === 'all')).toBe(true))
    expect(router.state.location.search).toBe('?range=all')
  })

  it('treats a topic the API does not know as "all topics" instead of sending a 422', async () => {
    const { api } = renderApp({ path: '/leaderboard?topic=ghost', routes: { 'GET /topics': json(topicsBody()), 'GET /leaderboards': json(leaderboardBody(names(1))) } })

    await screen.findByText('Alpha')
    expect(api.callsTo('GET /leaderboards').every((c) => !c.query.has('topic'))).toBe(true)
  })

  it('shows an empty state and an error with Retry', async () => {
    let fail = false
    renderApp({ path: '/leaderboard', routes: { 'GET /topics': json(topicsBody()), 'GET /leaderboards': () => (fail ? apiError(500, 'x') : json(leaderboardBody([]))) } })

    expect(await screen.findByText('No launches here yet')).toBeTruthy()
    fail = true
    fireEvent.click(screen.getByRole('tab', { name: 'All time' }))
    expect(await screen.findByText('Could not load the leaderboard')).toBeTruthy()
  })
})

describe('Topics', () => {
  it('lists the topics from the API', async () => {
    renderApp({ path: '/topics', routes: { 'GET /topics': json(topicsBody([{}, { name: 'Design', slug: 'design' }])) } })

    expect(await screen.findByRole('link', { name: 'Design' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'AI' }).getAttribute('href')).toBe('/topics/ai')
  })

  it('shows an error with Retry', async () => {
    renderApp({ path: '/topics', routes: { 'GET /topics': apiError(500, 'x') } })

    expect(await screen.findByText('Could not load the topics')).toBeTruthy()
  })

  it('lists a topic through a topic-only search and loads more by cursor', async () => {
    const { api } = renderApp({
      path: '/topics/ai',
      routes: {
        'GET /topics': json(topicsBody()),
        'GET /search': (call) =>
          call.query.get('cursor') === 'c2' ? json(pageBody([apiProduct({ name: 'Third' })])) : json(pageBody([apiProduct({ name: 'First' }), apiProduct({ name: 'Second' })], 'c2')),
      },
    })

    expect(await screen.findByText('First')).toBeTruthy()
    const first = api.callsTo('GET /search')[0].query
    expect(first.get('topic')).toBe('ai')
    expect(first.get('sort')).toBe('votes')
    expect(first.has('q')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('Third')).toBeTruthy()
    expect(api.callsTo('GET /search').filter((c) => c.query.get('cursor') === 'c2')).toHaveLength(1)
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull())
  })

  it('switches to newest through the URL', async () => {
    const { api, router } = renderApp({ path: '/topics/ai', routes: { 'GET /topics': json(topicsBody()), 'GET /search': json(pageBody([apiProduct({ name: 'One' })])) } })

    await screen.findByText('One')
    fireEvent.click(screen.getByRole('tab', { name: 'Newest' }))

    await waitFor(() => expect(api.callsTo('GET /search').some((c) => c.query.get('sort') === 'newest')).toBe(true))
    expect(router.state.location.search).toBe('?sort=newest')
  })

  it('shows "Topic not found" without searching for a slug the API does not have', async () => {
    const { api } = renderApp({ path: '/topics/ghost', routes: { 'GET /topics': json(topicsBody()) } })

    expect(await screen.findByText('Topic not found')).toBeTruthy()
    expect(api.callsTo('GET /search')).toHaveLength(0)
  })

  it('has an empty state for a topic without launches', async () => {
    renderApp({ path: '/topics/ai', routes: { 'GET /topics': json(topicsBody()), 'GET /search': json(pageBody([])) } })

    expect(await screen.findByText('No AI launches yet')).toBeTruthy()
  })
})

describe('Search', () => {
  const searchRoutes = (handler: Handler = () => json(pageBody([apiProduct({ name: 'Lumen Notes', tagline: 'Notes that glow' })], null, { query: 'lumen' }))) => ({
    'GET /topics': json(topicsBody()),
    'GET /leaderboards': json(leaderboardBody(names(2), 'all')),
    'GET /search': handler,
  })

  it('shows popular products and recent searches before anything is typed', async () => {
    localStorage.setItem('launchpad-recent-searches', JSON.stringify(['notes app']))
    const { api } = renderApp({ path: '/search', routes: searchRoutes() })

    expect(await screen.findByText('Alpha')).toBeTruthy()
    expect(screen.getByText('notes app')).toBeTruthy()
    expect(api.callsTo('GET /search')).toHaveLength(0)
    expect(api.callsTo('GET /leaderboards')[0].query.get('period')).toBe('all')
    localStorage.clear()
  })

  it('searches after a 250 ms pause, once, and puts q in the URL', async () => {
    const { api, router } = renderApp({ path: '/search', routes: searchRoutes() })
    const box = await screen.findByLabelText('Search products')

    fireEvent.change(box, { target: { value: 'lu' } })
    fireEvent.change(box, { target: { value: 'lum' } })
    fireEvent.change(box, { target: { value: 'lume' } })

    expect(await screen.findByText('Notes that glow')).toBeTruthy()
    const calls = api.callsTo('GET /search')
    expect(calls).toHaveLength(1)
    expect(calls[0].query.get('q')).toBe('lume')
    expect(calls[0].query.get('sort')).toBe('relevance')
    expect(router.state.location.search).toBe('?q=lume')
  })

  it('aborts the request that is still in flight when the query changes', async () => {
    const { api } = renderApp({
      path: '/search?q=first',
      strict: false,
      routes: searchRoutes((call) => {
        if (call.query.get('q') === 'first') return new Promise<never>(() => {}) as never
        return json(pageBody([apiProduct({ name: 'Zeta' })], null, { query: 'second' }))
      }),
    })
    await waitFor(() => expect(api.callsTo('GET /search')).toHaveLength(1))

    fireEvent.change(await screen.findByLabelText('Search products'), { target: { value: 'second' } })

    expect(await screen.findByText('Zeta')).toBeTruthy()
    const calls = api.callsTo('GET /search')
    expect(calls.map((c) => c.query.get('q'))).toEqual(['first', 'second'])
    expect(calls[0].signal?.aborted).toBe(true)
    expect(calls[1].signal?.aborted).toBe(false)
  })

  it('never sends relevance without a query, and never sends a one-letter query', async () => {
    const { api } = renderApp({ path: '/search?topic=ai&sort=relevance', routes: searchRoutes() })

    expect(await screen.findByText('Notes that glow')).toBeTruthy()
    const q = api.callsTo('GET /search')[0].query
    expect(q.get('sort')).toBe('votes')
    expect(q.has('q')).toBe(false)
    expect(q.get('topic')).toBe('ai')

    fireEvent.change(screen.getByLabelText('Search products'), { target: { value: 'a' } })
    await new Promise((r) => setTimeout(r, 400))
    expect(api.callsTo('GET /search').every((c) => !c.query.has('q'))).toBe(true)
    expect(screen.queryByRole('tab', { name: 'Best match' })).toBeNull()
  })

  it('highlights the server query on plain text', async () => {
    renderApp({ path: '/search?q=lumen', routes: searchRoutes() })

    await screen.findByText('Notes that glow')
    const marks = document.querySelectorAll('mark')
    expect([...marks].map((m) => m.textContent)).toContain('Lumen')
  })

  it('renders server text as text, never as markup', async () => {
    renderApp({ path: '/search?q=lumen', routes: searchRoutes(() => json(pageBody([apiProduct({ name: '<img src=x onerror=alert(1)> Lumen' })], null, { query: 'lumen' }))) })

    await screen.findByText(/onerror=alert/)
    expect(document.querySelector('img[src="x"]')).toBeNull()
  })

  it('loads more with the cursor and stops when there is none', async () => {
    const { api } = renderApp({
      path: '/search?q=lumen',
      routes: searchRoutes((call) =>
        call.query.get('cursor') === 'c2' ? json(pageBody([apiProduct({ name: 'Page Two' })], null, { query: 'lumen' })) : json(pageBody([apiProduct({ name: 'Page One' })], 'c2', { query: 'lumen' })),
      ),
    })

    expect(await screen.findByText('Page One')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Page Two')).toBeTruthy()
    const more = api.callsTo('GET /search').filter((c) => c.query.get('cursor') === 'c2')
    expect(more).toHaveLength(1)
    expect(more[0].query.get('q')).toBe('lumen')
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull())
  })

  it('shows the server message for a 422', async () => {
    renderApp({ path: '/search?q=%3F%3F', routes: searchRoutes(() => validation({ q: ['Enter at least two letters or digits.'] }) as never) })

    expect(await screen.findByText('Enter at least two letters or digits.')).toBeTruthy()
  })

  it('shows the timeout with its Retry-After and keeps Retry disabled until then', async () => {
    renderApp({
      path: '/search?q=lumen',
      routes: searchRoutes(() => apiError(503, 'Search timed out.', { code: 'search_timeout' }, { 'Retry-After': '30' }) as never),
    })

    expect(await screen.findByText('That search took too long')).toBeTruthy()
    expect(screen.getByText(/Try fewer words, or try again in 30 s/)).toBeTruthy()
    expect((await screen.findByRole('button', { name: /Retry in/ })).hasAttribute('disabled')).toBe(true)
  })

  it('shows the wait for a 429', async () => {
    renderApp({ path: '/search?q=lumen', routes: searchRoutes(() => apiError(429, 'Too many.', {}, { 'Retry-After': '12' }) as never) })

    expect(await screen.findByText('Search is unavailable')).toBeTruthy()
    expect(screen.getByText('Too many attempts. Try again in 12 s.')).toBeTruthy()
    expect((await screen.findByRole('button', { name: /Retry in/ })).hasAttribute('disabled')).toBe(true)
  })

  it('shows "No matches" for an empty result', async () => {
    renderApp({ path: '/search?q=zzzz', routes: searchRoutes(() => json(pageBody([], null, { query: 'zzzz' }))) })

    expect(await screen.findByText('No matches')).toBeTruthy()
  })

  it('still works when localStorage is blocked', async () => {
    const getItem = Storage.prototype.getItem
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(function (this: Storage, k: string) {
      if (k === 'launchpad-recent-searches') throw new DOMException('blocked', 'SecurityError')
      return getItem.call(this, k)
    })
    const setItem = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (this: Storage, k: string, v: string) {
      if (k === 'launchpad-recent-searches') throw new DOMException('full', 'QuotaExceededError')
      return setItem.call(this, k, v)
    })
    renderApp({ path: '/search', routes: searchRoutes() })

    expect(await screen.findByText('Alpha')).toBeTruthy()
    fireEvent.keyDown(screen.getByLabelText('Search products'), { key: 'Enter' })
  })
})

describe('Following feed', () => {
  const member = apiUser()

  it('shows each launch with the reasons it is in the feed, grouped by day', async () => {
    const { api } = renderApp({
      path: '/feed',
      user: member,
      routes: {
        ...noFollows(),
        'GET /topics': json(topicsBody()),
        'GET /me/feed': json({ ...pageBody([{ ...apiProduct({ name: 'From Mia' }), reasons: [memberReason('Mia Maker'), topicReason('AI')] }]), meta: { next_cursor: null, has_follows: true } }),
      },
    })

    expect(await screen.findByText('From Mia')).toBeTruthy()
    expect(screen.getByText('Mia Maker launched')).toBeTruthy()
    expect(screen.getByText('Because you follow AI')).toBeTruthy()
    expect(screen.getByRole('heading', { level: 2, name: 'Today' })).toBeTruthy()
    expect(api.callsTo('GET /me/feed').length).toBeGreaterThan(0)
  })

  it('invites a member who follows nothing to browse topics', async () => {
    renderApp({ path: '/feed', user: member, routes: { ...noFollows(), 'GET /topics': json(topicsBody()), 'GET /me/feed': json({ data: [], meta: { next_cursor: null, has_follows: false } }) } })

    expect(await screen.findByText('Your feed is waiting')).toBeTruthy()
    expect(screen.getByText('Suggestions for you')).toBeTruthy()
  })

  it('says "nothing new" for a member who follows someone without launches', async () => {
    renderApp({ path: '/feed', user: member, routes: { ...noFollows(), 'GET /topics': json(topicsBody()), 'GET /me/feed': json({ data: [], meta: { next_cursor: null, has_follows: true } }) } })

    expect(await screen.findByText('Nothing new yet')).toBeTruthy()
  })

  it('loads more and shows an error with Retry', async () => {
    let fail = true
    renderApp({
      path: '/feed',
      user: member,
      routes: { ...noFollows(), 'GET /topics': json(topicsBody()), 'GET /me/feed': () => (fail ? apiError(500, 'x') : json({ ...pageBody([apiProduct({ name: 'Recovered' })], 'n2'), meta: { next_cursor: 'n2', has_follows: true } })) },
    })

    expect(await screen.findByText('Could not load your feed')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('Recovered')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Load more' })).toBeTruthy()
  })

  it('asks a guest to sign in instead of calling the API', async () => {
    const { api, router } = renderApp({ path: '/feed' })

    await waitFor(() => expect(router.state.location.pathname).toBe('/signin'))
    expect(api.callsTo('GET /me/feed')).toHaveLength(0)
  })
})
