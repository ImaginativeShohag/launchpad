import { api } from '../client'
import type { ApiList, TopicDto } from '../types'

export const listTopics = () => api.get<ApiList<TopicDto>>('/topics').then((r) => r.data)
