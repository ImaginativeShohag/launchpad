import type { InfiniteData, QueryClient } from '@tanstack/react-query'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { keys } from '@/api/keys'
import type { ApiPage, DayDto, ProductDto, TodayDto } from '@/api/types'
import { addDays, today } from '@/lib/date'
import { apiError, json, type MockResult } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { dayBody, rankedList, todayBody, topicsBody } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const deferred = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}

const HERO = '01JVTEHERK0000000000000001'

function setup(opts: { user?: ReturnType<typeof apiUser> | null; vote?: () => MockResult | Promise<MockResult>; unvote?: () => MockResult | Promise<MockResult>; hasVoted?: boolean } = {}) {
  const list = rankedList(2, (i) => ({ name: ['Hero', 'Second'][i], ...(i === 0 ? { id: HERO, votes_count: 10, ...(opts.hasVoted === undefined ? {} : { has_voted: opts.hasVoted }) } : {}) }))
  const user = opts.user === undefined ? apiUser() : opts.user
  return renderApp({
    path: '/',
    user,
    routes: {
      'GET /today': () => json(todayBody(list)),
      'GET /days/:date': json(dayBody([], addDays(today(), -1))),
      'GET /topics': json(topicsBody()),
      ...(opts.vote ? { 'PUT /products/:id/vote': opts.vote } : {}),
      ...(opts.unvote ? { 'DELETE /products/:id/vote': opts.unvote } : {}),
    },
  })
}

const hero = (client: QueryClient) => client.getQueryData<TodayDto>(keys.today)!.data.find((p) => p.id === HERO)!
const heroButtons = (pressed?: boolean) => screen.findAllByRole('button', { name: /^Upvote Hero/, pressed })
const clickHeroVote = async (pressed?: boolean) => fireEvent.click((await heroButtons(pressed))[0])

/** The hero is also cached in a search list and a day list, like a product that was opened from several places. */
function seedOtherCopies(client: QueryClient) {
  const copy: ProductDto = { ...hero(client) }
  const search: InfiniteData<ApiPage<ProductDto>> = { pages: [{ data: [copy], meta: { next_cursor: null } }], pageParams: [undefined] }
  client.setQueryData(['search', { q: 'hero', topic: undefined, sort: 'relevance' }, {}], search)
  const day: DayDto = { data: [copy], meta: { date: addDays(today(), -2), frozen: true } }
  client.setQueryData(keys.day('seeded'), day)
}
const otherCopies = (client: QueryClient) => ({
  search: client.getQueryData<InfiniteData<ApiPage<ProductDto>>>(['search', { q: 'hero', topic: undefined, sort: 'relevance' }, {}])!.pages[0].data[0],
  day: client.getQueryData<DayDto>(keys.day('seeded'))!.data[0],
})

