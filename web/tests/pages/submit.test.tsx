import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { FounderProductDto } from '@/api/types'
import { apiError, json, noContent, validation, type Call, type Handler, type MockResult, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { installFakeXhr } from '../helpers/fakeXhr'
import { renderApp } from '../helpers/renderApp'
import { myProductsBody, listRow } from '../fixtures/founder'
import { completeDraft, founderProduct, review, shot } from '../fixtures/founder'
import { topicsBody } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

beforeEach(() => installFakeXhr())
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const ID = '01JDRAFT000000000000000000'
const deferred = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}
// Never fake setInterval here: jsdom's requestAnimationFrame runs on it, and motion's frame loop stays stuck for the rest of the file.
const fakeTimers = () => vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
/** vi.waitFor also moves the fake clock a little on every check; the wait is generous because it runs against real promises. */
const until = (fn: () => void) => vi.waitFor(fn, { timeout: 5000, interval: 25 })
const tick = (ms: number) => act(async () => void (await vi.advanceTimersByTimeAsync(ms)))
const png = (name = 'a.png', type = 'image/png', size = 10) => new File([new Uint8Array(size)], name, { type })
const pickFile = (label: string, files: File[]) => fireEvent.change(screen.getByLabelText(label), { target: { files } })

/** A one-product backend: GET/PATCH keep their state. */
function draftBackend(initial: FounderProductDto) {
  let current = initial
  const set = (patch: Partial<FounderProductDto>) => (current = { ...current, ...patch })
  const routes: Record<string, RouteValue> = {
    'GET /topics': json(topicsBody([{}, {}])),
    'GET /me/products/:id': (() => json({ data: current })) as Handler,
  }
  return { get: () => current, set, routes }
}
const open = (b: ReturnType<typeof draftBackend>, extra: Record<string, RouteValue> = {}, user = apiUser()) => renderApp({ path: `/submit/${b.get().id}`, user, routes: { ...b.routes, ...extra } })
const goStep = (name: RegExp) => fireEvent.click(screen.getByRole('button', { name }))

