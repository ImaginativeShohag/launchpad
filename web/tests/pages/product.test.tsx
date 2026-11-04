import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { keys } from '@/api/keys'
import type { ApiItem, ProductDto } from '@/api/types'
import { apiError, json, validation } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { installFakeEcho, removeFakeEcho } from '../helpers/fakeEcho'
import { productRoutes } from '../helpers/productRoutes'
import { renderApp } from '../helpers/renderApp'
import { apiReport } from '../fixtures/comment'
import { apiProduct, pageBody, rankedProduct, todayBody } from '../fixtures/product'
import { resetEntryReferrer } from '@/api/hooks/useProduct'
import { apiTopic, apiUser, publicUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.restoreAllMocks())

const ID = '01JPRODUCT00000000000000A1'
const topic = apiTopic({ slug: 'ai', name: 'AI' })
const shots = [
  { id: 's1', url: 'http://api.test/storage/s1.webp', position: 0, width: 1600, height: 1000, alt_text: 'The inbox' },
  { id: 's2', url: 'http://api.test/storage/s2.webp', position: 1, width: 1600, height: 1000, alt_text: null },
]
const lumen = (o: Partial<ProductDto> = {}) =>
  apiProduct({ id: ID, slug: 'lumen', name: 'Lumen', tagline: 'Notes that think', description: 'Line one\nLine two', website_url: 'https://lumen.example/app', topics: [topic], votes_count: 7, comments_count: 3, screenshots: shots, ...o })

