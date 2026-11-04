/** DTOs, hand-written from api/app/Http/Resources/Api/V1. Pages never use these directly: mappers turn them into view models. */

export type ApiRole = 'member' | 'reviewer' | 'admin'

export type LinkKind = 'website' | 'app_store' | 'play_store' | 'github' | 'x' | 'linkedin' | 'docs' | 'youtube' | 'other'

export interface UserLinkDto {
  id: string
  kind: LinkKind
  label: string | null
  url: string
  position: number
}

/** UserResource: the signed-in member's own view (`/me`, login, register, avatar, verify). `links` only when loaded. */
export interface MeDto {
  id: string
  handle: string
  name: string
  email: string
  email_verified: boolean
  pending_email: string | null
  has_password: boolean
  role: ApiRole
  headline: string | null
  avatar_url: string | null
  links?: UserLinkDto[]
  created_at: string | null
}

/** PublicUserResource: what anyone sees. A suspended member shows only name and handle. */
export interface PublicUserDto {
  id: string
  handle: string
  name: string
  headline: string | null
  avatar_url: string | null
  links?: UserLinkDto[]
  is_suspended: boolean
  joined_at: string | null
  followers_count?: number
  following_count?: number
  is_following?: boolean
}

export interface TopicDto {
  id: string
  slug: string
  name: string
  emoji: string
  description: string | null
  position: number
}

export interface EmailPrefDto {
  type: string
  enabled: boolean
}

export interface ApiItem<T> {
  data: T
}

export interface ApiList<T> {
  data: T[]
}

/** Laravel cursorPaginate envelope. Search adds `query`/`topic`/`sort`, the feed adds `has_follows`. */
export interface ApiPage<T> {
  data: T[]
  links?: { next?: string | null; prev?: string | null }
  meta?: {
    next_cursor?: string | null
    prev_cursor?: string | null
    per_page?: number
    /** Search: the server's normalised phrase (what the highlight uses). */
    query?: string | null
    topic?: string | null
    sort?: string
    /** Feed: false = the member follows nothing yet. */
    has_follows?: boolean
  }
}

export interface MessageDto {
  message: string
  code?: string
}

export interface ProductScreenshotDto {
  id: string
  url: string
  position: number
  width: number | null
  height: number | null
  alt_text: string | null
}

export interface ProductLinkDto {
  id: string
  kind: LinkKind
  url: string
  position: number
}

/**
 * ProductResource: what anyone sees of a visible product. `votes_count` and `comments_count` appear on lists and single reads,
 * `has_voted` only for a signed-in caller, `rank` on today/day/leaderboard cards, `reasons` on feed cards. Relations when loaded.
 */
export interface ProductDto {
  id: string
  slug: string
  name: string
  tagline: string | null
  description: string | null
  website_url: string | null
  logo_url: string | null
  launch_date: string | null
  launched_at: string | null
  votes_count?: number
  comments_count?: number
  has_voted?: boolean
  rank?: number
  maker?: PublicUserDto
  topics?: TopicDto[]
  links?: ProductLinkDto[]
  screenshots?: ProductScreenshotDto[]
  reasons?: FeedReasonDto[]
}

export type FeedReasonDto =
  | { type: 'member'; user: { id: string; handle: string; name: string } }
  | { type: 'topic'; topic: { id: string; slug: string; name: string } }

/** GET /today. */
export interface TodayDto {
  data: ProductDto[]
  meta: { date: string; generated_at: string }
}

/** GET /days/{date}: `frozen` is false while the day is current or not yet finalised. */
export interface DayDto {
  data: ProductDto[]
  meta: { date: string; frozen: boolean }
}

/** GET /leaderboards. */
export interface LeaderboardDto {
  data: ProductDto[]
  meta: { period: string; topic: string | null; generated_at: string }
}

/** PUT/DELETE /products/{id}/vote. */
export interface VoteDto {
  voted: boolean
  votes_count: number
}

/** Frame on `today` / `.rank.updated`. A hint: never trusted for anything but order and counts. */
export interface RankFrame {
  date: string
  items: { product_id: string; rank: number; votes_count: number }[]
}

export type CommentStateDto = 'visible' | 'removed' | 'deleted'

/** CommentResource's author card. */
export interface CommentAuthorDto {
  id: string
  handle: string
  name: string
  avatar_url: string | null
  is_suspended: boolean
  is_maker: boolean
}

/**
 * CommentResource. A removed or deleted comment is a placeholder: no `body`, no `author`. `can_edit` / `can_delete` exist
 * only for a signed-in caller. `replies` (the first three) only on a top-level comment of a product list.
 */
export interface CommentDto {
  id: string
  parent_id: string | null
  state: CommentStateDto
  body: string | null
  author: CommentAuthorDto | null
  reply_to: { id: string; handle: string; name: string } | null
  created_at: string
  edited_at: string | null
  replies_count: number
  replies?: CommentDto[]
  can_edit?: boolean
  can_delete?: boolean
}

