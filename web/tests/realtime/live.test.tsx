import { QueryClient } from '@tanstack/react-query'
import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { keys } from '@/api/keys'
import type { ProductDto, TodayDto } from '@/api/types'
import { addDays, today } from '@/lib/date'
import { getLiveEcho, setEchoFactory } from '@/realtime/echo'
import { QUIET_AFTER_VOTE_MS } from '@/api/hooks/useVote'
import { applyRankFrame, applyVoteCount, parseRankFrame, refetchTodayThrottled } from '@/realtime/useLive'
import { createQueryClient } from '@/lib/queryClient'
import { apiError, json, type MockResult } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { installFakeEcho, removeFakeEcho } from '../helpers/fakeEcho'
import { renderApp } from '../helpers/renderApp'
import { dayBody, frame, rankedList, todayBody, topicsBody } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const ids = ['01JKHVEA', '01JKHVEB', '01JKHVEC'].map((p) => p.padEnd(26, '0'))
const list = () => rankedList(3, (i) => ({ id: ids[i], name: ['Alpha', 'Beta', 'Gamma'][i], votes_count: 30 - i * 10 }))

const homeRoutes = (extra: Record<string, never> = {}) => ({
  'GET /today': () => json(todayBody(list())),
  'GET /days/:date': json(dayBody([], addDays(today(), -1))),
  'GET /topics': json(topicsBody()),
  ...extra,
})

const cachedToday = (client: QueryClient) => client.getQueryData<TodayDto>(keys.today)!
const headingNames = (level: number) => screen.getAllByRole('heading', { level }).map((h) => h.textContent)

let echo: ReturnType<typeof installFakeEcho>
beforeEach(() => {
  echo = installFakeEcho()
})
afterEach(() => removeFakeEcho())

