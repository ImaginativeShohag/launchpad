import { api } from '../client'
import type { ApiItem, ApiList, FollowStateDto, TopicDto } from '../types'

const seg = encodeURIComponent

export const followUser = (handle: string) => api.put<ApiItem<FollowStateDto>>(`/users/${seg(handle)}/follow`).then((r) => r.data)
export const unfollowUser = (handle: string) => api.delete<ApiItem<FollowStateDto>>(`/users/${seg(handle)}/follow`).then((r) => r.data)
export const followTopic = (slug: string) => api.put<ApiItem<FollowStateDto>>(`/topics/${seg(slug)}/follow`).then((r) => r.data)
export const unfollowTopic = (slug: string) => api.delete<ApiItem<FollowStateDto>>(`/topics/${seg(slug)}/follow`).then((r) => r.data)

/** Not paged: the topic list is small and fixed. */
export const getFollowedTopics = (signal?: AbortSignal) => api.get<ApiList<TopicDto>>('/me/follows/topics', { signal })
