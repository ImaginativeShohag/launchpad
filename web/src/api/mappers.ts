import type { AppNotification, Comment, FeedCard, FeedReason, FounderProduct, ProductCard, ProductDetail, ProductStatus, StaffProduct, Topic, User, UserLink } from '@/data/types'
import { hueFromId } from '@/lib/hue'
import { appHref, cleanParams, isNotificationType } from '@/lib/notificationText'
import type { CommentDto, FeedReasonDto, FollowedUserDto, FounderProductDto, LinkKind, MeDto, NotificationDto, ProductDto, PublicUserDto, StaffProductDto, StaffProductRowDto, TopicDto, UserLinkDto } from './types'

const kindLabels: Record<LinkKind, string> = {
  website: 'Website',
  app_store: 'App Store',
  play_store: 'Google Play',
  github: 'GitHub',
  x: 'X',
  linkedin: 'LinkedIn',
  docs: 'Docs',
  youtube: 'YouTube',
  other: 'Link',
}

export function toLink(dto: UserLinkDto): UserLink {
  return { kind: dto.kind, label: dto.label?.trim() || kindLabels[dto.kind] || 'Link', url: dto.url }
}

/** The API stores a `kind` per link; the editor only has label + URL, so the kind is read from the host. */
export function inferLinkKind(url: string): LinkKind {
  let host = ''
  try {
    host = new URL(url).hostname.toLowerCase().replace(/^www\./, '')
  } catch {
    return 'other'
  }
  const is = (d: string) => host === d || host.endsWith(`.${d}`)
  if (is('github.com')) return 'github'
  if (is('x.com') || is('twitter.com')) return 'x'
  if (is('linkedin.com')) return 'linkedin'
  if (is('youtube.com') || is('youtu.be')) return 'youtube'
  if (is('apps.apple.com') || is('itunes.apple.com')) return 'app_store'
  if (is('play.google.com')) return 'play_store'
  return 'website'
}

const day = (iso: string | null | undefined) => (iso ? iso.slice(0, 10) : '')

function isMe(dto: MeDto | PublicUserDto): dto is MeDto {
  return 'email' in dto
}

/** `/me` and the public profile both become the same view model; fields only one of them has stay undefined. */
export function toUser(dto: MeDto | PublicUserDto): User {
  const base: User = {
    id: dto.id,
    name: dto.name,
    handle: dto.handle,
    hue: hueFromId(dto.id),
    headline: dto.headline ?? '',
    links: (dto.links ?? []).map(toLink),
    role: 'member',
    joinedAt: '',
    avatarUrl: dto.avatar_url ?? undefined,
  }
  if (isMe(dto)) {
    return {
      ...base,
      role: dto.role,
      joinedAt: day(dto.created_at),
      email: dto.email,
      emailVerified: dto.email_verified,
      pendingEmail: dto.pending_email ?? undefined,
      hasPassword: dto.has_password,
    }
  }
  return {
    ...base,
    joinedAt: day(dto.joined_at),
    suspended: dto.is_suspended || undefined,
    followersCount: dto.followers_count,
    followingCount: dto.following_count,
    isFollowing: dto.is_following,
  }
}

export function toTopic(dto: TopicDto): Topic {
  return { id: dto.id, slug: dto.slug, name: dto.name, emoji: dto.emoji, description: dto.description ?? undefined, position: dto.position }
}

/** A list card (today, a day, a leaderboard, search, a topic). Absent counts are 0, a guest has no `hasVoted`. */
export function toProductCard(dto: ProductDto): ProductCard {
  return {
    id: dto.id,
    slug: dto.slug,
    name: dto.name,
    tagline: dto.tagline ?? '',
    description: dto.description ?? '',
    hue: hueFromId(dto.id),
    logoUrl: dto.logo_url ?? undefined,
    topics: (dto.topics ?? []).map(toTopic),
    makerId: dto.maker?.id ?? '',
    launchDate: dto.launch_date ?? undefined,
    votes: dto.votes_count ?? 0,
    commentsCount: dto.comments_count ?? 0,
    hasVoted: dto.has_voted,
    rank: dto.rank,
  }
}

function toReason(dto: FeedReasonDto): FeedReason {
  return dto.type === 'member' ? { type: 'member', name: dto.user.name, handle: dto.user.handle } : { type: 'topic', name: dto.topic.name, slug: dto.topic.slug }
}

export function toFeedCard(dto: ProductDto): FeedCard {
  return { ...toProductCard(dto), reasons: (dto.reasons ?? []).map(toReason) }
}

/** A card from a list that carries no vote or comment numbers (`GET /users/{handle}/products`): nothing is shown for them. */
export function toProfileCard(dto: ProductDto): ProductCard {
  return { ...toProductCard(dto), votes: 0, commentsCount: undefined }
}

/** `GET /products/{slug}`: everything the product page shows. */
export function toProductDetail(dto: ProductDto): ProductDetail {
  const card = toProductCard(dto)
  const maker = dto.maker ? toUser(dto.maker) : undefined
  return {
    ...card,
    description: dto.description ?? '',
    website: dto.website_url ?? '',
    launchedAt: dto.launched_at ?? undefined,
    topics: card.topics ?? [],
    screenshots: [...(dto.screenshots ?? [])]
      .sort((a, b) => a.position - b.position)
      .map((s, i) => ({ id: s.id, url: s.url, alt: s.alt_text?.trim() || `${dto.name} screenshot ${i + 1}`, width: s.width, height: s.height })),
    links: (dto.links ?? []).map((l) => ({ id: l.id, kind: l.kind, url: l.url })),
    maker: maker ?? { id: '', name: 'Unknown maker', handle: '', hue: 0, headline: '', links: [], role: 'member', joinedAt: '' },
    commentsCount: dto.comments_count ?? 0,
  }
}