describe('optimistic vote', () => {
  it('adds one to every cached copy at once, then takes the server number', async () => {
    const pending = deferred()
    const { client, api } = setup({ vote: () => pending.promise })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })
    seedOtherCopies(client)

    await clickHeroVote()

    await waitFor(() => expect(hero(client)).toMatchObject({ votes_count: 11, has_voted: true }))
    expect(otherCopies(client).search).toMatchObject({ votes_count: 11, has_voted: true })
    expect(otherCopies(client).day).toMatchObject({ votes_count: 11, has_voted: true })
    expect(screen.getAllByLabelText('11').length).toBeGreaterThan(0)
    expect(screen.getAllByRole('button', { name: /^Upvote Hero/, pressed: true }).length).toBeGreaterThan(0)

    pending.resolve(json({ data: { voted: true, votes_count: 14 } }))

    await waitFor(() => expect(hero(client).votes_count).toBe(14))
    expect(otherCopies(client).search.votes_count).toBe(14)
    expect(otherCopies(client).day.votes_count).toBe(14)
    expect(api.callsTo(`PUT /products/${HERO}/vote`)).toHaveLength(1)
    expect(api.callsTo(`PUT /products/${HERO}/vote`)[0].headers.get('X-XSRF-TOKEN')).toBeTruthy()
  })

  it('withdraws a vote with DELETE and takes one off', async () => {
    const { client, api } = setup({ hasVoted: true, unvote: () => json({ data: { voted: false, votes_count: 9 } }) })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote(true)

    await waitFor(() => expect(hero(client)).toMatchObject({ votes_count: 9, has_voted: false }))
    expect(api.callsTo(`DELETE /products/${HERO}/vote`)).toHaveLength(1)
  })

  it('rolls every copy back and says so when voting is closed (409 voting_closed)', async () => {
    const pending = deferred()
    const { client, api } = setup({ vote: () => pending.promise })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })
    seedOtherCopies(client)

    await clickHeroVote()
    await waitFor(() => expect(hero(client).votes_count).toBe(11))
    pending.resolve(apiError(409, 'Voting is only open on the launch day.', { code: 'voting_closed' }))

    expect(await screen.findByText('Voting closed for this launch day')).toBeTruthy()
    await waitFor(() => expect(hero(client)).toMatchObject({ votes_count: 10 }))
    expect(hero(client).has_voted).not.toBe(true)
    expect(otherCopies(client).search).toMatchObject({ votes_count: 10 })
    expect(otherCopies(client).search.has_voted).not.toBe(true)
    expect(otherCopies(client).day.votes_count).toBe(10)
    // The lists are read again so the page shows what the server thinks.
    await waitFor(() => expect(api.callsTo('GET /today').length).toBeGreaterThan(1))
  })

  it('rolls back on a 429, shows the seconds and blocks the button for that long', async () => {
    const { client } = setup({ vote: () => apiError(429, 'Too many.', {}, { 'Retry-After': '17' }) })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()

    expect(await screen.findByText('Too many attempts. Try again in 17 s.')).toBeTruthy()
    expect(hero(client).votes_count).toBe(10)
    await waitFor(() => expect(screen.getAllByRole('button', { name: /^Upvote Hero/ })[0].getAttribute('title')).toMatch(/Try again in 1[67] s/))
    expect(screen.getAllByRole('button', { name: /^Upvote Hero/ })[0].getAttribute('aria-disabled')).toBe('true')
  })

  it('rolls back on a server error', async () => {
    const { client } = setup({ vote: () => apiError(500, 'boom') })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()

    expect(await screen.findByText('Could not save your vote')).toBeTruthy()
    expect(hero(client).votes_count).toBe(10)
  })

  it('does not send a vote on your own product and explains why', async () => {
    const own = apiUser({ id: '01JMAKER0000000000000000000' })
    const { api } = setup({ user: own })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()

    expect(await screen.findByText('This one is yours')).toBeTruthy()
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0)
  })

  it('asks an unverified member to verify instead of calling the API', async () => {
    const { api } = setup({ user: apiUser({ email_verified: false }) })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()

    expect(await screen.findByText('Verify your email')).toBeTruthy()
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0)
  })

  it('opens the verify prompt when the API says the email is unverified', async () => {
    const { client } = setup({ vote: () => apiError(403, 'Verify your email first.', { code: 'email_unverified' }) })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()

    expect(await screen.findByText('Verify your email')).toBeTruthy()
    expect(hero(client).votes_count).toBe(10)
  })

  it('asks a guest to sign in', async () => {
    const { api } = setup({ user: null })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0)
  })

  it('ignores a second tap while the first is in flight', async () => {
    const pending = deferred()
    const { api, client } = setup({ vote: () => pending.promise })
    await screen.findByRole('heading', { level: 2, name: 'Hero' })

    await clickHeroVote()
    await waitFor(() => expect(hero(client).votes_count).toBe(11))
    fireEvent.click(screen.getAllByRole('button', { name: /^Upvote Hero/, pressed: true })[0])
    pending.resolve(json({ data: { voted: true, votes_count: 11 } }))

    await waitFor(() => expect(hero(client).has_voted).toBe(true))
    expect(api.callsTo(`PUT /products/${HERO}/vote`)).toHaveLength(1)
    expect(api.calls.filter((c) => c.method === 'DELETE')).toHaveLength(0)
  })
})