/** FollowStateResource: the answer to every follow and unfollow. */
export interface FollowStateDto {
  following: boolean
  followers_count: number
}

/** FollowedUserResource: one row of GET /me/follows/users. */
export interface FollowedUserDto {
  id: string
  handle: string
  name: string
  headline: string | null
  avatar_url: string | null
  is_suspended: boolean
  followed_at: string
}

export type ReportReasonDto = 'spam' | 'abuse' | 'misleading' | 'inappropriate' | 'other'

/** ReportResource: what the reporter gets back. */
export interface ReportDto {
  id: string
  type: string
  target_id: string
  reason: ReportReasonDto
  status: string
  created_at: string
}

export type ReviewDecisionDto = 'approved' | 'rejected' | 'changes_requested'

/** ProductReviewResource: one decision in the history; `current` marks the one the present status came from. */
export interface ProductReviewDto {
  id: string
  decision: ReviewDecisionDto
  note: string | null
  current: boolean
  created_at: string
}

/**
 * FounderProductResource: the maker's own view in every status. `editable_fields` and `media_editable` say what the status
 * allows. The relations (links, topics, screenshots, reviews) are present on a single read and after a write, not on the list.
 */
export interface FounderProductDto {
  id: string
  slug: string
  name: string
  tagline: string | null
  description: string | null
  website_url: string | null
  logo_url: string | null
  status: string
  launch_date: string | null
  submitted_at: string | null
  approved_at: string | null
  launched_at: string | null
  taken_down_at: string | null
  takedown_reason: string | null
  editable_fields: string[]
  media_editable: boolean
  links?: ProductLinkDto[]
  topics?: TopicDto[]
  screenshots?: ProductScreenshotDto[]
  reviews?: ProductReviewDto[]
  created_at: string | null
  updated_at: string | null
}

export type DashboardPhaseDto = 'before_launch' | 'launch_day' | 'after'

/** GET /me/products/{id}/dashboard (inside `data`). */
export interface DashboardSummaryDto {
  product_id: string
  name: string
  slug: string
  status: string
  phase: DashboardPhaseDto
  launch_date: string | null
  taken_down: boolean
  views: number
  votes: number
  comments: number
  /** votes / views, 4 decimals; null with no views. */
  conversion: number | null
  current_rank: number | null
  best_rank: number | null
  final_rank: number | null
  top_source: { source: string; category: string; visits: number } | null
  timeline_available: boolean
  as_of: string
}

export interface SeriesPointDto {
  t: string
  votes: number
  views: number
  cumulative_votes: number
  cumulative_views: number
}

/** GET .../dashboard/series. A point for every hour or UTC day of the window (zero-filled). */
export interface DashboardSeriesDto {
  granularity: 'hour' | 'day'
  from: string | null
  to: string | null
  points: SeriesPointDto[]
}

export interface RankPointDto {
  t: string
  rank: number
  score: number
}

/** GET .../dashboard/rank: the launch day's 10-minute marks (top 500 only, so a mark can be missing). */
export interface DashboardRankDto {
  launch_date: string | null
  phase: DashboardPhaseDto
  frozen: boolean
  final_rank: number | null
  current_rank: number | null
  best_rank: number | null
  best_rank_at: string | null
  timeline_available: boolean
  interval_minutes: number
  points: RankPointDto[]
  outside_top: number
}

export interface ReferrerSourceDto {
  source: string
  category: string
  visits: number
  share: number
}

/** GET .../dashboard/referrers: top ten sources, the rest folded into `other`. */
export interface DashboardReferrersDto {
  from: string | null
  to: string | null
  total: number
  sources: ReferrerSourceDto[]
  by_category: { category: string; visits: number; share: number }[]
}

export type ExportStatusDto = 'queued' | 'processing' | 'ready' | 'failed' | 'expired'

/** ExportResource: never a path or a URL; the client builds the download path. `error` is a short code. */
export interface ExportDto {
  id: string
  product_id: string
  status: ExportStatusDto
  created_at: string
  completed_at: string | null
  expires_at: string | null
  file_size: number | null
  row_counts: Record<string, number> | null
  error: string | null
}

/* ---- Staff and admin (web phase 6). Free text in here (notes, reasons, names, excerpts) is plain text: render it as text. ---- */

export interface StaffMakerDto {
  id: string
  handle: string
  name: string
}

export interface StaffReviewDto {
  id: string
  decision: ReviewDecisionDto
  note: string | null
  created_at: string
  reviewer?: { id: string; handle: string }
}

/**
 * StaffProductResource: the review queue (maker and topics) and the single read (everything). `submitted_at` keeps
 * microsecond precision on purpose: the review decision echoes it back and the API compares it exactly.
 */
