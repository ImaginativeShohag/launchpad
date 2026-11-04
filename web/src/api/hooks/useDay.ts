import { useQuery } from '@tanstack/react-query'
import { getDay } from '../endpoints/discovery'
import { keys } from '../keys'
import { toProductCard } from '../mappers'
import type { DayDto } from '../types'

const select = (r: DayDto) => ({ date: r.meta.date, frozen: r.meta.frozen, products: r.data.map(toProductCard) })

/** GET /days/{date}. A date after the server's day is a 404 (the page shows "Nothing to see yet"). */
export function useDay(date: string, enabled = true) {
  return useQuery({ queryKey: keys.day(date), queryFn: ({ signal }) => getDay(date, signal), select, enabled })
}
