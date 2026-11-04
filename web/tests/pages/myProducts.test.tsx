import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { FounderProductDto } from '@/api/types'
import { addDays, formatDay, today } from '@/lib/date'
import { apiError, json, noContent, validation, type Handler, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { renderApp } from '../helpers/renderApp'
import { founderProduct, listRow, myProductsBody, review } from '../fixtures/founder'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

/** A tiny stateful backend for the founder routes: the list filters by `status`, writes change the row. */
function backend(initial: FounderProductDto[]) {
  const rows = new Map(initial.map((p) => [p.id, p]))
  const change = (id: string, patch: Partial<FounderProductDto>) => {
    const next = { ...rows.get(id)!, ...patch }
    rows.set(id, next)
    return next
  }
  const routes: Record<string, RouteValue> = {
    'GET /me/products': ((call) => {
      const status = call.query.get('status')
      return json(myProductsBody([...rows.values()].filter((p) => !status || p.status === status).map(listRow)))
    }) as Handler,
    'GET /me/products/:id': ((_c, { id }) => (rows.has(id) ? json({ data: rows.get(id) }) : apiError(404, 'Not found.'))) as Handler,
  }
  return { rows, change, routes }
}

const renderMine = (b: ReturnType<typeof backend>, extra: Record<string, RouteValue> = {}) => renderApp({ path: '/my/products', user: apiUser(), routes: { ...b.routes, ...extra } })

describe('my products list', () => {
  it('lists the maker’s products with the status each one is in', async () => {
    const b = backend([founderProduct({ name: 'Draft One', tagline: 'First' }), founderProduct({ name: 'In Review', status: 'pending', submitted_at: '2026-10-20T10:00:00+00:00' }), founderProduct({ name: 'Ready', status: 'approved' })])
    renderMine(b)

    expect(await screen.findByRole('heading', { name: 'Draft One' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'In Review' })).toBeTruthy()
    expect(screen.getByText(/1 needs your attention/)).toBeTruthy()
    expect(screen.getByRole('button', { name: /Continue editing/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /Pick launch day/ })).toBeTruthy()
    expect(screen.getAllByRole('button', { name: /Withdraw/ })).toHaveLength(2)
  })

  it('asks the API for just one status when a tab is chosen', async () => {
    const b = backend([founderProduct({ name: 'Draft One' }), founderProduct({ name: 'In Review', status: 'pending' })])
    const { api } = renderMine(b)
    await screen.findByRole('heading', { name: 'Draft One' })

    fireEvent.click(screen.getByRole('tab', { name: /Drafts/ }))

    await waitFor(() => expect(api.callsTo('GET /me/products').some((c) => c.query.get('status') === 'draft')).toBe(true))
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'In Review' })).toBeNull())
    expect(screen.getByRole('heading', { name: 'Draft One' })).toBeTruthy()
  })

  it('shows the first-product prompt when there are none', async () => {
    renderMine(backend([]))
    expect(await screen.findByText('Launch your first product')).toBeTruthy()
    expect(screen.getByRole('link', { name: /Submit a product/ }).getAttribute('href')).toBe('/submit')
  })

  it('shows an error with Retry that recovers', async () => {
    let fail = true
    const b = backend([founderProduct({ name: 'Draft One' })])
    renderMine(b, { 'GET /me/products': (() => (fail ? apiError(500, 'boom') : json(myProductsBody([listRow({ name: 'Draft One' })])))) as Handler })

    fireEvent.click(await screen.findByRole('button', { name: /Retry/ }))
    fail = false
    fireEvent.click(await screen.findByRole('button', { name: /Retry/ }))

    expect(await screen.findByRole('heading', { name: 'Draft One' })).toBeTruthy()
  })

  it('sends a guest to sign in', async () => {
    const { router } = renderApp({ path: '/my/products', user: null })
    await waitFor(() => expect(router.state.location.pathname).toBe('/signin'))
    expect(router.state.location.search).toContain('next=')
  })

  it('shows the reviewer’s note on a product that needs changes (read from the single product)', async () => {
    const b = backend([founderProduct({ name: 'Needs Work', status: 'changes_requested', reviews: [review({ note: 'Add real screenshots please' })] })])
    renderMine(b)

    expect(await screen.findByText(/Add real screenshots please/)).toBeTruthy()
    expect(screen.getByRole('button', { name: /Fix & resubmit/ })).toBeTruthy()
  })
})

