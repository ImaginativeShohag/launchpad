import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiError, json, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { productRoutes } from '../helpers/productRoutes'
import { renderApp } from '../helpers/renderApp'
import { followState } from '../fixtures/comment'
import { apiProduct, pageBody, topicsBody } from '../fixtures/product'
import { followedUser, followedUsersBody, profileBody } from '../fixtures/profile'
import { apiUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.restoreAllMocks())

const topicList = topicsBody([{}, {}])
const aiTopic = topicList.data[0]
const topicRoutes = (extra: Record<string, RouteValue> = {}): Record<string, RouteValue> => ({
  'GET /topics': json(topicList),
  'GET /search': json(pageBody([])),
  'GET /me/follows/topics': json({ data: [] }),
  ...extra,
})

/** The button is disabled until the member's own follow list is known; wait for that before clicking. */
const enabledButton = async (name: string, pressed?: boolean) => {
  const button = await screen.findByRole('button', { name, pressed })
  await waitFor(() => expect(button.getAttribute('aria-disabled')).toBeNull())
  return button
}

describe('follow a topic', () => {
  it('asks a guest to sign in and does not read the follow list', async () => {
    const { api } = renderApp({ path: '/topics/ai', routes: topicRoutes() })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow AI' }))

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
    expect(api.callsTo('GET /me/follows/topics')).toHaveLength(0)
    expect(api.calls.filter((c) => c.path.endsWith('/follow'))).toHaveLength(0)
  })

  it('follows with PUT, shows it at once, and unfollows with DELETE', async () => {
    const { api } = renderApp({
      path: '/topics/ai',
      user: apiUser(),
      routes: topicRoutes({ 'PUT /topics/:slug/follow': json(followState(true, 4)), 'DELETE /topics/:slug/follow': json(followState(false, 3)) }),
    })

    fireEvent.click(await enabledButton('Follow AI'))
    expect(await screen.findByRole('button', { name: 'Unfollow AI', pressed: true })).toBeTruthy()
    await waitFor(() => expect(api.callsTo('PUT /topics/ai/follow')).toHaveLength(1))

    fireEvent.click(await enabledButton('Unfollow AI'))
    expect(await screen.findByRole('button', { name: 'Follow AI', pressed: false })).toBeTruthy()
    await waitFor(() => expect(api.callsTo('DELETE /topics/ai/follow')).toHaveLength(1))
  })

  it('starts as followed when the member already follows the topic', async () => {
    renderApp({ path: '/topics/ai', user: apiUser(), routes: topicRoutes({ 'GET /me/follows/topics': json({ data: [aiTopic] }) }) })

    expect(await screen.findByRole('button', { name: 'Unfollow AI', pressed: true })).toBeTruthy()
  })

  it('rolls back and says so when the topic is gone (404)', async () => {
    renderApp({ path: '/topics/ai', user: apiUser(), routes: topicRoutes({ 'PUT /topics/:slug/follow': apiError(404, 'Not found.') }) })

    fireEvent.click(await enabledButton('Follow AI'))

    expect(await screen.findByText('This topic was not found')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Follow AI', pressed: false })).toBeTruthy()
  })

  it('shows the verify prompt to an unverified member and reads no list', async () => {
    const { api } = renderApp({ path: '/topics/ai', user: apiUser({ email_verified: false }), routes: topicRoutes() })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow AI' }))

    expect(await screen.findByText('Verify your email')).toBeTruthy()
    expect(api.callsTo('GET /me/follows/topics')).toHaveLength(0)
  })

  it('has a follow button on every topic card of the index', async () => {
    renderApp({ path: '/topics', routes: topicRoutes() })

    expect(await screen.findByRole('button', { name: 'Follow AI' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Follow Topic 1' })).toBeTruthy()
  })
})

describe('the follow side of the feed', () => {
  const feedRoutes = (extra: Record<string, RouteValue> = {}): Record<string, RouteValue> => ({
    'GET /topics': json(topicList),
    'GET /me/feed': json({ ...pageBody([apiProduct({ name: 'From someone' })]), meta: { next_cursor: null, has_follows: true } }),
    'GET /me/follows/users': json(followedUsersBody([followedUser(), followedUser({ id: '01JFOLLOWED0000000000000B', handle: 'sam', name: 'Sam Maker' })])),
    'GET /me/follows/topics': json({ data: [aiTopic] }),
    ...extra,
  })
  const strip = async () => within(await screen.findByRole('region', { name: 'You follow' }))

  it('lists the makers and topics the member follows', async () => {
    const { api } = renderApp({ path: '/feed', user: apiUser(), routes: feedRoutes() })

    const list = await strip()
    expect(await list.findByRole('link', { name: 'Rafi Chowdhury' })).toBeTruthy()
    expect(list.getByRole('link', { name: 'Sam Maker' }).getAttribute('href')).toBe('/u/sam')
    expect(list.getByRole('link', { name: /AI/ }).getAttribute('href')).toBe('/topics/ai')
    expect(api.callsTo('GET /me/follows/users')[0].query.get('per_page')).toBe('50')
  })

  it('unfollows a maker from the strip: the chip goes at once and DELETE is sent', async () => {
    // The server forgets the follow once the DELETE has been answered.
    const gone = new Set<string>()
    const { api } = renderApp({
      path: '/feed',
      user: apiUser(),
      routes: feedRoutes({
        'GET /me/follows/users': () => json(followedUsersBody([followedUser(), followedUser({ id: '01JFOLLOWED0000000000000B', handle: 'sam', name: 'Sam Maker' })].filter((u) => !gone.has(u.handle)))),
        'DELETE /users/:handle/follow': (_c, p) => (gone.add(p.handle), json(followState(false, 1))),
      }),
    })
    const list = await strip()

    fireEvent.click(await list.findByRole('button', { name: 'Unfollow Sam Maker' }))

    await waitFor(() => expect(list.queryByRole('link', { name: 'Sam Maker' })).toBeNull())
    expect(api.callsTo('DELETE /users/sam/follow')).toHaveLength(1)
    expect(list.getByRole('link', { name: 'Rafi Chowdhury' })).toBeTruthy()
  })

  it('puts the chip back when unfollowing fails', async () => {
    renderApp({ path: '/feed', user: apiUser(), routes: feedRoutes({ 'DELETE /users/:handle/follow': apiError(500, 'boom') }) })
    const list = await strip()

    fireEvent.click(await list.findByRole('button', { name: 'Unfollow Sam Maker' }))

    expect(await screen.findByText('Could not update your follow')).toBeTruthy()
    await waitFor(() => expect(list.getByRole('link', { name: 'Sam Maker' })).toBeTruthy())
  })

  it('unfollows a topic from the strip', async () => {
    const gone = { value: false }
    const { api } = renderApp({
      path: '/feed',
      user: apiUser(),
      routes: feedRoutes({ 'GET /me/follows/topics': () => json({ data: gone.value ? [] : [aiTopic] }), 'DELETE /topics/:slug/follow': () => ((gone.value = true), json(followState(false, 0))) }),
    })
    const list = await strip()

    fireEvent.click(await list.findByRole('button', { name: 'Unfollow AI' }))

    await waitFor(() => expect(list.queryByRole('link', { name: /AI/ })).toBeNull())
    expect(api.callsTo('DELETE /topics/ai/follow')).toHaveLength(1)
  })

  it('loads more followed makers with the cursor', async () => {
    const { api } = renderApp({
      path: '/feed',
      user: apiUser(),
      routes: feedRoutes({
        'GET /me/follows/users': (call) => json(call.query.get('cursor') === 'f2' ? followedUsersBody([followedUser({ id: '01JFOLLOWED0000000000000C', handle: 'zed', name: 'Zed Maker' })]) : followedUsersBody([followedUser()], 'f2')),
      }),
    })
    const list = await strip()

    fireEvent.click(await list.findByRole('button', { name: 'Show more makers' }))

    expect(await list.findByRole('link', { name: 'Zed Maker' })).toBeTruthy()
    expect(api.callsTo('GET /me/follows/users').filter((c) => c.query.get('cursor') === 'f2')).toHaveLength(1)
  })

  it('has no strip when the member follows nobody, and suggests topics with follow buttons', async () => {
    const { api } = renderApp({
      path: '/feed',
      user: apiUser(),
      routes: feedRoutes({
        'GET /me/feed': json({ data: [], meta: { next_cursor: null, has_follows: false } }),
        'GET /me/follows/users': json(followedUsersBody([])),
        'GET /me/follows/topics': json({ data: [] }),
        'PUT /topics/:slug/follow': json(followState(true, 1)),
      }),
    })

    expect(await screen.findByText('Suggestions for you')).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'You follow' })).toBeNull()
    fireEvent.click(await screen.findByRole('button', { name: 'Follow AI' }))
    expect(await screen.findByRole('button', { name: 'Unfollow AI', pressed: true })).toBeTruthy()
    expect(api.callsTo('PUT /topics/ai/follow')).toHaveLength(1)
  })

  it('does not suggest a topic the member already follows', async () => {
    renderApp({
      path: '/feed',
      user: apiUser(),
      routes: feedRoutes({ 'GET /me/feed': json({ data: [], meta: { next_cursor: null, has_follows: true } }), 'GET /me/follows/users': json(followedUsersBody([])) }),
    })

    expect(await screen.findByText('Suggestions for you')).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Follow AI' })).toBeNull())
    expect(screen.getByRole('button', { name: 'Follow Topic 1' })).toBeTruthy()
  })
})

describe('the maker card', () => {
  const product = apiProduct({ id: '01JPRODUCT00000000000000F1', slug: 'orbit', name: 'Orbit' })

  it('follows the maker from the product page using the maker profile', async () => {
    const { api } = renderApp({
      path: '/p/orbit',
      user: apiUser(),
      routes: productRoutes(product, { 'GET /users/:handle': json(profileBody({ id: product.maker!.id, handle: 'maker', name: 'Mia Maker', is_following: false })), 'PUT /users/:handle/follow': json(followState(true, 13)) }),
    })

    fireEvent.click((await screen.findAllByRole('button', { name: 'Follow Mia Maker' }))[0])

    expect((await screen.findAllByRole('button', { name: 'Unfollow Mia Maker', pressed: true })).length).toBeGreaterThan(0)
    expect(api.callsTo('PUT /users/maker/follow')).toHaveLength(1)
    await waitFor(() => expect(screen.getAllByLabelText('13').length).toBeGreaterThan(0))
  })

  it('shows the card without a follow button while the profile is not available', async () => {
    renderApp({ path: '/p/orbit', user: apiUser(), routes: productRoutes(product, { 'GET /users/:handle': apiError(500, 'x') }) })

    expect((await screen.findAllByText('Mia Maker')).length).toBeGreaterThan(0)
    await waitFor(() => expect(screen.queryByRole('button', { name: /Follow Mia Maker/ })).toBeNull())
  })

  it('asks a guest to sign in', async () => {
    renderApp({ path: '/p/orbit', routes: productRoutes(product, { 'GET /users/:handle': json(profileBody({ id: product.maker!.id, handle: 'maker', name: 'Mia Maker' })) }) })

    fireEvent.click((await screen.findAllByRole('button', { name: 'Follow Mia Maker' }))[0])

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
  })
})
