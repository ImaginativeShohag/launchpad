import type { InfiniteData, QueryClient } from '@tanstack/react-query'
import { keys } from './keys'
import type { ApiPage, FounderProductDto } from './types'

type Lists = InfiniteData<ApiPage<FounderProductDto>>

/** The list rows carry no relations; a write that returns them must not add stale ones to a row. */
const scalars = ({ links: _l, topics: _t, screenshots: _s, reviews: _r, ...rest }: FounderProductDto): FounderProductDto => rest

/** Changes the cached single read of a product, if there is one. */
export function patchMyProduct(qc: QueryClient, id: string, fn: (p: FounderProductDto) => FounderProductDto) {
  qc.setQueryData<FounderProductDto>(keys.myProduct(id), (prev) => (prev ? fn(prev) : prev))
}

/**
 * Puts the server's authoritative product into the single read and into every cached list row, then re-reads the lists: a status
 * tab's membership changed (the product moved from "Drafts" to "In review"). `keepMedia` keeps the cached logo and screenshots,
 * for a text save whose response may predate an upload that finished meanwhile.
 */
export function writeFounderProduct(qc: QueryClient, dto: FounderProductDto, opts: { keepMedia?: boolean; refetchLists?: boolean } = {}) {
  qc.setQueryData<FounderProductDto>(keys.myProduct(dto.id), (prev) => (opts.keepMedia && prev ? { ...dto, logo_url: prev.logo_url, screenshots: prev.screenshots } : dto))
  qc.setQueriesData<Lists>({ queryKey: ['my-products'] }, (old) =>
    old?.pages ? { ...old, pages: old.pages.map((p) => ({ ...p, data: p.data.map((row) => (row.id === dto.id ? { ...row, ...scalars(dto) } : row)) })) } : old,
  )
  if (opts.refetchLists !== false) void qc.invalidateQueries({ queryKey: ['my-products'] })
}

export function removeFounderProduct(qc: QueryClient, id: string) {
  qc.setQueriesData<Lists>({ queryKey: ['my-products'] }, (old) => (old?.pages ? { ...old, pages: old.pages.map((p) => ({ ...p, data: p.data.filter((row) => row.id !== id) })) } : old))
  qc.removeQueries({ queryKey: keys.myProduct(id) })
  void qc.invalidateQueries({ queryKey: ['my-products'] })
}
