import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { apiError, json, validation, type Handler } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { layoutRoutes } from '../helpers/staffRoutes'
import { microsAgo, page, staffProduct } from '../fixtures/staff'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const reviewer = () => apiUser({ role: 'reviewer', id: '01JREVIEWER000000000000001', handle: 'kenji' })
const A = '01JPRKDA00000000000000001A'
const B = '01JPRKDB00000000000000001B'
const key = (e: HTMLElement | Document, k: string, o: KeyboardEventInit = {}) => fireEvent.keyDown(e === document ? document.body : e, { key: k, ...o })

describe('review queue', () => {
  it('lists the API order (oldest first) with the wait derived from submitted_at', async () => {
    const old = staffProduct({ id: A, name: 'Old One', submitted_at: microsAgo(30) })
    const fresh = staffProduct({ id: B, name: 'Fresh One', submitted_at: microsAgo(2) })
    const { api } = renderApp({ path: '/staff', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/review-queue': json(page([old, fresh])) } })

    const items = await screen.findAllByRole('heading', { level: 3 })
    expect(items.map((h) => h.textContent)).toEqual(['Old One', 'Fresh One'])
    expect(screen.getByText('waiting 30h')).toBeTruthy()
    expect(screen.getByText('waiting 2h')).toBeTruthy()
    expect(screen.getByText('Over 24h').nextElementSibling?.textContent).toBe('1')
    // No auto risk flags any more: nothing but the API's data.
    expect(screen.queryByText(/High-risk/)).toBeNull()
    expect(api.callsTo('GET /staff/review-queue')[0].query.get('per_page')).toBe('50')
  })

  it('loads the next page by cursor', async () => {
    const first = staffProduct({ id: A, name: 'First' })
    const second = staffProduct({ id: B, name: 'Second' })
    const { api } = renderApp({
      path: '/staff',
      user: reviewer(),
      routes: { ...layoutRoutes(), 'GET /staff/review-queue': ((call) => (call.query.get('cursor') === 'c2' ? json(page([second])) : json(page([first], 'c2')))) as Handler },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Load more' }))

    expect(await screen.findByRole('heading', { name: 'Second' })).toBeTruthy()
    expect(api.callsTo('GET /staff/review-queue').some((c) => c.query.get('cursor') === 'c2')).toBe(true)
    expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull()
  })

  it('shows the clear state', async () => {
    renderApp({ path: '/staff', user: reviewer(), routes: layoutRoutes() })
    expect(await screen.findByText(/Queue clear/)).toBeTruthy()
  })

  it('shows the not-allowed state when the API refuses the role, and Retry recovers from a failure', async () => {
    renderApp({ path: '/staff', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/review-queue': apiError(403, 'This action is unauthorized.') } })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
  })

  it('offers Retry after a server failure', async () => {
    let fail = true
    renderApp({ path: '/staff', user: reviewer(), routes: { ...layoutRoutes(), 'GET /staff/review-queue': (() => (fail ? apiError(500, 'boom') : json(page([staffProduct({ id: A, name: 'Back Again' })])))) as Handler } })
    const retry = await screen.findByRole('button', { name: /Retry/ })
    fail = false
    fireEvent.click(retry)
    expect(await screen.findByRole('heading', { name: 'Back Again' })).toBeTruthy()
  })

  it('asks an unverified reviewer to verify first and requests nothing', async () => {
    const { api } = renderApp({ path: '/staff', user: apiUser({ role: 'reviewer', email_verified: false }) })
    expect(await screen.findByText('Verify your email first')).toBeTruthy()
    expect(api.calls.filter((c) => c.path.startsWith('/staff'))).toHaveLength(0)
  })

  it('does not open the staff area for a plain member and asks the API for nothing', async () => {
    const { api } = renderApp({ path: '/staff', user: apiUser() })
    expect(await screen.findByText('Not allowed')).toBeTruthy()
    expect(api.calls.filter((c) => c.path.startsWith('/staff'))).toHaveLength(0)
  })
})

function detailRoutes(product = staffProduct({ id: A, name: 'Lumen Notes' }), more: Record<string, unknown> = {}) {
  return {
    ...layoutRoutes(),
    'GET /staff/review-queue': json(page([product, staffProduct({ id: B, name: 'Next Up' })])),
    'GET /staff/products/:id': ((_c, { id }) => (id === A ? json({ data: product }) : apiError(404, 'Not found.'))) as Handler,
    ...more,
  }
}

describe('review detail', () => {
  it('renders the submission with its links, history and the maker', async () => {
    const product = staffProduct({
      id: A,
      name: 'Lumen Notes',
      screenshots: [{ id: 's1', url: 'http://api.test/s1.webp', position: 1, width: 10, height: 10, alt_text: 'Editor view' }],
      links: [{ id: 'l1', kind: 'github', url: 'https://github.com/x/y', position: 1 }],
      reviews: [{ id: 'r1', decision: 'changes_requested', note: 'Add screenshots please', created_at: '2026-10-20T10:00:00+00:00', reviewer: { id: 'k', handle: 'kenji' } }],
    })
    renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes(product) })

    expect(await screen.findByRole('heading', { name: 'Lumen Notes' })).toBeTruthy()
    expect(screen.getByAltText('Editor view')).toBeTruthy()
    expect(screen.getByText('https://github.com/x/y')).toBeTruthy()
    expect(screen.getByText('Add screenshots please')).toBeTruthy()
    expect(screen.getByRole('link', { name: /Maker risk summary/ }).getAttribute('href')).toBe('/staff/risk/members/01JMAKER0000000000000000001')
    expect(screen.getByText('1 of 2')).toBeTruthy()
  })

  it('shows not found for an unknown product', async () => {
    renderApp({ path: '/staff/review/01JUNKNOWN0000000000000000', user: reviewer(), routes: detailRoutes() })
    expect(await screen.findByText('Not found')).toBeTruthy()
  })

  it('sends the decision with the exact submitted_at and moves on to the next submission', async () => {
    const product = staffProduct({ id: A, name: 'Lumen Notes', submitted_at: '2026-10-22T08:15:30.123456Z' })
    const { api, router } = renderApp({
      path: `/staff/review/${A}`,
      user: reviewer(),
      routes: detailRoutes(product, { 'POST /staff/products/:id/review': json({ data: { ...product, status: 'approved' } }) }),
    })
    await screen.findByRole('heading', { name: 'Lumen Notes' })

    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))

    await waitFor(() => expect(router.state.location.pathname).toBe(`/staff/review/${B}`))
    const post = api.callsTo('POST /staff/products/:id/review')[0]
    expect(post.path).toBe(`/staff/products/${A}/review`)
    expect(post.body).toEqual({ decision: 'approved', note: null, submitted_at: '2026-10-22T08:15:30.123456Z' })
  })

  it('goes back to the queue after the last submission', async () => {
    const product = staffProduct({ id: A, name: 'Only One' })
    const { router } = renderApp({
      path: `/staff/review/${A}`,
      user: reviewer(),
      routes: detailRoutes(product, { 'GET /staff/review-queue': json(page([product])), 'POST /staff/products/:id/review': json({ data: { ...product, status: 'approved' } }) }),
    })
    await screen.findByRole('heading', { name: 'Only One' })
    key(document, 'a')
    key(document, 'Enter', { metaKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/staff'))
  })

  it('picks the decision with A, C and R and ignores the keys while typing', async () => {
    renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes() })
    await screen.findByRole('heading', { name: 'Lumen Notes' })

    key(document, 'c')
    expect(screen.getByRole('button', { name: /Request changes/, pressed: true })).toBeTruthy()
    key(document, 'r')
    expect(screen.getByRole('button', { name: /Reject/, pressed: true })).toBeTruthy()
    key(document, 'a')
    expect(screen.getByRole('button', { name: /Approve/, pressed: true })).toBeTruthy()

    key(document, 'r')
    const note = screen.getByRole('textbox', { name: 'Note to founder' })
    key(note, 'a')
    expect(screen.getByRole('button', { name: /Reject/, pressed: true })).toBeTruthy()
  })

  it('requires a note to reject or request changes and sends nothing until there is one', async () => {
    const { api } = renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes() })
    await screen.findByRole('heading', { name: 'Lumen Notes' })

    key(document, 'r')
    fireEvent.click(screen.getByRole('button', { name: /Reject & notify/ }))

    expect(await screen.findByText(/The founder needs to know why/)).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(api.callsTo('POST /staff/products/:id/review')).toHaveLength(0)

    // A canned reason fills the note, and then the decision goes to the confirm step.
    fireEvent.click(screen.getByRole('button', { name: 'Spam or misleading claims.' }))
    fireEvent.click(screen.getByRole('button', { name: /Reject & notify/ }))
    expect(await screen.findByRole('dialog')).toBeTruthy()
  })

  it('stale submission: closes the dialog, shows the message outside it, clears the pick, reads again and then sends the NEW token', async () => {
    let token = '2026-10-22T08:15:30.123456Z'
    let first = true
    const mk = () => staffProduct({ id: A, name: 'Lumen Notes', submitted_at: token })
    const { api } = renderApp({
      path: `/staff/review/${A}`,
      user: reviewer(),
      routes: detailRoutes(mk(), {
        'GET /staff/products/:id': (() => json({ data: mk() })) as Handler,
        'POST /staff/products/:id/review': (() => {
          if (first) {
            first = false
            token = '2026-10-22T09:00:00.654321Z'
            return apiError(409, 'This product was changed.', { code: 'stale_submission' })
          }
          return json({ data: { ...mk(), status: 'approved' } })
        }) as Handler,
      }),
    })
    await screen.findByRole('heading', { name: 'Lumen Notes' })
    const readsBefore = api.callsTo('GET /staff/products/:id').length

    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))

    expect(await screen.findByText(/The founder changed this product after you opened it/)).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    await waitFor(() => expect(api.callsTo('GET /staff/products/:id').length).toBeGreaterThan(readsBefore))
    // The pick is cleared: the reviewer chooses again after seeing the refreshed content.
    expect(screen.getByRole('button', { name: /Approve/, pressed: false })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Pick a decision' })).toBeTruthy()

    await waitFor(() => expect(screen.getByRole('button', { name: /Approve/ }).closest('section')?.getAttribute('aria-busy')).toBe('false'))
    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))
    await waitFor(() => expect(api.callsTo('POST /staff/products/:id/review')).toHaveLength(2))
    expect((api.callsTo('POST /staff/products/:id/review')[1].body as { submitted_at: string }).submitted_at).toBe('2026-10-22T09:00:00.654321Z')
  })

  it('sends the detail’s microsecond token, not the queue row’s seconds', async () => {
    const row = staffProduct({ id: A, name: 'Lumen Notes', submitted_at: '2026-10-22T08:15:30Z' })
    const detail = staffProduct({ id: A, name: 'Lumen Notes', submitted_at: '2026-10-22T08:15:30.123456Z' })
    const { api } = renderApp({
      path: `/staff/review/${A}`,
      user: reviewer(),
      routes: { ...detailRoutes(detail), 'GET /staff/review-queue': json(page([row])), 'POST /staff/products/:id/review': json({ data: { ...detail, status: 'approved' } }) },
    })
    await screen.findByRole('heading', { name: 'Lumen Notes' })
    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))
    await waitFor(() => expect(api.callsTo('POST /staff/products/:id/review')).toHaveLength(1))
    expect((api.callsTo('POST /staff/products/:id/review')[0].body as { submitted_at: string }).submitted_at).toBe('2026-10-22T08:15:30.123456Z')
  })

  it('invalid_status closes the dialog and shows the message', async () => {
    renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes(undefined, { 'POST /staff/products/:id/review': apiError(409, 'x', { code: 'invalid_status' }) }) })
    await screen.findByRole('heading', { name: 'Lumen Notes' })
    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))
    expect(await screen.findByText(/no longer in a state where that works/)).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  })

  it('own_product stays in the dialog', async () => {
    renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes(undefined, { 'POST /staff/products/:id/review': apiError(403, 'x', { code: 'own_product' }) }) })
    await screen.findByRole('heading', { name: 'Lumen Notes' })
    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))
    expect(await within(await screen.findByRole('dialog')).findByText(/cannot review your own product/)).toBeTruthy()
  })

  it('shortcut guards: Cmd+Enter with no pick does nothing, A is ignored while the dialog is open, repeats and composing are ignored', async () => {
    renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes() })
    await screen.findByRole('heading', { name: 'Lumen Notes' })

    key(document, 'Enter', { metaKey: true })
    expect(screen.queryByRole('dialog')).toBeNull()

    key(document, 'r', { repeat: true })
    key(document, 'r', { isComposing: true })
    expect(screen.getByRole('button', { name: 'Pick a decision' })).toBeTruthy()

    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    const dialog = await screen.findByRole('dialog')
    key(within(dialog).getByRole('button', { name: 'Cancel' }), 'r')
    expect(screen.getByRole('button', { name: /Reject/, pressed: false })).toBeTruthy()
    expect(screen.getAllByRole('button', { name: /Approve/, pressed: true })).toHaveLength(1)
  })

  it('shows the wait on a 429 and a field error on a 422', async () => {
    const routes = detailRoutes(undefined, { 'POST /staff/products/:id/review': apiError(429, 'Too many', {}, { 'Retry-After': '7' }) })
    const { api } = renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes })
    await screen.findByRole('heading', { name: 'Lumen Notes' })
    key(document, 'a')
    key(document, 'Enter', { ctrlKey: true })
    fireEvent.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Approve & notify' }))
    expect(await within(await screen.findByRole('dialog')).findByText(/Try again in 7 s/)).toBeTruthy()

    api.set('POST /staff/products/:id/review', validation({ note: ['The note may not be greater than 2000 characters.'] }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Approve & notify' }))
    await waitFor(() => expect(api.callsTo('POST /staff/products/:id/review')).toHaveLength(2))
    expect(await screen.findByText(/may not be greater than 2000 characters/)).toBeTruthy()
  })

  it('shows a decided product read-only with its status', async () => {
    const decided = staffProduct({ id: A, name: 'Lumen Notes', status: 'approved', reviews: [{ id: 'r1', decision: 'approved', note: 'Looks great', created_at: '2026-10-21T10:00:00+00:00' }] })
    renderApp({ path: `/staff/review/${A}`, user: reviewer(), routes: detailRoutes(decided) })
    expect(await screen.findByText(/already has a decision/)).toBeTruthy()
    expect(screen.getAllByText('Looks great').length).toBeGreaterThan(0)
    // The shortcuts are off for a decided product.
    key(document, 'a')
    expect(screen.getByRole('button', { name: /Approve/, pressed: false })).toBeTruthy()
  })
})