describe('live updates on Today', () => {
  it('listens once per channel with the broadcastAs() names, even under StrictMode', async () => {
    renderApp({ path: '/', routes: homeRoutes() })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })

    await waitFor(() => expect(echo.count('today', '.rank.updated')).toBe(1))
    for (const id of ids) expect(echo.count(`product.${id}`, '.vote.count')).toBe(1)
    expect(echo.factory).toHaveBeenCalledTimes(1)
  })

  it('patches the count on every cached copy from a vote.count frame', async () => {
    const { client } = renderApp({ path: '/', routes: homeRoutes() })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    client.setQueryData(keys.day('copy'), { data: [{ ...cachedToday(client).data[1] }], meta: { date: 'x', frozen: true } })
    await waitFor(() => expect(echo.count(`product.${ids[1]}`, '.vote.count')).toBe(1))

    act(() => echo.emit(`product.${ids[1]}`, '.vote.count', { product_id: ids[1], votes_count: 77 }))

    await waitFor(() => expect(cachedToday(client).data[1].votes_count).toBe(77))
    expect(client.getQueryData<{ data: ProductDto[] }>(keys.day('copy'))!.data[0].votes_count).toBe(77)
    expect(screen.getAllByLabelText('77').length).toBeGreaterThan(0)
  })

  it('ignores a frame for another product, a malformed frame and a non-numeric count', async () => {
    const { client } = renderApp({ path: '/', routes: homeRoutes() })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    await waitFor(() => expect(echo.count(`product.${ids[0]}`, '.vote.count')).toBe(1))

    act(() => {
      echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[1], votes_count: 5 })
      echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[0], votes_count: 'lots' })
      echo.emit(`product.${ids[0]}`, '.vote.count', null)
      echo.emit('today', '.rank.updated', { date: today(), items: 'nope' })
      echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[0], votes_count: Number.NaN })
      echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[0], votes_count: Number.POSITIVE_INFINITY })
      echo.emit('today', '.rank.updated', { date: today(), items: [{ product_id: ids[0], rank: 1, votes_count: Number.POSITIVE_INFINITY }] })
    })

    expect(cachedToday(client).data.map((p) => p.votes_count)).toEqual([30, 20, 10])
  })

  it('ignores a frame for a product with a vote in flight, then the response wins', async () => {
    let release!: (v: MockResult) => void
    const pending = new Promise<MockResult>((r) => (release = r))
    const { client } = renderApp({ path: '/', user: apiUser(), routes: homeRoutes({ 'PUT /products/:id/vote': () => pending } as never) })
    fireEvent.click((await screen.findAllByRole('button', { name: /^Upvote Alpha/ }))[0])
    await waitFor(() => expect(cachedToday(client).data[0].votes_count).toBe(31))

    act(() => echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[0], votes_count: 99 }))
    expect(cachedToday(client).data[0].votes_count).toBe(31)

    release(json({ data: { voted: true, votes_count: 32 } }))
    await waitFor(() => expect(cachedToday(client).data[0].votes_count).toBe(32))

    // A frame read before the vote can still arrive just after the response: it must not put the old number back.
    act(() => echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[0], votes_count: 30 }))
    expect(cachedToday(client).data[0].votes_count).toBe(32)

    await new Promise((r) => setTimeout(r, QUIET_AFTER_VOTE_MS + 100))
    act(() => echo.emit(`product.${ids[0]}`, '.vote.count', { product_id: ids[0], votes_count: 40 }))
    await waitFor(() => expect(cachedToday(client).data[0].votes_count).toBe(40))
  })

  it('reorders the list from a rank.updated frame', async () => {
    const { client, api } = renderApp({ path: '/', routes: homeRoutes() })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    const before = api.callsTo('GET /today').length
    await waitFor(() => expect(echo.count('today', '.rank.updated')).toBe(1))

    act(() =>
      echo.emit(
        'today',
        '.rank.updated',
        frame([
          { id: ids[2], rank: 1, votes: 50 },
          { id: ids[0], rank: 2, votes: 31 },
          { id: ids[1], rank: 3, votes: 20 },
        ]),
      ),
    )

    expect(await screen.findByRole('heading', { level: 2, name: 'Gamma' })).toBeTruthy()
    expect(headingNames(3).filter((n) => ['Alpha', 'Beta'].includes(n ?? ''))).toEqual(['Alpha', 'Beta'])
    expect(cachedToday(client).data.map((p) => [p.id, p.rank, p.votes_count])).toEqual([
      [ids[2], 1, 50],
      [ids[0], 2, 31],
      [ids[1], 3, 20],
    ])
    // Same products, new positions: no channel was left or joined again.
    expect(echo.left).toEqual([])
    expect(echo.channels().length).toBe(4)
    expect(echo.factory).toHaveBeenCalledTimes(1)
    for (const id of ids) expect(echo.count(`product.${id}`, '.vote.count')).toBe(1)
    // A frame is a hint: no immediate re-read (the verification read comes later).
    expect(api.callsTo('GET /today').length).toBe(before)
    // Alpha went from the hero to second, Gamma from third to the hero.
    expect(document.querySelectorAll('li svg.lucide-arrow-down').length).toBeGreaterThan(0)
  })

  it('reads Today again when a frame names a product the cache does not have', async () => {
    const { api } = renderApp({ path: '/', routes: homeRoutes() })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    await waitFor(() => expect(echo.count('today', '.rank.updated')).toBe(1))
    const before = api.callsTo('GET /today').length

    act(() => echo.emit('today', '.rank.updated', frame([{ id: '01JLIVE0000000000000000NEW', rank: 1, votes: 5 }, { id: ids[0], rank: 2, votes: 30 }])))

    await waitFor(() => expect(api.callsTo('GET /today').length).toBe(before + 1))
  })

  it('shows the Live state from the socket and reads Today again after a reconnect', async () => {
    const { api } = renderApp({ path: '/', routes: homeRoutes() })
    expect(await screen.findByText('Live ranking')).toBeTruthy()
    const before = api.callsTo('GET /today').length

    act(() => echo.setStatus('disconnected'))
    expect(await screen.findByText('Updates every 2 min')).toBeTruthy()
    expect(api.callsTo('GET /today').length).toBe(before)

    act(() => echo.setStatus('connected'))
    expect(await screen.findByText('Live ranking')).toBeTruthy()
    await waitFor(() => expect(api.callsTo('GET /today').length).toBe(before + 1))
  })

  it('unsubscribes and leaves the channels when the page unmounts', async () => {
    const { unmount } = renderApp({ path: '/', routes: homeRoutes() })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    await waitFor(() => expect(echo.channels().length).toBe(4))

    unmount()

    await waitFor(() => expect(echo.channels()).toEqual([]))
    expect(echo.left).toEqual(expect.arrayContaining(['today', ...ids.map((id) => `product.${id}`)]))
  })

  it('unsubscribes when the member navigates away', async () => {
    const { router } = renderApp({ path: '/', routes: { ...homeRoutes(), 'GET /leaderboards': json({ data: [], meta: { period: 'week', topic: null, generated_at: '' } }) } })
    await screen.findByRole('heading', { level: 2, name: 'Alpha' })
    await waitFor(() => expect(echo.channels().length).toBe(4))

    await act(async () => {
      await router.navigate('/leaderboard')
    })

    await waitFor(() => expect(echo.channels()).toEqual([]))
  })

  it('keeps working (without live updates) when the server error-answers Today', async () => {
    renderApp({ path: '/', routes: { ...homeRoutes(), 'GET /today': apiError(500, 'x') as never } })

    expect(await screen.findByText("Could not load today's launches")).toBeTruthy()
    expect(echo.channels()).toEqual(['today'])
  })
})

