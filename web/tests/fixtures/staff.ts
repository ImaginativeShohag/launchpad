import type {
  AdminMemberDto,
  AuditLogDto,
  ProductIntegrityDto,
  ResolveOutcomeDto,
  RiskMemberDto,
  RiskMemberSummaryDto,
  RiskSignalDetailDto,
  RiskSignalDto,
  StaffProductDto,
  StaffProductRowDto,
  StaffReportDetailDto,
  StaffReportHistoryDto,
  StaffReportItemDto,
  StaffReportRowDto,
  StaffVoteDto,
} from '@/api/types'
import { ulid } from './product'

export const staffMaker = (o: Partial<NonNullable<StaffProductDto['maker']>> = {}) => ({ id: '01JMAKER0000000000000000001', handle: 'rafi', name: 'Rafi Chowdhury', ...o })

/** Hours before now as an ISO timestamp with microseconds, the way the review token looks. */
export const microsAgo = (hours: number) => new Date(Date.now() - hours * 3_600_000).toISOString().replace(/\.(\d{3})Z$/, '.$1000Z')

/** StaffProductResource as the queue and the single read return it. */
export const staffProduct = (o: Partial<StaffProductDto> = {}): StaffProductDto => {
  const id = o.id ?? ulid()
  return {
    id,
    slug: `product-${id.slice(-4).toLowerCase()}`,
    name: `Product ${id.slice(-4)}`,
    tagline: 'Does one thing well',
    description: 'A longer description of the product.',
    website_url: 'https://example.com',
    logo_url: null,
    status: 'pending',
    launch_date: null,
    submitted_at: microsAgo(5),
    approved_at: null,
    launched_at: null,
    taken_down_at: null,
    takedown_reason: null,
    maker: staffMaker(),
    topics: [{ id: '01JTOPIC0000000000000000000', slug: 'ai', name: 'AI', emoji: '✨', description: null, position: 1 }],
    links: [],
    screenshots: [],
    reviews: [],
    ...o,
  }
}

export const staffRow = (o: Partial<StaffProductRowDto> = {}): StaffProductRowDto => {
  const id = o.id ?? ulid()
  return {
    id,
    slug: `product-${id.slice(-4).toLowerCase()}`,
    name: `Product ${id.slice(-4)}`,
    tagline: 'Does one thing well',
    logo_url: null,
    status: 'live',
    launch_date: '2026-10-20',
    votes_count: 42,
    comments_count: 3,
    submitted_at: '2026-10-15T10:00:00+00:00',
    launched_at: '2026-10-20T00:00:00+00:00',
    taken_down_at: null,
    takedown_reason: null,
    maker: staffMaker(),
    topics: [],
    created_at: '2026-10-14T10:00:00+00:00',
    updated_at: '2026-10-20T10:00:00+00:00',
    ...o,
  }
}

/** Laravel cursorPaginate envelope. */
export const page = <T>(data: T[], next: string | null = null) => ({ data, links: { next: null }, meta: { next_cursor: next, per_page: 20 } })

export const reportRow = (o: Partial<StaffReportRowDto> = {}): StaffReportRowDto => ({
  type: 'comment',
  target: { id: ulid(), state: 'visible', body_excerpt: 'Buy followers here', author: { id: 'u1', handle: 'spammer' }, product: { id: 'p1', slug: 'lumen', name: 'Lumen' } },
  open_reports: 2,
  reasons: { spam: 2 },
  score: 1.5,
  oldest_report_at: '2026-10-22T08:00:00.000000Z',
  ...o,
})

export const reportsBody = (rows: StaffReportRowDto[], meta: { total_targets?: number; truncated?: boolean } = {}) => ({
  data: rows,
  meta: { total_targets: meta.total_targets ?? rows.length, truncated: meta.truncated ?? false },
})

export const reportItem = (o: Partial<StaffReportItemDto> = {}): StaffReportItemDto => ({
  id: ulid(),
  reporter: { id: 'u2', handle: 'amina' },
  reporter_eligible: true,
  reason: 'spam',
  details: null,
  created_at: '2026-10-22T08:00:00.000000Z',
  ...o,
})

export const commentReport = (id: string, o: Partial<StaffReportDetailDto> = {}): StaffReportDetailDto => ({
  type: 'comment',
  target: {
    id,
    parent_id: null,
    state: 'visible',
    body: 'Buy followers at spam.example',
    author: { id: 'u1', handle: 'spammer', name: 'Spam Er', is_suspended: false },
    product: { id: 'p1', slug: 'lumen', name: 'Lumen', status: 'live' },
    created_at: '2026-10-22T07:00:00.000000Z',
    removed_at: null,
    removed_by: null,
    removed_reason: null,
  },
  open_reports: [reportItem({ details: 'Same message everywhere' })],
  open_total: 1,
  closed_reports: [],
  ...o,
})

export const productReport = (id: string, o: Partial<StaffReportDetailDto> = {}): StaffReportDetailDto => ({
  type: 'product',
  target: { id, slug: 'crypto-moon', name: 'Crypto Moon', tagline: 'To the moon', status: 'live', launch_date: '2026-10-20', takedown_reason: null, maker: { id: 'u3', handle: 'lee', name: 'Lee', is_suspended: false } },
  open_reports: [reportItem({ reason: 'misleading' })],
  open_total: 1,
  closed_reports: [],
  ...o,
})

export const outcome = (o: Partial<ResolveOutcomeDto> = {}): ResolveOutcomeDto => ({ closed: 1, action: 'dismiss', type: 'comment', target_id: 'x', target_changed: false, ...o })

