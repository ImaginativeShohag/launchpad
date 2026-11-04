import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CommentDto, ProductDto } from '@/api/types'
import { apiError, json, validation, type MockResult, type RouteValue } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { productRoutes } from '../helpers/productRoutes'
import { renderApp } from '../helpers/renderApp'
import { apiComment, apiReport, commentAuthor, commentsBody, placeholderComment } from '../fixtures/comment'
import { apiProduct } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()
afterEach(() => vi.restoreAllMocks())

const me = apiUser()
const mine = commentAuthor({ id: me.id, handle: me.handle, name: me.name })
const maker = commentAuthor({ id: '01JMAKER0000000000000000000', handle: 'maker', name: 'Mia Maker', is_maker: true })
const PRODUCT: ProductDto = apiProduct({ id: '01JPRODUCT00000000000000C1', slug: 'lumen', name: 'Lumen', comments_count: 3 })

const deferred = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}

const open = (comments: CommentDto[], opts: { user?: ReturnType<typeof apiUser> | null; next?: string | null; routes?: Record<string, RouteValue> } = {}) =>
  renderApp({
    path: '/p/lumen',
    user: opts.user === undefined ? me : opts.user,
    routes: productRoutes(PRODUCT, { 'GET /products/:slug/comments': json(commentsBody(comments, opts.next ?? null)), ...opts.routes }),
  })

const row = (text: string) => screen.getByText(text).closest('li') as HTMLElement
const composer = () => screen.getByLabelText('What do you think of Lumen?') as HTMLTextAreaElement
const type = (el: HTMLElement, value: string) => fireEvent.change(el, { target: { value } })
const post = async (text: string) => {
  fireEvent.focus(composer())
  type(composer(), text)
  fireEvent.keyDown(composer(), { key: 'Enter', ctrlKey: true })
}

