export type FieldErrors = Record<string, string[]>

export interface ApiErrorInit {
  status: number
  code: string
  message: string
  fields?: FieldErrors
  retryAfter?: number
}

/** Every failed call becomes one of these: the API's `{message, code}` body, 422 `errors`, 429 `Retry-After`. */
export class ApiError extends Error {
  status: number
  code: string
  fields: FieldErrors
  retryAfter?: number

  constructor(init: ApiErrorInit) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code
    this.fields = init.fields ?? {}
    this.retryAfter = init.retryAfter
  }
}

export const isApiError = (e: unknown): e is ApiError => e instanceof ApiError

/** First 422 message for a field name ("email", "links.0.url"), if any. */
export function firstFieldError(e: unknown, field: string): string | undefined {
  return isApiError(e) ? e.fields[field]?.[0] : undefined
}

/** Human text for any thrown value. Rate limits say how long to wait. */
export function errorMessage(e: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (!isApiError(e)) return fallback
  if (e.status === 429) return e.retryAfter ? `Too many attempts. Try again in ${e.retryAfter} s.` : 'Too many attempts. Please wait a moment and try again.'
  if (e.status === 0) return e.message
  if (e.status >= 500) return e.status === 503 && e.retryAfter ? `The service is busy. Try again in ${e.retryAfter} s.` : 'The server had a problem. Please try again in a moment.'
  return e.message || fallback
}