export interface StaffProductDto {
  id: string
  slug: string
  name: string
  tagline: string | null
  description: string | null
  website_url: string | null
  logo_url: string | null
  status: string
  launch_date: string | null
  submitted_at: string | null
  approved_at: string | null
  launched_at: string | null
  taken_down_at: string | null
  takedown_reason: string | null
  maker?: StaffMakerDto
  topics?: TopicDto[]
  links?: ProductLinkDto[]
  screenshots?: ProductScreenshotDto[]
  reviews?: StaffReviewDto[]
}

/** StaffProductRowResource: a row of GET /staff/products (no description, links or screenshots). `submitted_at` is second precision here. */
export interface StaffProductRowDto {
  id: string
  slug: string
  name: string
  tagline: string | null
  logo_url: string | null
  status: string
  launch_date: string | null
  votes_count: number
  comments_count: number
  submitted_at: string | null
  launched_at: string | null
  taken_down_at: string | null
  takedown_reason: string | null
  maker?: StaffMakerDto
  topics?: TopicDto[]
  created_at: string | null
  updated_at: string | null
}

export type ReportTypeDto = 'comment' | 'product'
export type ReportActionDto = 'remove_comment' | 'take_down' | 'dismiss'

/** The compact target of a queue row: a comment (excerpt, author, product) or a product (status, maker). */
export interface StaffReportTargetDto {
  id: string
  state?: CommentStateDto
  body_excerpt?: string
  author?: { id: string; handle: string }
  product?: { id: string; slug: string; name: string }
  slug?: string
  name?: string
  status?: string
  maker?: { id: string; handle: string }
}

/** One row of GET /staff/reports: a reported target with the count, reasons and score of its open reports. */
export interface StaffReportRowDto {
  type: ReportTypeDto
  target: StaffReportTargetDto
  open_reports: number
  reasons: Record<string, number>
  score: number
  oldest_report_at: string
}

export interface StaffReportQueueDto {
  data: StaffReportRowDto[]
  meta: { total_targets: number; truncated: boolean }
}

export interface StaffReportItemDto {
  id: string
  reporter: { id: string; handle: string }
  /** False when the reporter no longer counts (unverified, suspended or deleted). */
  reporter_eligible: boolean
  reason: ReportReasonDto
  details: string | null
  created_at: string
  /** Closed reports only. */
  status?: 'resolved' | 'dismissed'
  handled_by?: { id: string; handle: string } | null
  handled_at?: string | null
  resolution_note?: string | null
}

/** A row of GET /staff/reports/history: a closed report with the target it was about. */
export interface StaffReportHistoryDto extends StaffReportItemDto {
  type: ReportTypeDto
  target_id: string
}

export interface StaffReportDetailDto {
  type: ReportTypeDto
  /** Comment: full stored body (even when removed), author, product, removal facts. Product: tagline, status, launch day, maker. */
  target: {
    id: string
    parent_id?: string | null
    state?: CommentStateDto
    body?: string | null
    author?: { id: string; handle: string; name: string; is_suspended: boolean }
    product?: { id: string; slug: string; name: string; status: string }
    created_at?: string | null
    removed_at?: string | null
    removed_by?: { id: string; handle: string } | null
    removed_reason?: string | null
    slug?: string
    name?: string
    tagline?: string | null
    status?: string
    launch_date?: string | null
    takedown_reason?: string | null
    maker?: { id: string; handle: string; name: string; is_suspended: boolean }
  }
  open_reports: StaffReportItemDto[]
  open_total: number
  closed_reports: StaffReportItemDto[]
}

/** POST /staff/reports/{type}/{id}/resolve. `closed: 0` means nobody had an open report any more (a replay). */
export interface ResolveOutcomeDto {
  closed: number
  action: ReportActionDto
  type: ReportTypeDto
  target_id: string
  target_changed: boolean
}

/** AdminMemberResource: a row of GET /admin/users (admins only; carries the e-mail). */
export interface AdminMemberDto {
  id: string
  handle: string
  name: string
  email: string
  role: ApiRole
  created_at: string | null
  is_suspended: boolean
  suspended_at: string | null
  suspended_reason: string | null
  deleted_at: string | null
  email_verified_at: string | null
  risk_score: number
}

/** AdminUserResource: the answer to a role change. */
export interface AdminUserDto {
  id: string
  handle: string
  name: string
  email: string
  role: ApiRole
  created_at: string | null
}

/** SuspensionResource: the answer to an admin suspend or restore. */
export interface SuspensionDto {
  id: string
  handle: string
  is_suspended: boolean
  suspended_at: string | null
  suspended_reason: string | null
}