describe('comment list', () => {
  it('shows comments with embedded replies, the maker badge, mentions, edits and placeholders', async () => {
    const reply = apiComment({ body: 'Thanks for asking', author: maker, parent_id: 'p', reply_to: { id: 'x', handle: 'carl', name: 'Carl Commenter' }, edited_at: '2026-10-21T09:05:00.000000Z' })
    const { api } = open([
      apiComment({ body: 'Love the onboarding', replies_count: 3, replies: [reply] }),
      placeholderComment('deleted', { replies_count: 1, replies: [apiComment({ body: 'A reply under a deleted one' })] }),
      placeholderComment('removed', { replies_count: 1, replies: [apiComment({ body: 'A reply under a removed one' })] }),
    ])

    expect(await screen.findByText('Love the onboarding')).toBeTruthy()
    expect(within(row('Love the onboarding')).getByText('Carl Commenter')).toBeTruthy()
    expect(within(row('Thanks for asking')).getByText('Maker')).toBeTruthy()
    expect(within(row('Thanks for asking')).getByText('@carl').getAttribute('href')).toBe('/u/carl')
    expect(within(row('Thanks for asking')).getByText('· edited')).toBeTruthy()
    expect(screen.getByText('This comment was deleted.')).toBeTruthy()
    expect(screen.getByText('This comment was removed by a moderator.')).toBeTruthy()
    expect(screen.getByText('A reply under a deleted one')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Show 2 more replies' })).toBeTruthy()
    const call = api.callsTo('GET /products/:slug/comments')[0]
    expect([call.query.get('sort'), call.query.get('per_page'), call.query.has('cursor')]).toEqual(['top', '20', false])
  })

  it('renders a body as plain text, never as markup or links', async () => {
    open([apiComment({ body: '<img src=x onerror=alert(1)> see https://example.com' })])

    const body = await screen.findByText(/see https:\/\/example.com/)
    expect(body.textContent).toContain('<img src=x onerror=alert(1)>')
    expect(body.querySelector('img, a[href^="https://example.com"]')).toBeNull()
    expect(body.className).toContain('whitespace-pre-wrap')
  })

  it('shows an empty state, and an error with Retry', async () => {
    let fail = true
    open([], { routes: { 'GET /products/:slug/comments': () => (fail ? apiError(500, 'boom') : json(commentsBody([]))) } })

    expect(await screen.findByText('Could not load the discussion')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('No comments yet')).toBeTruthy()
  })

  it('switches the sort and asks the server again', async () => {
    const { api } = open([], {
      routes: {
        'GET /products/:slug/comments': (call) =>
          json(commentsBody(call.query.get('sort') === 'newest' ? [apiComment({ body: 'Newest one' }), apiComment({ body: 'Newest two' })] : [apiComment({ body: 'Top one' }), apiComment({ body: 'Top two' })])),
      },
    })
    await screen.findByText('Top one')

    fireEvent.click(screen.getByRole('tab', { name: 'Newest' }))

    expect(await screen.findByText('Newest one')).toBeTruthy()
    expect(screen.queryByText('Top one')).toBeNull()
    // (StrictMode may abort and repeat the first read, so compare the sorts that were asked for.)
    expect([...new Set(api.callsTo('GET /products/:slug/comments').map((c) => c.query.get('sort')))]).toEqual(['top', 'newest'])
  })

  it('loads more with the cursor and keeps sort and page size', async () => {
    const { api } = open([], {
      routes: {
        'GET /products/:slug/comments': (call) =>
          call.query.get('cursor') === 'c2' ? json(commentsBody([apiComment({ body: 'Second page' })])) : json(commentsBody([apiComment({ body: 'First page' })], 'c2')),
      },
    })
    await screen.findByText('First page')

    fireEvent.click(screen.getByRole('button', { name: 'Load more' }))

    expect(await screen.findByText('Second page')).toBeTruthy()
    expect(screen.getByText('First page')).toBeTruthy()
    const second = api.callsTo('GET /products/:slug/comments').filter((c) => c.query.has('cursor'))
    expect(second).toHaveLength(1)
    expect([second[0].query.get('cursor'), second[0].query.get('sort'), second[0].query.get('per_page')]).toEqual(['c2', 'top', '20'])
    expect(screen.queryByRole('button', { name: 'Load more' })).toBeNull()
  })

  it('pages the replies of a thread from the replies endpoint', async () => {
    const [r1, r2, r3, r4] = [1, 2, 3, 4].map((n) => apiComment({ body: `Reply ${n}` }))
    const root = apiComment({ body: 'Root', replies_count: 4, replies: [r1, r2] })
    const { api } = open([root], {
      routes: {
        'GET /comments/:id/replies': (call) => (call.query.get('cursor') === 'r2' ? json(commentsBody([r4])) : json(commentsBody([r1, r2, r3], 'r2'))),
      },
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Show 2 more replies' }))

    expect(await screen.findByText('Reply 3')).toBeTruthy()
    expect(screen.getAllByText('Reply 1')).toHaveLength(1)
    fireEvent.click(screen.getByRole('button', { name: 'Show more replies' }))

    expect(await screen.findByText('Reply 4')).toBeTruthy()
    expect(api.callsTo(`GET /comments/${root.id}/replies`).filter((c) => c.query.get('cursor') === 'r2')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Show more replies' })).toBeNull()
  })

  it('keeps the embedded replies and offers Retry when the replies endpoint fails', async () => {
    let fail = true
    open([apiComment({ body: 'Root', replies_count: 3, replies: [apiComment({ body: 'Reply 1' })] })], {
      routes: { 'GET /comments/:id/replies': () => (fail ? apiError(500, 'x') : json(commentsBody([apiComment({ body: 'Reply 1' }), apiComment({ body: 'Reply 2' })]))) },
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Show 2 more replies' }))

    expect(await screen.findByText('Could not load the replies.')).toBeTruthy()
    expect(screen.getByText('Reply 1')).toBeTruthy()
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('Reply 2')).toBeTruthy()
  })
})

describe('posting', () => {
  it('shows the comment at once and swaps in the server row', async () => {
    const pending = deferred()
    const { api } = open([], { routes: { 'POST /products/:id/comments': () => pending.promise } })
    await screen.findByText('No comments yet')

    await post('  Brilliant idea  ')

    await screen.findByText('Brilliant idea')
    expect(within(row('Brilliant idea')).getByText('sending…')).toBeTruthy()
    expect(within(row('Brilliant idea')).queryByRole('button', { name: /Edit|Delete/ })).toBeNull()
    expect(screen.getByText('4 comments')).toBeTruthy()
    expect(composer().value).toBe('')
    pending.resolve(json({ data: apiComment({ id: '01JSERVER0000000000000000A', body: 'Brilliant idea', author: mine, can_edit: true, can_delete: true }) }, 201))

    await waitFor(() => expect(screen.queryByText('sending…')).toBeNull())
    expect(screen.getAllByText('Brilliant idea')).toHaveLength(1)
    expect(within(row('Brilliant idea')).getByRole('button', { name: /Edit/ })).toBeTruthy()
    expect(screen.getByText('4 comments')).toBeTruthy()
    const call = api.callsTo(`POST /products/${PRODUCT.id}/comments`)[0]
    expect(call.body).toEqual({ body: 'Brilliant idea' })
    expect(call.headers.get('X-XSRF-TOKEN')).toBeTruthy()
  })

  it('removes the row and restores the draft when posting fails', async () => {
    open([], { routes: { 'POST /products/:id/comments': apiError(500, 'boom') } })
    await screen.findByText('No comments yet')

    await post('Will not go through')

    expect(await screen.findByText('Could not post your comment')).toBeTruthy()
    await waitFor(() => expect(composer().value).toBe('Will not go through'))
    expect(screen.queryByText('sending…')).toBeNull()
    expect(screen.getByText('3 comments')).toBeTruthy()
    expect(screen.getByText('No comments yet')).toBeTruthy()
  })

  it('shows the link-cap message from a 422 under the box and keeps the text', async () => {
    open([], { routes: { 'POST /products/:id/comments': validation({ body: ['A comment can contain at most 3 links.'] }) } })
    await screen.findByText('No comments yet')

    await post('a.com b.com c.com d.com')

    expect(await screen.findByText('A comment can contain at most 3 links.')).toBeTruthy()
    expect(composer().value).toBe('a.com b.com c.com d.com')
    expect(screen.queryByText('Could not post your comment')).toBeNull()
    type(composer(), 'a.com')
    await waitFor(() => expect(screen.queryByText('A comment can contain at most 3 links.')).toBeNull())
  })

  it('waits out the comment-create limiter and keeps the draft', async () => {
    open([], { routes: { 'POST /products/:id/comments': apiError(429, 'Too many', {}, { 'Retry-After': '40' }) } })
    await screen.findByText('No comments yet')

    await post('One too many')

    const wait = await screen.findByRole('button', { name: /Try again in \d+ s/ })
    expect((wait as HTMLButtonElement).disabled).toBe(true)
    expect(composer().value).toBe('One too many')
    expect(await screen.findByText(/Too many attempts. Try again in 40 s./)).toBeTruthy()
  })

  it('refuses text over 1000 characters before sending', async () => {
    const { api } = open([])
    await screen.findByText('No comments yet')

    fireEvent.focus(composer())
    type(composer(), 'x'.repeat(1001))

    expect(screen.getByText('1001/1000')).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Post' }) as HTMLButtonElement).disabled).toBe(true)
    fireEvent.keyDown(composer(), { key: 'Enter', ctrlKey: true })
    expect(api.calls.filter((c) => c.method === 'POST' && c.path.endsWith('/comments'))).toHaveLength(0)
  })

  it('counts an emoji as one character, like the server', async () => {
    open([])
    await screen.findByText('No comments yet')

    fireEvent.focus(composer())
    type(composer(), '😀'.repeat(900))

    expect((screen.getByRole('button', { name: 'Post' }) as HTMLButtonElement).disabled).toBe(false)
    expect(screen.getByText('900/1000')).toBeTruthy()
  })

  it('opens the verify prompt and drops the row when the server says the email is unverified', async () => {
    open([], { routes: { 'POST /products/:id/comments': apiError(403, 'Verify your email.', { code: 'email_unverified' }) } })
    await screen.findByText('No comments yet')

    await post('Hello')

    expect(await screen.findByText('Verify your email')).toBeTruthy()
    expect(screen.queryByText('sending…')).toBeNull()
    expect(screen.getByText('3 comments')).toBeTruthy()
  })

  it('tells the member when the discussion is gone (404) and re-reads the list', async () => {
    const { api } = open([], { routes: { 'POST /products/:id/comments': apiError(404, 'Not found.') } })
    await screen.findByText('No comments yet')

    await post('Hello')

    expect(await screen.findByText('This discussion is no longer available')).toBeTruthy()
    await waitFor(() => expect(api.callsTo('GET /products/:slug/comments').length).toBeGreaterThanOrEqual(2))
  })

  it('asks a guest to sign in when the box is focused', async () => {
    const { api } = open([], { user: null })
    await screen.findByText('No comments yet')

    fireEvent.focus(composer())

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
    expect(api.calls.filter((c) => c.method === 'POST')).toHaveLength(1) // only the view beacon
  })

  it('shows the verify prompt to an unverified member', async () => {
    open([], { user: apiUser({ email_verified: false }) })
    await screen.findByText('No comments yet')

    fireEvent.focus(composer())

    expect(await screen.findByText('Verify your email')).toBeTruthy()
  })
})

describe('replying', () => {
  const thread = () => apiComment({ body: 'Root question', replies_count: 1, replies: [apiComment({ body: 'First answer', parent_id: 'x', author: maker })] })

  it('replies to a top-level comment with parent_id and shows the reply in the thread', async () => {
    const root = thread()
    const pending = deferred()
    const { api } = open([root], { routes: { 'POST /products/:id/comments': () => pending.promise } })
    await screen.findByText('Root question')

    fireEvent.click(within(row('Root question')).getAllByRole('button', { name: /Reply/ })[0])
    const box = await screen.findByLabelText('Reply to Carl…')
    type(box, 'Good question')
    fireEvent.keyDown(box, { key: 'Enter', ctrlKey: true })

    expect(await screen.findByText('Good question')).toBeTruthy()
    expect(within(row('Good question')).getByText('sending…')).toBeTruthy()
    pending.resolve(json({ data: apiComment({ id: '01JSERVER0000000000000000B', parent_id: root.id, body: 'Good question', author: mine, can_edit: true, can_delete: true }) }, 201))

    await waitFor(() => expect(screen.queryByText('sending…')).toBeNull())
    expect(api.callsTo(`POST /products/${PRODUCT.id}/comments`)[0].body).toEqual({ body: 'Good question', parent_id: root.id })
    await waitFor(() => expect(screen.queryByLabelText('Reply to Carl…')).toBeNull())
    expect(screen.getByText('First answer')).toBeTruthy()
  })

  it('answers a reply: parent_id is that reply and the new row mentions its author', async () => {
    const first = apiComment({ body: 'First answer', parent_id: 'x', author: maker })
    const root = apiComment({ body: 'Root question', replies_count: 1, replies: [first] })
    const { api } = open([root], { routes: { 'POST /products/:id/comments': json({ data: apiComment({ parent_id: root.id, body: 'Agreed', author: mine, reply_to: { id: 'x', handle: 'maker', name: 'Mia Maker' } }) }, 201) } })
    await screen.findByText('First answer')

    fireEvent.click(within(row('First answer')).getByRole('button', { name: /Reply/ }))
    const box = await screen.findByLabelText('Reply to Mia…')
    type(box, 'Agreed')
    fireEvent.keyDown(box, { key: 'Enter', ctrlKey: true })

    expect(await screen.findByText('Agreed')).toBeTruthy()
    expect(api.callsTo(`POST /products/${PRODUCT.id}/comments`)[0].body).toEqual({ body: 'Agreed', parent_id: first.id })
    await waitFor(() => expect(within(row('Agreed')).getByText('@maker')).toBeTruthy())
  })

  it('brings the reply text back when the reply fails', async () => {
    open([thread()], { routes: { 'POST /products/:id/comments': apiError(500, 'x') } })
    await screen.findByText('Root question')

    fireEvent.click(within(row('Root question')).getAllByRole('button', { name: /Reply/ })[0])
    const box = await screen.findByLabelText('Reply to Carl…')
    type(box, 'Lost reply')
    fireEvent.keyDown(box, { key: 'Enter', ctrlKey: true })

    expect(await screen.findByText('Could not post your comment')).toBeTruthy()
    await waitFor(() => expect((screen.getByLabelText('Reply to Carl…') as HTMLTextAreaElement).value).toBe('Lost reply'))
    expect(screen.queryByText('sending…')).toBeNull()
  })

  it('keeps the reply box until the post has an outcome, so a failed draft is not lost', async () => {
    const pending = deferred()
    open([thread()], { routes: { 'POST /products/:id/comments': () => pending.promise } })
    await screen.findByText('Root question')
    fireEvent.click(within(row('Root question')).getAllByRole('button', { name: /Reply/ })[0])
    const box = await screen.findByLabelText('Reply to Carl…')
    type(box, 'Mid-flight')
    fireEvent.keyDown(box, { key: 'Enter', ctrlKey: true })
    await screen.findByText('sending…')

    expect((screen.getByRole('button', { name: 'Cancel' }) as HTMLButtonElement).disabled).toBe(true)
    fireEvent.keyDown(box, { key: 'Escape' })
    fireEvent.click(within(row('Root question')).getAllByRole('button', { name: /Reply/ })[0])
    expect(screen.getByLabelText('Reply to Carl…')).toBeTruthy()
    pending.resolve(apiError(500, 'boom'))

    await waitFor(() => expect((screen.getByLabelText('Reply to Carl…') as HTMLTextAreaElement).value).toBe('Mid-flight'))
  })

  it('names a comment whose author is gone "Former member" without a link', async () => {
    open([apiComment({ body: 'Orphan', author: null })])
    await screen.findByText('Orphan')

    const item = row('Orphan')
    expect(within(item).getByText('Former member').tagName).toBe('SPAN')
  })

  it('offers no Reply on a placeholder', async () => {
    open([placeholderComment('deleted', { replies_count: 1, replies: [apiComment({ body: 'Still here' })] })])
    await screen.findByText('Still here')

    expect(within(screen.getByText('This comment was deleted.').closest('div.relative') as HTMLElement).queryByRole('button')).toBeNull()
  })
})

describe('editing and deleting', () => {
  const own = (o: Partial<CommentDto> = {}) => apiComment({ id: '01JOWN0000000000000000000A', body: 'My first take', author: mine, can_edit: true, can_delete: true, ...o })

  it('edits in place with PATCH and marks the comment as edited', async () => {
    const { api } = open([own()], {
      routes: { 'PATCH /comments/:id': (call) => json({ data: own({ body: (call.body as { body: string }).body, edited_at: '2026-10-21T09:10:00.000000Z' }) }) },
    })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    const box = screen.getByLabelText('Edit comment')
    type(box, 'My better take')
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('· edited')).toBeTruthy()
    expect(screen.getByText('My better take').tagName).toBe('P')
    expect(screen.queryByLabelText('Edit comment')).toBeNull()
    expect(api.callsTo('PATCH /comments/01JOWN0000000000000000000A')[0].body).toEqual({ body: 'My better take' })
  })

  it('puts the old text back and says so when the edit window has closed (409)', async () => {
    const { api } = open([own()], { routes: { 'PATCH /comments/:id': apiError(409, 'The edit window is closed.', { code: 'edit_window_closed' }) } })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    type(screen.getByLabelText('Edit comment'), 'Too late')
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('The edit window has closed')).toBeTruthy()
    await waitFor(() => expect(api.callsTo('GET /products/:slug/comments').length).toBeGreaterThanOrEqual(2))
    // Nothing left to edit: the box closes and the old text shows.
    await waitFor(() => expect(screen.queryByLabelText('Edit comment')).toBeNull())
    expect(screen.getByText('My first take')).toBeTruthy()
  })

  it('shows a 422 on the edit box and keeps it open', async () => {
    open([own()], { routes: { 'PATCH /comments/:id': validation({ body: ['A comment can contain at most 3 links.'] }) } })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    type(screen.getByLabelText('Edit comment'), 'a.com b.com c.com d.com')
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('A comment can contain at most 3 links.')).toBeTruthy()
    expect(screen.getByLabelText('Edit comment')).toBeTruthy()
  })

  it('disables Save while the edit limiter cools down', async () => {
    open([own()], { routes: { 'PATCH /comments/:id': apiError(429, 'Slow', {}, { 'Retry-After': '30' }) } })
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    type(screen.getByLabelText('Edit comment'), 'Again')
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    const wait = await screen.findByRole('button', { name: /Try again in \d+ s/ })
    expect((wait as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByLabelText('Edit comment')).toBeTruthy()
  })

  it('offers no Edit once the window closed (can_edit false), and none to other people', async () => {
    open([own({ can_edit: false }), apiComment({ body: 'Someone else' })])
    await screen.findByText('My first take')

    expect(screen.queryByRole('button', { name: /Edit/ })).toBeNull()
    expect(within(row('My first take')).getByRole('button', { name: /Delete/ })).toBeTruthy()
    expect(within(row('Someone else')).queryByRole('button', { name: /Delete/ })).toBeNull()
    expect(within(row('My first take')).queryByRole('button', { name: /Report/ })).toBeNull()
  })

  it('deletes after a confirmation: the placeholder appears at once and the count drops', async () => {
    const pending = deferred()
    const { api } = open([own()], { routes: { 'DELETE /comments/:id': () => pending.promise } })
    fireEvent.click(await screen.findByRole('button', { name: /Delete/ }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('My first take')).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))

    expect(await screen.findByText('This comment was deleted.')).toBeTruthy()
    await waitFor(() => expect(screen.queryByText('My first take')).toBeNull())
    expect(screen.getByText('2 comments')).toBeTruthy()
    pending.resolve(json({ data: placeholderComment('deleted', { id: '01JOWN0000000000000000000A' }) }))

    await screen.findByText('Comment deleted')
    expect(api.callsTo('DELETE /comments/01JOWN0000000000000000000A')).toHaveLength(1)
    expect(screen.getByText('2 comments')).toBeTruthy()
  })

  it('brings the comment back when deleting fails', async () => {
    open([own()], { routes: { 'DELETE /comments/:id': apiError(500, 'boom') } })
    fireEvent.click(await screen.findByRole('button', { name: /Delete/ }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete' }))

    expect(await screen.findByText('Could not delete the comment')).toBeTruthy()
    expect(await screen.findByText('My first take')).toBeTruthy()
    expect(screen.queryByText('This comment was deleted.')).toBeNull()
    expect(screen.getByText('3 comments')).toBeTruthy()
  })
})

describe('reporting a comment', () => {
  it('sends the reason to POST /comments/{id}/report', async () => {
    const target = apiComment({ id: '01JTARGET00000000000000000', body: 'Buy my pills' })
    const { api } = open([target], { routes: { 'POST /comments/:id/report': json(apiReport({ type: 'comment', reason: 'spam' }), 201) } })
    await screen.findByText('Buy my pills')

    fireEvent.click(within(row('Buy my pills')).getByRole('button', { name: /Report/ }))
    expect(await screen.findByText(/this comment by Carl Commenter/)).toBeTruthy()
    fireEvent.click(screen.getByRole('radio', { name: /Spam/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    expect(await screen.findByText('Thanks for the report')).toBeTruthy()
    expect(api.callsTo('POST /comments/01JTARGET00000000000000000/report')[0].body).toEqual({ reason: 'spam' })
  })

  it('shows the own-content rule in the dialog', async () => {
    open([apiComment({ body: 'Mine in disguise' })], { routes: { 'POST /comments/:id/report': apiError(403, 'x', { code: 'own_content' }) } })
    await screen.findByText('Mine in disguise')

    fireEvent.click(within(row('Mine in disguise')).getByRole('button', { name: /Report/ }))
    fireEvent.click(await screen.findByRole('radio', { name: /Abuse/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Send report' }))

    expect((await screen.findByRole('alert')).textContent).toBe('You cannot report your own content.')
  })

  it('asks a guest to sign in', async () => {
    open([apiComment({ body: 'Rude comment' })], { user: null })
    await screen.findByText('Rude comment')

    fireEvent.click(within(row('Rude comment')).getByRole('button', { name: /Report/ }))

    expect(await screen.findByText('Join Launchpad')).toBeTruthy()
  })
})
