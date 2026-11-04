import type { FounderProduct } from '@/data/types'
import type { LinkKind } from '@/api/types'

/** A route id that could be a ULID (26 letters and digits). Anything else is a 404 without asking the API. */
export const looksLikeUlid = (id: string) => /^[0-9A-Za-z]{26}$/.test(id)

/** The API's limits (CreateProductRequest, UpdateProductRequest). The topic and screenshot caps are settings the API enforces; the UI keeps the prototype's. */
export const NAME_MAX = 60
export const TAGLINE_MAX = 80
export const DESC_MAX = 5000
export const SLUG_MIN = 3
export const SLUG_MAX = 80
// TODO: these two and the launch cap/window are admin settings; read them from the backend's config endpoint once it exists.
export const MAX_TOPICS = 3
export const MAX_SHOTS = 6
export const MAX_PRODUCT_LINKS = 5

/** Link kinds a product accepts (LinkKind::productKinds: everything but `website`). */
export const PRODUCT_LINK_KINDS: { value: LinkKind; label: string }[] = [
  { value: 'app_store', label: 'App Store' },
  { value: 'play_store', label: 'Google Play' },
  { value: 'github', label: 'GitHub' },
  { value: 'x', label: 'X' },
  { value: 'linkedin', label: 'LinkedIn' },
  { value: 'docs', label: 'Docs' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'other', label: 'Other' },
]

export interface ProductLinkInput {
  /** Local row key, never sent. */
  key: string
  kind: LinkKind
  url: string
}

export interface DraftForm {
  name: string
  slug: string
  tagline: string
  description: string
  website: string
  topicIds: string[]
  links: ProductLinkInput[]
  /** Captions (alt text) by screenshot id; only screenshots the member edited appear. */
  alts: Record<string, string>
}

const SLUG_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
export const isValidSlug = (s: string) => s.length >= SLUG_MIN && s.length <= SLUG_MAX && SLUG_RE.test(s)

export function slugify(name: string): string {
  return name
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, SLUG_MAX)
    .replace(/-+$/g, '')
}

export const isValidName = (s: string) => s.trim().length > 0 && s.trim().length <= NAME_MAX && /[\p{L}\p{N}]/u.test(s)

/** https only, with a real-looking host. */
export function isHttpsUrl(v: string): boolean {
  try {
    const u = new URL(v)
    return u.protocol === 'https:' && /\.(?:[a-z]{2,}|xn--[a-z0-9-]+)$/i.test(u.hostname)
  } catch {
    return false
  }
}

let keyN = 0
export const newKey = () => `k${++keyN}`

export function formFromProduct(p?: FounderProduct): DraftForm {
  return {
    name: p?.name ?? '',
    slug: p?.slug ?? '',
    tagline: p?.tagline ?? '',
    description: p?.description ?? '',
    website: p?.website ?? '',
    topicIds: (p?.topics ?? []).map((t) => t.id),
    links: (p?.links ?? []).map((l) => ({ key: newKey(), kind: l.kind as LinkKind, url: l.url })),
    alts: {},
  }
}

export const linkRowValid = (l: ProductLinkInput) => isHttpsUrl(l.url.trim())

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp']
export const LOGO_MAX_BYTES = 2 * 1024 * 1024
export const SHOT_MAX_BYTES = 4 * 1024 * 1024

/** The browser's quick look at a file; the server's verdict (pixels, animation) is the final one. */
export function imageProblem(file: File, maxBytes: number): string | undefined {
  if (!IMAGE_TYPES.includes(file.type)) return 'Use a JPEG, PNG or WebP image.'
  if (file.size > maxBytes) return `That file is over ${maxBytes / 1024 / 1024} MB.`
  return undefined
}

