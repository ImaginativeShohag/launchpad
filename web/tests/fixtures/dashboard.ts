import type { DashboardRankDto, DashboardReferrersDto, DashboardSeriesDto, DashboardSummaryDto, ExportDto, SeriesPointDto } from '@/api/types'
import { today } from '@/lib/date'

export const PRODUCT_ID = '01JDASH0000000000000000001'

export const summary = (o: Partial<DashboardSummaryDto> = {}): { data: DashboardSummaryDto } => ({
  data: {
    product_id: PRODUCT_ID,
    name: 'Lumen Notes',
    slug: 'lumen-notes',
    status: 'live',
    phase: 'launch_day',
    launch_date: today(),
    taken_down: false,
    views: 200,
    votes: 50,
    comments: 7,
    conversion: 0.25,
    current_rank: 3,
    best_rank: 2,
    final_rank: null,
    top_source: { source: 'x', category: 'social', visits: 90 },
    timeline_available: true,
    as_of: new Date().toISOString(),
    ...o,
  },
})

/** Hourly points of a UTC day starting at `day` 00:00, with `votes[i]` in hour i (the rest 0). */
export const hourly = (votes: number[], day = today()): SeriesPointDto[] => {
  let total = 0
  return Array.from({ length: 24 }, (_, h) => {
    const v = votes[h] ?? 0
    total += v
    return { t: `${day}T${String(h).padStart(2, '0')}:00:00Z`, votes: v, views: v * 4, cumulative_votes: total, cumulative_views: total * 4 }
  })
}

export const series = (points: SeriesPointDto[], granularity: 'hour' | 'day' = 'hour', day = today()): { data: DashboardSeriesDto } => ({ data: { granularity, from: day, to: day, points } })

export const rank = (o: Partial<DashboardRankDto> = {}): { data: DashboardRankDto } => ({
  data: {
    launch_date: today(),
    phase: 'launch_day',
    frozen: false,
    final_rank: null,
    current_rank: 3,
    best_rank: 2,
    best_rank_at: `${today()}T00:20:00Z`,
    timeline_available: true,
    interval_minutes: 10,
    points: [
      { t: `${today()}T00:00:00Z`, rank: 5, score: 1 },
      { t: `${today()}T00:10:00Z`, rank: 3, score: 2 },
      { t: `${today()}T00:30:00Z`, rank: 2, score: 3 },
    ],
    outside_top: 500,
    ...o,
  },
})

export const referrers = (o: Partial<DashboardReferrersDto> = {}): { data: DashboardReferrersDto } => ({
  data: {
    from: today(),
    to: today(),
    total: 140,
    sources: [
      { source: 'x', category: 'social', visits: 90, share: 0.6429 },
      { source: 'direct', category: 'direct', visits: 50, share: 0.3571 },
    ],
    by_category: [],
    ...o,
  },
})

export const exportRow = (o: Partial<ExportDto> = {}): ExportDto => ({
  id: '01JEXPORT000000000000000001',
  product_id: PRODUCT_ID,
  status: 'queued',
  created_at: new Date().toISOString(),
  completed_at: null,
  expires_at: null,
  file_size: null,
  row_counts: null,
  error: null,
  ...o,
})

/** Everything the dashboard asks for, quietly. */
export const dashboardRoutes = () => ({
  'GET /me/products/:id/dashboard': { body: summary() },
  'GET /me/products/:id/dashboard/series': { body: series(hourly([6, 3])) },
  'GET /me/products/:id/dashboard/rank': { body: rank() },
  'GET /me/products/:id/dashboard/referrers': { body: referrers() },
  'GET /me/products/:id/exports': { body: { data: [] } },
})
