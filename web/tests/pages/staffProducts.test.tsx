import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { apiError, json, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { layoutRoutes } from '../helpers/staffRoutes'
import { integrity, page, staffProduct, staffRow, staffVote } from '../fixtures/staff'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const reviewer = () => apiUser({ role: 'reviewer', id: '01JREVIEWER000000000000001', handle: 'kenji' })
const admin = () => apiUser({ role: 'admin', id: '01JADMIN00000000000000001A', handle: 'sofia' })
const L = '01JPRKDL00000000000000001L'
const T = '01JPRKDT00000000000000001T'

const rows = () => [staffRow({ id: L, name: 'Live One', status: 'live' }), staffRow({ id: T, name: 'Down One', status: 'taken_down', takedown_reason: 'Scam site' })]
const base = (extra: Record<string, unknown> = {}) => ({ ...layoutRoutes(), 'GET /staff/products': json(page(rows())), ...extra })

describe('staff products', () => {
  it('lists non-draft products with their state', async () => {
    renderApp({ path: '/staff/products', user: admin(), routes: base() })
    expect(await screen.findByText('Live One')).toBeTruthy()
    expect(screen.getByText('Down One')).toBeTruthy()
    expect(screen.getByText('Taken down: Scam site')).toBeTruthy()
    expect(screen.getAllByText('42')).toHaveLength(2)
  })

  it('sends the status filter and a search of two or more characters only', async () => {
    const { api } = renderApp({ path: '/staff/products', user: admin(), routes: base() })
    await screen.findByText('Live One')

    fireEvent.click(screen.getByRole('tab', { name: 'Live' }))
    await waitFor(() => expect(api.callsTo('GET /staff/products').some((c) => c.query.get('status') === 'live')).toBe(true))

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'l' } })
    expect(await screen.findByText('Type at least two characters to search.')).toBeTruthy()
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'lum' } })
    await waitFor(() => expect(api.callsTo('GET /staff/products').some((c) => c.query.get('q') === 'lum')).toBe(true))
    expect(api.callsTo('GET /staff/products').some((c) => c.query.get('q') === 'l')).toBe(false)
  })

  it('pages by cursor', async () => {
    const { api } = renderApp({
      path: '/staff/products',
      user: reviewer(),
      routes: base({ 'GET /staff/products': ((call) => (call.query.get('cursor') === 'c2' ? json(page([staffRow({ name: 'Second Page' })])) : json(page(rows(), 'c2')))) as Handler }),
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))
    expect(await screen.findByText('Second Page')).toBeTruthy()
    expect(api.callsTo('GET /staff/products').some((c) => c.query.get('cursor') === 'c2')).toBe(true)
  })

  it('hides take down and restore from a reviewer', async () => {
    renderApp({ path: '/staff/products', user: reviewer(), routes: base() })
    await screen.findByText('Live One')
    expect(screen.queryByRole('button', { name: /Take down/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /Restore/ })).toBeNull()
    expect(screen.getByText('Takedowns and restores are admin-only.')).toBeTruthy()
  })

  it('takes a live product down with a required reason', async () => {
    const { api } = renderApp({ path: '/staff/products', user: admin(), routes: base({ 'POST /staff/products/:id/take-down': json({ data: staffProduct({ id: L, status: 'taken_down' }) }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Take down/ }))
    const dialog = await screen.findByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Take down' }))
    expect(await within(dialog).findByText('Add a reason before continuing.')).toBeTruthy()
    expect(api.callsTo('POST /staff/products/:id/take-down')).toHaveLength(0)

    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Misleading claims' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Take down' }))

    await waitFor(() => expect(api.callsTo('POST /staff/products/:id/take-down')).toHaveLength(1))
    expect(api.callsTo('POST /staff/products/:id/take-down')[0].path).toBe(`/staff/products/${L}/take-down`)
    expect(api.callsTo('POST /staff/products/:id/take-down')[0].body).toEqual({ reason: 'Misleading claims' })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  })

  it('shows the API refusal in the dialog', async () => {
    renderApp({ path: '/staff/products', user: admin(), routes: base({ 'POST /staff/products/:id/take-down': apiError(409, 'x', { code: 'invalid_status' }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Take down/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Because' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Take down' }))
    expect(await within(dialog).findByText(/no longer in a state where that works/)).toBeTruthy()
  })

  it('a reviewer who calls it anyway gets the 403 text', async () => {
    renderApp({ path: '/staff/products', user: admin(), routes: base({ 'POST /staff/products/:id/take-down': apiError(403, 'This action is unauthorized.') }) })
    fireEvent.click(await screen.findByRole('button', { name: /Take down/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Because' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Take down' }))
    expect(await within(dialog).findByText(/not allowed to do that/)).toBeTruthy()
  })

  it('restores a taken-down product and reads the list again', async () => {
    let calls = 0
    const { api } = renderApp({
      path: '/staff/products',
      user: admin(),
      routes: base({
        'GET /staff/products': (() => json(page(calls ? [staffRow({ id: T, name: 'Down One', status: 'live' })] : rows()))) as Handler,
        'POST /staff/products/:id/restore': (() => {
          calls++
          return json({ data: staffProduct({ id: T, status: 'live' }) })
        }) as Handler,
      }),
    })
    fireEvent.click(await screen.findByRole('button', { name: /Restore/ }))
    await waitFor(() => expect(api.callsTo('POST /staff/products/:id/restore')).toHaveLength(1))
    await waitFor(() => expect(screen.queryByText('Taken down: Scam site')).toBeNull())
  })

  it('shows a restore refusal in the row', async () => {
    renderApp({ path: '/staff/products', user: admin(), routes: base({ 'POST /staff/products/:id/restore': apiError(409, 'x', { code: 'maker_unavailable' }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Restore/ }))
    expect(await screen.findByText(/suspended or deleted/)).toBeTruthy()
  })

  it('shows the 429 wait when taking down', async () => {
    renderApp({ path: '/staff/products', user: admin(), routes: base({ 'POST /staff/products/:id/take-down': apiError(429, 'x', {}, { 'Retry-After': '9' }) }) })
    fireEvent.click(await screen.findByRole('button', { name: /Take down/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Reason' }), { target: { value: 'Because' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Take down' }))
    expect(await within(dialog).findByText(/Try again in 9 s/)).toBeTruthy()
  })

  it('shows not allowed when the API refuses the list', async () => {
    renderApp({ path: '/staff/products', user: reviewer(), routes: base({ 'GET /staff/products': apiError(403, 'no') }) })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
  })
})

describe('vote integrity', () => {
  it('shows the counts, the shared fingerprints and the votes, 8 hex characters and nothing more', async () => {
    renderApp({
      path: `/staff/products/${L}/integrity`,
      user: reviewer(),
      routes: {
        ...layoutRoutes(),
        'GET /staff/products/:id/vote-integrity': json({ data: integrity() }),
        'GET /staff/products/:id/votes': json(page([staffVote(), staffVote({ status: 'flagged', device_fp: null })])),
      },
    })
    expect(await screen.findByRole('heading', { name: /Vote integrity: Crypto Moon/ })).toBeTruthy()
    expect(screen.getByText(/watch · 90%/)).toBeTruthy()
    expect(screen.getByText('deadbeef')).toBeTruthy()
    expect(await screen.findAllByText('ab12cd34')).toHaveLength(1)
    expect(screen.getAllByText('99887766')).toHaveLength(2)
    for (const code of Array.from(document.querySelectorAll('code'))) expect(code.textContent).toMatch(/^[0-9a-f]{8}$/)
  })

  it('filters the votes by status', async () => {
    const { api } = renderApp({
      path: `/staff/products/${L}/integrity`,
      user: reviewer(),
      routes: { ...layoutRoutes(), 'GET /staff/products/:id/vote-integrity': json({ data: integrity() }), 'GET /staff/products/:id/votes': json(page([staffVote()])) },
    })
    await screen.findByRole('heading', { name: /Vote integrity/ })
    fireEvent.change(screen.getByLabelText('Vote status'), { target: { value: 'flagged' } })
    await waitFor(() => expect(api.callsTo('GET /staff/products/:id/votes').some((c) => c.query.get('status') === 'flagged')).toBe(true))
  })

  it('shows not found for a hidden product', async () => {
    renderApp({ path: `/staff/products/${L}/integrity`, user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/products/:id/vote-integrity': apiError(404, 'Not found.'), 'GET /staff/products/:id/votes': apiError(404, 'Not found.') } })
    expect(await screen.findByText('Not found')).toBeTruthy()
  })
})
