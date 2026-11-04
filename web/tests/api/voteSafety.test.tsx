import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { keys } from '@/api/keys'
import type { DayDto, TodayDto } from '@/api/types'
import { addDays, today } from '@/lib/date'
import { dropSession } from '@/session/SessionProvider'
import { apiError, json, type MockResult } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { installFakeEcho, removeFakeEcho } from '../helpers/fakeEcho'
import { renderApp } from '../helpers/renderApp'
import { dayBody, frame, rankedList, todayBody, topicsBody } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const ids = ['01JSAFE0000000000000000A01', '01JSAFE0000000000000000B02']
const list = () => rankedList(2, (i) => ({ id: ids[i], name: ['Alpha', 'Beta'][i], votes_count: 10 - i }))
const pendingResult = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}
const base = (extra: Record<string, never> = {}) => ({
  'GET /today': () => json(todayBody(list())),
  'GET /days/:date': json(dayBody([], addDays(today(), -1))),
  'GET /topics': json(topicsBody()),
  ...extra,
})
const today$ = (c: { getQueryData: <T>(k: readonly unknown[]) => T | undefined }) => c.getQueryData<TodayDto>(keys.today)!

describe('vote rollback is field-level', () => {
  it('keeps a reorder and a live count that arrived while the vote was in flight', async () => {
    const echo = installFakeEcho()
    const pending = pendingResult()
    const { client } = renderApp({ path: '/', user: apiUser(), routes: base({ 'PUT /products/:id/vote': () => pending.promise } as never) })
    fireEvent.click((await screen.findAllByRole('button', { name: /^Upvote Alpha/ }))[0])
    await waitFor(() => expect(today$(client).data[0]).toMatchObject({ id: ids[0], votes_count: 11, has_voted: true }))
    await waitFor(() => expect(echo.count('today', '.rank.updated')).toBe(1))

    act(() => {
      echo.emit('today', '.rank.updated', frame([{ id: ids[1], rank: 1, votes: 25 }, { id: ids[0], rank: 2, votes: 11 }]))
    })
    expect(today$(client).data.map((p) => p.id)).toEqual([ids[1], ids[0]])

    pending.resolve(apiError(500, 'boom'))
    expect(await screen.findByText('Could not save your vote')).toBeTruthy()

    await waitFor(() => expect(today$(client).data.find((p) => p.id === ids[0])).toMatchObject({ votes_count: 10, has_voted: false }))
    expect(today$(client).data.map((p) => [p.id, p.votes_count])).toEqual([[ids[1], 25], [ids[0], 10]])
    removeFakeEcho()
  })

  it('does not bring back a cache that a sign-out dropped while the vote was in flight', async () => {
    const pending = pendingResult()
    const { client } = renderApp({ path: '/', user: apiUser(), routes: base({ 'PUT /products/:id/vote': () => pending.promise } as never) })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    const copy: DayDto = { data: [{ ...today$(client).data[0] }], meta: { date: 'x', frozen: true } }
    client.setQueryData(keys.day('seeded'), copy)

    fireEvent.click((await screen.findAllByRole('button', { name: /^Upvote Alpha/ }))[0])
    await waitFor(() => expect(client.getQueryData<DayDto>(keys.day('seeded'))!.data[0].votes_count).toBe(11))
    await act(async () => {
      await dropSession(client)
    })
    pending.resolve(apiError(500, 'boom'))
    await screen.findByText('Could not save your vote')

    expect(client.getQueryData(keys.day('seeded'))).toBeUndefined()
  })
})

describe('vote button accessibility and guards', () => {
  it('keeps one label, exposes the state with aria-pressed and describes why it is disabled', async () => {
    renderApp({ path: '/', user: apiUser({ id: '01JMAKER0000000000000000000' }), routes: base() })

    const button = (await screen.findAllByRole('button', { name: /^Upvote Alpha/ }))[0]
    const described = document.getElementById(button.getAttribute('aria-describedby') ?? '')
    expect(described?.textContent).toBe('You can’t vote on your own product')
    expect(button.getAttribute('aria-pressed')).toBe('false')
  })

  it('fires one request when two buttons show the same product (hero and a list)', async () => {
    const same = rankedList(1, () => ({ id: ids[0], name: 'Alpha' }))
    const pending = pendingResult()
    const { api } = renderApp({
      path: '/',
      user: apiUser(),
      strict: false,
      routes: {
        ...base(),
        'GET /today': json(todayBody(same)),
        'GET /days/:date': json(dayBody(same, addDays(today(), -1))),
        'PUT /products/:id/vote': () => pending.promise,
      } as never,
    })
    const all = await screen.findAllByRole('button', { name: /^Upvote Alpha/ })
    expect(all.length).toBeGreaterThan(2)

    fireEvent.click(all[0])
    fireEvent.click(all[all.length - 1])
    pending.resolve(json({ data: { voted: true, votes_count: 11 } }))

    await waitFor(() => expect(screen.getAllByRole('button', { name: /^Upvote Alpha/, pressed: true }).length).toBe(all.length))
    expect(api.callsTo('PUT /products/:id/vote')).toHaveLength(1)
    expect(api.calls.filter((c) => c.method === 'DELETE')).toHaveLength(0)
  })

  it("judges 'closed' by the server's day when Today is loaded", async () => {
    const serverDay = '2099-01-01'
    renderApp({
      path: '/',
      user: apiUser(),
      routes: base({ 'GET /today': json(todayBody(rankedList(1, () => ({ id: ids[0], name: 'Alpha', launch_date: serverDay })), serverDay)) } as never),
    })

    const button = (await screen.findAllByRole('button', { name: /^Upvote Alpha/ }))[0]
    expect(button.getAttribute('aria-disabled')).toBeNull()
  })
})
