import type { AuditLogDto } from '@/api/types'
import { toCsv } from './csv'

/** The export holds the rows loaded on screen, capped here. */
export const CSV_ROW_CAP = 1000

/** The CSV text of audit rows. Every cell goes through the formula guard in `csv.ts`; `meta` is written as JSON text. */
export function auditCsv(entries: AuditLogDto[]): string {
  return toCsv([
    ['id', 'timestamp_utc', 'actor_handle', 'action', 'subject_type', 'subject_id', 'meta'],
    ...entries.slice(0, CSV_ROW_CAP).map((e) => [e.id, e.created_at, e.actor?.handle ?? '', e.action, e.subject_type ?? '', e.subject_id ?? '', e.meta ?? '']),
  ])
}
