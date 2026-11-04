import { act, fireEvent, renderHook, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { rememberSearch } from '@/lib/recentSearches'
import { useRankMoves } from '@/lib/rankMoves'
import { addDays, today } from '@/lib/date'
import { apiError, json } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { apiProduct, dayBody, memberReason, pageBody, topicsBody } from '../fixtures/product'
import { noFollows } from '../fixtures/profile'
import { apiUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.useRealTimers())

describe('a failed next page keeps the list', () => {
  const page = (call: { query: URLSearchParams }, ok: () => boolean) =>
    call.query.get('cursor') === 'c2' ? (ok() ? json(pageBody([apiProduct({ name: 'Second Page' })])) : apiError(500, 'boom')) : json(pageBody([apiProduct({ name: 'First Page' })], 'c2', { query: null }))

  it('on search', async () => {
    let ok = false
    renderApp({ path: '/search?topic=ai', routes: { 'GET /topics': json(topicsBody()), 'GET /leaderboards': json({ data: [], meta: { period: 'all', topic: null, generated_at: '' } }), 'GET /search': (call) => page(call, () => ok) } })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Could not load more.')).toBeTruthy()
    expect(screen.getByText('First Page')).toBeTruthy()
    expect(screen.queryByText('Search is unavailable')).toBeNull()

    ok = true
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('Second Page')).toBeTruthy()
    expect(screen.getByText('First Page')).toBeTruthy()
  })

  it('on a topic', async () => {
    let ok = false
    renderApp({ path: '/topics/ai', routes: { 'GET /topics': json(topicsBody()), 'GET /search': (call) => page(call, () => ok) } })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Could not load more.')).toBeTruthy()
    expect(screen.getByText('First Page')).toBeTruthy()
    expect(screen.queryByText('Could not load these launches')).toBeNull()

    ok = true
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('Second Page')).toBeTruthy()
  })

  it('on the feed', async () => {
    let ok = false
    renderApp({
      path: '/feed',
      user: apiUser(),
      routes: {
        ...noFollows(),
        'GET /topics': json(topicsBody()),
        'GET /me/feed': (call) =>
          call.query.get('cursor') === 'c2'
            ? ok
              ? json({ ...pageBody([apiProduct({ name: 'Second Page' })]), meta: { next_cursor: null, has_follows: true } })
              : apiError(500, 'boom')
            : json({ ...pageBody([{ ...apiProduct({ name: 'First Page' }), reasons: [memberReason()] }], 'c2'), meta: { next_cursor: 'c2', has_follows: true } }),
      },
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Could not load more.')).toBeTruthy()
    expect(screen.getByText('First Page')).toBeTruthy()
    expect(screen.queryByText('Could not load your feed')).toBeNull()

    ok = true
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('Second Page')).toBeTruthy()
  })
})

describe('search input', () => {
  it('stops at 100 characters and remembers at most 100', async () => {
    renderApp({ path: '/search', routes: { 'GET /topics': json(topicsBody()), 'GET /leaderboards': json({ data: [], meta: { period: 'all', topic: null, generated_at: '' } }) } })

    expect((await screen.findByLabelText('Search products')).getAttribute('maxlength')).toBe('100')
    expect(rememberSearch([], 'x'.repeat(150))[0]).toHaveLength(100)
  })
})

describe('Day page keys', () => {
  const date = addDays(today(), -3)
  const routes = { 'GET /days/:date': (_c: unknown, p: Record<string, string>) => json(dayBody([], p.date)) }

  it('step to the previous day, but not with a modifier or inside an editable element', async () => {
    const { router } = renderApp({ path: `/day/${date}`, routes })
    await screen.findByText('A quiet day')

    fireEvent.keyDown(document.body, { key: 'ArrowLeft', metaKey: true })
    fireEvent.keyDown(document.body, { key: 'ArrowLeft', altKey: true })
    fireEvent.keyDown(document.body, { key: 'ArrowLeft', ctrlKey: true })
    const select = document.body.appendChild(document.createElement('select'))
    fireEvent.keyDown(select, { key: 'ArrowLeft' })
    const editable = document.body.appendChild(document.createElement('div'))
    Object.defineProperty(editable, 'isContentEditable', { value: true })
    fireEvent.keyDown(editable, { key: 'ArrowLeft' })
    expect(router.state.location.pathname).toBe(`/day/${date}`)

    fireEvent.keyDown(document.body, { key: 'ArrowLeft' })
    await waitFor(() => expect(router.state.location.pathname).toBe(`/day/${addDays(date, -1)}`))
    select.remove()
    editable.remove()
  })
})

describe('retry honours Retry-After', () => {
  it('on the topics list', async () => {
    renderApp({ path: '/topics', routes: { 'GET /topics': apiError(429, 'Slow down', {}, { 'Retry-After': '9' }) } })

    expect((await screen.findByRole('button', { name: /Retry in \d s/ })).hasAttribute('disabled')).toBe(true)
  })
})

describe('useRankMoves', () => {
  const at = (...order: string[]) => order.map((id, i) => ({ id, rank: i + 1 }))

  it('expires each pill by its own token, so an older timer never clears a newer pill', () => {
    vi.useFakeTimers()
    const { result, rerender } = renderHook(({ list }) => useRankMoves(list, 8000), { initialProps: { list: at('a', 'b') } })

    rerender({ list: at('b', 'a') })
    expect(result.current).toEqual({ b: 1, a: -1 })

    act(() => vi.advanceTimersByTime(5000))
    rerender({ list: at('a', 'b') })
    expect(result.current).toEqual({ a: 1, b: -1 })

    // The first reorder's timer fires at 8 s: the pills of the second reorder (the same values) must survive.
    act(() => vi.advanceTimersByTime(3500))
    expect(result.current).toEqual({ a: 1, b: -1 })

    act(() => vi.advanceTimersByTime(5000))
    expect(result.current).toEqual({})
  })
})