describe('product page', () => {
  it('shows the product from GET /products/{slug}, with its maker, topics, gallery and discussion count', async () => {
    const { api } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    expect(await screen.findByRole('heading', { level: 1, name: 'Lumen' })).toBeTruthy()
    expect(screen.getByText('Notes that think')).toBeTruthy()
    expect(screen.getByText(/Line one/)).toBeTruthy()
    expect(screen.getByRole('link', { name: /AI/ }).getAttribute('href')).toBe('/topics/ai')
    expect(screen.getByText('3 comments')).toBeTruthy()
    const visit = screen.getByRole('link', { name: /Visit website/ })
    expect(visit.getAttribute('href')).toBe('https://lumen.example/app?ref=launchpad')
    expect(visit.getAttribute('rel')).toContain('noopener')
    expect(screen.getAllByText('Mia Maker').length).toBeGreaterThan(0)
    expect(await screen.findAllByLabelText('12')).not.toHaveLength(0)
    expect(api.callsTo('GET /products/:slug')[0].path).toBe('/products/lumen')
  })

  it('shows the live rank from Today for a launch of today', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen(), { 'GET /today': json(todayBody([rankedProduct(1, { name: 'Other' }), rankedProduct(2, { id: ID, name: 'Lumen' })])) }) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect(await screen.findByTitle('Live rank — updates as votes come in')).toBeTruthy()
  })

  it('shows "not found" for a hidden, unknown or taken-down product (one 404) and sends no view beacon', async () => {
    const { api } = renderApp({ path: '/p/ghost', routes: productRoutes(lumen(), { 'GET /products/:slug': apiError(404, 'Not found.') }) })

    expect(await screen.findByText('Product not found')).toBeTruthy()
    expect(screen.getByText(/hasn’t launched yet, or was removed/)).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'My products' })).toBeNull()
    expect(api.callsTo('POST /products/:id/view')).toHaveLength(0)
    expect(api.callsTo('GET /products/:slug/comments')).toHaveLength(0)
  })

  it('points a signed-in member to My products from the not-found state', async () => {
    renderApp({ path: '/p/ghost', user: apiUser(), routes: productRoutes(lumen(), { 'GET /products/:slug': apiError(404, 'Not found.') }) })

    expect((await screen.findByRole('link', { name: 'My products' })).getAttribute('href')).toBe('/my/products')
  })

  it('shows an error with Retry for a failed read, and recovers', async () => {
    let fail = true
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen(), { 'GET /products/:slug': () => (fail ? apiError(500, 'boom') : json({ data: lumen() })) }) })

    expect(await screen.findByText('Could not load this product')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Lumen' })).toBeTruthy()
  })

  it('sends the view beacon exactly once, even under StrictMode', async () => {
    const { api } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    await waitFor(() => expect(api.callsTo(`POST /products/${ID}/view`)).toHaveLength(1))
    await act(async () => { await new Promise((r) => setTimeout(r, 50)) })
    expect(api.callsTo(`POST /products/${ID}/view`)).toHaveLength(1)
  })

  it('sends the entry referrer on the first beacon only, never an own-site one', async () => {
    resetEntryReferrer('https://news.example/post/1?utm=x#top')
    const { api, router } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen(), { 'GET /products/:slug': (_c, p) => json({ data: lumen({ id: p.slug === 'lumen' ? ID : '01JPRODUCT00000000000000A2', slug: p.slug }) }) }) })
    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    await waitFor(() => expect(api.callsTo(`POST /products/${ID}/view`)).toHaveLength(1))
    expect(api.callsTo(`POST /products/${ID}/view`)[0].body).toEqual({ referrer: 'https://news.example/post/1' })

    await act(async () => { await router.navigate('/p/other') })
    await waitFor(() => expect(api.callsTo('POST /products/01JPRODUCT00000000000000A2/view')).toHaveLength(1))
    expect(api.callsTo('POST /products/01JPRODUCT00000000000000A2/view')[0].body).toBeUndefined()
    // An own-site referrer is never kept.
    resetEntryReferrer(window.location.origin + '/x')
  })

  it('never shows a failing beacon to the member', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen(), { 'POST /products/:id/view': apiError(429, 'Too many', {}, { 'Retry-After': '30' }) }) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect(screen.queryByText(/Too many/)).toBeNull()
  })

  it('opens the gallery in a lightbox and moves with the arrow keys; hides the gallery without screenshots', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    fireEvent.click(await screen.findByRole('button', { name: /Open screenshot 1: The inbox/ }))
    const dialog = await screen.findByRole('dialog', { name: 'Lumen screenshots' })
    expect(within(dialog).getByAltText('The inbox').getAttribute('src')).toBe('http://api.test/storage/s1.webp')
    expect(within(dialog).getByText('1 / 2')).toBeTruthy()

    fireEvent.keyDown(document, { key: 'ArrowRight' })
    expect(await within(dialog).findByAltText('Lumen screenshot 2')).toBeTruthy()
    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Lumen screenshots' })).toBeNull())
  })

  it('traps focus in the lightbox: focus moves in, Tab wraps, Esc returns to the trigger', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })
    const trigger = await screen.findByRole('button', { name: /Open screenshot 1/ })
    trigger.focus()
    fireEvent.click(trigger)

    const dialog = await screen.findByRole('dialog', { name: 'Lumen screenshots' })
    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true))
    const focusable = Array.from(dialog.querySelectorAll<HTMLElement>('button'))
    focusable[focusable.length - 1].focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(document.activeElement).toBe(focusable[0])
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(focusable[focusable.length - 1])
    expect(within(dialog).getByRole('button', { name: 'Show screenshot 1' }).getAttribute('aria-current')).toBe('true')

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(document.activeElement).toBe(trigger))
  })

  it('shows the product links as safe pills and drops a javascript: link', async () => {
    renderApp({
      path: '/p/lumen',
      routes: productRoutes(
        lumen({
          links: [
            { id: 'l1', kind: 'github', url: 'https://github.com/acme/lumen', position: 0 },
            { id: 'l2', kind: 'other', url: 'javascript:alert(1)', position: 1 },
          ],
        }),
      ),
    })

    const pill = await screen.findByRole('link', { name: /github.com/ })
    expect(pill.getAttribute('href')).toBe('https://github.com/acme/lumen')
    expect(pill.getAttribute('rel')).toBe('noopener noreferrer')
    expect(screen.getAllByRole('link').filter((a) => (a.getAttribute('href') ?? '').startsWith('javascript'))).toHaveLength(0)
  })

  it('has no gallery when the product has no screenshots', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen({ screenshots: [] })) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect(screen.queryByRole('region', { name: 'Screenshots' })).toBeNull()
    expect(screen.queryByText('Gallery')).toBeNull()
  })

  it('does not link a website that is not http(s)', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen({ website_url: 'javascript:alert(1)' })) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect(screen.queryByRole('link', { name: /Visit website/ })).toBeNull()
  })

  it('lists up to three related products of the first topic, never the product itself', async () => {
    const others = ['One', 'Two', 'Three', 'Four'].map((name) => apiProduct({ name, topics: [topic] }))
    const { api } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen(), { 'GET /search': json(pageBody([lumen(), ...others])) }) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect((await screen.findAllByText('One')).length).toBeGreaterThan(0)
    expect(screen.queryAllByText('Four')).toHaveLength(0)
    expect(screen.getAllByText('More in AI').length).toBeGreaterThan(0)
    const call = api.callsTo('GET /search')[0]
    expect([call.query.get('topic'), call.query.get('sort')]).toEqual(['ai', 'votes'])
    // The product itself is not among its related cards.
    expect(screen.getAllByRole('heading', { level: 3, name: 'One' }).length).toBeGreaterThan(0)
    expect(screen.queryAllByRole('heading', { level: 3, name: 'Lumen' })).toHaveLength(0)
  })

  it('asks nothing for related products when the product has no topic', async () => {
    const { api } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen({ topics: [] })) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect(api.callsTo('GET /search')).toHaveLength(0)
  })

  it('copies the link from Share', async () => {
    const writeText = vi.fn(() => Promise.resolve())
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    fireEvent.click(await screen.findByRole('button', { name: 'Copy link' }))

    expect(await screen.findByText('Link copied')).toBeTruthy()
    expect(writeText).toHaveBeenCalledWith(window.location.href)
  })
})

