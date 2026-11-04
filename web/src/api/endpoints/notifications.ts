import { api } from '../client'
import type { MarkReadResultDto, UnreadCountDto } from '../types'

export const getUnreadCount = (signal?: AbortSignal) => api.get<UnreadCountDto>('/me/notifications/unread-count', { signal })

/** One to 50 notification ids. */
export const markRead = (ids: string[]) => api.post<MarkReadResultDto>('/me/notifications/read', { body: { ids } })

export const markAllRead = () => api.post<MarkReadResultDto>('/me/notifications/read', { body: { all: true } })
