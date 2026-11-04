import { api } from '../client'
import type {
  ApiItem,
  ApiList,
  DashboardRankDto,
  DashboardReferrersDto,
  DashboardSeriesDto,
  DashboardSummaryDto,
  ExportDto,
  FounderProductDto,
  LinkKind,
  ProductScreenshotDto,
} from '../types'

const seg = encodeURIComponent

export const getMyProduct = (id: string, signal?: AbortSignal) => api.get<ApiItem<FounderProductDto>>(`/me/products/${seg(id)}`, { signal }).then((r) => r.data)

export const createProduct = (input: { name: string; slug: string }) => api.post<ApiItem<FounderProductDto>>('/products', { body: input }).then((r) => r.data)

/** Any subset of the editable fields; only the keys present change. */
export interface ProductPatch {
  name?: string
  slug?: string
  tagline?: string | null
  description?: string | null
  website_url?: string | null
  links?: { kind: LinkKind; url: string }[]
  topic_ids?: string[]
  screenshots?: { id: string; alt_text: string | null }[]
}

export const updateProduct = (id: string, patch: ProductPatch) => api.patch<ApiItem<FounderProductDto>>(`/products/${seg(id)}`, { body: patch }).then((r) => r.data)

export const deleteProduct = (id: string) => api.delete<void>(`/products/${seg(id)}`)

export const uploadLogo = (id: string, file: File, onProgress?: (pct: number) => void) => {
  const form = new FormData()
  form.append('logo', file)
  return api.upload<ApiItem<FounderProductDto>>(`/products/${seg(id)}/logo`, form, { onProgress }).then((r) => r.data)
}

export const removeLogo = (id: string) => api.delete<void>(`/products/${seg(id)}/logo`)

export const uploadScreenshot = (id: string, file: File, onProgress?: (pct: number) => void) => {
  const form = new FormData()
  form.append('screenshot', file)
  return api.upload<ApiItem<ProductScreenshotDto>>(`/products/${seg(id)}/screenshots`, form, { onProgress }).then((r) => r.data)
}

/** Every screenshot id exactly once, in the wanted order. */
export const reorderScreenshots = (id: string, ids: string[]) => api.put<ApiList<ProductScreenshotDto>>(`/products/${seg(id)}/screenshots/order`, { body: { ids } }).then((r) => r.data)

export const deleteScreenshot = (id: string, screenshotId: string) => api.delete<void>(`/products/${seg(id)}/screenshots/${seg(screenshotId)}`)

export const submitProduct = (id: string) => api.post<ApiItem<FounderProductDto>>(`/products/${seg(id)}/submit`).then((r) => r.data)

export const withdrawProduct = (id: string) => api.post<ApiItem<FounderProductDto>>(`/products/${seg(id)}/withdraw`).then((r) => r.data)

export const scheduleLaunch = (id: string, launchDate: string) => api.put<ApiItem<FounderProductDto>>(`/products/${seg(id)}/launch`, { body: { launch_date: launchDate } }).then((r) => r.data)

/* Dashboard: the maker's own numbers. Summary and rank take no parameters; series and referrers take UTC days. */

const dash = (id: string) => `/me/products/${seg(id)}/dashboard`

export const getDashboard = (id: string, signal?: AbortSignal) => api.get<ApiItem<DashboardSummaryDto>>(dash(id), { signal }).then((r) => r.data)

export const getSeries = (id: string, params: { granularity?: 'hour' | 'day'; from?: string; to?: string } = {}, signal?: AbortSignal) =>
  api.get<ApiItem<DashboardSeriesDto>>(`${dash(id)}/series`, { query: params, signal }).then((r) => r.data)

export const getRank = (id: string, signal?: AbortSignal) => api.get<ApiItem<DashboardRankDto>>(`${dash(id)}/rank`, { signal }).then((r) => r.data)

export const getReferrers = (id: string, params: { from?: string; to?: string } = {}, signal?: AbortSignal) =>
  api.get<ApiItem<DashboardReferrersDto>>(`${dash(id)}/referrers`, { query: params, signal }).then((r) => r.data)

/* Exports: one in flight per product. POST answers 202 (new) or 200 (the one already running). */

const exportsPath = (id: string) => `/me/products/${seg(id)}/exports`

export const startExport = (id: string) => api.post<ApiItem<ExportDto>>(exportsPath(id)).then((r) => r.data)

export const listExports = (id: string, signal?: AbortSignal) => api.get<ApiList<ExportDto>>(exportsPath(id), { signal }).then((r) => r.data)

/** One export, plus the seconds the server asked us to wait before polling again (`Retry-After`, only while it is in flight). */
export async function getExport(id: string, exportId: string, signal?: AbortSignal): Promise<{ export: ExportDto; retryAfter?: number }> {
  let retryAfter: number | undefined
  const res = await api.get<ApiItem<ExportDto>>(`${exportsPath(id)}/${seg(exportId)}`, {
    signal,
    onHeaders: (h) => {
      const n = Number(h.get('Retry-After'))
      retryAfter = Number.isFinite(n) && n > 0 ? n : undefined
    },
  })
  return { export: res.data, retryAfter }
}

/** The zip as a Blob, through the session cookie (the API never hands out a URL). */
export const downloadExport = (id: string, exportId: string) => api.download(`${exportsPath(id)}/${seg(exportId)}/download`)