describe('share', () => {
  it('only says "Link copied" when the clipboard accepted it; otherwise offers the link to copy by hand', async () => {
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn(() => Promise.reject(new Error('denied'))) }, configurable: true })
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    fireEvent.click(await screen.findByRole('button', { name: 'Copy link' }))

    expect(await screen.findByText('Press Ctrl/Cmd+C to copy')).toBeTruthy()
    expect((screen.getByLabelText('Page link') as HTMLInputElement).value).toBe(window.location.href)
    expect(screen.queryByText('Link copied')).toBeNull()
  })
})

describe('product page: voting', () => {
  it('votes with PUT /products/{id}/vote and shows the server count', async () => {
    const { api } = renderApp({ path: '/p/lumen', user: apiUser(), routes: productRoutes(lumen({ has_voted: false }), { 'PUT /products/:id/vote': json({ data: { voted: true, votes_count: 9 } }) }) })

    fireEvent.click((await screen.findAllByRole('button', { name: 'Upvote Lumen' }))[0])

    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Upvote Lumen', pressed: true }).length).toBeGreaterThan(0))
    expect(api.callsTo(`PUT /products/${ID}/vote`)).toHaveLength(1)
    await waitFor(() => expect(screen.getAllByLabelText('9').length).toBeGreaterThan(0))
  })

  it('asks a guest to sign in without calling the API', async () => {
    const { api } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    fireEvent.click((await screen.findAllByRole('button', { name: 'Upvote Lumen' }))[0])

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
    expect(api.calls.filter((c) => c.method === 'PUT')).toHaveLength(0)
  })

  it('does not offer the maker a vote or a report, and links to My products', async () => {
    const maker = publicUser({ id: apiUser().id, handle: 'amina_r', name: 'Amina Rahman' })
    renderApp({ path: '/p/lumen', user: apiUser(), routes: productRoutes(lumen({ maker })) })

    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    expect(screen.queryByRole('button', { name: 'Report product' })).toBeNull()
    expect(screen.getAllByRole('button', { name: 'Upvote Lumen' })[0].getAttribute('aria-disabled')).toBe('true')
    expect(screen.getAllByRole('link', { name: /Manage in My products/ })[0].getAttribute('href')).toBe('/my/products')
    expect(screen.queryByRole('button', { name: /Follow/ })).toBeNull()
  })

  it('takes live counts from the product channel', async () => {
    // Its own id: a vote on another product in this file would keep frames for it quiet for two seconds.
    const live = '01JPRODUCT00000000000000L1'
    const echo = installFakeEcho()
    const { client } = renderApp({ path: '/p/lumen', routes: productRoutes(lumen({ id: live })) })
    await screen.findByRole('heading', { level: 1, name: 'Lumen' })
    await waitFor(() => expect(echo.count(`product.${live}`, '.vote.count')).toBe(1))

    act(() => echo.emit(`product.${live}`, '.vote.count', { product_id: live, votes_count: 42 }))

    await waitFor(() => expect(client.getQueryData<ApiItem<ProductDto>>(keys.product('lumen'))!.data.votes_count).toBe(42))
    expect(screen.getAllByLabelText('42').length).toBeGreaterThan(0)
    removeFakeEcho()
  })
})