describe('delete and withdraw', () => {
  it('deletes a draft only after the confirmation', async () => {
    const b = backend([founderProduct({ id: '01JDEL00000000000000000001', name: 'Draft One' })])
    const { api } = renderMine(b, {
      'DELETE /products/:id': ((_c, { id }) => {
        b.rows.delete(id)
        return noContent()
      }) as Handler,
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    expect(api.callsTo('DELETE /products/:id')).toHaveLength(0)

    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete draft' }))

    await waitFor(() => expect(api.callsTo('DELETE /products/:id')).toHaveLength(1))
    expect(api.callsTo('DELETE /products/:id')[0].path).toBe('/products/01JDEL00000000000000000001')
    expect(await screen.findByText('Launch your first product')).toBeTruthy()
  })

  it('withdraws a product that is in review and shows it as a draft', async () => {
    const b = backend([founderProduct({ id: '01JWDR00000000000000000001', name: 'In Review', status: 'pending' })])
    const { api } = renderMine(b, {
      'POST /products/:id/withdraw': ((_c, { id }) => json({ data: b.change(id, { status: 'draft', editable_fields: ['name'], submitted_at: null }) })) as Handler,
    })
    fireEvent.click(await screen.findByRole('button', { name: /Withdraw/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Withdraw' }))

    await waitFor(() => expect(api.callsTo('POST /products/:id/withdraw')).toHaveLength(1))
    expect(await screen.findByRole('button', { name: /Continue editing/ })).toBeTruthy()
  })

  it('says why a withdraw was refused (409 invalid_status) and keeps the product', async () => {
    const b = backend([founderProduct({ name: 'In Review', status: 'pending' })])
    renderMine(b, { 'POST /products/:id/withdraw': apiError(409, 'x', { code: 'invalid_status' }) })
    fireEvent.click(await screen.findByRole('button', { name: /Withdraw/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Withdraw' }))

    expect(await screen.findByText(/not in a state where that works/)).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'In Review' })).toBeTruthy()
  })

  it('shows the wait on a 429', async () => {
    const b = backend([founderProduct({ name: 'In Review', status: 'pending' })])
    renderMine(b, { 'POST /products/:id/withdraw': apiError(429, 'Too many.', {}, { 'Retry-After': '7' }) })
    fireEvent.click(await screen.findByRole('button', { name: /Withdraw/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Withdraw' }))

    expect(await screen.findByText('Too many attempts. Try again in 7 s.')).toBeTruthy()
  })
})

const dayLabel = (d: string) => formatDay(d, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
const escapeRe = (t: string) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
/** The day's button, paging forward until its month is on screen. */
const showDay = (dialog: HTMLElement, d: string) => {
  for (let i = 0; i < 4; i++) {
    const b = within(dialog).queryByRole('button', { name: new RegExp(`^${escapeRe(dayLabel(d))}`) })
    if (b) return b
    fireEvent.click(within(dialog).getByRole('button', { name: 'Next month' }))
  }
  throw new Error(`day ${d} not reachable`)
}

describe('scheduling a launch', () => {
  const approved = () => backend([founderProduct({ id: '01JSCH00000000000000000001', name: 'Ready', status: 'approved' })])

  it('disables today, sends the chosen day and shows the product as scheduled', async () => {
    const b = approved()
    const day = addDays(today(), 3)
    const { api } = renderMine(b, {
      'PUT /products/:id/launch': ((call, { id }) => json({ data: b.change(id, { status: 'scheduled', launch_date: (call.body as { launch_date: string }).launch_date }) })) as Handler,
    })
    fireEvent.click(await screen.findByRole('button', { name: /Pick launch day/ }))
    const dialog = await screen.findByRole('dialog')

    expect(showDay(dialog, today()).getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(showDay(dialog, day))
    fireEvent.click(within(dialog).getByRole('button', { name: /^Launch / }))

    await waitFor(() => expect(api.callsTo('PUT /products/:id/launch')).toHaveLength(1))
    expect(api.callsTo('PUT /products/:id/launch')[0].body).toEqual({ launch_date: day })
    expect(await screen.findByText(/Launches in/)).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('shows the API’s reason inline when the day is full (422 on launch_date) and keeps the calendar open', async () => {
    const day = addDays(today(), 3)
    renderMine(approved(), { 'PUT /products/:id/launch': validation({ launch_date: ['You can launch at most 2 products on one day.'] }) })
    fireEvent.click(await screen.findByRole('button', { name: /Pick launch day/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(showDay(dialog, day))
    fireEvent.click(within(dialog).getByRole('button', { name: /^Launch / }))

    expect((await within(dialog).findByRole('alert')).textContent).toBe('You can launch at most 2 products on one day.')
    expect(screen.getByRole('dialog')).toBeTruthy()
  })

  it('marks a day that already has the cap of launches as unavailable', async () => {
    const full = addDays(today(), 2)
    const b = backend([
      founderProduct({ id: '01JSCH00000000000000000001', name: 'Ready', status: 'approved' }),
      founderProduct({ name: 'Other A', status: 'scheduled', launch_date: full }),
      founderProduct({ name: 'Other B', status: 'live', launch_date: full }),
    ])
    renderMine(b)
    fireEvent.click(await screen.findByRole('button', { name: /Pick launch day/ }))
    const dialog = await screen.findByRole('dialog')

    expect(showDay(dialog, full).getAttribute('aria-disabled')).toBe('true')
  })

  it('changes the day of a scheduled product and unschedules it back to a draft', async () => {
    const b = backend([founderProduct({ id: '01JSCH00000000000000000002', name: 'Sched', status: 'scheduled', launch_date: addDays(today(), 5) })])
    const { api } = renderMine(b, {
      'PUT /products/:id/launch': ((call, { id }) => json({ data: b.change(id, { launch_date: (call.body as { launch_date: string }).launch_date }) })) as Handler,
      'POST /products/:id/withdraw': ((_c, { id }) => json({ data: b.change(id, { status: 'draft', launch_date: null }) })) as Handler,
    })
    fireEvent.click(await screen.findByRole('button', { name: /Change day/ }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(showDay(dialog, addDays(today(), 6)))
    fireEvent.click(within(dialog).getByRole('button', { name: /^Launch / }))
    await waitFor(() => expect(api.callsTo('PUT /products/:id/launch')).toHaveLength(1))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

    fireEvent.click(await screen.findByRole('button', { name: /Unschedule/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Unschedule' }))

    await waitFor(() => expect(api.callsTo('POST /products/:id/withdraw')).toHaveLength(1))
    expect(await screen.findByRole('button', { name: /Continue editing/ })).toBeTruthy()
  })
})

describe('editing a live product', () => {
  const live = () =>
    backend([
      founderProduct({
        id: '01JLIV00000000000000000001',
        name: 'Live One',
        status: 'live',
        launch_date: addDays(today(), -2),
        description: 'The first description of this product.',
        website_url: 'https://example.com',
        links: [{ id: 'l1', kind: 'github', url: 'https://github.com/live', position: 0 }],
      }),
    ])

  it('sends only the fields that changed', async () => {
    const b = live()
    const { api } = renderMine(b, { 'PATCH /products/:id': ((call, { id }) => json({ data: b.change(id, { description: (call.body as { description: string }).description }) })) as Handler })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    const field = await screen.findByLabelText('Description')

    fireEvent.change(field, { target: { value: 'A better description of this product.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))
    expect(api.callsTo('PATCH /products/:id')[0].body).toEqual({ description: 'A better description of this product.' })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  })

  it('shows 422 messages under the field and toasts a locked product', async () => {
    const b = live()
    renderMine(b, { 'PATCH /products/:id': validation({ website_url: ['The website url must be a public https link.'] }) })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    fireEvent.change(await screen.findByLabelText('Website'), { target: { value: 'https://other.example.org' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('The website url must be a public https link.')).toBeTruthy()
  })

  it('toasts product_locked (403)', async () => {
    const b = live()
    renderMine(b, { 'PATCH /products/:id': apiError(403, 'x', { code: 'product_locked' }) })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    fireEvent.change(await screen.findByLabelText('Description'), { target: { value: 'Changed description for the live product.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('This product can no longer be changed that way.')).toBeTruthy()
  })
})

describe('the launch calendar window', () => {
  const approved = () => backend([founderProduct({ id: '01JSCH00000000000000000009', name: 'Ready', status: 'approved' })])

  it('reaches a day 50 days out, and disables the days beyond the window with the reason in their label', async () => {
    const b = approved()
    const day = addDays(today(), 50)
    const { api } = renderMine(b, {
      'PUT /products/:id/launch': ((call, { id }) => json({ data: b.change(id, { status: 'scheduled', launch_date: (call.body as { launch_date: string }).launch_date }) })) as Handler,
    })
    fireEvent.click(await screen.findByRole('button', { name: /Pick launch day/ }))
    const dialog = await screen.findByRole('dialog')

    const button = showDay(dialog, day)
    expect(button.getAttribute('aria-disabled')).toBe('false')
    fireEvent.click(button)
    fireEvent.click(within(dialog).getByRole('button', { name: /^Launch / }))
    await waitFor(() => expect(api.callsTo('PUT /products/:id/launch')[0]?.body).toEqual({ launch_date: day }))

    // 61 days out is past the window.
    const late = showDay(dialog, addDays(today(), 61))
    expect(late.getAttribute('aria-disabled')).toBe('true')
    expect(late.getAttribute('aria-label')).toContain('unavailable: more than 60 days ahead')
    expect(late.getAttribute('aria-label')).toContain(addDays(today(), 61).slice(0, 4))
  })

  it('stops the month arrows at this month and at the end of the window', async () => {
    renderMine(approved())
    fireEvent.click(await screen.findByRole('button', { name: /Pick launch day/ }))
    const dialog = await screen.findByRole('dialog')

    expect((within(dialog).getByRole('button', { name: 'Previous month' }) as HTMLButtonElement).disabled).toBe(true)
    for (let i = 0; i < 4; i++) {
      const next = within(dialog).getByRole('button', { name: 'Next month' }) as HTMLButtonElement
      if (!next.disabled) fireEvent.click(next)
    }
    expect((within(dialog).getByRole('button', { name: 'Next month' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('moves between days with the arrow keys (one day in the Tab order) and across months', async () => {
    renderMine(approved())
    fireEvent.click(await screen.findByRole('button', { name: /Pick launch day/ }))
    const dialog = await screen.findByRole('dialog')
    const start = showDay(dialog, addDays(today(), 1))
    expect(start.getAttribute('tabindex')).toBe('0')
    expect(within(dialog).getAllByRole('button').filter((b) => b.getAttribute('tabindex') === '0' && b.hasAttribute('data-day'))).toHaveLength(1)

    start.focus()
    fireEvent.keyDown(start, { key: 'ArrowDown' })

    await waitFor(() => expect(document.activeElement?.getAttribute('data-day')).toBe(addDays(today(), 8)))
    fireEvent.keyDown(document.activeElement as Element, { key: 'PageDown' })
    await waitFor(() => expect(document.activeElement?.getAttribute('data-day')).toBe(addDays(today(), 38)))
    // Never past the window.
    fireEvent.keyDown(document.activeElement as Element, { key: 'PageDown' })
    await waitFor(() => expect(document.activeElement?.getAttribute('data-day')).toBe(addDays(today(), 60)))
  })
})
