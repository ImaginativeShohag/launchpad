import type { FollowedUserDto, PublicUserDto } from '@/api/types'
import { publicUser } from './user'

/** `GET /users/{handle}` body. */
export const profileBody = (o: Partial<PublicUserDto> = {}) => ({ data: publicUser({ followers_count: 12, following_count: 3, ...o }) })

export const followedUser = (o: Partial<FollowedUserDto> = {}): FollowedUserDto => ({
  id: '01JFOLLOWED0000000000000A',
  handle: 'rafi',
  name: 'Rafi Chowdhury',
  headline: null,
  avatar_url: null,
  is_suspended: false,
  followed_at: '2026-10-20T10:00:00+00:00',
  ...o,
})

export const followedUsersBody = (data: FollowedUserDto[], next: string | null = null) => ({ data, links: { next: null }, meta: { next_cursor: next, per_page: 50 } })

/** The member follows nothing: the two lists the feed and the topic pages read. */
export const noFollows = () => ({
  'GET /me/follows/users': { body: followedUsersBody([]) },
  'GET /me/follows/topics': { body: { data: [] } },
})
