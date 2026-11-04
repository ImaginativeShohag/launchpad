import { cleanup, configure } from '@testing-library/react'
import { afterEach, beforeAll } from 'vitest'
import { resetCsrf } from '@/api/client'
import { resetDeviceIdCache } from '@/lib/deviceId'
import { resetEmailedLinks } from '@/lib/useStripQuery'
import { restoreFetch, takeUnmatched } from './helpers/mockApi'

// Global on purpose: the first findBy of nearly every page test waits for a lazy route chunk to load, and with all files running
// in parallel that can pass the 1 s default. Scoping it would mean a helper call in ~150 tests for the same effect; a wrong
// assertion still fails, only later.
configure({ asyncUtilTimeout: 5000 })

beforeAll(() => {
  window.matchMedia =
    window.matchMedia ||
    ((q: string) => ({ matches: false, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, onchange: null, dispatchEvent: () => false }) as unknown as MediaQueryList)
  // Browsers return a Promise from scroll methods; mirror that so effects that leak it as a cleanup fail here.
  window.scrollTo = (() => Promise.resolve()) as unknown as typeof window.scrollTo
  Element.prototype.scrollIntoView = () => {}
  Element.prototype.scrollTo = (() => {}) as typeof Element.prototype.scrollTo
  globalThis.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
    takeRecords() {
      return []
    }
    root = null
    rootMargin = ''
    thresholds = []
  } as unknown as typeof IntersectionObserver
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver
})

afterEach(() => {
  cleanup()
  restoreFetch()
  resetCsrf()
  resetEmailedLinks()
  resetDeviceIdCache()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  document.head.querySelectorAll('meta[name="referrer"]').forEach((m) => m.remove())
  const unmatched = takeUnmatched()
  // A request the test did not expect fails the test, even if the app swallowed the resulting error.
  if (unmatched.length) throw new Error(`Unmatched API requests:\n${unmatched.join('\n')}`)
})