describe('creating and autosaving the draft', () => {
  it('creates the draft with name and slug first, then saves the rest with PATCH, debounced', async () => {
    const created = founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' })
    const { api, router } = renderApp({
      path: '/submit',
      user: apiUser(),
      routes: {
        'GET /topics': json(topicsBody([{}])),
        'POST /products': json({ data: created }, 201),
        'PATCH /products/:id': ((call) => json({ data: { ...created, ...(call.body as object) } })) as Handler,
      },
    })
    const name = await screen.findByLabelText('Product name')
    fakeTimers()

    fireEvent.change(name, { target: { value: 'Palette Pilot' } })
    expect((screen.getByLabelText('Web address') as HTMLInputElement).value).toBe('palette-pilot')
    // Typing alone creates nothing, however long it takes: a half-typed name must not become a draft.
    await tick(10_000)
    expect(api.callsTo('POST /products')).toHaveLength(0)
    fireEvent.blur(name)
    await until(() => expect(api.callsTo('POST /products')).toHaveLength(1))
    expect(api.callsTo('POST /products')[0].body).toEqual({ name: 'Palette Pilot', slug: 'palette-pilot' })
    await until(() => expect(router.state.location.pathname).toBe(`/submit/${ID}`))

    fireEvent.change(screen.getByLabelText('One-line pitch'), { target: { value: 'Pick colours fast' } })
    await tick(1300)
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))
    // Only what changed, and the creation call is not repeated.
    expect(api.callsTo('PATCH /products/:id')[0].body).toEqual({ tagline: 'Pick colours fast' })
    expect(api.callsTo('POST /products')).toHaveLength(1)
    await until(() => expect(screen.getByText(/^Saved/)).toBeTruthy())
  })

  it('runs one save at a time: edits made during a save become a single follow-up with the newest values', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const first = deferred()
    let n = 0
    const { api } = open(b, {
      'PATCH /products/:id': ((call: Call) => {
        n += 1
        if (n === 1) return first.promise
        return json({ data: { ...b.get(), ...(call.body as object) } })
      }) as Handler,
    })
    const tagline = await screen.findByLabelText('One-line pitch')
    fakeTimers()

    fireEvent.change(tagline, { target: { value: 'First pitch' } })
    await tick(1300)
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))

    // Two more edits while the first request is still open: nothing is sent yet.
    fireEvent.change(tagline, { target: { value: 'Second pitch' } })
    await tick(1300)
    fireEvent.change(screen.getByLabelText('Website'), { target: { value: 'https://palette.example.com' } })
    await tick(1300)
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(1)

    first.resolve(json({ data: { ...b.get(), tagline: 'First pitch' } }))
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(2))
    expect(api.callsTo('PATCH /products/:id')[0].body).toEqual({ tagline: 'First pitch' })
    expect(api.callsTo('PATCH /products/:id')[1].body).toEqual({ tagline: 'Second pitch', website_url: 'https://palette.example.com' })
    await tick(5000)
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(2)
  })

  it('does not send a value the browser already knows is invalid', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const { api } = open(b, { 'PATCH /products/:id': json({ data: b.get() }) })
    const site = await screen.findByLabelText('Website')
    fakeTimers()

    fireEvent.change(site, { target: { value: 'http://palette.example.com' } })
    await tick(3000)

    expect(api.callsTo('PATCH /products/:id')).toHaveLength(0)
  })

  it('shows a taken slug under the Web address field and saves once it is changed', async () => {
    const created = founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot-2' })
    let taken = true
    const { api } = renderApp({
      path: '/submit',
      user: apiUser(),
      routes: { 'GET /topics': json(topicsBody([{}])), 'POST /products': (() => (taken ? validation({ slug: ['This slug is already taken.'] }) : json({ data: created }, 201))) as Handler },
    })
    const name = await screen.findByLabelText('Product name')
    fakeTimers()

    fireEvent.change(name, { target: { value: 'Palette Pilot' } })
    fireEvent.blur(name)
    await until(() => expect(screen.getByText('This slug is already taken.')).toBeTruthy())
    expect(screen.getByText('Not saved')).toBeTruthy()

    taken = false
    fireEvent.change(screen.getByLabelText('Web address'), { target: { value: 'palette-pilot-2' } })
    await until(() => expect(screen.queryByText('This slug is already taken.')).toBeNull())
    fireEvent.blur(name)
    await until(() => expect(api.callsTo('POST /products')).toHaveLength(2))
    expect(api.callsTo('POST /products')[1].body).toEqual({ name: 'Palette Pilot', slug: 'palette-pilot-2' })
  })

  it('saves what is waiting and goes to My products on "Save & finish later"', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const { api, router } = open(b, {
      'PATCH /products/:id': ((call: Call) => json({ data: { ...b.get(), ...(call.body as object) } })) as Handler,
      'GET /me/products': json({ data: [], meta: { next_cursor: null } }),
    })
    fireEvent.change(await screen.findByLabelText('One-line pitch'), { target: { value: 'Pick colours fast' } })
    fireEvent.click(screen.getByRole('button', { name: /Save & finish later/ }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/my/products'))
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(1)
    expect(api.callsTo('PATCH /products/:id')[0].body).toEqual({ tagline: 'Pick colours fast' })
  })

  it('stays on the page and says so when the save fails', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const { router } = open(b, { 'PATCH /products/:id': apiError(403, 'x', { code: 'product_locked' }) })
    fireEvent.change(await screen.findByLabelText('One-line pitch'), { target: { value: 'Pick colours fast' } })
    fireEvent.click(screen.getByRole('button', { name: /Save & finish later/ }))

    expect(await screen.findByText('Could not save your draft')).toBeTruthy()
    expect(router.state.location.pathname).toBe(`/submit/${ID}`)
  })
})

