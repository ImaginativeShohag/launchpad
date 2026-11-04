import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { addDays, today } from '@/lib/date'
import { apiError, json, type Handler, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { productRoutes } from '../helpers/productRoutes'
import { renderApp } from '../helpers/renderApp'
import { exportRow, hourly, PRODUCT_ID, rank, referrers, series, summary } from '../fixtures/dashboard'
import { apiProduct } from '../fixtures/product'
import { apiUser, publicUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.useRealTimers())

const PATH = `/my/products/${PRODUCT_ID}/dashboard`
const base = (): Record<string, RouteValue> => ({
  'GET /me/products/:id/dashboard': json(summary()),
  'GET /me/products/:id/dashboard/series': json(series(hourly([6, 3]))),
  'GET /me/products/:id/dashboard/rank': json(rank()),
  'GET /me/products/:id/dashboard/referrers': json(referrers()),
  'GET /me/products/:id/exports': json({ data: [] }),
})
const open = (routes: Record<string, RouteValue> = {}) => renderApp({ path: PATH, user: apiUser(), routes: { ...base(), ...routes } })

describe('dashboard', () => {
  it('shows the numbers, the votes and rank charts and where visitors came from', async () => {
    const { api } = open()

    expect(await screen.findByRole('heading', { name: 'Lumen Notes' })).toBeTruthy()
    expect(await screen.findByText('Rank today')).toBeTruthy()
    expect(screen.getByText('25.0% of viewers voted')).toBeTruthy()
    expect(await screen.findByRole('img', { name: 'votes chart' })).toBeTruthy()
    expect(await screen.findByRole('img', { name: 'Rank through the day' })).toBeTruthy()
    expect(await screen.findByText('X / Twitter')).toBeTruthy()
    expect(screen.getByText('Direct')).toBeTruthy()
    expect(screen.getByText('140 visits')).toBeTruthy()
    // The default launch-day read: hourly, no window written (the server clamps it).
    expect(api.callsTo('GET /me/products/:id/dashboard/series')[0].query.get('granularity')).toBe('hour')
    expect(api.callsTo('GET /me/products/:id/dashboard/series')[0].query.get('from')).toBeNull()
  })

  it('reads the daily series when "By day" is chosen, and Refresh re-reads everything', async () => {
    const { api } = open({ 'GET /me/products/:id/dashboard/series': ((call) => json(series(hourly([2, 1]), call.query.get('granularity') === 'day' ? 'day' : 'hour'))) as Handler })
    await screen.findByRole('img', { name: 'votes chart' })

    fireEvent.click(screen.getByRole('tab', { name: 'By day' }))
    await waitFor(() => expect(api.callsTo('GET /me/products/:id/dashboard/series').some((c) => c.query.get('granularity') === 'day')).toBe(true))

    await waitFor(() => expect((screen.getByRole('button', { name: 'Refresh' }) as HTMLButtonElement).disabled).toBe(false))
    const before = api.callsTo('GET /me/products/:id/dashboard').length
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await waitFor(() => expect(api.callsTo('GET /me/products/:id/dashboard').length).toBe(before + 1))
  })

  it('says so when nothing has happened yet', async () => {
    open({
      'GET /me/products/:id/dashboard': json(summary({ views: 0, votes: 0, conversion: null, current_rank: null, best_rank: null })),
      'GET /me/products/:id/dashboard/series': json(series(hourly([]))),
      'GET /me/products/:id/dashboard/rank': json(rank({ points: [], best_rank: null, current_rank: null })),
      'GET /me/products/:id/dashboard/referrers': json(referrers({ total: 0, sources: [] })),
    })

    expect(await screen.findByText('No votes yet.')).toBeTruthy()
    expect(await screen.findByText(/Rank shows once votes come in/)).toBeTruthy()
    expect(await screen.findByText('No visits recorded yet.')).toBeTruthy()
    expect(screen.getByText('No views yet')).toBeTruthy()
  })

  it('explains an expired rank timeline and keeps the final rank', async () => {
    open({
      'GET /me/products/:id/dashboard': json(summary({ phase: 'after', launch_date: addDays(today(), -60), current_rank: null, final_rank: 4, timeline_available: false })),
      'GET /me/products/:id/dashboard/rank': json(rank({ phase: 'after', timeline_available: false, points: [], best_rank: null, final_rank: 4 })),
      'GET /me/products/:id/dashboard/series': json(series(hourly([5]), 'hour', addDays(today(), -60))),
    })

    expect(await screen.findByText(/no longer kept for this launch day/)).toBeTruthy()
    expect(screen.getByText(/finished #4/)).toBeTruthy()
    expect(screen.getByText('Final rank')).toBeTruthy()
    expect(screen.getByText('Frozen at day end')).toBeTruthy()
  })

  it('shows the empty state before the launch day and reads nothing else', async () => {
    const { api } = renderApp({
      path: PATH,
      user: apiUser(),
      routes: { 'GET /me/products/:id/dashboard': json(summary({ phase: 'before_launch', status: 'scheduled', launch_date: addDays(today(), 3) })), 'GET /me/products/:id/exports': json({ data: [] }) },
    })

    expect(await screen.findByText('Numbers appear on launch day')).toBeTruthy()
    expect(api.calls.some((c) => c.path.endsWith('/series') || c.path.endsWith('/rank') || c.path.endsWith('/referrers'))).toBe(false)
  })

  it('shows a banner for a taken-down product', async () => {
    open({ 'GET /me/products/:id/dashboard': json(summary({ taken_down: true, status: 'taken_down', phase: 'after' })) })
    expect((await screen.findByRole('alert')).textContent).toContain('taken down by staff')
    expect(screen.queryByRole('link', { name: /Public page/ })).toBeNull()
  })

  it('shows not found for a product that is not the caller’s (404)', async () => {
    renderApp({ path: PATH, user: apiUser(), routes: { 'GET /me/products/:id/dashboard': apiError(404, 'Not found.'), 'GET /me/products/:id/exports': apiError(404, 'Not found.') } })
    expect(await screen.findByText('Product not found')).toBeTruthy()
  })

  it('shows the wait when the dashboard is rate limited (429) and recovers on Retry', async () => {
    let limited = true
    open({ 'GET /me/products/:id/dashboard': (() => (limited ? apiError(429, 'x', {}, { 'Retry-After': '1' }) : json(summary()))) as Handler })

    expect(await screen.findByText('Too many attempts. Try again in 1 s.')).toBeTruthy()
    limited = false
    await waitFor(() => expect((screen.getByRole('button', { name: /Retry/ }) as HTMLButtonElement).disabled).toBe(false), { timeout: 4000 })
    fireEvent.click(screen.getByRole('button', { name: /Retry/ }))
    expect(await screen.findByRole('heading', { name: 'Lumen Notes' })).toBeTruthy()
  })

  it('does not poll on a timer', async () => {
    const { api } = open()
    await screen.findByRole('img', { name: 'Rank through the day' })
    await screen.findByText('X / Twitter')
    const settled = api.calls.length
    vi.useFakeTimers()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(120_000)
    })
    expect(api.calls.length).toBe(settled)
  })
})

describe('export', () => {
  let clicked: { download: string; href: string }[]
  beforeEach(() => {
    clicked = []
    URL.createObjectURL = vi.fn(() => 'blob:zip')
    URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked.push({ download: this.download, href: this.href })
    })
  })
  afterEach(() => vi.restoreAllMocks())

  it('picks up an export that is already running when the page opens', async () => {
    const { api } = open({
      'GET /me/products/:id/exports': json({ data: [exportRow({ status: 'processing' })] }),
      'GET /me/products/:id/exports/:export': json({ data: exportRow({ status: 'ready', file_size: 10 }) }),
    })
    expect(await screen.findByText(/Preparing your file/)).toBeTruthy()
    expect(api.callsTo('POST /me/products/:id/exports')).toHaveLength(0)
  })

  it('shows a failed export with a way to try again, and an expired one', async () => {
    const { api } = open({ 'GET /me/products/:id/exports': json({ data: [exportRow({ status: 'failed', error: 'too_large' })] }), 'POST /me/products/:id/exports': json({ data: exportRow({ id: '01JEXPORT000000000000000002' }) }, 202) })
    expect((await screen.findByRole('alert', {}, { timeout: 5000 })).textContent).toContain('too large')
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
    await waitFor(() => expect(api.callsTo('POST /me/products/:id/exports')).toHaveLength(1))
  })

  it('turns an expired download (410) into the expired state', async () => {
    open({
      'GET /me/products/:id/exports': json({ data: [exportRow({ status: 'ready', file_size: 10 })] }),
      'GET /me/products/:id/exports/:export/download': apiError(410, 'gone', { code: 'export_expired' }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Download zip/ }))

    expect(await screen.findByText('This export has expired. Start a new one.')).toBeTruthy()
    expect(await screen.findByText(/This export has expired. Files are kept/)).toBeTruthy()
  })

  it('reads the export again when the download says it is not ready (409)', async () => {
    let status: 'ready' | 'processing' = 'ready'
    const { api } = open({
      'GET /me/products/:id/exports': (() => json({ data: [exportRow({ status, file_size: 10 })] })) as Handler,
      'GET /me/products/:id/exports/:export': json({ data: exportRow({ status: 'processing' }) }),
      'GET /me/products/:id/exports/:export/download': apiError(409, 'not yet', { code: 'export_not_ready' }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Download zip/ }))
    status = 'processing'

    expect(await screen.findByText('This export is not ready to download yet.')).toBeTruthy()
    expect(await screen.findByText(/Preparing your file/)).toBeTruthy()
    expect(api.callsTo('GET /me/products/:id/exports').length).toBeGreaterThanOrEqual(2)
  })

  it('shows the wait when starting an export is rate limited (429)', async () => {
    open({ 'POST /me/products/:id/exports': apiError(429, 'x', {}, { 'Retry-After': '1800' }) })
    fireEvent.click(await screen.findByRole('button', { name: /Download all data/ }))
    expect(await screen.findByText('Too many attempts. Try again in 1800 s.')).toBeTruthy()
  })
})

