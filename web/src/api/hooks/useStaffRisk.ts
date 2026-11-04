import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getProductIntegrity, getRiskMember, getRiskSignal, reviewRiskSignal, suspendForAbuse, voidMemberVotes } from '../endpoints/staff'
import { keys } from '../keys'
import { useCursorList } from '../pagination'
import type { RiskMemberDto, RiskOutcomeDto, RiskSignalDto, RiskStatusDto, StaffVoteDto, VoidVotesDto } from '../types'

export interface SignalFilters {
  status: RiskStatusDto
  kind?: string
  min_weight?: number
}

/** GET /staff/risk-signals: the open queue by default, highest weight first. */
export function useRiskSignals(filters: SignalFilters) {
  return useCursorList<RiskSignalDto>(keys.staffRisk('signals'), '/staff/risk-signals', { status: filters.status, kind: filters.kind, min_weight: filters.min_weight, per_page: 25 }, { staleTime: 0 })
}

export function useRiskSignal(id: string | undefined) {
  return useQuery({ queryKey: keys.staffRisk('signal', id ?? ''), queryFn: ({ signal }) => getRiskSignal(id ?? '', signal), enabled: !!id, staleTime: 0 })
}

/** Members by risk score, highest first. */
export function useRiskMembers(suspended?: boolean) {
  return useCursorList<RiskMemberDto>(keys.staffRisk('members'), '/staff/risk/members', { suspended: suspended === undefined ? undefined : String(suspended), per_page: 25 }, { staleTime: 0 })
}

export function useRiskMember(id: string | undefined) {
  return useQuery({ queryKey: keys.staffRisk('member', id ?? ''), queryFn: ({ signal }) => getRiskMember(id ?? '', signal), enabled: !!id, staleTime: 0 })
}

export function useProductIntegrity(id: string | undefined) {
  return useQuery({ queryKey: keys.staffRisk('integrity', id ?? ''), queryFn: ({ signal }) => getProductIntegrity(id ?? '', signal), enabled: !!id, staleTime: 0 })
}

/** GET /staff/products/{id}/votes: the voters with 8-hex fingerprints, nothing more. */
export function useProductVotes(id: string | undefined, status?: string) {
  return useCursorList<StaffVoteDto>(keys.staffVotes(id ?? ''), `/staff/products/${encodeURIComponent(id ?? '')}/votes`, { status, per_page: 25 }, { enabled: !!id, staleTime: 0 })
}

/** Confirm or dismiss a signal (403 `own_signal`, 403 `admin_only_signal`). The lists and the member's summary are read again. */
export function useReviewRiskSignal() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, outcome, note }: { id: string; outcome: RiskOutcomeDto; note: string | null }) => reviewRiskSignal(id, { outcome, note }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['staff', 'risk'] }),
  })
}

export interface VoidTotals {
  voided: number
  skipped_frozen: number
  failed: number
  calls: number
}

/** A runaway guard: 100 calls of up to 100 votes each. */
export const MAX_VOID_CALLS = 100

/**
 * Voids a member's votes in calls of up to 100 until the API says there is no more, OR a call voided nothing (then
 * `has_more` can stay true for ever because the remaining votes keep failing). `onProgress` sees the running totals, so a
 * failure half way still shows what was done.
 */
export async function voidAllVotes(
  call: (productId?: string) => Promise<VoidVotesDto>,
  productId: string | undefined,
  onProgress?: (t: VoidTotals) => void,
  signal?: AbortSignal,
): Promise<VoidTotals & { stalled: boolean }> {
  const totals: VoidTotals = { voided: 0, skipped_frozen: 0, failed: 0, calls: 0 }
  let stalled = false
  for (;;) {
    if (signal?.aborted) throw new DOMException('Aborted', 'AbortError')
    const r = await call(productId)
    totals.calls += 1
    totals.voided += r.voided
    totals.skipped_frozen += r.skipped_frozen
    totals.failed += r.failed
    onProgress?.({ ...totals })
    if (!r.has_more) break
    if (r.voided === 0 || totals.calls >= MAX_VOID_CALLS) {
      stalled = true
      break
    }
  }
  return { ...totals, stalled }
}

export function useVoidMemberVotes() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, reason, productId, onProgress, signal }: { id: string; reason: string; productId?: string; onProgress?: (t: VoidTotals) => void; signal?: AbortSignal }) =>
      voidAllVotes((p) => voidMemberVotes(id, { reason, productId: p, signal }), productId, onProgress, signal),
    onSettled: (_r, _e, { id }) => {
      void qc.invalidateQueries({ queryKey: keys.staffRisk('member', id) })
      void qc.invalidateQueries({ queryKey: ['staff', 'votes'] })
      void qc.invalidateQueries({ queryKey: keys.staffRisk('integrity') })
      // Voided votes change the counts everyone sees.
      void qc.invalidateQueries({ predicate: (q) => ['today', 'day', 'leaderboard', 'search', 'product'].includes(String(q.queryKey[0])) })
    },
  })
}

/** Suspend for abuse; `voidVotes` queues the vote clean-up on the server (the answer says whether it was queued). */
export function useSuspendForAbuse() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, reason, voidVotes }: { id: string; reason: string; voidVotes: boolean }) => suspendForAbuse(id, { reason, voidVotes }),
    onSuccess: (res, { id }) => {
      qc.setQueryData(keys.staffRisk('member', id), res.data)
      void qc.invalidateQueries({ queryKey: ['staff', 'risk'] })
      void qc.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
  })
}