export const historyRow = (o: Partial<StaffReportHistoryDto> = {}): StaffReportHistoryDto => ({
  ...reportItem(),
  type: 'comment',
  target_id: ulid(),
  status: 'resolved',
  handled_by: { id: 'u9', handle: 'kenji' },
  handled_at: '2026-10-21T10:00:00.000000Z',
  resolution_note: 'Removed: spam',
  ...o,
})

export const adminMember = (o: Partial<AdminMemberDto> = {}): AdminMemberDto => {
  const id = o.id ?? ulid()
  return {
    id,
    handle: `member_${id.slice(-4).toLowerCase()}`,
    name: `Member ${id.slice(-4)}`,
    email: `m${id.slice(-4)}@example.com`,
    role: 'member',
    created_at: '2026-09-01T10:00:00+00:00',
    is_suspended: false,
    suspended_at: null,
    suspended_reason: null,
    deleted_at: null,
    email_verified_at: '2026-09-01T10:05:00+00:00',
    risk_score: 0,
    ...o,
  }
}

export const auditRow = (o: Partial<AuditLogDto> = {}): AuditLogDto => ({
  id: ulid(),
  action: 'product.reviewed',
  subject_type: 'product',
  subject_id: '01JPRODUCT00000000000000001',
  meta: { decision: 'approved', review_id: '01JREVIEW000000000000000001' },
  actor: { id: '01JKENJI0000000000000000001', handle: 'kenji' },
  created_at: '2026-10-22T09:00:00+00:00',
  ...o,
})

export const riskSignal = (o: Partial<RiskSignalDto> = {}): RiskSignalDto => ({
  id: ulid(),
  kind: 'shared_device',
  status: 'open',
  weight: 30,
  detected_at: '2026-10-22T08:00:00+00:00',
  reviewed_at: null,
  reviewer: null,
  review_note: null,
  outcome: null,
  member: { id: '01JBADMEM00000000000000001', handle: 'badactor', name: 'Bad Actor', risk_score: 80, suspended: false, deleted: false },
  product: { id: '01JPRODUCT00000000000000001', slug: 'crypto-moon', name: 'Crypto Moon' },
  observed: 4,
  threshold: 3,
  ...o,
})

export const signalDetail = (o: Partial<RiskSignalDetailDto> = {}): RiskSignalDetailDto => ({
  ...riskSignal(),
  details: { user_ids: ['01JBADMEM00000000000000001'] },
  evidence: {
    members: [{ id: '01JBADMEM00000000000000001', handle: 'badactor', name: 'Bad Actor', role: 'member', risk_score: 80, created_at: null, suspended: false, deleted: false }],
    votes: [{ id: ulid(), user_id: '01JBADMEM00000000000000001', status: 'flagged', voted_at: '2026-10-22T07:00:00+00:00', removed: false, device_fp: 'ab12cd34', ip_fp: null }],
    product: { id: '01JPRODUCT00000000000000001', slug: 'crypto-moon', name: 'Crypto Moon', launch_date: '2026-10-22' },
  },
  ...o,
})

export const riskMember = (o: Partial<RiskMemberDto> = {}): RiskMemberDto => ({
  id: '01JBADMEM00000000000000001',
  handle: 'badactor',
  name: 'Bad Actor',
  risk_score: 80,
  suspended: false,
  account_age_days: 3,
  open_signals: 2,
  flagged_votes: 5,
  ...o,
})

export const memberSummary = (o: Partial<RiskMemberSummaryDto> = {}): RiskMemberSummaryDto => ({
  id: '01JBADMEM00000000000000001',
  handle: 'badactor',
  name: 'Bad Actor',
  role: 'member',
  created_at: '2026-10-19T10:00:00+00:00',
  suspended: false,
  suspended_at: null,
  suspended_reason: null,
  deleted: false,
  risk_score: 80,
  breakdown: { shared_device: { contribution: 40, open: 1, confirmed: 0, dismissed: 0 } },
  votes: { active: 12, flagged: 5, voided: 0, withdrawn: 1, distinct_makers: 2, top_maker_share: 0.75 },
  sign_ins: { devices_24h: 3, devices_7d: 4, ip_groups_24h: 2, ip_groups_7d: 2 },
  linked_accounts: [{ id: '01JLINKED0000000000000000001', handle: 'sidekick', via: 'device' }],
  signals: [riskSignal()],
  ...o,
})

export const integrity = (o: Partial<ProductIntegrityDto> = {}): ProductIntegrityDto => ({
  product: { id: '01JPRODUCT00000000000000001', slug: 'crypto-moon', name: 'Crypto Moon' },
  counted: 90,
  flagged: 10,
  voided: 2,
  removed: 1,
  active: 100,
  integrity_ratio: 0.9,
  band: 'watch',
  open_signals: 1,
  buckets: Array.from({ length: 24 }, (_, i) => ({ hour: new Date(Date.UTC(2026, 9, 21, i)).toISOString(), votes: i % 5, burst: i === 7 })),
  groups: { device: [{ fingerprint: 'deadbeef', accounts: 3, votes: 7 }], ip: [] },
  ...o,
})

export const staffVote = (o: Partial<StaffVoteDto> = {}): StaffVoteDto => ({
  id: ulid(),
  status: 'counted',
  voted_at: '2026-10-22T07:00:00+00:00',
  removed: false,
  toggle_count: 1,
  voter: { id: '01JBADMEM00000000000000001', handle: 'badactor', risk_score: 80 },
  device_fp: 'ab12cd34',
  ip_fp: '99887766',
  ...o,
})