describe('report a product', () => {
  const open = async (user: ReturnType<typeof apiUser> | null = apiUser(), extra: Record<string, never> = {}) => {
    const out = renderApp({ path: '/p/lumen', user, routes: productRoutes(lumen(), extra as never) })
    fireEvent.click(await screen.findByRole('button', { name: 'Report product' }))
    return out
  }
  beforeEach(() => removeFakeEcho())

  it('asks a guest to sign in first', async () => {
    renderApp({ path: '/p/lumen', routes: productRoutes(lumen()) })

    fireEvent.click(await screen.findByRole('button', { name: 'Report product' }))

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
  })

  it('needs a reason, and details for "Other"', async () => {
    await open()
    const send = await screen.findByRole('button', { name: 'Send report' })
    expect((send as HTMLButtonElement).disabled).toBe(true)

    fireEvent.click(screen.getByRole('radio', { name: /Other/ }))
    expect((send as HTMLButtonElement).disabled).toBe(true)
    fireEvent.change(screen.getByLabelText('Details'), { target: { value: 'It copies my work' } })
    expect((send as HTMLButtonElement).disabled).toBe(false)
  })

  it('posts the enum reason and trimmed details, thanks the member and closes', async () => {
    const { api } = await open(apiUser(), { 'POST /products/:id/report': json(apiReport({ reason: 'misleading' }), 201) } as never)

    fireEvent.click(await screen.findByRole('radio', { name: /Misleading/ }))
    fireEvent.change(screen.getByLabelText('Details'), { target: { value: '  Claims it is free  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    expect(await screen.findByText('Thanks for the report')).toBeTruthy()
    expect(api.callsTo(`POST /products/${ID}/report`)[0].body).toEqual({ reason: 'misleading', details: 'Claims it is free' })
    await waitFor(() => expect(screen.queryByRole('radiogroup')).toBeNull())
  })

  it('sends only the reason when there are no details', async () => {
    const { api } = await open(apiUser(), { 'POST /products/:id/report': json(apiReport(), 200) } as never)

    fireEvent.click(await screen.findByRole('radio', { name: /Spam/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    await screen.findByText('Thanks for the report')
    expect(api.callsTo('POST /products/:id/report')[0].body).toEqual({ reason: 'spam' })
  })

  it.each([
    ['own_content', 403, 'You cannot report your own content.'],
    ['already_reviewed', 409, 'Our team already reviewed your earlier report on this and found nothing to act on.'],
    ['report_limit', 409, 'You have too many open reports. Please wait until some are reviewed.'],
  ])('explains %s in the dialog and keeps it open', async (code, status, message) => {
    await open(apiUser(), { 'POST /products/:id/report': apiError(status, 'x', { code }) } as never)

    fireEvent.click(await screen.findByRole('radio', { name: /Spam/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    expect((await screen.findByRole('alert')).textContent).toBe(message)
    expect(screen.getByRole('radiogroup')).toBeTruthy()
  })

  it('shows a 422 on the details field', async () => {
    await open(apiUser(), { 'POST /products/:id/report': validation({ details: ['Tell us what is wrong when the reason is "other".'] }) } as never)

    fireEvent.click(await screen.findByRole('radio', { name: /Other/ }))
    fireEvent.change(screen.getByLabelText('Details'), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    expect(await screen.findByText('Tell us what is wrong when the reason is "other".')).toBeTruthy()
  })

  it('disables sending while a 429 cooldown runs', async () => {
    await open(apiUser(), { 'POST /products/:id/report': apiError(429, 'Too many', {}, { 'Retry-After': '30' }) } as never)

    fireEvent.click(await screen.findByRole('radio', { name: /Spam/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    const wait = await screen.findByRole('button', { name: /Try again in \d+ s/ })
    expect((wait as HTMLButtonElement).disabled).toBe(true)
  })

  it('moves the reason with the arrow keys and traps focus in the dialog', async () => {
    await open()
    const spam = await screen.findByRole('radio', { name: /Spam/ })
    expect(spam.getAttribute('tabindex')).toBe('0')
    expect(screen.getByRole('radio', { name: /Abuse/ }).getAttribute('tabindex')).toBe('-1')
    const dialog = screen.getByRole('dialog')
    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true))

    spam.focus()
    fireEvent.keyDown(spam, { key: 'ArrowRight' })

    expect(screen.getByRole('radio', { name: /Abuse/, checked: true })).toBeTruthy()
    expect(document.activeElement).toBe(screen.getByRole('radio', { name: /Abuse/ }))
  })

  it('returns focus to the Report button when the dialog closes', async () => {
    renderApp({ path: '/p/lumen', user: apiUser(), routes: productRoutes(lumen()) })
    const trigger = await screen.findByRole('button', { name: 'Report product' })
    trigger.focus()
    fireEvent.click(trigger)
    await screen.findByRole('radiogroup')

    fireEvent.keyDown(document, { key: 'Escape' })

    await waitFor(() => expect(document.activeElement).toBe(trigger))
  })

  it('counts details in characters, like the server', async () => {
    await open()
    fireEvent.click(await screen.findByRole('radio', { name: /Spam/ }))

    fireEvent.change(screen.getByLabelText('Details'), { target: { value: '😀'.repeat(600) } })

    expect(screen.getByText('600/1000')).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Send report' }) as HTMLButtonElement).disabled).toBe(false)
  })

  it('shows the verify prompt for an unverified member', async () => {
    renderApp({ path: '/p/lumen', user: apiUser({ email_verified: false }), routes: productRoutes(lumen()) })

    fireEvent.click(await screen.findByRole('button', { name: 'Report product' }))

    expect(await screen.findByText('Verify your email')).toBeTruthy()
  })
})
