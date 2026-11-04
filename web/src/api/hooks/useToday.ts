import { useQuery } from '@tanstack/react-query'
import { getToday } from '../endpoints/discovery'
import { keys } from '../keys'
import { toProductCard } from '../mappers'
import type { TodayDto } from '../types'

const select = (r: TodayDto) => ({ date: r.meta.date, products: r.data.map(toProductCard) })

/**
 * GET /today (top 100, never paged). The order moves about every minute, so the list is re-read every 2 minutes while the
 * tab is visible, and the realtime frames only patch it in between.
 */
export function useToday(enabled = true) {
  return useQuery({ queryKey: keys.today, queryFn: ({ signal }) => getToday(signal), select, refetchInterval: 120_000, enabled })
}
