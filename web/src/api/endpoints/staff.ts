import { api } from '../client'
import type {
  AdminUserDto,
  ApiItem,
  ProductIntegrityDto,
  ReportActionDto,
  ReportTypeDto,
  ResolveOutcomeDto,
  ReviewDecisionDto,
  RiskMemberSummaryDto,
  RiskOutcomeDto,
  RiskSignalDetailDto,
  RiskSignalDto,
  StaffProductDto,
  StaffReportDetailDto,
  StaffReportQueueDto,
  SuspendForAbuseDto,
  SuspensionDto,
  VoidVotesDto,
} from '../types'

/** Staff and admin calls. Every id goes in the path encoded; the role is judged by the API (403), never by the client. */
const seg = encodeURIComponent

/* ---- Review ---- */

export const getStaffProduct = (id: string, signal?: AbortSignal) => api.get<ApiItem<StaffProductDto>>(`/staff/products/${seg(id)}`, { signal }).then((r) => r.data)

/** `submittedAt` is the microsecond string exactly as the API returned it. */
export const reviewProduct = (id: string, input: { decision: ReviewDecisionDto; note: string | null; submittedAt: string }) =>
  api.post<ApiItem<StaffProductDto>>(`/staff/products/${seg(id)}/review`, { body: { decision: input.decision, note: input.note, submitted_at: input.submittedAt } }).then((r) => r.data)

export const takeDownProduct = (id: string, reason: string) => api.post<ApiItem<StaffProductDto>>(`/staff/products/${seg(id)}/take-down`, { body: { reason } }).then((r) => r.data)

export const restoreProduct = (id: string) => api.post<ApiItem<StaffProductDto>>(`/staff/products/${seg(id)}/restore`).then((r) => r.data)

/* ---- Reports ---- */

export const getReportQueue = (type: ReportTypeDto | undefined, signal?: AbortSignal) => api.get<StaffReportQueueDto>('/staff/reports', { query: { type }, signal })

export const getReportDetail = (type: ReportTypeDto, id: string, signal?: AbortSignal) => api.get<ApiItem<StaffReportDetailDto>>(`/staff/reports/${seg(type)}/${seg(id)}`, { signal }).then((r) => r.data)

export const resolveReport = (type: ReportTypeDto, id: string, input: { action: ReportActionDto; note: string | null }) =>
  api.post<ApiItem<ResolveOutcomeDto>>(`/staff/reports/${seg(type)}/${seg(id)}/resolve`, { body: input }).then((r) => r.data)

/* ---- Members (admin) ---- */

export const changeRole = (handle: string, role: 'member' | 'reviewer') => api.patch<ApiItem<AdminUserDto>>(`/admin/users/${seg(handle)}/role`, { body: { role } }).then((r) => r.data)

export const suspendMember = (handle: string, reason: string) => api.post<ApiItem<SuspensionDto>>(`/admin/users/${seg(handle)}/suspend`, { body: { reason } }).then((r) => r.data)

export const restoreMember = (handle: string) => api.post<ApiItem<SuspensionDto>>(`/admin/users/${seg(handle)}/restore`).then((r) => r.data)

/* ---- Anti-cheat ---- */

export const getRiskSignal = (id: string, signal?: AbortSignal) => api.get<ApiItem<RiskSignalDetailDto>>(`/staff/risk-signals/${seg(id)}`, { signal }).then((r) => r.data)

export const reviewRiskSignal = (id: string, input: { outcome: RiskOutcomeDto; note: string | null }) =>
  api.post<ApiItem<RiskSignalDto> & { meta: { changed: boolean } }>(`/staff/risk-signals/${seg(id)}/review`, { body: input })

export const getRiskMember = (id: string, signal?: AbortSignal) => api.get<ApiItem<RiskMemberSummaryDto>>(`/staff/risk/members/${seg(id)}`, { signal }).then((r) => r.data)

export const voidMemberVotes = (id: string, input: { reason: string; productId?: string; signal?: AbortSignal }) =>
  api.post<ApiItem<VoidVotesDto>>(`/staff/risk/members/${seg(id)}/void-votes`, { body: { reason: input.reason, product_id: input.productId }, signal: input.signal }).then((r) => r.data)

export const suspendForAbuse = (id: string, input: { reason: string; voidVotes: boolean }) =>
  api.post<SuspendForAbuseDto>(`/staff/risk/members/${seg(id)}/suspend`, { body: { reason: input.reason, void_votes: input.voidVotes } })

export const getProductIntegrity = (id: string, signal?: AbortSignal) => api.get<ApiItem<ProductIntegrityDto>>(`/staff/products/${seg(id)}/vote-integrity`, { signal }).then((r) => r.data)
