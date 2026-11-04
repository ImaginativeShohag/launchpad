import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { isCardQuery } from '../cache'
import { getReportDetail, getReportQueue, resolveReport } from '../endpoints/staff'
import { keys } from '../keys'
import { useCursorList } from '../pagination'
import type { ReportActionDto, ReportTypeDto, StaffReportHistoryDto } from '../types'

/** GET /staff/reports: one row per reported target, top 100, no cursor. `meta` says how many targets exist and whether the list was cut. */
export function useReportQueue(type?: ReportTypeDto) {
  return useQuery({ queryKey: keys.staffReports(type), queryFn: ({ signal }) => getReportQueue(type, signal), staleTime: 0 })
}

/** GET /staff/reports/history: closed reports, newest first. */
export function useReportHistory(type?: ReportTypeDto, enabled = true) {
  return useCursorList<StaffReportHistoryDto>(keys.staffReportHistory(type), '/staff/reports/history', { type, per_page: 25 }, { enabled, staleTime: 0 })
}

export function useReportDetail(type: ReportTypeDto | undefined, id: string | undefined) {
  return useQuery({ queryKey: keys.staffReportDetail(type ?? '', id ?? ''), queryFn: ({ signal }) => getReportDetail(type!, id!, signal), enabled: !!type && !!id, staleTime: 0 })
}

/** Closes every open report of the target. The queue is read again (the order moves as reports arrive); nothing is patched by hand. */
export function useResolveReport() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ type, id, action, note }: { type: ReportTypeDto; id: string; action: ReportActionDto; note: string | null }) => resolveReport(type, id, { action, note }),
    onSuccess: (outcome, { type, id }) => {
      void qc.invalidateQueries({ queryKey: ['staff', 'reports'] })
      void qc.invalidateQueries({ queryKey: ['staff', 'report-history'] })
      void qc.invalidateQueries({ queryKey: keys.staffReportDetail(type, id) })
      if (outcome.target_changed) {
        void qc.invalidateQueries({ queryKey: ['staff', 'products'] })
        void qc.invalidateQueries({ predicate: (q) => isCardQuery(q.queryKey) || q.queryKey[0] === 'comments' || q.queryKey[0] === 'replies' })
      }
    },
  })
}
