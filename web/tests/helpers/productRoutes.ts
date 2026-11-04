import type { ProductDto } from '@/api/types'
import { commentsBody } from '../fixtures/comment'
import { profileBody } from '../fixtures/profile'
import { pageBody, todayBody } from '../fixtures/product'
import { json, noContent, type RouteValue } from './mockApi'

/** Everything a product page asks for, answered quietly. Spread first, override per test. */
export const productRoutes = (product: ProductDto, extra: Record<string, RouteValue> = {}): Record<string, RouteValue> => ({
  'GET /products/:slug': json({ data: product }),
  'GET /products/:slug/comments': json(commentsBody([])),
  'GET /users/:handle': json(profileBody({ id: product.maker?.id, handle: product.maker?.handle, name: product.maker?.name })),
  'GET /search': json(pageBody([])),
  'GET /today': json(todayBody([])),
  'POST /products/:id/view': noContent(),
  ...extra,
})
