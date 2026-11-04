import { json, type RouteValue } from './mockApi'
import { page, reportsBody } from '../fixtures/staff'

/** The calls the staff layout makes on every staff page (the two tab badges). */
export const layoutRoutes = (): Record<string, RouteValue> => ({
  'GET /staff/review-queue': json(page([])),
  'GET /staff/reports': json(reportsBody([])),
})
