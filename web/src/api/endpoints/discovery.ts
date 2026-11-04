import { api } from '../client'
import type { DayDto, LeaderboardDto, TodayDto, VoteDto } from '../types'

export const getToday = (signal?: AbortSignal) => api.get<TodayDto>('/today', { signal })

export const getDay = (date: string, signal?: AbortSignal) => api.get<DayDto>(`/days/${date}`, { signal })

export type LeaderboardPeriod = 'week' | 'month' | 'all'

export const getLeaderboard = (input: { period: LeaderboardPeriod; topic?: string }, signal?: AbortSignal) =>
  api.get<LeaderboardDto>('/leaderboards', { query: { period: input.period, topic: input.topic }, signal })

export const castVote = (productId: string) => api.put<{ data: VoteDto }>(`/products/${productId}/vote`).then((r) => r.data)

export const withdrawVote = (productId: string) => api.delete<{ data: VoteDto }>(`/products/${productId}/vote`).then((r) => r.data)
