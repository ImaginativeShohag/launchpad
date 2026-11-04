import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { getProduct, recordView } from '../endpoints/products'
import { keys } from '../keys'
import { toProductDetail } from '../mappers'
import type { ApiItem, ProductDto } from '../types'

const select = (r: ApiItem<ProductDto>) => toProductDetail(r.data)

/**
 * GET /products/{slug}. The cache keeps the API envelope so the vote, the live count and the comment counter patch it like any
 * other card. A hidden, unknown or taken-down product is a 404 (the page shows "not available").
 */
export function useProduct(slug: string | undefined) {
  return useQuery({ queryKey: keys.product(slug ?? ''), queryFn: ({ signal }) => getProduct(slug ?? '', signal), select, enabled: !!slug })
}

/** Where the visitor came from: an outside page as origin + path (at most 2048 characters). Our own pages are never reported. */
function outsideReferrer(raw: string): string | undefined {
  if (!raw) return undefined
  try {
    const u = new URL(raw)
    if (typeof window !== 'undefined' && u.origin === window.location.origin) return undefined
    return `${u.origin}${u.pathname}`.slice(0, 2048)
  } catch {
    return undefined
  }
}

/** Read once when the app loads: `document.referrer` describes how the visit began, not later in-app navigation. */
let entryReferrer = typeof document === 'undefined' ? undefined : outsideReferrer(document.referrer)

/** For tests: pretend the visit began from `raw`. */
export function resetEntryReferrer(raw = '') {
  entryReferrer = outsideReferrer(raw)
}

/** POST /products/{id}/view once per product shown. Fire and forget: a failure is never shown. Not sent for a product that did not load. */
export function useViewBeacon(productId: string | undefined) {
  const sent = useRef<string | null>(null)
  useEffect(() => {
    if (!productId || sent.current === productId) return
    sent.current = productId
    // Only the first beacon of the visit carries the entry referrer.
    const ref = entryReferrer
    entryReferrer = undefined
    recordView(productId, ref).catch(() => {})
  }, [productId])
}
