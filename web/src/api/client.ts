import { config } from '@/config'
import { getDeviceId } from '@/lib/deviceId'
import { ApiError, type FieldErrors } from './errors'

export type RequestOptions = {
  query?: Record<string, string | number | boolean | undefined>
  body?: unknown
  form?: FormData
  /** A query string forwarded exactly as received (signed links are order-sensitive). With or without the leading "?". */
  rawQuery?: string
  signal?: AbortSignal
  /** Called with the headers of a successful response (a poll reads `Retry-After` this way). */
  onHeaders?: (headers: Headers) => void
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

let unauthorized: () => void = () => {}
let unavailable: () => void = () => {}

/** Called on a 401 that is not from /me or /auth/login. */
export function setUnauthorizedHandler(fn: () => void) {
  unauthorized = fn
}

/** Called on a 403 `account_unavailable` (suspended or deleted while signed in). */
export function setAccountUnavailableHandler(fn: () => void) {
  unavailable = fn
}

function readXsrf(): string | undefined {
  if (typeof document === 'undefined') return undefined
  for (const part of document.cookie.split('; ')) {
    if (part.startsWith('XSRF-TOKEN=')) {
      try {
        return decodeURIComponent(part.slice('XSRF-TOKEN='.length))
      } catch {
        return undefined
      }
    }
  }
  return undefined
}

let csrfInFlight: Promise<void> | null = null
let csrfDone = false

/** Fetches the Sanctum cookie once; parallel callers share one request. `force` re-fetches (after a 419). */
export function csrf(force = false): Promise<void> {
  if (!force && csrfDone && readXsrf()) return Promise.resolve()
  if (csrfInFlight) return csrfInFlight
  csrfInFlight = (async () => {
    try {
      const res = await fetch(`${config.apiUrl}/sanctum/csrf-cookie`, {
        credentials: 'include',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Device-Id': getDeviceId() },
      })
      if (!res.ok) throw await toApiError(res.status, await res.text().catch(() => ''), res.headers.get('Retry-After'))
      csrfDone = true
    } catch (e) {
      throw e instanceof ApiError ? e : networkError()
    } finally {
      csrfInFlight = null
    }
  })()
  return csrfInFlight
}

/** For tests: forget that the cookie was fetched. */
export function resetCsrf() {
  csrfDone = false
  csrfInFlight = null
}

function networkError() {
  return new ApiError({ status: 0, code: 'network', message: 'Could not reach the server. Check your connection and try again.' })
}

const genericMessage = (status: number) =>
  status === 429
    ? 'Too many requests. Please wait a moment and try again.'
    : status >= 500
      ? 'The server had a problem. Please try again in a moment.'
      : status === 404
        ? 'Not found.'
        : status === 403
          ? 'You are not allowed to do that.'
          : status === 401
            ? 'Please sign in to continue.'
            : 'The request could not be completed.'

function parseRetryAfter(value: string | null): number | undefined {
  if (!value) return undefined
  const secs = Number(value)
  if (Number.isFinite(secs) && secs >= 0) return Math.ceil(secs)
  const when = Date.parse(value)
  return Number.isNaN(when) ? undefined : Math.max(0, Math.ceil((when - Date.now()) / 1000))
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v)
}

/** Turns a failed response into an ApiError. A non-JSON body (an HTML 500) is never surfaced. */
async function toApiError(status: number, text: string, retryAfterHeader: string | null): Promise<ApiError> {
  let body: unknown
  try {
    body = text ? JSON.parse(text) : undefined
  } catch {
    body = undefined
  }
  const json = isRecord(body) ? body : {}
  const fields: FieldErrors = {}
  if (isRecord(json.errors)) {
    for (const [k, v] of Object.entries(json.errors)) {
      if (Array.isArray(v)) fields[k] = v.filter((m): m is string => typeof m === 'string')
    }
  }
  const retryAfter = status === 429 || status === 503 ? parseRetryAfter(retryAfterHeader) : undefined
  return new ApiError({
    status,
    code: typeof json.code === 'string' && json.code ? json.code : `http_${status}`,
    message: typeof json.message === 'string' && json.message && status < 500 ? json.message : genericMessage(status),
    fields,
    retryAfter,
  })
}

function buildUrl(path: string, o: RequestOptions): string {
  let url = `${config.apiUrl}/api/v1${path}`
  if (o.rawQuery) url += `?${o.rawQuery.replace(/^\?/, '')}`
  else if (o.query) {
    const qs = new URLSearchParams()
    for (const [k, v] of Object.entries(o.query)) if (v !== undefined) qs.set(k, String(v))
    const s = qs.toString()
    if (s) url += `?${s}`
  }
  return url
}

function reportSessionErrors(path: string, err: ApiError) {
  if (err.status === 401 && path !== '/me' && path !== '/auth/login') unauthorized()
  else if (err.status === 403 && err.code === 'account_unavailable' && path !== '/me') unavailable()
}

