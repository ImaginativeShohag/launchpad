import type { InfiniteData, QueryClient } from '@tanstack/react-query'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { keys } from '@/api/keys'
import type { ApiItem, ApiPage, CommentDto, ProductDto } from '@/api/types'
import { apiError, json, type MockResult } from '../helpers/mockApi'
import { failOnConsoleError } from '../helpers/consoleErrors'
import { productRoutes } from '../helpers/productRoutes'
import { renderApp } from '../helpers/renderApp'
import { apiComment, commentAuthor, commentsBody } from '../fixtures/comment'
import { apiProduct } from '../fixtures/product'
import { apiUser } from '../fixtures/user'

failOnConsoleError()

const me = apiUser()
const mine = commentAuthor({ id: me.id, handle: me.handle, name: me.name })
const PRODUCT = apiProduct({ id: '01JPRODUCT00000000000000D1', slug: 'orbit', name: 'Orbit', comments_count: 5 })
const ROOT = '01JROOT00000000000000000A1'
const OWN = '01JOWN000000000000000000B1'

const deferred = () => {
  let resolve!: (v: MockResult) => void
  const promise = new Promise<MockResult>((r) => (resolve = r))
  return { promise, resolve }
}

const root = () => apiComment({ id: ROOT, body: 'Root', replies_count: 1, replies: [apiComment({ body: 'Existing reply', parent_id: ROOT })] })
const own = () => apiComment({ id: OWN, body: 'Mine', author: mine, can_edit: true, can_delete: true })

function setup(routes: Record<string, never>) {
  return renderApp({
    path: '/p/orbit',
    user: me,
    routes: productRoutes(PRODUCT, { 'GET /products/:slug': json({ data: PRODUCT }), 'GET /products/:slug/comments': json(commentsBody([root(), own()])), ...routes } as never),
  })
}

type Pages = InfiniteData<ApiPage<CommentDto>>
const list = (client: QueryClient, sort = 'top') => client.getQueryData<Pages>(['comments', 'orbit', sort, { sort, per_page: 20 }])
const comments = (client: QueryClient, sort = 'top') => list(client, sort)?.pages[0].data ?? []
const count = (client: QueryClient) => client.getQueryData<ApiItem<ProductDto>>(keys.product('orbit'))!.data.comments_count
const composer = () => screen.getByLabelText('What do you think of Orbit?')
const send = (text: string, box: HTMLElement = composer()) => {
  fireEvent.focus(box)
  fireEvent.change(box, { target: { value: text } })
  fireEvent.keyDown(box, { key: 'Enter', ctrlKey: true })
}

describe('optimistic comments', () => {
  it('puts a temporary row first in the list and counts it, then swaps in the server comment', async () => {
    const pending = deferred()
    const { client } = setup({ 'POST /products/:id/comments': () => pending.promise } as never)
    await screen.findByText('Root')

    send('Fresh thought')

    await waitFor(() => expect(comments(client)[0].body).toBe('Fresh thought'))
    expect(comments(client)[0].id).toMatch(/^tmp-\d+$/)
    expect(count(client)).toBe(6)
    pending.resolve(json({ data: apiComment({ id: '01JSERVER0000000000000000C1', body: 'Fresh thought', author: mine }) }, 201))

    await waitFor(() => expect(comments(client)[0].id).toBe('01JSERVER0000000000000000C1'))
    expect(comments(client).filter((c) => c.body === 'Fresh thought')).toHaveLength(1)
    expect(count(client)).toBe(6)
  })

  it('removes the temporary row and the count when the server refuses', async () => {
    const { client } = setup({ 'POST /products/:id/comments': apiError(500, 'boom') } as never)
    await screen.findByText('Root')

    send('Doomed')

    await screen.findByText('Could not post your comment')
    expect(comments(client).map((c) => c.body)).toEqual(['Root', 'Mine'])
    expect(count(client)).toBe(5)
  })

  it('adds a reply to its thread and bumps the thread counter, and undoes both on failure', async () => {
    const pending = deferred()
    const { client } = setup({ 'POST /products/:id/comments': () => pending.promise } as never)
    await screen.findByText('Root')

    fireEvent.click(screen.getAllByRole('button', { name: /Reply/ })[0])
    send('My reply', await screen.findByLabelText(/^Reply to /))

    await waitFor(() => expect(comments(client)[0].replies).toHaveLength(2))
    expect(comments(client)[0]).toMatchObject({ replies_count: 2 })
    expect(comments(client)[0].replies![1]).toMatchObject({ body: 'My reply', parent_id: ROOT })
    pending.resolve(apiError(500, 'boom'))

    await screen.findByText('Could not post your comment')
    expect(comments(client)[0].replies).toHaveLength(1)
    expect(comments(client)[0].replies_count).toBe(1)
    expect(count(client)).toBe(5)
  })

  it('shows the new comment under the other sort as well', async () => {
    const pending = deferred()
    const { client } = setup({ 'POST /products/:id/comments': () => pending.promise } as never)
    await screen.findByText('Root')
    fireEvent.click(screen.getByRole('tab', { name: 'Newest' }))
    await waitFor(() => expect(list(client, 'newest')).toBeTruthy())

    send('Everywhere')

    await waitFor(() => expect(comments(client, 'newest')[0].body).toBe('Everywhere'))
    expect(comments(client, 'top')[0].body).toBe('Everywhere')
    pending.resolve(apiError(500, 'x'))
    await screen.findByText('Could not post your comment')
  })

  it('edits optimistically and restores the text and the edited mark on failure', async () => {
    const pending = deferred()
    const { client } = setup({ 'PATCH /comments/:id': () => pending.promise } as never)
    fireEvent.click(await screen.findByRole('button', { name: /Edit/ }))
    fireEvent.change(screen.getByLabelText('Edit comment'), { target: { value: 'Changed' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(comments(client).find((c) => c.id === OWN)).toMatchObject({ body: 'Changed' }))
    expect(comments(client).find((c) => c.id === OWN)!.edited_at).toBeTruthy()
    pending.resolve(apiError(404, 'Not found.'))

    await screen.findByText('This comment is no longer available')
    await waitFor(() => expect(comments(client).find((c) => c.id === OWN)).toMatchObject({ body: 'Mine', edited_at: null }))
  })

  it('turns a comment into the deleted placeholder at once and brings it back on failure', async () => {
    const pending = deferred()
    const { client } = setup({ 'DELETE /comments/:id': () => pending.promise } as never)
    fireEvent.click(await screen.findByRole('button', { name: /Delete/ }))
    fireEvent.click((await screen.findAllByRole('button', { name: 'Delete' })).at(-1)!)

    await waitFor(() => expect(comments(client).find((c) => c.id === OWN)).toMatchObject({ state: 'deleted', body: null, author: null, can_edit: false, can_delete: false }))
    expect(count(client)).toBe(4)
    pending.resolve(apiError(500, 'boom'))

    await screen.findByText('Could not delete the comment')
    await waitFor(() => expect(comments(client).find((c) => c.id === OWN)).toMatchObject({ state: 'visible', body: 'Mine', can_delete: true }))
    expect(comments(client).find((c) => c.id === OWN)!.author).toMatchObject({ handle: me.handle })
    expect(count(client)).toBe(5)
  })
})
