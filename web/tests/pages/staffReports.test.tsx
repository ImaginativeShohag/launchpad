import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { apiError, json, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { layoutRoutes } from '../helpers/staffRoutes'
import { commentReport, historyRow, outcome, page, productReport, reportRow, reportsBody } from '../fixtures/staff'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const reviewer = () => apiUser({ role: 'reviewer', id: '01JREVIEWER000000000000001', handle: 'kenji' })
const admin = () => apiUser({ role: 'admin', id: '01JADMIN00000000000000001A', handle: 'sofia' })
const C = '01JCOMMENT0000000000000001'
const P = '01JPRODUCT0000000000000001'

describe('reports queue', () => {
  it('lists reported targets with their counts and reasons', async () => {
    const rows = [reportRow({ target: { ...reportRow().target, id: C }, reasons: { spam: 2, abuse: 1 }, open_reports: 3 }), reportRow({ type: 'product', target: { id: P, slug: 'moon', name: 'Crypto Moon', status: 'live', maker: { id: 'u3', handle: 'lee' } }, reasons: { misleading: 1 }, open_reports: 1 })]
    const { api } = renderApp({ path: '/staff/reports', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/reports': json(reportsBody(rows, { total_targets: 2 })) } })

    expect(await screen.findByText('Buy followers here')).toBeTruthy()
    expect(screen.getByText('spam · 2')).toBeTruthy()
    expect(screen.getByText('3 open reports')).toBeTruthy()
    expect(screen.getByText('Crypto Moon')).toBeTruthy()
    expect(screen.getByRole('link', { name: /Buy followers here/ }).getAttribute('href')).toBe(`/staff/reports/comment/${C}`)
    expect(api.callsTo('GET /staff/reports').some((c) => c.query.has('type'))).toBe(false)
  })

  it('filters by type through the query', async () => {
    const { api } = renderApp({ path: '/staff/reports', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/reports': json(reportsBody([reportRow()])) } })
    await screen.findByText('Buy followers here')

    fireEvent.click(screen.getByRole('button', { name: /Products/ }))

    await waitFor(() => expect(api.callsTo('GET /staff/reports').some((c) => c.query.get('type') === 'product')).toBe(true))
  })

  it('says when the list was cut at 100', async () => {
    renderApp({ path: '/staff/reports', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/reports': json(reportsBody([reportRow()], { total_targets: 140, truncated: true })) } })
    expect(await screen.findByText(/Showing 1 of 140 reported items/)).toBeTruthy()
  })

  it('shows the empty state and the closed history with a cursor', async () => {
    const { api } = renderApp({
      path: '/staff/reports',
      user: reviewer(),
      routes: {
        ...layoutRoutes(),
        'GET /staff/reports/history': ((call) => (call.query.get('cursor') === 'h2' ? json(page([historyRow({ resolution_note: 'Second page' })])) : json(page([historyRow({ resolution_note: 'Removed: spam' })], 'h2')))) as Handler,
      },
    })
    expect(await screen.findByText('No open reports')).toBeTruthy()

    fireEvent.click(screen.getByRole('tab', { name: /Resolved/ }))
    expect(await screen.findByText('Removed: spam')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Second page')).toBeTruthy()
    expect(api.callsTo('GET /staff/reports/history').some((c) => c.query.get('cursor') === 'h2')).toBe(true)
  })

  it('shows not allowed when the API says 403', async () => {
    renderApp({ path: '/staff/reports', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/reports': apiError(403, 'no') } })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
  })
})

const detailRoutes = (detail: unknown, extra: Record<string, unknown> = {}) => ({
  ...layoutRoutes(),
  'GET /staff/reports/:type/:id': json({ data: detail }),
  ...extra,
})

describe('report detail', () => {
  it('offers remove and dismiss for a comment and no take down', async () => {
    renderApp({ path: `/staff/reports/comment/${C}`, user: reviewer(), routes: detailRoutes(commentReport(C)) })
    expect(await screen.findByText('Buy followers at spam.example')).toBeTruthy()
    expect(screen.getByRole('button', { name: /Remove comment/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /Dismiss/ })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Take down/ })).toBeNull()
    expect(screen.getByText('Same message everywhere', { exact: false })).toBeTruthy()
  })

  it('hides take down from a reviewer and shows it to an admin; never remove comment on a product', async () => {
    const { unmount } = renderApp({ path: `/staff/reports/product/${P}`, user: reviewer(), routes: detailRoutes(productReport(P)) })
    expect(await screen.findByRole('heading', { name: 'Crypto Moon' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Take down/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /Remove comment/ })).toBeNull()
    expect(screen.getByText(/Takedowns are admin-only/)).toBeTruthy()
    unmount()

    renderApp({ path: `/staff/reports/product/${P}`, user: admin(), routes: detailRoutes(productReport(P)) })
    expect(await screen.findByRole('button', { name: /Take down/ })).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Remove comment/ })).toBeNull()
  })

  it('requires a reason to remove, then resolves and goes back to a queue that no longer has the row', async () => {
    let open = true
    const { api, router } = renderApp({
      path: `/staff/reports/comment/${C}`,
      user: reviewer(),
      routes: detailRoutes(commentReport(C), {
        'GET /staff/reports': (() => json(reportsBody(open ? [reportRow({ target: { ...reportRow().target, id: C } })] : []))) as Handler,
        'POST /staff/reports/:type/:id/resolve': (() => {
          open = false
          return json({ data: outcome({ closed: 1, action: 'remove_comment', target_changed: true, target_id: C }) })
        }) as Handler,
      }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Remove comment/ }))
    const dialog = await screen.findByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove comment' }))
    expect(await within(dialog).findByText('Add a reason before continuing.')).toBeTruthy()
    expect(api.callsTo('POST /staff/reports/:type/:id/resolve')).toHaveLength(0)

    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Spam link' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove comment' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/reports'))
    expect(api.callsTo('POST /staff/reports/:type/:id/resolve')[0].body).toEqual({ action: 'remove_comment', note: 'Spam link' })
    expect(api.callsTo('POST /staff/reports/:type/:id/resolve')[0].path).toBe(`/staff/reports/comment/${C}/resolve`)
    expect(await screen.findByText('No open reports')).toBeTruthy()
    expect(screen.queryByText('Buy followers here')).toBeNull()
  })

  it('dismisses without a note', async () => {
    const { api } = renderApp({
      path: `/staff/reports/comment/${C}`,
      user: reviewer(),
      routes: detailRoutes(commentReport(C), { 'POST /staff/reports/:type/:id/resolve': json({ data: outcome({ action: 'dismiss' }) }) }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Dismiss/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Dismiss' }))
    await waitFor(() => expect(api.callsTo('POST /staff/reports/:type/:id/resolve')).toHaveLength(1))
    expect(api.callsTo('POST /staff/reports/:type/:id/resolve')[0].body).toEqual({ action: 'dismiss', note: null })
  })

  it('an admin takes a product down with a reason', async () => {
    const { api } = renderApp({
      path: `/staff/reports/product/${P}`,
      user: admin(),
      routes: detailRoutes(productReport(P), { 'POST /staff/reports/:type/:id/resolve': json({ data: outcome({ type: 'product', action: 'take_down', target_changed: true }) }) }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Take down/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Scam' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Take down' }))
    await waitFor(() => expect(api.callsTo('POST /staff/reports/:type/:id/resolve')).toHaveLength(1))
    expect(api.callsTo('POST /staff/reports/:type/:id/resolve')[0].body).toEqual({ action: 'take_down', note: 'Scam' })
  })

  it.each([
    [409, 'invalid_status', /no longer in a state where that works/],
    [403, 'forbidden', /not allowed to do that/],
    [404, 'http_404', /no longer exists/],
  ])('shows a %s from resolve in the dialog and keeps it open', async (status, code, text) => {
    renderApp({ path: `/staff/reports/comment/${C}`, user: reviewer(), routes: detailRoutes(commentReport(C), { 'POST /staff/reports/:type/:id/resolve': apiError(status, 'x', { code }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Dismiss/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Dismiss' }))
    expect(await within(await screen.findByRole('dialog')).findByText(text)).toBeTruthy()
  })

  it('shows the 429 wait', async () => {
    renderApp({ path: `/staff/reports/comment/${C}`, user: reviewer(), routes: detailRoutes(commentReport(C), { 'POST /staff/reports/:type/:id/resolve': apiError(429, 'x', {}, { 'Retry-After': '12' }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Dismiss/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Dismiss' }))
    expect(await within(await screen.findByRole('dialog')).findByText(/Try again in 12 s/)).toBeTruthy()
  })

  it('a replay that closed nothing says so', async () => {
    const { router } = renderApp({
      path: `/staff/reports/comment/${C}`,
      user: reviewer(),
      routes: detailRoutes(commentReport(C), { 'POST /staff/reports/:type/:id/resolve': json({ data: outcome({ closed: 0 }) }) }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Dismiss/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Dismiss' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/reports'))
    expect(await screen.findByText('Nothing left to resolve')).toBeTruthy()
  })

  it('unknown type is not found and asks nothing', async () => {
    const { api } = renderApp({ path: '/staff/reports/widget/abc', user: reviewer(), routes: layoutRoutes() })
    expect(await screen.findByText('Not found')).toBeTruthy()
    expect(api.callsTo('GET /staff/reports/:type/:id')).toHaveLength(0)
  })

  it('shows not found when the API hides the target', async () => {
    renderApp({ path: `/staff/reports/comment/${C}`, user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/reports/:type/:id': apiError(404, 'Not found.') } })
    expect(await screen.findByText('Not found')).toBeTruthy()
  })

  it('marks reports whose reporter no longer counts', async () => {
    const detail = commentReport(C)
    detail.open_reports = [{ ...detail.open_reports[0], reporter_eligible: false }]
    renderApp({ path: `/staff/reports/comment/${C}`, user: reviewer(), routes: detailRoutes(detail) })
    expect(await screen.findByText('does not count')).toBeTruthy()
  })
})
