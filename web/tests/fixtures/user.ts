import type { EmailPrefDto, MeDto, PublicUserDto, TopicDto, UserLinkDto } from '@/api/types'

let n = 0
const id = () => `01J${String(++n).padStart(23, '0')}`

export const apiLink = (o: Partial<UserLinkDto> = {}): UserLinkDto => ({ id: id(), kind: 'website', label: 'Website', url: 'https://amina.dev', position: 0, ...o })

/** API-shaped `/me` body (UserResource). */
export const apiUser = (o: Partial<MeDto> = {}): MeDto => ({
  id: '01JAMINA0000000000000000000',
  handle: 'amina_r',
  name: 'Amina Rahman',
  email: 'amina@example.com',
  email_verified: true,
  pending_email: null,
  has_password: true,
  role: 'member',
  headline: 'Product designer',
  avatar_url: null,
  links: [],
  created_at: '2026-03-12T09:30:00+00:00',
  ...o,
})

export const publicUser = (o: Partial<PublicUserDto> = {}): PublicUserDto => ({
  id: '01JRAFI00000000000000000000',
  handle: 'rafi',
  name: 'Rafi Chowdhury',
  headline: 'Indie maker',
  avatar_url: 'http://api.test/storage/avatars/rafi.webp',
  links: [apiLink()],
  is_suspended: false,
  joined_at: '2025-11-02T10:00:00+00:00',
  followers_count: 12,
  following_count: 3,
  ...o,
})

export const apiTopic = (o: Partial<TopicDto> = {}): TopicDto => ({ id: id(), slug: 'ai', name: 'AI', emoji: '✨', description: 'Artificial intelligence', position: 1, ...o })

export const apiPrefs = (o: Record<string, boolean> = {}): { data: EmailPrefDto[] } => {
  const base: Record<string, boolean> = { review_outcome: true, launch_reminder: true, product_live: true, new_comment: true, daily_top5: true, weekly_best: true, export_ready: true, ...o }
  return { data: Object.entries(base).map(([type, enabled]) => ({ type, enabled })) }
}
