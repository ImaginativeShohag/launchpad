import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useCursorList } from '@/api/pagination'
import { createQueryClient } from '@/lib/queryClient'
import { json, mockApi, apiError } from '../helpers/mockApi'

type Row = { id: string }

function List({ params }: { params?: Record<string, string> }) {
  const list = useCursorList<Row, string>(['things'], '/things', params, { map: (r) => `row-${r.id}` })
  if (list.isError) return <p>failed</p>
  return (
    <div>
      <ul>{list.items.map((i) => <li key={i}>{i}</li>)}</ul>
      {list.hasNextPage && <button onClick={() => void list.fetchNextPage()}>more</button>}
    </div>
  )
}

const renderList = (params?: Record<string, string>) =>
  render(
    <QueryClientProvider client={createQueryClient({ retry: false })}>
      <List params={params} />
    </QueryClientProvider>,
  )

describe('useCursorList', () => {
  it('follows meta.next_cursor and appends pages until it is null', async () => {
    const api = mockApi({
      'GET /things': (call) =>
        call.query.get('cursor') === 'c2'
          ? json({ data: [{ id: '3' }], meta: { next_cursor: null } })
          : json({ data: [{ id: '1' }, { id: '2' }], meta: { next_cursor: 'c2' } }),
    })
    renderList({ per_page: '2' })

    expect(await screen.findByText('row-1')).toBeTruthy()
    expect(api.calls[0].query.get('cursor')).toBeNull()
    expect(api.calls[0].query.get('per_page')).toBe('2')

    fireEvent.click(screen.getByRole('button', { name: 'more' }))

    expect(await screen.findByText('row-3')).toBeTruthy()
    expect(screen.getAllByRole('listitem')).toHaveLength(3)
    expect(api.calls[1].query.get('cursor')).toBe('c2')
    await waitFor(() => expect(screen.queryByRole('button', { name: 'more' })).toBeNull())
  })

  it('surfaces an error', async () => {
    mockApi({ 'GET /things': apiError(500, 'boom') })
    renderList()
    expect(await screen.findByText('failed')).toBeTruthy()
  })
})
