import { render } from '@testing-library/react'
import { StrictMode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppProviders } from '@/AppProviders'
import { createQueryClient } from '@/lib/queryClient'
import { router as real } from '@/routes'
import type { MeDto } from '@/api/types'
import { apiError, json, mockApi, type RouteValue } from './mockApi'

/** Renders the real route table on a memory router with the real providers (under StrictMode, like main.tsx); `/me` answers as `user` (null = guest). */
export function renderApp({ path = '/', routes = {}, user = null, strict = true }: { path?: string; routes?: Record<string, RouteValue>; user?: MeDto | null; strict?: boolean }) {
  const api = mockApi({
    'GET /me': user ? json({ data: user }) : apiError(401, 'Unauthenticated.'),
    // The header bell of any signed-in page reads this; tests of the bell override it.
    ...(user ? { 'GET /me/notifications/unread-count': json({ unread_count: 0, capped: false }) } : {}),
    ...routes,
  })
  const client = createQueryClient({ retry: false })
  const router = createMemoryRouter(real.routes, { initialEntries: [path] })
  const tree = (
    <AppProviders client={client}>
      <RouterProvider router={router} />
    </AppProviders>
  )
  const view = render(strict ? <StrictMode>{tree}</StrictMode> : tree)
  return { api, client, router, ...view }
}
