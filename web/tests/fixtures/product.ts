import type { FeedReasonDto, ProductDto, RankFrame, TodayDto, TopicDto } from '@/api/types'
import { today } from '@/lib/date'
import { publicUser } from './user'

let n = 0
/** A valid ULID-shaped id that sorts by creation order. */
export const ulid = () => `01JPRKD${String(++n).padStart(19, '0')}`

/** A card as `ProductResource` / `RankedProductResource` returns it. Defaults to a launch of today's UTC day. */
export const apiProduct = (o: Partial<ProductDto> = {}): ProductDto => {
  const id = o.id ?? ulid()
  const slug = o.slug ?? `product-${id.slice(-4).toLowerCase()}`
  return {
    id,
    slug,
    name: o.name ?? `Product ${id.slice(-4)}`,
    tagline: 'A short pitch',
    description: 'A longer description of what this product does.',
    website_url: 'https://example.com',
    logo_url: null,
    launch_date: today(),
    launched_at: new Date().toISOString(),
    votes_count: 10,
    comments_count: 2,
    maker: publicUser({ id: '01JMAKER0000000000000000000', handle: 'maker', name: 'Mia Maker' }),
    topics: [],
    ...o,
  }
}

/** A ranked card (today, a day, a leaderboard): `rank` from the position, `has_voted` only when given. */
export const rankedProduct = (rank: number, o: Partial<ProductDto> = {}): ProductDto => apiProduct({ rank, ...o })

export const rankedList = (count: number, o: (i: number) => Partial<ProductDto> = () => ({})): ProductDto[] =>
  Array.from({ length: count }, (_, i) => rankedProduct(i + 1, { votes_count: 100 - i * 10, ...o(i) }))

export const todayBody = (data: ProductDto[], date = today()): TodayDto => ({ data, meta: { date, generated_at: new Date().toISOString() } })
export const dayBody = (data: ProductDto[], date: string, frozen = true) => ({ data, meta: { date, frozen } })
export const leaderboardBody = (data: ProductDto[], period = 'week', topic: string | null = null) => ({ data, meta: { period, topic, generated_at: new Date().toISOString() } })

export const pageBody = (data: ProductDto[], next: string | null = null, extra: Record<string, unknown> = {}) => ({
  data,
  links: { next: next ? `http://api.test/api/v1/search?cursor=${next}` : null },
  meta: { next_cursor: next, per_page: 20, ...extra },
})

export const topicsBody = (list: Partial<TopicDto>[] = [{}]) => ({
  data: list.map((t, i) => ({ id: `01JTOPIC${String(i).padStart(19, '0')}`, slug: i === 0 ? 'ai' : `topic-${i}`, name: i === 0 ? 'AI' : `Topic ${i}`, emoji: '✨', description: 'Things about it', position: i + 1, ...t })),
})

export const aiTopic = (): TopicDto => topicsBody().data[0] as TopicDto

export const frame = (items: { id: string; rank: number; votes: number }[], date = today()): RankFrame => ({
  date,
  items: items.map((i) => ({ product_id: i.id, rank: i.rank, votes_count: i.votes })),
})

export const memberReason = (name = 'Mia Maker'): FeedReasonDto => ({ type: 'member', user: { id: '01JMAKER0000000000000000000', handle: 'maker', name } })
export const topicReason = (name = 'AI', slug = 'ai'): FeedReasonDto => ({ type: 'topic', topic: { id: '01JTOPIC0000000000000000000', slug, name } })

/** Default discovery routes, enough for every page to render empty. Spread first, override per test. */
export const emptyDiscovery = (): Record<string, () => { body: unknown }> => ({
  'GET /today': () => ({ body: todayBody([]) }),
  'GET /topics': () => ({ body: topicsBody() }),
})