export interface AuditLogDto {
  id: string
  action: string
  subject_type: string | null
  subject_id: string | null
  meta: Record<string, unknown> | unknown[] | null
  actor: { id: string; handle: string } | null
  created_at: string
}

export type RiskSignalKindDto =
  | 'shared_device'
  | 'ip_cluster'
  | 'vote_burst'
  | 'fresh_account'
  | 'disposable_email'
  | 'single_maker_voter'
  | 'login_churn'
  | 'coordinated_votes'

export type RiskStatusDto = 'open' | 'reviewed' | 'all'
export type RiskOutcomeDto = 'confirmed' | 'dismissed'

export interface RiskMemberCardDto {
  id: string
  handle: string | null
  name: string
  risk_score: number
  suspended: boolean
  deleted: boolean
}

export interface RiskSignalDto {
  id: string
  kind: RiskSignalKindDto
  status: string
  weight: number
  detected_at: string
  reviewed_at: string | null
  reviewer: { id: string; handle: string } | null
  review_note: string | null
  outcome: RiskOutcomeDto | null
  member: RiskMemberCardDto | null
  product: { id: string; slug: string; name: string } | null
  observed: unknown
  threshold: unknown
}

export interface RiskEvidenceVoteDto {
  id: string
  user_id: string
  status: string
  voted_at: string
  removed: boolean
  /** 8-hex fingerprints; null once the 90-day purge ran. */
  device_fp: string | null
  ip_fp: string | null
}

export interface RiskSignalDetailDto extends RiskSignalDto {
  details: Record<string, unknown>
  evidence: {
    members: { id: string; handle: string; name: string; role: string; risk_score: number; created_at: string | null; suspended: boolean; deleted: boolean }[]
    votes: RiskEvidenceVoteDto[]
    product: { id: string; slug: string; name: string; launch_date: string | null } | null
  }
}

/** A row of GET /staff/risk/members. */
export interface RiskMemberDto {
  id: string
  handle: string
  name: string
  risk_score: number
  suspended: boolean
  account_age_days: number
  open_signals: number
  flagged_votes: number
}

export interface RiskMemberSummaryDto {
  id: string
  handle: string
  name: string
  role: ApiRole
  created_at: string | null
  suspended: boolean
  suspended_at: string | null
  suspended_reason: string | null
  deleted: boolean
  risk_score: number
  breakdown: Record<string, { contribution: number; open: number; confirmed: number; dismissed: number }>
  votes: { active: number; flagged: number; voided: number; withdrawn: number; distinct_makers: number; top_maker_share: number }
  sign_ins: { devices_24h: number; devices_7d: number; ip_groups_24h: number; ip_groups_7d: number }
  linked_accounts: { id: string; handle: string; via: 'device' | 'ip' }[]
  signals: RiskSignalDto[]
}

export interface IntegrityGroupDto {
  fingerprint: string
  accounts: number
  votes: number
}

/** GET /staff/products/{id}/vote-integrity (inside `data`). */
export interface ProductIntegrityDto {
  product: { id: string; slug: string; name: string } | null
  counted: number
  flagged: number
  voided: number
  removed: number
  active: number
  integrity_ratio: number
  band: 'clean' | 'watch' | 'alert'
  open_signals: number
  buckets: { hour: string; votes: number; burst: boolean }[]
  groups: { device: IntegrityGroupDto[]; ip: IntegrityGroupDto[] }
}

export type VoteStatusDto = 'counted' | 'flagged' | 'voided'

export interface StaffVoteDto {
  id: string
  status: VoteStatusDto
  voted_at: string
  removed: boolean
  toggle_count: number
  voter: { id: string | null; handle: string | null; risk_score: number | null }
  device_fp: string | null
  ip_fp: string | null
}

/** POST /staff/risk/members/{id}/void-votes. Up to 100 votes per call; `has_more` says to call again. */
export interface VoidVotesDto {
  voided: number
  skipped_frozen: number
  failed: number
  has_more: boolean
}

/** POST /staff/risk/members/{id}/suspend: the member summary plus what the call did (`void_votes` says whether the vote clean-up was queued). */
export interface SuspendForAbuseDto {
  data: RiskMemberSummaryDto
  meta: { suspended_now: boolean; signals_confirmed: number; void_votes: 'queued' | 'dispatch_failed' | 'not_requested' }
}

/** `GET /me/notifications` row. `data` holds ids, a message key, scalar params and an app-relative href; never prose. */
export interface NotificationDto {
  id: string
  type: string
  data: { message_key?: unknown; product_id?: unknown; params?: unknown; href?: unknown }
  read_at: string | null
  created_at: string
}

/** `GET /me/notifications/unread-count`: the count stops at 100 (`capped`). */
export interface UnreadCountDto {
  unread_count: number
  capped: boolean
}

export interface MarkReadResultDto {
  marked: number
  unread_count: number
}
