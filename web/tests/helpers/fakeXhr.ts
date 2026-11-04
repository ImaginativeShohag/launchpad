import { vi } from 'vitest'

/** Replaces XMLHttpRequest with one that sends through the (mocked) fetch, firing one progress event. Restore with vi.unstubAllGlobals(). */
export function installFakeXhr(progress = { loaded: 5, total: 10 }) {
  class FakeXhr {
    upload: { onprogress?: (e: { lengthComputable: boolean; loaded: number; total: number }) => void } = {}
    withCredentials = false
    status = 0
    responseText = ''
    onload?: () => void
    onerror?: () => void
    onabort?: () => void
    private method = ''
    private url = ''
    private headers: Record<string, string> = {}
    private retryAfter: string | null = null
    open(method: string, url: string) {
      this.method = method
      this.url = url
    }
    setRequestHeader(k: string, v: string) {
      this.headers[k] = v
    }
    getResponseHeader() {
      return this.retryAfter
    }
    abort() {
      this.onabort?.()
    }
    send(body: FormData) {
      this.upload.onprogress?.({ lengthComputable: true, ...progress })
      fetch(this.url, { method: this.method, headers: this.headers, body, credentials: 'include' }).then(
        async (res) => {
          this.status = res.status
          this.retryAfter = res.headers.get('Retry-After')
          this.responseText = await res.text()
          this.onload?.()
        },
        () => this.onerror?.(),
      )
    }
  }
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
}