describe('who can open the wizard', () => {
  it('shows the reviewer’s note on a product that needs changes', async () => {
    const b = draftBackend(completeDraft({ id: ID, status: 'changes_requested', reviews: [review({ note: 'Please add real screenshots.' })] }))
    open(b)
    expect(await screen.findByRole('heading', { name: 'Fix & resubmit' })).toBeTruthy()
    expect(screen.getByText(/Please add real screenshots\./)).toBeTruthy()
  })

  it('makes a product that is in review read-only', async () => {
    const b = draftBackend(completeDraft({ id: ID, status: 'pending' }))
    open(b)
    expect(await screen.findByText('This product can’t be edited here')).toBeTruthy()
    expect(screen.queryByLabelText('Product name')).toBeNull()
  })

  it('shows not found for a product that is not the caller’s (404)', async () => {
    renderApp({ path: `/submit/${ID}`, user: apiUser(), routes: { 'GET /me/products/:id': apiError(404, 'Not found.') } })
    expect(await screen.findByText('We couldn’t find that draft')).toBeTruthy()
  })

  it('asks an unverified member to verify first', async () => {
    renderApp({ path: '/submit', user: apiUser({ email_verified: false }) })
    expect(await screen.findByText('Verify your email first')).toBeTruthy()
  })
})

describe('the logo', () => {
  const draft = () => draftBackend(completeDraft({ id: ID, logo_url: null, screenshots: [] }))
  const toMedia = async () => {
    await screen.findByLabelText('Product name')
    goStep(/Media/)
    await screen.findByText('Logo')
  }

  it('shows upload progress, then the new logo', async () => {
    const b = draft()
    const gate = deferred()
    const { api } = open(b, { 'POST /products/:id/logo': (() => gate.promise) as Handler })
    await toMedia()

    pickFile('Logo file', [png('logo.png')])

    expect((await screen.findByRole('progressbar', { name: 'Uploading logo' })).getAttribute('aria-valuenow')).toBe('50')
    b.set({ logo_url: 'http://api.test/storage/logo.webp' })
    gate.resolve(json({ data: b.get() }))
    await waitFor(() => expect(screen.getByRole('button', { name: /Remove logo/ })).toBeTruthy())
    expect(api.callsTo('POST /products/:id/logo')[0].form?.get('logo')).toBeInstanceOf(File)
  })

  it('refuses the wrong type or a big file in the browser, without a request', async () => {
    const b = draft()
    const { api } = open(b)
    await toMedia()

    pickFile('Logo file', [png('anim.gif', 'image/gif')])
    expect(await screen.findByText('Use a JPEG, PNG or WebP image.')).toBeTruthy()
    pickFile('Logo file', [png('big.png', 'image/png', 3 * 1024 * 1024)])
    expect(await screen.findByText('That file is over 2 MB.')).toBeTruthy()
    expect(api.callsTo('POST /products/:id/logo')).toHaveLength(0)
  })

  it('shows the server’s verdict when the upload fails (422), and the wait on a 429', async () => {
    const b = draft()
    let limited = false
    open(b, { 'POST /products/:id/logo': (() => (limited ? apiError(429, 'x', {}, { 'Retry-After': '30' }) : validation({ logo: ['The logo must be at least 128 x 128 pixels.'] }))) as Handler })
    await toMedia()

    pickFile('Logo file', [png()])
    expect(await screen.findByText('The logo must be at least 128 x 128 pixels.')).toBeTruthy()
    limited = true
    pickFile('Logo file', [png()])
    expect(await screen.findByText('Too many attempts. Try again in 30 s.')).toBeTruthy()
  })

  it('removes the logo', async () => {
    const b = draftBackend(completeDraft({ id: ID }))
    const { api } = open(b, { 'DELETE /products/:id/logo': noContent() })
    await toMedia()

    fireEvent.click(await screen.findByRole('button', { name: /Remove logo/ }))

    await waitFor(() => expect(api.callsTo('DELETE /products/:id/logo')).toHaveLength(1))
    expect(await screen.findByRole('button', { name: /Upload image/ })).toBeTruthy()
  })
})

