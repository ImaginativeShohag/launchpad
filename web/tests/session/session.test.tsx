import { QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { keys } from '@/api/keys'
import { createQueryClient } from '@/lib/queryClient'
import { SessionProvider, useSession, type Session } from '@/session/SessionProvider'
import { apiError, json, mockApi, noContent, validation } from '../helpers/mockApi'
import { apiUser } from '../fixtures/user'

function setup(routes: Parameters<typeof mockApi>[0]) {
  const api = mockApi(routes)
  const client = createQueryClient({ retry: false })
  let session!: Session
  function Probe() {
    session = useSession()
    return <p>{`${session.status}:${session.user?.handle ?? '-'}:${session.isStaff ? 'staff' : 'plain'}`}</p>
  }
  render(
    <QueryClientProvider client={client}>
      <SessionProvider>
        <Probe />
      </SessionProvider>
    </QueryClientProvider>,
  )
  return { api, client, get: () => session }
}

describe('SessionProvider', () => {
  it('starts loading, then is a member when /me answers', async () => {
    setup({ 'GET /me': json({ data: apiUser({ role: 'reviewer' }) }) })

    expect(screen.getByText('loading:-:plain')).toBeTruthy()
    expect(await screen.findByText('member:amina_r:staff')).toBeTruthy()
  })

  it('treats a 401 from /me as the normal guest state, not an error', async () => {
    const { get } = setup({ 'GET /me': apiError(401, 'Unauthenticated.') })

    expect(await screen.findByText('guest:-:plain')).toBeTruthy()
    expect(get().error).toBeNull()
  })

  it('exposes a server failure so pages can offer Retry', async () => {
    const { get } = setup({ 'GET /me': apiError(500, 'boom') })

    expect(await screen.findByText('guest:-:plain')).toBeTruthy()
    expect(get().error?.status).toBe(500)
  })

  it('signs in: login, then a /me refetch fills in the links', async () => {
    let signedIn = false
    const { api, get } = setup({
      'GET /me': () => (signedIn ? json({ data: apiUser({ links: [{ id: 'l1', kind: 'website', label: 'Site', url: 'https://a.dev', position: 0 }] }) }) : apiError(401, 'Unauthenticated.')),
      'POST /auth/login': () => {
        signedIn = true
        return json({ data: apiUser() })
      },
    })
    await screen.findByText('guest:-:plain')

    await act(() => get().signIn('amina@example.com', 'correct-horse-battery', false))

    expect(await screen.findByText('member:amina_r:plain')).toBeTruthy()
    expect(api.callsTo('POST /auth/login')[0].body).toEqual({ email: 'amina@example.com', password: 'correct-horse-battery', remember: false })
    expect(api.callsTo('GET /me')).toHaveLength(2)
    expect(get().user?.links).toHaveLength(1)
  })

  it('rejects a wrong password with the API error and stays a guest', async () => {
    const { get } = setup({ 'GET /me': apiError(401, 'Unauthenticated.'), 'POST /auth/login': validation({ email: ['These credentials do not match our records.'] }) })
    await screen.findByText('guest:-:plain')

    await expect(act(() => get().signIn('a@b.co', 'nope', false))).rejects.toMatchObject({ status: 422 })

    expect(get().status).toBe('guest')
  })

  it('fails loudly when the login worked but the cookie was not kept', async () => {
    const { get } = setup({ 'GET /me': apiError(401, 'Unauthenticated.'), 'POST /auth/login': json({ data: apiUser() }) })
    await screen.findByText('guest:-:plain')

    await expect(act(() => get().signIn('a@b.co', 'pw', false))).rejects.toMatchObject({ code: 'session_not_kept' })
  })

  it('signs out: posts logout and clears every private cache', async () => {
    const { api, client, get } = setup({ 'GET /me': json({ data: apiUser() }), 'POST /auth/logout': noContent() })
    await screen.findByText('member:amina_r:plain')
    client.setQueryData(keys.myProducts('draft'), [{ id: 'p1' }])
    client.setQueryData(keys.feed, [{ id: 'p2' }])

    await act(() => get().signOut())

    await waitFor(() => expect(get().status).toBe('guest'))
    expect(api.callsTo('POST /auth/logout')).toHaveLength(1)
    expect(client.getQueryData(keys.myProducts('draft'))).toBeUndefined()
    expect(client.getQueryData(keys.feed)).toBeUndefined()
    expect(client.getQueryData(keys.me)).toBeNull()
  })

  it('still ends the local session when logout cannot reach the server', async () => {
    const { get } = setup({ 'GET /me': json({ data: apiUser() }), 'POST /auth/logout': apiError(500, 'down') })
    await screen.findByText('member:amina_r:plain')

    await act(() => get().signOut())

    await waitFor(() => expect(get().status).toBe('guest'))
  })

  it('flips to guest when any later call gets a 401', async () => {
    const { get } = setup({ 'GET /me': json({ data: apiUser() }), 'GET /me/email-preferences': apiError(401, 'Unauthenticated.') })
    await screen.findByText('member:amina_r:plain')
    const { api } = await import('@/api/client')

    await act(async () => {
      await api.get('/me/email-preferences').catch(() => {})
    })

    await waitFor(() => expect(get().status).toBe('guest'))
  })

  it('signs out and raises a notice when the account becomes unavailable', async () => {
    const { get } = setup({ 'GET /me': json({ data: apiUser() }), 'GET /me/email-preferences': apiError(403, 'This account is unavailable.', { code: 'account_unavailable' }) })
    await screen.findByText('member:amina_r:plain')
    const { api } = await import('@/api/client')

    await act(async () => {
      await api.get('/me/email-preferences').catch(() => {})
    })

    await waitFor(() => expect(get().status).toBe('guest'))
    expect(get().notice).toBe('account_unavailable')
  })
})
