import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, resetCsrf, setAccountUnavailableHandler, setUnauthorizedHandler } from '@/api/client'
import { ApiError, errorMessage, firstFieldError, isApiError } from '@/api/errors'
import { apiError, json, mockApi, noContent, validation } from '../helpers/mockApi'

afterEach(() => {
  setUnauthorizedHandler(() => {})
  setAccountUnavailableHandler(() => {})
  vi.restoreAllMocks()
})

const caught = async (p: Promise<unknown>) => {
  try {
    await p
  } catch (e) {
    return e
  }
  throw new Error('expected a rejection')
}

describe('csrf and headers', () => {
  it('fetches the Sanctum cookie once for two parallel writes', async () => {
    // Given a write endpoint
    const mock = mockApi({ 'POST /thing': json({ ok: true }) })

    // When two writes start together
    await Promise.all([api.post('/thing', { body: { a: 1 } }), api.post('/thing', { body: { a: 2 } })])

    // Then the cookie was requested once and both writes carry the decoded token
    expect(mock.callsTo('GET /sanctum/csrf-cookie')).toHaveLength(1)
    const posts = mock.callsTo('POST /thing')
    expect(posts).toHaveLength(2)
    for (const c of posts) expect(c.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf==')
  })

  it('does not fetch the cookie for reads, and sends the standard headers with credentials', async () => {
    const mock = mockApi({ 'GET /topics': json({ data: [] }) })

    await api.get('/topics')

    expect(mock.callsTo('GET /sanctum/csrf-cookie')).toHaveLength(0)
    const [call] = mock.calls
    expect(call.credentials).toBe('include')
    expect(call.headers.get('Accept')).toBe('application/json')
    expect(call.headers.get('X-Requested-With')).toBe('XMLHttpRequest')
    expect(call.headers.get('X-Device-Id')).toMatch(/^[A-Za-z0-9-]{16,64}$/)
    expect(call.headers.get('X-XSRF-TOKEN')).toBeNull()
  })

  it('keeps one device id across calls and stores it in localStorage', async () => {
    const mock = mockApi({ 'GET /topics': json({ data: [] }) })
    await api.get('/topics')
    await api.get('/topics')
    const ids = mock.calls.map((c) => c.headers.get('X-Device-Id'))
    expect(ids[0]).toBe(ids[1])
    expect(localStorage.getItem('launchpad-device-id')).toBe(ids[0])
  })

  it('sends JSON bodies with a content type, and forms without one', async () => {
    const mock = mockApi({ 'POST /json': noContent(), 'POST /form': noContent() })
    const form = new FormData()
    form.append('avatar', new Blob(['x'], { type: 'image/png' }), 'a.png')

    await api.post('/json', { body: { a: 1 } })
    await api.post('/form', { form })

    expect(mock.callsTo('POST /json')[0].headers.get('Content-Type')).toBe('application/json')
    expect(mock.callsTo('POST /form')[0].headers.get('Content-Type')).toBeNull()
    expect(mock.callsTo('POST /form')[0].form?.get('avatar')).toBeInstanceOf(Blob)
  })

  it('forwards a raw query string verbatim and drops undefined query values', async () => {
    const mock = mockApi({ 'POST /auth/email/verify': noContent(), 'GET /search': json({ data: [] }) })
    const raw = '?signature=abc&id=01J&hash=zz&expires=1'

    await api.post('/auth/email/verify', { rawQuery: raw })
    await api.get('/search', { query: { q: 'a b', topic: undefined, page: 2 } })

    expect(mock.callsTo('POST /auth/email/verify')[0].search).toBe(raw)
    expect(mock.callsTo('GET /search')[0].search).toBe('?q=a+b&page=2')
  })
})

describe('419 handling', () => {
  it('refreshes the cookie and retries exactly once', async () => {
    let attempts = 0
    const mock = mockApi({
      'POST /thing': () => (++attempts === 1 ? apiError(419, 'CSRF token mismatch.') : json({ ok: true })),
    })

    await expect(api.post('/thing')).resolves.toEqual({ ok: true })

    expect(attempts).toBe(2)
    expect(mock.callsTo('GET /sanctum/csrf-cookie')).toHaveLength(2)
  })

  it('gives up after the single retry', async () => {
    mockApi({ 'POST /thing': apiError(419, 'CSRF token mismatch.') })

    const err = await caught(api.post('/thing'))

    expect(isApiError(err) && err.status).toBe(419)
  })
})

describe('error normalisation', () => {
  it('maps {message, code}', async () => {
    mockApi({ 'GET /x': apiError(403, 'Confirm your email address first.', { code: 'email_unverified' }) })

    const err = (await caught(api.get('/x'))) as ApiError

    expect(err).toBeInstanceOf(ApiError)
    expect([err.status, err.code, err.message]).toEqual([403, 'email_unverified', 'Confirm your email address first.'])
    expect(err.fields).toEqual({})
  })

  it('falls back to http_<status> when the body has no code', async () => {
    mockApi({ 'GET /x': apiError(404, 'Not found.') })
    expect(((await caught(api.get('/x'))) as ApiError).code).toBe('http_404')
  })

  it('exposes 422 field errors', async () => {
    mockApi({ 'POST /x': validation({ email: ['The email has already been taken.'], 'links.0.url': ['Bad url.'] }) })

    const err = await caught(api.post('/x'))

    expect(firstFieldError(err, 'email')).toBe('The email has already been taken.')
    expect(firstFieldError(err, 'links.0.url')).toBe('Bad url.')
    expect(firstFieldError(err, 'name')).toBeUndefined()
  })

  it('reads Retry-After on 429 and words it for people', async () => {
    mockApi({ 'POST /x': apiError(429, 'Too Many Attempts.', {}, { 'Retry-After': '42' }) })

    const err = (await caught(api.post('/x'))) as ApiError

    expect(err.status).toBe(429)
    expect(err.retryAfter).toBe(42)
    expect(errorMessage(err)).toBe('Too many attempts. Try again in 42 s.')
  })

  it('never renders an HTML error body', async () => {
    mockApi({ 'GET /x': { status: 502, body: '<html><body>Bad gateway nginx</body></html>' } })

    const err = (await caught(api.get('/x'))) as ApiError

    expect(err.code).toBe('http_502')
    expect(err.message).not.toContain('html')
    expect(err.message).not.toContain('nginx')
  })

  it('turns a network failure into status 0', async () => {
    mockApi()
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'))

    const err = (await caught(api.get('/x'))) as ApiError

    expect([err.status, err.code]).toEqual([0, 'network'])
  })

  it('resolves 204 to undefined', async () => {
    mockApi({ 'DELETE /x': noContent() })
    await expect(api.delete('/x')).resolves.toBeUndefined()
  })
})

describe('session errors', () => {
  it('calls the unauthorized handler on a 401, except for /me and /auth/login', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    mockApi({ 'GET /me': apiError(401, 'Unauthenticated.'), 'POST /auth/login': apiError(401, 'No.'), 'GET /me/products': apiError(401, 'Unauthenticated.') })

    await caught(api.get('/me'))
    await caught(api.post('/auth/login'))
    expect(handler).not.toHaveBeenCalled()

    await caught(api.get('/me/products'))
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('reports account_unavailable', async () => {
    const handler = vi.fn()
    setAccountUnavailableHandler(handler)
    mockApi({ 'GET /me/products': apiError(403, 'This account is unavailable.', { code: 'account_unavailable' }) })

    await caught(api.get('/me/products'))

    expect(handler).toHaveBeenCalledTimes(1)
  })
})

describe('upload with progress', () => {
  it('reports progress over XMLHttpRequest with the same headers and CSRF', async () => {
    mockApi()
    resetCsrf()
    const seen: Record<string, string> = {}
    class FakeXhr {
      upload: { onprogress?: (e: { lengthComputable: boolean; loaded: number; total: number }) => void } = {}
      withCredentials = false
      status = 201
      responseText = '{"data":{"id":"1"}}'
      onload?: () => void
      onerror?: () => void
      onabort?: () => void
      method = ''
      open(method: string, url: string) {
        this.method = method
        seen.url = url
      }
      setRequestHeader(k: string, v: string) {
        seen[k] = v
      }
      getResponseHeader() {
        return null
      }
      abort() {}
      send() {
        this.upload.onprogress?.({ lengthComputable: true, loaded: 5, total: 10 })
        this.onload?.()
      }
    }
    vi.stubGlobal('XMLHttpRequest', FakeXhr)
    const progress: number[] = []

    const out = await api.upload('/products/1/logo', new FormData(), { onProgress: (p) => progress.push(p) })

    vi.unstubAllGlobals()
    expect(out).toEqual({ data: { id: '1' } })
    expect(progress).toEqual([50])
    expect(seen.url).toBe('http://api.test/api/v1/products/1/logo')
    expect(seen['X-XSRF-TOKEN']).toBe('test-xsrf==')
    expect(seen['X-Device-Id']).toMatch(/^[A-Za-z0-9-]{16,64}$/)
    expect(seen['Content-Type']).toBeUndefined()
  })

  it('turns an unparseable success body into invalid_response and honours an already-aborted signal', async () => {
    mockApi({ 'POST /up': { status: 200, body: 'not json' } })
    class Xhr {
      upload = {}
      status = 200
      responseText = 'not json'
      onload?: () => void
      open() {}
      setRequestHeader() {}
      getResponseHeader() {
        return null
      }
      abort() {}
      send() {
        this.onload?.()
      }
    }
    vi.stubGlobal('XMLHttpRequest', Xhr)

    const err = (await caught(api.upload('/up', new FormData(), { onProgress: () => {} }))) as ApiError
    expect(err.code).toBe('invalid_response')

    const ctl = new AbortController()
    ctl.abort()
    const aborted = await caught(api.upload('/up', new FormData(), { onProgress: () => {}, signal: ctl.signal }))
    vi.unstubAllGlobals()
    expect((aborted as DOMException).name).toBe('AbortError')
  })
})