describe('screenshots', () => {
  const two = () => draftBackend(completeDraft({ id: ID, screenshots: [shot({ id: '01JSHOT00000000000000000001', position: 0 }), shot({ id: '01JSHOT00000000000000000002', position: 1, alt_text: 'Second' })] }))
  const toMedia = async () => {
    await screen.findByLabelText('Product name')
    goStep(/Media/)
    await screen.findByText('Screenshots')
  }

  it('uploads files one after another, with progress, and lists them', async () => {
    const b = draftBackend(completeDraft({ id: ID, screenshots: [] }))
    const gates = [deferred(), deferred()]
    let n = 0
    const { api } = open(b, {
      'POST /products/:id/screenshots': (() => gates[n++].promise) as Handler,
    })
    await toMedia()

    pickFile('Screenshot files', [png('one.png'), png('two.png')])

    expect((await screen.findByRole('progressbar', { name: 'Uploading one.png' })).getAttribute('aria-valuenow')).toBe('50')
    expect(api.callsTo('POST /products/:id/screenshots')).toHaveLength(1)
    gates[0].resolve(json({ data: shot({ id: '01JSHOT00000000000000000001', position: 0 }) }, 201))
    await waitFor(() => expect(api.callsTo('POST /products/:id/screenshots')).toHaveLength(2))
    gates[1].resolve(json({ data: shot({ id: '01JSHOT00000000000000000002', position: 1 }) }, 201))

    await waitFor(() => expect(screen.getAllByLabelText('Screenshot caption')).toHaveLength(2))
    expect(api.callsTo('POST /products/:id/screenshots')[0].form?.get('screenshot')).toBeInstanceOf(File)
  })

  it('keeps a failed file with the reason and retries it', async () => {
    const b = draftBackend(completeDraft({ id: ID, screenshots: [] }))
    let fail = true
    const { api } = open(b, {
      'POST /products/:id/screenshots': (() => (fail ? validation({ screenshot: ['The screenshot must be at least 320 x 320 pixels.'] }) : json({ data: shot({ id: '01JSHOT00000000000000000001' }) }, 201))) as Handler,
    })
    await toMedia()

    pickFile('Screenshot files', [png('small.png')])
    expect(await screen.findByText('The screenshot must be at least 320 x 320 pixels.')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry small.png' }))

    await waitFor(() => expect(api.callsTo('POST /products/:id/screenshots')).toHaveLength(2))
    await waitFor(() => expect(screen.getAllByLabelText('Screenshot caption')).toHaveLength(1))
    expect(screen.queryByText('The screenshot must be at least 320 x 320 pixels.')).toBeNull()
  })

  it('refuses a wrong type in the browser', async () => {
    const b = two()
    const { api } = open(b)
    await toMedia()
    pickFile('Screenshot files', [png('doc.pdf', 'application/pdf')])
    expect(await screen.findByText('Use a JPEG, PNG or WebP image.')).toBeTruthy()
    expect(api.callsTo('POST /products/:id/screenshots')).toHaveLength(0)
  })

  it('sends every id in the new order when one is moved', async () => {
    const b = two()
    const { api } = open(b, {
      'PUT /products/:id/screenshots/order': ((call: Call) => {
        const ids = (call.body as { ids: string[] }).ids
        return json({ data: ids.map((id, i) => shot({ id, position: i })) })
      }) as Handler,
    })
    await toMedia()

    fireEvent.click(screen.getAllByRole('button', { name: 'Move up' })[1])

    await waitFor(() => expect(api.callsTo('PUT /products/:id/screenshots/order')).toHaveLength(1))
    expect(api.callsTo('PUT /products/:id/screenshots/order')[0].body).toEqual({ ids: ['01JSHOT00000000000000000002', '01JSHOT00000000000000000001'] })
  })

  it('puts the order back and says so when saving it fails', async () => {
    const b = two()
    open(b, { 'PUT /products/:id/screenshots/order': apiError(403, 'x', { code: 'product_locked' }) })
    await toMedia()
    const before = screen.getAllByLabelText('Screenshot caption').map((i) => (i as HTMLInputElement).value)

    fireEvent.click(screen.getAllByRole('button', { name: 'Move up' })[1])

    expect(await screen.findByText('Could not save the new order')).toBeTruthy()
    await waitFor(() => expect(screen.getAllByLabelText('Screenshot caption').map((i) => (i as HTMLInputElement).value)).toEqual(before))
  })

  it('deletes a screenshot', async () => {
    const b = two()
    const { api } = open(b, { 'DELETE /products/:id/screenshots/:sid': noContent() })
    await toMedia()

    fireEvent.click(screen.getAllByRole('button', { name: 'Remove screenshot' })[0])

    await waitFor(() => expect(api.callsTo('DELETE /products/:id/screenshots/:sid')).toHaveLength(1))
    expect(api.callsTo('DELETE /products/:id/screenshots/:sid')[0].path).toBe(`/products/${ID}/screenshots/01JSHOT00000000000000000001`)
    await waitFor(() => expect(screen.getAllByLabelText('Screenshot caption')).toHaveLength(1))
  })

  it('saves a caption with the autosave as the screenshot’s alt text', async () => {
    const b = two()
    const { api } = open(b, { 'PATCH /products/:id': ((call: Call) => json({ data: { ...b.get(), ...(call.body as object) } })) as Handler })
    await toMedia()
    fakeTimers()

    fireEvent.change(screen.getAllByLabelText('Screenshot caption')[0], { target: { value: 'The home screen' } })
    await tick(1300)

    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))
    expect(api.callsTo('PATCH /products/:id')[0].body).toEqual({ screenshots: [{ id: '01JSHOT00000000000000000001', alt_text: 'The home screen' }] })
  })
})

