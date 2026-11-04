import { useMutation } from '@tanstack/react-query'
import { reportComment, reportProduct, type ReportInput } from '../endpoints/reports'

export interface ReportVars extends ReportInput {
  kind: 'product' | 'comment'
  targetId: string
}

/** POST /products/{id}/report or /comments/{id}/report. The modal reads the error code and shows it in place. */
export function useReport() {
  return useMutation({
    mutationFn: ({ kind, targetId, ...input }: ReportVars) => (kind === 'product' ? reportProduct(targetId, input) : reportComment(targetId, input)),
  })
}
