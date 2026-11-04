export type Role = 'visitor' | 'member' | 'reviewer' | 'admin'

export interface UserLink {
  label: string
  url: string
  /** The API's link kind (website, github, ...). Absent on links that were never saved. */
  kind?: string
}

export interface User {
  id: string
  name: string
  handle: string
  /** Two-stop gradient used for the generated avatar (derived from the id). */
  hue: number
  headline: string
  links: UserLink[]
  role: Exclude<Role, 'visitor'>
  /** YYYY-MM-DD */
  joinedAt: string
  /** Own account (`/me`) only. */
  email?: string
  pendingEmail?: string
  emailVerified?: boolean
  /** False for a Google-only account: no Launchpad password to change. */
  hasPassword?: boolean
  avatarUrl?: string
  suspended?: boolean
  /** Public profiles only. */
  followersCount?: number
  followingCount?: number
  isFollowing?: boolean
}

export interface Topic {
  id: string
  slug: string
  name: string
  emoji: string
  description?: string
  position?: number
}

/** What a product list card needs (`logoUrl`, `topics`, `commentsCount`, `hasVoted` and `rank` come from the API). */
export interface ProductCard {
  id: string
  slug: string
  name: string
  tagline: string
  hue: number
  votes: number
  makerId: string
  logoUrl?: string
  topics?: Topic[]
  /** YYYY-MM-DD */
  launchDate?: string
  description?: string
  commentsCount?: number
  /** Absent for guests. */
  hasVoted?: boolean
  /** 1-based position on today / a day / a leaderboard. */
  rank?: number
}

/** Why a product is in the member's following feed. */
export type FeedReason = { type: 'member'; name: string; handle: string } | { type: 'topic'; name: string; slug: string }

export interface FeedCard extends ProductCard {
  reasons: FeedReason[]
}

export type ProductStatus =
  | 'draft'
  | 'pending'
  | 'changes_requested'
  | 'rejected'
  | 'approved'
  | 'scheduled'
  | 'live'
  | 'taken_down'

export type CommentState = 'visible' | 'removed' | 'deleted'

/** The author card on a comment. A removed or deleted comment has none. */
export interface CommentAuthor {
  id: string
  handle: string
  name: string
  hue: number
  avatarUrl?: string
  suspended: boolean
  /** The author is the product's maker. */
  isMaker: boolean
}

/**
 * A comment from the API. `body` and `author` are null unless `state` is visible (a placeholder). `replies` holds the first
 * replies the list embedded; the rest come from the replies endpoint. `pending` marks a row the server has not confirmed yet.
 */
export interface Comment {
  id: string
  parentId: string | null
  state: CommentState
  body: string | null
  author: CommentAuthor | null
  replyTo: { id: string; handle: string; name: string } | null
  /** ISO timestamp with microseconds. */
  createdAt: string
  editedAt: string | null
  repliesCount: number
  replies: Comment[]
  /** Only the author, inside the edit window. Absent for guests (false). */
  canEdit: boolean
  canDelete: boolean
  pending?: boolean
}

export type ReportReason = 'spam' | 'abuse' | 'misleading' | 'inappropriate' | 'other'

/** One screenshot of a product page. */
export interface Screenshot {
  id: string
  url: string
  alt: string
  width: number | null
  height: number | null
}

/** A live product as its page shows it (`GET /products/{slug}`). */
export interface ProductDetail extends ProductCard {
  description: string
  website: string
  launchedAt?: string
  topics: Topic[]
  screenshots: Screenshot[]
  links: { id: string; kind: string; url: string }[]
  maker: User
  commentsCount: number
}

/** The in-site notification types the API sends (`NotificationType` in the API). */
export type NotificationType = 'review_approved' | 'review_rejected' | 'review_changes_requested' | 'launch_reminder' | 'product_live' | 'new_comment' | 'export_ready'

/** One bell row. There is no title or body: the text is built from `messageKey` + `params` (lib/notificationText.ts). */
export interface AppNotification {
  id: string
  /** A type this build does not know renders a generic line. */
  type: NotificationType | 'unknown'
  messageKey: string
  /** Scalar values only, rendered as text. */
  params: Record<string, string | number | boolean | null>
  /** A validated app path (web route), or null when the API sent none or it was unsafe. */
  href: string | null
  read: boolean
  createdAt: string
}

export type EmailPref =
  | 'review_outcome'
  | 'launch_reminder'
  | 'product_live'
  | 'new_comment'
  | 'daily_top5'
  | 'weekly_best'
  | 'export_ready'

export interface FounderReview {
  id: string
  decision: 'approved' | 'rejected' | 'changes_requested'
  note: string
  current: boolean
  createdAt: string
}

export interface FounderScreenshot {
  id: string
  url: string
  /** The stored alt text (the caption), '' when none. */
  altText: string
  position: number
  width: number | null
  height: number | null
}

/**
 * The maker's own product (`GET /me/products`, `GET /me/products/{id}`, every founder write). `links`, `topics`, `screenshots`
 * and `reviews` are undefined on a list row: the list does not load them.
 */
export interface FounderProduct {
  id: string
  slug: string
  name: string
  tagline: string
  description: string
  website: string
  hue: number
  logoUrl?: string
  status: ProductStatus
  /** YYYY-MM-DD (UTC) */
  launchDate?: string
  submittedAt?: string
  launchedAt?: string
  takenDownAt?: string
  takedownReason?: string
  /** What PATCH accepts in the current status. */
  editableFields: string[]
  /** Logo and screenshots can change. */
  mediaEditable: boolean
  links?: { kind: string; url: string }[]
  topics?: Topic[]
  screenshots?: FounderScreenshot[]
  reviews?: FounderReview[]
  /** The note of the decision the present status came from (changes requested or rejected). */
  reviewNote?: string
  createdAt: string
  updatedAt: string
}

export interface StaffReview {
  id: string
  decision: 'approved' | 'rejected' | 'changes_requested'
  note: string
  createdAt: string
  reviewer?: string
}

/** A product as staff read it: a queue card, a list row or the full single read. Only the single read has links, screenshots and reviews. */
export interface StaffProduct {
  id: string
  slug: string
  name: string
  tagline: string
  description: string
  website: string
  hue: number
  logoUrl?: string
  status: ProductStatus
  launchDate?: string
  /** Exactly as the API sent it: the single read has microseconds and the review decision must echo them back. */
  submittedAt?: string
  launchedAt?: string
  takenDownAt?: string
  takedownReason?: string
  maker?: { id: string; handle: string; name: string; hue: number }
  topics: Topic[]
  links: { kind: string; url: string }[]
  screenshots: { id: string; url: string; alt: string }[]
  reviews: StaffReview[]
  /** List rows only. */
  votes?: number
  commentsCount?: number
  updatedAt?: string
}