describe('submitting', () => {
  const toReview = async () => {
    await screen.findByLabelText('Product name')
    goStep(/Review$/)
    await screen.findByText('Checklist')
  }

  it('shows the checklist, then submits and celebrates', async () => {
    const b = draftBackend(completeDraft({ id: ID, tagline: 'Pick colours fast' }))
    const { api } = open(b, { 'POST /products/:id/submit': json({ data: { ...b.get(), status: 'pending' } }) })
    await toReview()
    expect(screen.getByText('6/6')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: /Submit for review/ }))

    expect(await screen.findByText(/in review/)).toBeTruthy()
    expect(api.callsTo('POST /products/:id/submit')).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'Submit another' })).toBeTruthy()
  })

  it('does not submit an incomplete draft and points at what is missing', async () => {
    const b = draftBackend(completeDraft({ id: ID, logo_url: null, screenshots: [] }))
    const { api } = open(b)
    await toReview()
    expect(screen.getByText('4/6')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: /Submit for review/ }))

    expect(await screen.findByText('Finish the checklist before submitting.')).toBeTruthy()
    expect(api.callsTo('POST /products/:id/submit')).toHaveLength(0)
  })

  it('opens the step of a field the API refuses (422) and shows its message there', async () => {
    const b = draftBackend(completeDraft({ id: ID }))
    open(b, { 'POST /products/:id/submit': validation({ topic_ids: ['Pick at least one topic.'] }) })
    await toReview()

    fireEvent.click(screen.getByRole('button', { name: /Submit for review/ }))

    expect(await screen.findByText('Pick at least one topic.')).toBeTruthy()
    expect(screen.getByText('Description', { selector: 'label' })).toBeTruthy()
  })

  it('explains a refusal by status (409 invalid_status) and the pending cap (409 pending_limit)', async () => {
    const b = draftBackend(completeDraft({ id: ID }))
    let code = 'invalid_status'
    open(b, { 'POST /products/:id/submit': (() => apiError(409, 'x', { code })) as Handler })
    await toReview()

    fireEvent.click(screen.getByRole('button', { name: /Submit for review/ }))
    expect(await screen.findByText(/not in a state where that works/)).toBeTruthy()

    code = 'pending_limit'
    fireEvent.click(screen.getByRole('button', { name: /Submit for review/ }))
    expect(await screen.findByText(/too many products waiting for review/)).toBeTruthy()
  })

  it('shows the topics from the API and caps the choice at three', async () => {
    const b = draftBackend(completeDraft({ id: ID, topics: [] }))
    open(b, { 'GET /topics': json(topicsBody([{}, {}, {}, {}])) })
    await screen.findByLabelText('Product name')
    goStep(/Details/)

    for (const name of ['AI', 'Topic 1', 'Topic 2']) fireEvent.click(await screen.findByRole('button', { name: new RegExp(name) }))
    fireEvent.click(screen.getByRole('button', { name: /Topic 3/ }))

    expect(await screen.findByText('You can pick up to three topics. Remove one to choose another.')).toBeTruthy()
    expect(within(screen.getByRole('button', { name: /Topic 3/ })).queryByText('✨')).toBeTruthy()
    expect(screen.getByRole('button', { name: /Topic 3/ }).getAttribute('aria-pressed')).toBe('false')
  })
})