/** A comment (and its embedded replies). Absent `can_*` keys mean a guest: false. A placeholder keeps no body or author. */
export function toComment(dto: CommentDto): Comment {
  const a = dto.author
  return {
    id: dto.id,
    parentId: dto.parent_id,
    state: dto.state,
    body: dto.state === 'visible' ? dto.body : null,
    author: a ? { id: a.id, handle: a.handle, name: a.name, hue: hueFromId(a.id), avatarUrl: a.avatar_url ?? undefined, suspended: a.is_suspended, isMaker: a.is_maker } : null,
    replyTo: dto.reply_to,
    createdAt: dto.created_at,
    editedAt: dto.edited_at,
    repliesCount: dto.replies_count,
    replies: (dto.replies ?? []).map(toComment),
    canEdit: dto.can_edit ?? false,
    canDelete: dto.can_delete ?? false,
    pending: dto.id.startsWith(TEMP_PREFIX) || undefined,
  }
}

/** Client-side ids of comments the server has not confirmed yet. */
export const TEMP_PREFIX = 'tmp-'
export const isTempId = (id: string) => id.startsWith(TEMP_PREFIX)

/** A row of GET /me/follows/users as a user card (only the card fields are filled). */
export function toFollowedUser(dto: FollowedUserDto): User {
  return {
    id: dto.id,
    name: dto.name,
    handle: dto.handle,
    hue: hueFromId(dto.id),
    headline: dto.headline ?? '',
    links: [],
    role: 'member',
    joinedAt: '',
    avatarUrl: dto.avatar_url ?? undefined,
    suspended: dto.is_suspended || undefined,
  }
}

/** The maker's product. Relations the response did not load stay undefined (a list row has none). */
export function toFounderProduct(dto: FounderProductDto): FounderProduct {
  const reviews = dto.reviews?.map((r) => ({ id: r.id, decision: r.decision, note: r.note ?? '', current: r.current, createdAt: r.created_at }))
  return {
    id: dto.id,
    slug: dto.slug,
    name: dto.name,
    tagline: dto.tagline ?? '',
    description: dto.description ?? '',
    website: dto.website_url ?? '',
    hue: hueFromId(dto.id),
    logoUrl: dto.logo_url ?? undefined,
    status: dto.status as ProductStatus,
    launchDate: dto.launch_date ?? undefined,
    submittedAt: dto.submitted_at ?? undefined,
    launchedAt: dto.launched_at ?? undefined,
    takenDownAt: dto.taken_down_at ?? undefined,
    takedownReason: dto.takedown_reason ?? undefined,
    editableFields: dto.editable_fields ?? [],
    mediaEditable: !!dto.media_editable,
    links: dto.links && [...dto.links].sort((a, b) => a.position - b.position).map((l) => ({ kind: l.kind, url: l.url })),
    topics: dto.topics && [...dto.topics].sort((a, b) => a.position - b.position).map(toTopic),
    screenshots:
      dto.screenshots &&
      [...dto.screenshots].sort((a, b) => a.position - b.position).map((s) => ({ id: s.id, url: s.url, altText: s.alt_text ?? '', position: s.position, width: s.width, height: s.height })),
    reviews,
    reviewNote: reviews?.find((r) => r.current)?.note || undefined,
    createdAt: dto.created_at ?? '',
    updatedAt: dto.updated_at ?? dto.created_at ?? '',
  }
}

/* ---- Staff ---- */

export function toStaffProduct(dto: StaffProductDto | StaffProductRowDto): StaffProduct {
  const full = 'description' in dto ? dto : undefined
  const row = 'votes_count' in dto ? dto : undefined
  return {
    id: dto.id,
    slug: dto.slug,
    name: dto.name,
    tagline: dto.tagline ?? '',
    description: full?.description ?? '',
    website: full?.website_url ?? '',
    hue: hueFromId(dto.id),
    logoUrl: dto.logo_url ?? undefined,
    status: dto.status as ProductStatus,
    launchDate: dto.launch_date ?? undefined,
    submittedAt: dto.submitted_at ?? undefined,
    launchedAt: dto.launched_at ?? undefined,
    takenDownAt: dto.taken_down_at ?? undefined,
    takedownReason: dto.takedown_reason ?? undefined,
    maker: dto.maker ? { id: dto.maker.id, handle: dto.maker.handle, name: dto.maker.name, hue: hueFromId(dto.maker.id) } : undefined,
    topics: (dto.topics ?? []).map(toTopic),
    links: (full?.links ?? []).map((l) => ({ kind: l.kind, url: l.url })),
    screenshots: (full?.screenshots ?? []).map((s) => ({ id: s.id, url: s.url, alt: s.alt_text ?? '' })),
    reviews: (full?.reviews ?? [])
      .map((r) => ({ id: r.id, decision: r.decision, note: r.note ?? '', createdAt: r.created_at, reviewer: r.reviewer?.handle }))
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
    votes: row?.votes_count,
    commentsCount: row?.comments_count,
    updatedAt: row?.updated_at ?? undefined,
  }
}

export function toNotification(dto: NotificationDto): AppNotification {
  const data = typeof dto.data === 'object' && dto.data !== null ? dto.data : {}
  return {
    id: dto.id,
    type: isNotificationType(dto.type) ? dto.type : 'unknown',
    messageKey: typeof data.message_key === 'string' ? data.message_key : '',
    params: cleanParams(data.params),
    href: appHref(data.href),
    read: dto.read_at !== null && dto.read_at !== undefined,
    createdAt: dto.created_at,
  }
}
