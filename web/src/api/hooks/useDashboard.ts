import { useQuery } from '@tanstack/react-query'
import { getDashboard, getRank, getReferrers, getSeries } from '../endpoints/founder'
import { keys } from '../keys'

/*
 * The server caches each dashboard read for ~30 s, so there is no timer here: the data is re-read when the tab regains focus
 * (once it is older than 30 s) and by the page's Refresh button. A 4xx is final (a foreign product is the same 404 as a missing one).
 */
const STALE = 30_000

export const useDashboardSummary = (id: string | undefined) =>
  useQuery({ queryKey: keys.dashboard(id ?? '', 'summary'), queryFn: ({ signal }) => getDashboard(id ?? '', signal), enabled: !!id, staleTime: STALE })

/** `hour` = the launch day by the hour; `day` = every UTC day since launch (the server caps a window at 7 days of hours or 366 days). */
export const useDashboardSeries = (id: string | undefined, granularity: 'hour' | 'day', enabled: boolean) =>
  useQuery({ queryKey: keys.dashboard(id ?? '', 'series', granularity), queryFn: ({ signal }) => getSeries(id ?? '', { granularity }, signal), enabled: !!id && enabled, staleTime: STALE })

export const useDashboardRank = (id: string | undefined, enabled: boolean) =>
  useQuery({ queryKey: keys.dashboard(id ?? '', 'rank'), queryFn: ({ signal }) => getRank(id ?? '', signal), enabled: !!id && enabled, staleTime: STALE })

export const useDashboardReferrers = (id: string | undefined, enabled: boolean) =>
  useQuery({ queryKey: keys.dashboard(id ?? '', 'referrers'), queryFn: ({ signal }) => getReferrers(id ?? '', {}, signal), enabled: !!id && enabled, staleTime: STALE })