describe('the web address and the draft', () => {
  it('keeps following the name after the draft exists, until the member edits the address', async () => {
    let current = founderProduct({ id: ID, name: 'Palette', slug: 'palette' })
    const { api } = renderApp({
      path: '/submit',
      user: apiUser(),
      routes: {
        'GET /topics': json(topicsBody([{}])),
        'POST /products': json({ data: current }, 201),
        'PATCH /products/:id': ((call: Call) => json({ data: (current = { ...current, ...(call.body as object) }) })) as Handler,
      },
    })
    const name = await screen.findByLabelText('Product name')
    fakeTimers()
    fireEvent.change(name, { target: { value: 'Palette' } })
    fireEvent.blur(name)
    await until(() => expect(api.callsTo('POST /products')).toHaveLength(1))

    fireEvent.change(name, { target: { value: 'Palette Pilot' } })
    await tick(1300)
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))
    expect(api.callsTo('PATCH /products/:id')[0].body).toEqual({ name: 'Palette Pilot', slug: 'palette-pilot' })

    fireEvent.change(screen.getByLabelText('Web address'), { target: { value: 'my-own' } })
    await tick(1300)
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(2))
    fireEvent.change(name, { target: { value: 'Palette Pilot 2' } })
    await tick(1300)
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(3))
    expect(api.callsTo('PATCH /products/:id')[2].body).toEqual({ name: 'Palette Pilot 2' })
  })

  it('shows a draft created in the wizard on My products at once', async () => {
    const created = founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' })
    let made = false
    const { router } = renderApp({
      path: '/my/products',
      user: apiUser(),
      routes: {
        'GET /topics': json(topicsBody([{}])),
        'GET /me/products': (() => json(myProductsBody(made ? [listRow(created)] : []))) as Handler,
        'POST /products': (() => {
          made = true
          return json({ data: created }, 201)
        }) as Handler,
      },
    })
    expect(await screen.findByText('Launch your first product')).toBeTruthy()
    await act(async () => void (await router.navigate('/submit')))
    const name = await screen.findByLabelText('Product name')
    fireEvent.change(name, { target: { value: 'Palette Pilot' } })
    fireEvent.blur(name)
    await waitFor(() => expect(router.state.location.pathname).toBe(`/submit/${ID}`))

    await act(async () => void (await router.navigate('/my/products')))

    expect(await screen.findByRole('heading', { name: 'Palette Pilot' })).toBeTruthy()
  })
})

