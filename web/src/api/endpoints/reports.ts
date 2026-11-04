import { api } from '../client'
import type { ApiItem, ReportDto, ReportReasonDto } from '../types'

const seg = encodeURIComponent

export interface ReportInput {
  reason: ReportReasonDto
  details?: string
}

/** 201 for a new report, 200 with the member's own open one: both read the same. */
export const reportProduct = (productId: string, input: ReportInput) => api.post<ApiItem<ReportDto>>(`/products/${seg(productId)}/report`, { body: input }).then((r) => r.data)

export const reportComment = (commentId: string, input: ReportInput) => api.post<ApiItem<ReportDto>>(`/comments/${seg(commentId)}/report`, { body: input }).then((r) => r.data)
