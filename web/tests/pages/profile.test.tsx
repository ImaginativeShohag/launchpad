import { fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { keys } from '@/api/keys'
import type { ApiItem, PublicUserDto } from '@/api/types'
import { apiError, json, type MockResult, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { followState } from '../fixtures/comment'
import { apiProduct, pageBody } from '../fixtures/product'
import { profileBody } from '../fixtures/profile'
import { apiLink, apiUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.restoreAllMocks())

const deferred = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}

const profileRoutes = (extra: Record<string, RouteValue> = {}, profile = profileBody()): Record<string, RouteValue> => ({
  'GET /users/:handle': json(profile),
  'GET /users/:handle/products': json(pageBody([apiProduct({ name: 'Launch One' })])),
  ...extra,
})

const cachedProfile = (client: { getQueryData: <T>(k: readonly unknown[]) => T | undefined }) => client.getQueryData<ApiItem<PublicUserDto>>(keys.user('rafi'))!.data

describe('public profile', () => {
  it('shows the member, safe links, counts and their launches without vote buttons', async () => {
    const { api } = renderApp({
      path: '/u/rafi',
      routes: profileRoutes({}, profileBody({ links: [apiLink({ url: 'https://rafi.dev' }), apiLink({ url: 'javascript:alert(1)' })] })),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Rafi Chowdhury' })).toBeTruthy()
    expect(screen.getByText('@rafi')).toBeTruthy()
    expect(screen.getByText('Indie maker')).toBeTruthy()
    const link = screen.getByRole('link', { name: /rafi.dev/ })
    expect(link.getAttribute('rel')).toContain('noopener')
    expect(screen.getAllByRole('link').filter((a) => a.getAttribute('href')?.startsWith('javascript'))).toHaveLength(0)
    expect(screen.getByLabelText('12')).toBeTruthy()
    expect(screen.getByText('followers')).toBeTruthy()
    expect(screen.getByLabelText('3')).toBeTruthy()
    expect(screen.getByText(/Joined/)).toBeTruthy()
    expect(await screen.findByRole('heading', { level: 3, name: 'Launch One' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /^Upvote/ })).toBeNull()
    expect(screen.queryByRole('tab', { name: /Upvoted/ })).toBeNull()
    expect(api.callsTo('GET /users/rafi')).not.toHaveLength(0)
    expect(api.callsTo('GET /users/rafi/products')[0].path).toBe('/users/rafi/products')
  })

  it('pages the launches with the cursor', async () => {
    const { api } = renderApp({
      path: '/u/rafi',
      routes: profileRoutes({ 'GET /users/:handle/products': (call) => json(call.query.get('cursor') === 'u2' ? pageBody([apiProduct({ name: 'Page Two' })]) : pageBody([apiProduct({ name: 'Page One' })], 'u2')) }),
    })
    await screen.findByRole('heading', { level: 3, name: 'Page One' })

    fireEvent.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByRole('heading', { level: 3, name: 'Page Two' })).toBeTruthy()
    expect(api.callsTo('GET /users/rafi/products').filter((c) => c.query.get('cursor') === 'u2')).toHaveLength(1)
  })

  it('has an empty state, and an error with Retry for the launches', async () => {
    let fail = true
    renderApp({ path: '/u/rafi', routes: profileRoutes({ 'GET /users/:handle/products': () => (fail ? apiError(500, 'x') : json(pageBody([]))) }) })

    expect(await screen.findByText('Could not load the products')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('No launches yet')).toBeTruthy()
  })

  it('shows "Member not found" for an unknown handle', async () => {
    const { api } = renderApp({ path: '/u/nobody', routes: { 'GET /users/:handle': apiError(404, 'Not found.') } })

    expect(await screen.findByText('Member not found')).toBeTruthy()
    expect(api.callsTo('GET /users/:handle/products')).toHaveLength(0)
  })

  it('shows an error with Retry when the profile fails', async () => {
    let fail = true
    renderApp({ path: '/u/rafi', routes: profileRoutes({ 'GET /users/:handle': () => (fail ? apiError(500, 'x') : json(profileBody())) }) })

    expect(await screen.findByText('Could not load this profile')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Rafi Chowdhury' })).toBeTruthy()
  })

  it('shows a suspended member reduced: no headline, links, launches or follow button', async () => {
    const { api } = renderApp({
      path: '/u/rafi',
      user: apiUser(),
      routes: profileRoutes({}, profileBody({ is_suspended: true, headline: null, links: [], avatar_url: null })),
    })

    expect(await screen.findByText('This account is suspended')).toBeTruthy()
    expect(screen.getAllByText('Suspended').length).toBeGreaterThan(0)
    expect(screen.queryByRole('button', { name: /Follow/ })).toBeNull()
    expect(screen.queryByText('Products')).toBeNull()
    expect(api.callsTo('GET /users/:handle/products')).toHaveLength(0)
  })

  it('shows your own profile with Edit profile and no follow button', async () => {
    const me = apiUser()
    renderApp({ path: `/u/${me.handle}`, user: me, routes: profileRoutes({ 'GET /users/:handle/products': json(pageBody([])) }, profileBody({ id: me.id, handle: me.handle, name: me.name, is_following: false })) })

    expect((await screen.findByRole('link', { name: /Edit profile/ })).getAttribute('href')).toBe('/settings')
    expect(screen.queryByRole('button', { name: /Follow/ })).toBeNull()
    expect(await screen.findByText('You haven’t launched yet')).toBeTruthy()
  })
})

describe('follow a member', () => {
  const asMember = (extra: Record<string, RouteValue> = {}, profile = profileBody({ is_following: false })) => renderApp({ path: '/u/rafi', user: apiUser(), routes: profileRoutes(extra, profile) })

  it('asks a guest to sign in without calling the API', async () => {
    const { api } = renderApp({ path: '/u/rafi', routes: profileRoutes() })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury' }))

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
    expect(api.calls.filter((c) => c.path.endsWith('/follow'))).toHaveLength(0)
  })

  it('follows at once, then takes the server number', async () => {
    const pending = deferred()
    const { client, api } = asMember({ 'PUT /users/:handle/follow': () => pending.promise })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury' }))

    await waitFor(() => expect(cachedProfile(client)).toMatchObject({ is_following: true, followers_count: 13 }))
    expect(screen.getByRole('button', { name: 'Unfollow Rafi Chowdhury', pressed: true })).toBeTruthy()
    pending.resolve(json(followState(true, 15)))

    await waitFor(() => expect(cachedProfile(client).followers_count).toBe(15))
    expect(api.callsTo('PUT /users/rafi/follow')).toHaveLength(1)
    expect(api.callsTo('PUT /users/rafi/follow')[0].headers.get('X-XSRF-TOKEN')).toBeTruthy()
  })

  it('unfollows with DELETE', async () => {
    const { client, api } = asMember({ 'DELETE /users/:handle/follow': json(followState(false, 11)) }, profileBody({ is_following: true }))

    fireEvent.click(await screen.findByRole('button', { name: 'Unfollow Rafi Chowdhury' }))

    await waitFor(() => expect(cachedProfile(client)).toMatchObject({ is_following: false, followers_count: 11 }))
    expect(api.callsTo('DELETE /users/rafi/follow')).toHaveLength(1)
  })

  it('rolls back and explains when the member cannot be followed (409 member_unavailable)', async () => {
    const { client } = asMember({ 'PUT /users/:handle/follow': apiError(409, 'Unavailable', { code: 'member_unavailable' }) })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury' }))

    expect(await screen.findByText('Cannot follow this member')).toBeTruthy()
    expect(cachedProfile(client)).toMatchObject({ is_following: false, followers_count: 12 })
    expect(screen.getByRole('button', { name: 'Follow Rafi Chowdhury', pressed: false })).toBeTruthy()
  })

  it('rolls back an unfollow that failed', async () => {
    const { client } = asMember({ 'DELETE /users/:handle/follow': apiError(500, 'boom') }, profileBody({ is_following: true }))

    fireEvent.click(await screen.findByRole('button', { name: 'Unfollow Rafi Chowdhury' }))

    expect(await screen.findByText('Could not update your follow')).toBeTruthy()
    expect(cachedProfile(client)).toMatchObject({ is_following: true, followers_count: 12 })
  })

  it('explains a self-follow answer from the server', async () => {
    asMember({ 'PUT /users/:handle/follow': apiError(403, 'No', { code: 'self_follow' }) })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury' }))

    expect(await screen.findByText('That is you')).toBeTruthy()
  })

  it('opens the verify prompt for email_unverified and rolls back', async () => {
    const { client } = asMember({ 'PUT /users/:handle/follow': apiError(403, 'Verify', { code: 'email_unverified' }) })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury' }))

    expect(await screen.findByText('Verify your email')).toBeTruthy()
    expect(cachedProfile(client).is_following).toBe(false)
  })

  it('disables the button while the follow limiter cools down', async () => {
    asMember({ 'PUT /users/:handle/follow': apiError(429, 'Slow down', {}, { 'Retry-After': '30' }) })

    fireEvent.click(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury' }))

    await waitFor(() => expect(screen.getByRole('button', { name: 'Follow Rafi Chowdhury' }).getAttribute('aria-disabled')).toBe('true'))
  })

  it('treats a guest profile (no is_following) as not followed', async () => {
    renderApp({ path: '/u/rafi', routes: profileRoutes() })

    expect(await screen.findByRole('button', { name: 'Follow Rafi Chowdhury', pressed: false })).toBeTruthy()
  })
})