describe('failures while saving', () => {
  const failing = (b: ReturnType<typeof draftBackend>, state: { fail: MockResult | null }) =>
    open(b, {
      'PATCH /products/:id': ((call: Call) => state.fail ?? json({ data: { ...b.get(), ...(call.body as object) } })) as Handler,
      'GET /me/products': json(myProductsBody([])),
    })

  it('retries unsaved work when the member leaves, and offers Retry on the Not saved pill', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const state = { fail: apiError(403, 'x', { code: 'product_locked' }) as MockResult | null }
    const { api, router } = failing(b, state)
    fireEvent.change(await screen.findByLabelText('One-line pitch'), { target: { value: 'Pick colours fast' } })
    fireEvent.click(screen.getByRole('button', { name: /Save & finish later/ }))
    expect(await screen.findByText('Not saved')).toBeTruthy()
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(1)

    // The Retry control saves again.
    state.fail = null
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(2))
    expect(await screen.findByText(/^Saved/)).toBeTruthy()

    // And leaving after a failure tries once more.
    state.fail = apiError(403, 'x', { code: 'product_locked' })
    fireEvent.change(screen.getByLabelText('One-line pitch'), { target: { value: 'Another pitch' } })
    fireEvent.click(screen.getByRole('button', { name: /Save & finish later/ }))
    await screen.findByText('Not saved')
    state.fail = null
    await act(async () => void (await router.navigate('/my/products')))
    await waitFor(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(4))
  })

  it('retries a transient failure three times with backoff and tells the member once', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const { api } = failing(b, { fail: apiError(503, 'down') })
    const tagline = await screen.findByLabelText('One-line pitch')
    fakeTimers()

    fireEvent.change(tagline, { target: { value: 'Pick colours fast' } })
    const patches = () => api.callsTo('PATCH /products/:id').length
    await tick(1300)
    await until(() => expect(patches()).toBe(1))
    // The retries wait 2 s, 4 s and 8 s after each failure.
    await tick(1500)
    expect(patches()).toBe(1)
    await tick(1000)
    await until(() => expect(patches()).toBe(2))
    await tick(3000)
    expect(patches()).toBe(2)
    await tick(1500)
    await until(() => expect(patches()).toBe(3))
    await tick(6000)
    expect(patches()).toBe(3)
    await tick(1500)
    await until(() => expect(patches()).toBe(4))

    await until(() => expect(screen.getByText('Not saved')).toBeTruthy())
    expect(screen.getAllByText('Could not save your changes')).toHaveLength(1)
    await tick(60_000)
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(4)
  })

  it('opens the step of a field the failed save refused when submitting (422 from the autosave)', async () => {
    const b = draftBackend(completeDraft({ id: ID, tagline: 'Pick colours fast' }))
    const { api } = open(b, { 'PATCH /products/:id': validation({ tagline: ['The tagline is not allowed.'] }) })
    fireEvent.change(await screen.findByLabelText('One-line pitch'), { target: { value: 'Something else' } })
    goStep(/Review$/)
    await screen.findByText('Checklist')

    fireEvent.click(screen.getByRole('button', { name: /Submit for review/ }))

    expect(await screen.findByText('The tagline is not allowed.')).toBeTruthy()
    expect(api.callsTo('POST /products/:id/submit')).toHaveLength(0)
  })
})

