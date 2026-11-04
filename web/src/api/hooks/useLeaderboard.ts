import { useQuery } from '@tanstack/react-query'
import { getLeaderboard, type LeaderboardPeriod } from '../endpoints/discovery'
import { keys } from '../keys'
import { toProductCard } from '../mappers'
import type { LeaderboardDto } from '../types'

const select = (r: LeaderboardDto) => r.data.map(toProductCard)

/** GET /leaderboards?period&topic (top 50, never paged). */
export function useLeaderboard(period: LeaderboardPeriod, topic?: string, enabled = true) {
  return useQuery({ queryKey: keys.leaderboard(period, topic), queryFn: ({ signal }) => getLeaderboard({ period, topic }, signal), select, enabled })
}