describe('live updates are off without a key', () => {
  it('creates no Echo at all', async () => {
    removeFakeEcho()
    expect(await getLiveEcho()).toBeNull()

    renderApp({ path: '/', routes: homeRoutes() })

    expect(await screen.findByRole('heading', { level: 2, name: 'Alpha' })).toBeTruthy()
    expect(screen.getByText('Updates every 2 min')).toBeTruthy()
    expect(await getLiveEcho()).toBeNull()
  })

  it('survives a factory that fails', async () => {
    setEchoFactory(() => {
      throw new Error('no websocket here')
    })
    renderApp({ path: '/', routes: homeRoutes() })

    expect(await screen.findByRole('heading', { level: 2, name: 'Alpha' })).toBeTruthy()
    expect(await getLiveEcho()).toBeNull()
  })
})

describe('frame handling', () => {
  const seed = (qc: QueryClient) => qc.setQueryData<TodayDto>(keys.today, todayBody(list()))

  afterEach(() => vi.useRealTimers())

  it('parses only well-formed frames', () => {
    expect(parseRankFrame(frame([{ id: 'a', rank: 1, votes: 2 }]))).toEqual({ date: today(), items: [{ product_id: 'a', rank: 1, votes_count: 2 }] })
    expect(parseRankFrame({ date: today(), items: [{ product_id: 'a', rank: '1', votes_count: 2 }] })).toBeNull()
    expect(parseRankFrame({ items: [] })).toBeNull()
    expect(parseRankFrame('x')).toBeNull()
    expect(parseRankFrame(null)).toBeNull()
  })

  it('rejects frames with unsafe numbers, too many items or a bad date', () => {
    const item = (o: Record<string, unknown> = {}) => ({ product_id: 'a', rank: 1, votes_count: 2, ...o })
    const f = (items: unknown[], date: unknown = today()) => parseRankFrame({ date, items })

    expect(f([item({ votes_count: Number.POSITIVE_INFINITY })])).toBeNull()
    expect(f([item({ votes_count: Number.NaN })])).toBeNull()
    expect(f([item({ rank: Number.POSITIVE_INFINITY })])).toBeNull()
    expect(f([item({ rank: 0 })])).toBeNull()
    expect(f([item({ rank: 1.5 })])).toBeNull()
    expect(f([item({ rank: Number.MAX_SAFE_INTEGER + 2 })])).toBeNull()
    expect(f(Array.from({ length: 201 }, () => item()))).toBeNull()
    expect(f(Array.from({ length: 200 }, () => item()))).not.toBeNull()
    expect(f([item()], 'tomorrow')).toBeNull()
    expect(f([item()], '2026-1-1')).toBeNull()
    expect(f([item({ votes_count: 5e12 })])!.items[0].votes_count).toBe(1_000_000_000)
    expect(f([item({ votes_count: -3 })])!.items[0].votes_count).toBe(0)
  })

  it('waits at least 30 s between reconnect-triggered reads', () => {
    vi.useFakeTimers()
    const qc = createQueryClient()
    const invalidate = vi.spyOn(qc, 'invalidateQueries')

    refetchTodayThrottled(qc, { gapMs: 30_000 })
    expect(invalidate).toHaveBeenCalledTimes(1)
    refetchTodayThrottled(qc, { gapMs: 30_000 })
    vi.advanceTimersByTime(29_999)
    expect(invalidate).toHaveBeenCalledTimes(1)
    vi.advanceTimersByTime(1)
    expect(invalidate).toHaveBeenCalledTimes(2)
  })

  it('clamps a negative count to zero', () => {
    const qc = createQueryClient()
    seed(qc)

    applyVoteCount(qc, ids[0], -4)

    expect(cachedToday(qc).data[0].votes_count).toBe(0)
  })

  it('re-reads Today at most once per 5 s, and the verification read after a reorder is trailing', () => {
    vi.useFakeTimers()
    const qc = createQueryClient()
    seed(qc)
    const invalidate = vi.spyOn(qc, 'invalidateQueries')
    const swap = frame([{ id: ids[1], rank: 1, votes: 20 }, { id: ids[0], rank: 2, votes: 30 }, { id: ids[2], rank: 3, votes: 10 }])

    applyRankFrame(qc, swap)
    expect(cachedToday(qc).data.map((p) => p.id)).toEqual([ids[1], ids[0], ids[2]])
    expect(invalidate).not.toHaveBeenCalled()

    vi.advanceTimersByTime(4_999)
    expect(invalidate).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(invalidate).toHaveBeenCalledTimes(1)

    // An unknown product right after: the cache is out of date, but the gap since the last read is under 5 s.
    applyRankFrame(qc, frame([{ id: 'unknown', rank: 1, votes: 1 }], today()))
    applyRankFrame(qc, frame([{ id: 'unknown', rank: 1, votes: 1 }], today()))
    expect(invalidate).toHaveBeenCalledTimes(1)
    vi.advanceTimersByTime(5_000)
    expect(invalidate).toHaveBeenCalledTimes(2)
  })

  it('treats a frame for another day, or with nothing cached, as "read it again"', () => {
    vi.useFakeTimers()
    const qc = createQueryClient()
    const invalidate = vi.spyOn(qc, 'invalidateQueries')

    applyRankFrame(qc, frame([{ id: ids[0], rank: 1, votes: 1 }]))
    expect(invalidate).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(6_000)
    seed(qc)
    applyRankFrame(qc, frame([{ id: ids[0], rank: 1, votes: 1 }], addDays(today(), 1)))
    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(cachedToday(qc).data.map((p) => p.id)).toEqual(ids)
  })

  it('keeps cached products the frame does not list after the listed ones', () => {
    vi.useFakeTimers()
    const qc = createQueryClient()
    seed(qc)

    applyRankFrame(qc, frame([{ id: ids[2], rank: 1, votes: 12 }]))

    expect(cachedToday(qc).data.map((p) => [p.id, p.rank])).toEqual([[ids[2], 1], [ids[0], 2], [ids[1], 3]])
  })
})

describe('channel limits and recovery', () => {
  it('joins at most 50 product channels, however long the list is', async () => {
    const many = rankedList(60, (i) => ({ id: `01JKHVF${String(i).padStart(19, '0')}`, name: `P${i}` }))
    renderApp({ path: '/', routes: { ...homeRoutes(), 'GET /today': json(todayBody(many)) } })
    await screen.findByRole('heading', { level: 2, name: 'P0' })

    await waitFor(() => expect(echo.channels().length).toBe(51))
    expect(echo.channels().filter((c) => c.startsWith('product.'))).toHaveLength(50)
  })

  it('tries again on the next subscribe after the connection failed to load', async () => {
    const raw = echo.factory.getMockImplementation()!
    let fail = true
    setEchoFactory(() => {
      if (fail) throw new Error('chunk failed to load')
      return raw()
    })
    expect(await getLiveEcho()).toBeNull()

    fail = false
    expect(await getLiveEcho()).not.toBeNull()
  })
})