describe('the save pill', () => {
  it('goes back to Saved when an edit is undone before it was sent', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot', tagline: 'Same' }))
    const { api } = open(b)
    const tagline = await screen.findByLabelText('One-line pitch')
    fakeTimers()

    fireEvent.change(tagline, { target: { value: 'Different' } })
    await until(() => expect(screen.getByText('Saving…')).toBeTruthy())
    fireEvent.change(tagline, { target: { value: 'Same' } })

    await until(() => expect(screen.getByText(/^Saved/)).toBeTruthy())
    await tick(5000)
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(0)
  })

  it('stays on Saving while a newer edit is waiting behind the save in flight', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const first = deferred()
    let n = 0
    const { api } = open(b, { 'PATCH /products/:id': ((call: Call) => (++n === 1 ? first.promise : json({ data: { ...b.get(), ...(call.body as object) } }))) as Handler })
    const tagline = await screen.findByLabelText('One-line pitch')
    fakeTimers()

    fireEvent.change(tagline, { target: { value: 'First' } })
    await tick(1300)
    await until(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))
    fireEvent.change(tagline, { target: { value: 'Second' } })
    await until(() => expect(screen.getByText('Saving…')).toBeTruthy())
    first.resolve(json({ data: { ...b.get(), tagline: 'First' } }))
    await tick(100)
    expect(screen.queryByText(/^Saved/)).toBeNull()
    expect(screen.getByText('Saving…')).toBeTruthy()

    await tick(1300)
    await until(() => expect(screen.getByText(/^Saved/)).toBeTruthy())
    expect(api.callsTo('PATCH /products/:id')).toHaveLength(2)
  })
})

describe('leaving, ids and focus', () => {
  it('saves what is waiting when the page is hidden', async () => {
    const b = draftBackend(founderProduct({ id: ID, name: 'Palette Pilot', slug: 'palette-pilot' }))
    const { api } = open(b, { 'PATCH /products/:id': ((call: Call) => json({ data: { ...b.get(), ...(call.body as object) } })) as Handler })
    fireEvent.change(await screen.findByLabelText('One-line pitch'), { target: { value: 'Pick colours fast' } })

    act(() => void window.dispatchEvent(new Event('pagehide')))

    await waitFor(() => expect(api.callsTo('PATCH /products/:id')).toHaveLength(1))
  })

  it('shows not found for an id that cannot be a product, without a request', async () => {
    const { api } = renderApp({ path: '/submit/not-an-id', user: apiUser() })
    expect(await screen.findByText('We couldn’t find that draft')).toBeTruthy()
    expect(api.calls.some((c) => c.path.includes('not-an-id'))).toBe(false)
  })

  it('starts over when the address changes to another product, and keeps its state when it is its own draft', async () => {
    const other = '01JDRAFT000000000000000002'
    const a = founderProduct({ id: ID, name: 'First Product', slug: 'first-product' })
    const c = founderProduct({ id: other, name: 'Second Product', slug: 'second-product' })
    const { router } = renderApp({
      path: `/submit/${ID}`,
      user: apiUser(),
      routes: { 'GET /topics': json(topicsBody([{}])), 'GET /me/products/:id': ((_c: Call, p: Record<string, string>) => json({ data: p.id === ID ? a : c })) as Handler },
    })
    expect(((await screen.findByLabelText('Product name')) as HTMLInputElement).value).toBe('First Product')
    // Both products are cached before the switch, so nothing shows a loading state in between.
    await act(async () => void (await router.navigate(`/submit/${other}`)))
    await waitFor(() => expect((screen.getByLabelText('Product name') as HTMLInputElement).value).toBe('Second Product'))
  })

  it('moves focus to the step heading on Next, and labels the step list', async () => {
    const b = draftBackend(completeDraft({ id: ID, tagline: 'Pick colours fast' }))
    open(b)
    await screen.findByLabelText('Product name')
    expect(screen.getByRole('list', { name: 'Steps' })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: /^Next/ }))

    await waitFor(() => expect(document.activeElement?.textContent).toBe('Step 2 of 4: Media'))
  })

  it('offers no Retry on a file the browser refused', async () => {
    const b = draftBackend(completeDraft({ id: ID, screenshots: [] }))
    open(b)
    await screen.findByLabelText('Product name')
    goStep(/Media/)
    await screen.findByText('Screenshots')

    pickFile('Screenshot files', [png('doc.pdf', 'application/pdf')])

    expect(await screen.findByText('Use a JPEG, PNG or WebP image.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Retry doc.pdf' })).toBeNull()
    expect(screen.getByRole('button', { name: 'Dismiss doc.pdf' })).toBeTruthy()
  })
})
