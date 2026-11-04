import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import type { StaffProduct } from '@/data/types'
import { isApiError } from '../errors'
import { getStaffProduct, restoreProduct, reviewProduct, takeDownProduct } from '../endpoints/staff'
import { isCardQuery } from '../cache'
import { keys } from '../keys'
import { toStaffProduct } from '../mappers'
import { useCursorList } from '../pagination'
import type { ReviewDecisionDto, StaffProductDto, StaffProductRowDto } from '../types'

/** GET /staff/review-queue: pending products, oldest submission first. Never served from a cache older than the page. */
export function useStaffQueue(per_page = 50) {
  return useCursorList<StaffProductDto, StaffProduct>(keys.staffQueue, '/staff/review-queue', { per_page }, { map: toStaffProduct, staleTime: 0 })
}

/** GET /staff/products/{id}: the whole product for a decision. A draft, a hidden or an unknown id is the same 404. */
export function useStaffProduct(id: string | undefined) {
  return useQuery({ queryKey: keys.staffProduct(id ?? ''), queryFn: ({ signal }) => getStaffProduct(id ?? '', signal), select: toStaffProduct, enabled: !!id, staleTime: 0 })
}

/** GET /staff/products: every non-draft product, latest change first (`q` needs two characters). */
export function useStaffProducts(filters: { status?: string; q?: string }) {
  return useCursorList<StaffProductRowDto, StaffProduct>(keys.staffProducts(), '/staff/products', { status: filters.status, q: filters.q, per_page: 20 }, { map: toStaffProduct, staleTime: 0 })
}

function afterProductChange(qc: QueryClient, dto: StaffProductDto) {
  qc.setQueryData(keys.staffProduct(dto.id), dto)
  void qc.invalidateQueries({ queryKey: ['staff', 'queue'] })
  void qc.invalidateQueries({ queryKey: ['staff', 'products'] })
  void qc.invalidateQueries({ queryKey: ['staff', 'reports'] })
  // A decision, a take-down or a restore changes what the public pages and the maker's own list show.
  void qc.invalidateQueries({ predicate: (q) => isCardQuery(q.queryKey) || q.queryKey[0] === 'my-products' || q.queryKey[0] === 'my-product' })
}

/** Not optimistic: the API decides (409 `stale_submission`, 409 `invalid_status`, 403 `own_product`), the screen shows its answer. */
export function useReviewDecision() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, decision, note, submittedAt }: { id: string; decision: ReviewDecisionDto; note: string | null; submittedAt: string }) => reviewProduct(id, { decision, note, submittedAt }),
    onSuccess: (dto) => afterProductChange(qc, dto),
    onError: (e, { id }) => {
      // The product moved on (resubmitted, or decided by someone else): read it again so the token and the status are current.
      if (isApiError(e) && (e.code === 'stale_submission' || e.code === 'invalid_status')) {
        void qc.invalidateQueries({ queryKey: keys.staffProduct(id) })
        void qc.invalidateQueries({ queryKey: ['staff', 'queue'] })
      }
    },
  })
}

export function useTakeDown() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: ({ id, reason }: { id: string; reason: string }) => takeDownProduct(id, reason), onSuccess: (dto) => afterProductChange(qc, dto) })
}

export function useRestoreProduct() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => restoreProduct(id), onSuccess: (dto) => afterProductChange(qc, dto) })
}