async function request<T>(method: Method, path: string, o: RequestOptions = {}, retried = false): Promise<T> {
  const write = method !== 'GET'
  if (write) await csrf()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Device-Id': getDeviceId(),
  }
  if (write) {
    const token = readXsrf()
    if (token) headers['X-XSRF-TOKEN'] = token
  }
  let body: BodyInit | undefined
  if (o.form) body = o.form
  else if (o.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(o.body)
  }

  let res: Response
  try {
    res = await fetch(buildUrl(path, o), { method, headers, body, credentials: 'include', signal: o.signal })
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') throw e
    throw networkError()
  }

  if (res.status === 419 && write && !retried) {
    await csrf(true)
    return request<T>(method, path, o, true)
  }

  const text = await res.text().catch(() => '')
  if (!res.ok) {
    const err = await toApiError(res.status, text, res.headers.get('Retry-After'))
    reportSessionErrors(path, err)
    throw err
  }
  o.onHeaders?.(res.headers)
  if (res.status === 204 || !text) return undefined as T
  try {
    return JSON.parse(text) as T
  } catch {
    throw new ApiError({ status: res.status, code: 'invalid_response', message: genericMessage(500) })
  }
}

/** A file behind the session cookie (the export zip): fetched with credentials and handed back as a Blob. */
async function download(path: string, o: { signal?: AbortSignal } = {}): Promise<{ blob: Blob; filename?: string }> {
  let res: Response
  try {
    res = await fetch(buildUrl(path, {}), {
      method: 'GET',
      headers: { Accept: '*/*', 'X-Requested-With': 'XMLHttpRequest', 'X-Device-Id': getDeviceId() },
      credentials: 'include',
      signal: o.signal,
    })
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') throw e
    throw networkError()
  }
  if (!res.ok) {
    const err = await toApiError(res.status, await res.text().catch(() => ''), res.headers.get('Retry-After'))
    reportSessionErrors(path, err)
    throw err
  }
  const blob = await res.blob()
  // A file name from a header is only a name: no path parts, no control characters.
  const raw = /filename="?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') ?? '')?.[1]
  const name = raw && [...raw].filter((c) => c !== '/' && c !== '\\' && c.charCodeAt(0) > 31 && c.charCodeAt(0) !== 127).join('').trim()
  return { blob, filename: name || undefined }
}

/** XMLHttpRequest is used only because fetch cannot report upload progress. Same headers, CSRF and errors as `request`. */
async function uploadWithProgress<T>(path: string, form: FormData, o: { onProgress?: (pct: number) => void; signal?: AbortSignal }, retried = false): Promise<T> {
  if (o.signal?.aborted) throw new DOMException('Aborted', 'AbortError')
  await csrf()
  if (o.signal?.aborted) throw new DOMException('Aborted', 'AbortError')
  type Done = { status: number; text: string; retryAfter: string | null }
  const done = await new Promise<Done>((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    const onAbort = () => xhr.abort()
    const finish = () => o.signal?.removeEventListener('abort', onAbort)
    xhr.open('POST', buildUrl(path, {}))
    xhr.withCredentials = true
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
    xhr.setRequestHeader('X-Device-Id', getDeviceId())
    const token = readXsrf()
    if (token) xhr.setRequestHeader('X-XSRF-TOKEN', token)
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) o.onProgress?.(Math.round((e.loaded / e.total) * 100))
    }
    xhr.onload = () => (finish(), resolve({ status: xhr.status, text: xhr.responseText, retryAfter: xhr.getResponseHeader('Retry-After') }))
    xhr.onerror = () => (finish(), reject(networkError()))
    xhr.onabort = () => (finish(), reject(new DOMException('Aborted', 'AbortError')))
    o.signal?.addEventListener('abort', onAbort, { once: true })
    xhr.send(form)
  })
  if (done.status === 419 && !retried) {
    await csrf(true)
    return uploadWithProgress<T>(path, form, o, true)
  }
  if (done.status < 200 || done.status >= 300) {
    const err = await toApiError(done.status, done.text, done.retryAfter)
    reportSessionErrors(path, err)
    throw err
  }
  if (done.status === 204 || !done.text) return undefined as T
  try {
    return JSON.parse(done.text) as T
  } catch {
    throw new ApiError({ status: done.status, code: 'invalid_response', message: genericMessage(500) })
  }
}

export const api = {
  get: <T>(path: string, o?: RequestOptions) => request<T>('GET', path, o),
  post: <T>(path: string, o?: RequestOptions) => request<T>('POST', path, o),
  put: <T>(path: string, o?: RequestOptions) => request<T>('PUT', path, o),
  patch: <T>(path: string, o?: RequestOptions) => request<T>('PATCH', path, o),
  delete: <T>(path: string, o?: RequestOptions) => request<T>('DELETE', path, o),
  download,
  upload<T>(path: string, form: FormData, o: { onProgress?: (pct: number) => void; signal?: AbortSignal } = {}) {
    return o.onProgress ? uploadWithProgress<T>(path, form, o) : request<T>('POST', path, { form, signal: o.signal })
  },
}
