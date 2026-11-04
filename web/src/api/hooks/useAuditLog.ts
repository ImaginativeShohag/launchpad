import { keys } from '../keys'
import { useCursorList } from '../pagination'
import type { AuditLogDto } from '../types'

export interface AuditFilters {
  actor?: string
  action?: string
  from?: string
  to?: string
}

/** GET /admin/audit-logs (admins only), newest first. Filters are sent as the API names them. */
export function useAuditLog(filters: AuditFilters) {
  return useCursorList<AuditLogDto>(keys.adminAudit(), '/admin/audit-logs', { actor: filters.actor, action: filters.action, from: filters.from, to: filters.to, per_page: 50 }, { staleTime: 0 })
}
