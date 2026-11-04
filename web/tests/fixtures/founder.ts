import type { FounderProductDto, ProductReviewDto, ProductScreenshotDto } from '@/api/types'
import { addDays, today } from '@/lib/date'
import { ulid } from './product'

export const ALL_FIELDS = ['name', 'slug', 'tagline', 'description', 'website_url', 'links', 'topic_ids', 'screenshots']
export const LIVE_FIELDS = ['description', 'website_url', 'links']

const editable = (status: string) => (status === 'draft' || status === 'changes_requested' ? ALL_FIELDS : status === 'live' ? LIVE_FIELDS : [])

/** FounderProductResource as the single read returns it (relations loaded). Defaults to a bare draft. */
export const founderProduct = (o: Partial<FounderProductDto> = {}): FounderProductDto => {
  const id = o.id ?? ulid()
  const status = o.status ?? 'draft'
  return {
    id,
    slug: `product-${id.slice(-4).toLowerCase()}`,
    name: `Product ${id.slice(-4)}`,
    tagline: null,
    description: null,
    website_url: null,
    logo_url: null,
    status,
    launch_date: null,
    submitted_at: null,
    approved_at: null,
    launched_at: null,
    taken_down_at: null,
    takedown_reason: null,
    editable_fields: editable(status),
    media_editable: status === 'draft' || status === 'changes_requested',
    links: [],
    topics: [],
    screenshots: [],
    reviews: [],
    created_at: '2026-10-20T09:00:00+00:00',
    updated_at: '2026-10-20T09:30:00+00:00',
    ...o,
  }
}

/** A product that passes every submit check. */
export const completeDraft = (o: Partial<FounderProductDto> = {}): FounderProductDto =>
  founderProduct({
    tagline: 'A short pitch',
    description: 'A description of what it does and who it is for.',
    website_url: 'https://example.com',
    logo_url: 'http://api.test/storage/logo.webp',
    topics: [{ id: '01JTOPIC0000000000000000000', slug: 'ai', name: 'AI', emoji: '✨', description: null, position: 1 }],
    screenshots: [shot({ id: '01JSHOT00000000000000000001', position: 0 })],
    ...o,
  })

export const shot = (o: Partial<ProductScreenshotDto> = {}): ProductScreenshotDto => {
  const id = o.id ?? ulid()
  return { id, url: `http://api.test/storage/${id}.webp`, position: 0, width: 1600, height: 1000, alt_text: null, ...o }
}

export const review = (o: Partial<ProductReviewDto> = {}): ProductReviewDto => ({ id: ulid(), decision: 'changes_requested', note: 'Please add real screenshots.', current: true, created_at: '2026-10-21T08:00:00+00:00', ...o })

/** A list row: the list resource loads no relations. */
export const listRow = (o: Partial<FounderProductDto> = {}): FounderProductDto => {
  const { links: _l, topics: _t, screenshots: _s, reviews: _r, ...row } = founderProduct(o)
  return row
}

export const myProductsBody = (data: FounderProductDto[], next: string | null = null) => ({
  data,
  links: { next: next ? `http://api.test/api/v1/me/products?cursor=${next}` : null },
  meta: { next_cursor: next, per_page: 100 },
})

export const tomorrow = () => addDays(today(), 1)
