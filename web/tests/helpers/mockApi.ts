/** A route-table fetch mock: "METHOD /path" (relative to /api/v1) -> response. Unmatched requests fail the test. */

export type Call = { method: string; path: string; search: string; query: URLSearchParams; headers: Headers; body: unknown; form?: FormData; credentials?: RequestCredentials; signal?: AbortSignal | null }

export type MockResult = { status?: number; body?: unknown; headers?: Record<string, string> }
export type Handler = (call: Call, params: Record<string, string>) => MockResult | Promise<MockResult>
export type RouteValue = Handler | MockResult

/** Build a JSON response description. */
export const json = (body: unknown, status = 200, headers: Record<string, string> = {}): MockResult => ({ status, body, headers })
export const noContent = (): MockResult => ({ status: 204 })
export const apiError = (status: number, message: string, extra: Record<string, unknown> = {}, headers: Record<string, string> = {}): MockResult => ({ status, body: { message, ...extra }, headers })
export const validation = (errors: Record<string, string[]>, message = 'The given data was invalid.'): MockResult => ({ status: 422, body: { message, errors } })

let unmatched: string[] = []
let original: typeof fetch | null = null

export const takeUnmatched = () => {
  const out = unmatched
  unmatched = []
  return out
}

export function restoreFetch() {
  if (original) globalThis.fetch = original
  original = null
}

type Route = { method: string; segments: string[]; query: [string, string][]; value: RouteValue; key: string }

function parseKey(key: string, value: RouteValue): Route {
  const [method, rest] = key.split(' ')
  const [path, qs] = rest.split('?')
  return { method, segments: path.split('/').filter(Boolean), query: qs ? [...new URLSearchParams(qs).entries()] : [], value, key }
}

function matchRoute(r: Route, method: string, path: string, query: URLSearchParams): Record<string, string> | null {
  if (r.method !== method) return null
  const segs = path.split('/').filter(Boolean)
  if (segs.length !== r.segments.length) return null
  const params: Record<string, string> = {}
  for (let i = 0; i < segs.length; i++) {
    const want = r.segments[i]
    if (want.startsWith(':')) params[want.slice(1)] = decodeURIComponent(segs[i])
    else if (want !== segs[i]) return null
  }
  for (const [k, v] of r.query) if (query.get(k) !== v) return null
  return params
}

const isHandler = (v: RouteValue): v is Handler => typeof v === 'function'

export interface MockApi {
  calls: Call[]
  /** Calls made to "METHOD /path" (pattern as written in the route table, without the query). */
  callsTo(key: string): Call[]
  /** Replace or add a route while the test runs. */
  set(key: string, value: RouteValue): void
}

export function mockApi(table: Record<string, RouteValue> = {}): MockApi {
  restoreFetch()
  original = globalThis.fetch
  const routes: Route[] = []
  const api: MockApi = {
    calls: [],
    callsTo: (key) => {
      const r = parseKey(key, {})
      return api.calls.filter((c) => matchRoute(r, c.method, c.path, c.query) !== null)
    },
    set(key, value) {
      const i = routes.findIndex((r) => r.key === key)
      if (i >= 0) routes.splice(i, 1)
      routes.unshift(parseKey(key, value))
      // Routes with a query are more specific: try them first.
      routes.sort((a, b) => b.query.length - a.query.length)
    },
  }
  // The Sanctum cookie endpoint behaves like the real one unless a test overrides it.
  api.set('GET /sanctum/csrf-cookie', () => {
    document.cookie = 'XSRF-TOKEN=test-xsrf%3D%3D; path=/'
    return noContent()
  })
  for (const [k, v] of Object.entries(table)) api.set(k, v)

  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url)
    const method = (init?.method ?? 'GET').toUpperCase()
    const path = url.pathname.startsWith('/api/v1') ? url.pathname.slice('/api/v1'.length) || '/' : url.pathname
    const headers = new Headers(init?.headers)
    let body: unknown
    let form: FormData | undefined
    if (init?.body instanceof FormData) form = init.body
    else if (typeof init?.body === 'string') {
      try {
        body = JSON.parse(init.body)
      } catch {
        body = init.body
      }
    }
    const call: Call = { method, path, search: url.search, query: url.searchParams, headers, body, form, credentials: init?.credentials, signal: init?.signal }
    api.calls.push(call)
    for (const r of routes) {
      const params = matchRoute(r, method, path, url.searchParams)
      if (!params) continue
      const out = isHandler(r.value) ? await r.value(call, params) : r.value
      const status = out.status ?? 200
      const text = out.body === undefined ? '' : typeof out.body === 'string' ? out.body : JSON.stringify(out.body)
      const respHeaders = { ...(out.body !== undefined && typeof out.body !== 'string' ? { 'Content-Type': 'application/json' } : {}), ...out.headers }
      return new Response(status === 204 ? null : text, { status, headers: respHeaders })
    }
    unmatched.push(`${method} ${path}${url.search}`)
    throw new Error(`mockApi: no route for ${method} ${path}${url.search}`)
  }) as typeof fetch
  return api
}
