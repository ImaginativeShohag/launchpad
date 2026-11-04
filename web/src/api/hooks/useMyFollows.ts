import { useQuery } from '@tanstack/react-query'
import { useSession } from '@/session/SessionProvider'
import { getFollowedTopics } from '../endpoints/follows'
import { keys } from '../keys'
import { toFollowedUser, toTopic } from '../mappers'
import { useCursorList } from '../pagination'
import type { ApiList, FollowedUserDto, TopicDto } from '../types'

const selectTopics = (r: ApiList<TopicDto>) => r.data.map(toTopic)

/** GET /me/follows/topics (not paged). Only a verified member has a list; a guest follows nothing. */
export function useFollowedTopics() {
  const { isVerified } = useSession()
  return useQuery({ queryKey: keys.followedTopics, queryFn: ({ signal }) => getFollowedTopics(signal), select: selectTopics, enabled: isVerified })
}

/** GET /me/follows/users, newest follow first, keyset-paged. */
export function useFollowedUsers(enabled = true) {
  const { isVerified } = useSession()
  return useCursorList<FollowedUserDto, ReturnType<typeof toFollowedUser>>(keys.followedUsers, '/me/follows/users', { per_page: 50 }, { enabled: enabled && isVerified, map: toFollowedUser })
}