describe('product page', () => {
  it('gives the maker an "Open dashboard" link', async () => {
    const maker = publicUser({ id: '01JAMINA0000000000000000000', handle: 'amina_r', name: 'Amina Rahman' })
    renderApp({ path: '/p/lumen-notes', user: apiUser(), routes: productRoutes(apiProduct({ id: PRODUCT_ID, slug: 'lumen-notes', name: 'Lumen Notes', maker })) })

    const links = await screen.findAllByRole('link', { name: /Open dashboard/ })
    expect(links[0].getAttribute('href')).toBe(`/my/products/${PRODUCT_ID}/dashboard`)
  })

  it('does not show it to other visitors', async () => {
    renderApp({ path: '/p/lumen-notes', user: apiUser({ id: '01JOTHER0000000000000000000', handle: 'other' }), routes: productRoutes(apiProduct({ id: PRODUCT_ID, slug: 'lumen-notes', name: 'Lumen Notes' })) })
    await screen.findByRole('heading', { name: 'Lumen Notes' })
    expect(screen.queryByRole('link', { name: /Open dashboard/ })).toBeNull()
  })
})

// Last on purpose: the poll timer needs a fake setInterval, which also stops jsdom's requestAnimationFrame (and so motion) for the rest of the file.
describe('export polling', () => {
  let clicked: { download: string; href: string }[]
  beforeEach(() => {
    clicked = []
    URL.createObjectURL = vi.fn(() => 'blob:zip')
    URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked.push({ download: this.download, href: this.href })
    })
  })
  afterEach(() => vi.restoreAllMocks())

  it('requests an export, polls at the pace the server asks, and downloads the zip through the session', async () => {
    let status: 'queued' | 'processing' | 'ready' = 'queued'
    const { api } = open({
      'POST /me/products/:id/exports': json({ data: exportRow() }, 202),
      'GET /me/products/:id/exports/:export': (() => json({ data: exportRow({ status, file_size: status === 'ready' ? 2048 : null, expires_at: `${addDays(today(), 7)}T00:00:00Z` }) }, 200, status === 'ready' ? {} : { 'Retry-After': '3' })) as Handler,
      'GET /me/products/:id/exports/:export/download': { status: 200, body: 'PKzip', headers: { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="sub/dir\\x.zip"' } },
    })
    const start = await screen.findByRole('button', { name: /Download all data/ })
    // Fake timers before the click: the poll timer is created when the export arrives.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
    fireEvent.click(start)
    await vi.waitFor(() => expect(screen.getByText(/Preparing your file/)).toBeTruthy())

    status = 'processing'
    await act(async () => {
      await vi.advanceTimersByTimeAsync(5000)
    })
    await vi.waitFor(() => expect(api.callsTo('GET /me/products/:id/exports/:export')).toHaveLength(1))
    // Retry-After: 3 moves the next poll to 3 s after this one, not 5.
    status = 'ready'
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2000)
    })
    expect(api.callsTo('GET /me/products/:id/exports/:export')).toHaveLength(1)
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1500)
    })
    await vi.waitFor(() => expect(screen.getByText(/Your file is ready \(2 KB\)/)).toBeTruthy())
    // Ready: the polling stops.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000)
    })
    expect(api.callsTo('GET /me/products/:id/exports/:export')).toHaveLength(2)

    vi.useRealTimers()
    fireEvent.click(screen.getByRole('button', { name: /Download zip/ }))

    await waitFor(() => expect(clicked).toHaveLength(1))
    expect(clicked[0].download).toBe('subdirx.zip')
    expect(clicked[0].href).toBe('blob:zip')
    await waitFor(() => expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:zip'))
    const call = api.callsTo('GET /me/products/:id/exports/:export/download')[0]
    expect(call.credentials).toBe('include')
  })

  it('stops polling and shows the error with Retry when the export is gone (404)', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
    const { api } = open({
      'GET /me/products/:id/exports': json({ data: [exportRow({ status: 'processing' })] }),
      'GET /me/products/:id/exports/:export': apiError(404, 'Not found.'),
    })
    await vi.waitFor(() => expect(screen.getByText(/Preparing your file/)).toBeTruthy(), { timeout: 5000 })

    await act(async () => {
      await vi.advanceTimersByTimeAsync(5500)
    })
    await vi.waitFor(() => expect(screen.getByText('Not found.')).toBeTruthy(), { timeout: 5000 })
    const polls = api.callsTo('GET /me/products/:id/exports/:export').length
    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000)
    })
    expect(api.callsTo('GET /me/products/:id/exports/:export')).toHaveLength(polls)

    // Retry forgets the old export and reads the latest list again.
    api.set('GET /me/products/:id/exports', json({ data: [exportRow({ status: 'expired' })] }))
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    await vi.waitFor(() => expect(screen.getByText(/This export has expired/)).toBeTruthy(), { timeout: 5000 })
  })

  it('stops polling when the page is left', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
    const { api, unmount } = open({
      'GET /me/products/:id/exports': json({ data: [exportRow({ status: 'processing' })] }),
      'GET /me/products/:id/exports/:export': json({ data: exportRow({ status: 'processing' }) }, 200, { 'Retry-After': '5' }),
    })
    await vi.waitFor(() => expect(screen.getByText(/Preparing your file/)).toBeTruthy(), { timeout: 5000 })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(5500)
    })
    await vi.waitFor(() => expect(api.callsTo('GET /me/products/:id/exports/:export').length).toBeGreaterThanOrEqual(1), { timeout: 5000 })
    unmount()
    const polls = api.callsTo('GET /me/products/:id/exports/:export').length

    await vi.advanceTimersByTimeAsync(60_000)

    expect(api.callsTo('GET /me/products/:id/exports/:export')).toHaveLength(polls)
  })
})
