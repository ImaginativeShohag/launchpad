import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { useToast } from '@/components/ui'
import { isCardQuery, patchCardsInCaches } from '../cache'
import { castVote, withdrawVote } from '../endpoints/discovery'
import { errorMessage, isApiError } from '../errors'

/** Products with a vote request in flight. Also stops a second tap on another button of the same product. */
const inFlight = new Map<string, number>()
export const hasVoteInFlight = (productId: string) => (inFlight.get(productId) ?? 0) > 0

/**
 * A frame for a product with a vote in flight is ignored (the response wins), and so is one that arrives shortly after the
 * response: it may have been read before the vote and would put the old number back.
 */
export const QUIET_AFTER_VOTE_MS = 2_000
const quietUntil = new Map<string, number>()
export const isFrameBlocked = (productId: string) => hasVoteInFlight(productId) || (quietUntil.get(productId) ?? 0) > Date.now()
const track = (id: string, delta: 1 | -1) => {
  const n = (inFlight.get(id) ?? 0) + delta
  if (n <= 0) inFlight.delete(id)
  else inFlight.set(id, n)
}

export interface VoteInput {
  productId: string
  /** The state the member is asking for. */
  voted: boolean
}

/**
 * Optimistic vote. `onMutate` patches `has_voted` and `votes_count` (+/-1) on every cached copy of the card; the response
 * then overwrites both with the server's numbers; an error puts every copy back and says why.
 */
export function useVote() {
  const qc = useQueryClient()
  const toast = useToast()
  const promptVerify = useVerifyPrompt()

  return useMutation({
    mutationFn: ({ productId, voted }: VoteInput) => (voted ? castVote(productId) : withdrawVote(productId)),
    onMutate: async ({ productId, voted }) => {
      track(productId, 1)
      await qc.cancelQueries({ predicate: (q) => isCardQuery(q.queryKey) })
      patchCardsInCaches(qc, productId, (c) => {
        if ((c.has_voted ?? false) === voted) return { ...c, has_voted: voted }
        return { ...c, has_voted: voted, votes_count: Math.max(0, (c.votes_count ?? 0) + (voted ? 1 : -1)) }
      })
    },
    onSuccess: (res, { productId }) => {
      patchCardsInCaches(qc, productId, (c) => ({ ...c, has_voted: res.voted, votes_count: res.votes_count }))
    },
    onError: (err, { productId, voted }) => {
      // Undo only this product's two fields, and only on copies still in the optimistic state: a reorder or a live count
      // that arrived meanwhile stays, and a cache dropped by a sign-out is not re-created.
      patchCardsInCaches(qc, productId, (c) =>
        c.has_voted === voted ? { ...c, has_voted: !voted, votes_count: Math.max(0, (c.votes_count ?? 0) + (voted ? -1 : 1)) } : c,
      )
      if (isApiError(err) && err.code === 'voting_closed') {
        toast({ tone: 'info', title: 'Voting closed for this launch day', body: 'Votes are only taken on the day a product launches.' })
        void qc.invalidateQueries({ predicate: (q) => isCardQuery(q.queryKey) })
      } else if (isApiError(err) && err.code === 'own_product') {
        toast({ tone: 'info', title: 'This one is yours', body: 'Makers can’t vote on their own product.' })
      } else if (isApiError(err) && err.code === 'email_unverified') {
        promptVerify()
      } else if (isApiError(err) && err.status === 401) {
        toast({ tone: 'error', title: 'Please sign in again', body: 'Your session ended.' })
      } else {
        toast({ tone: 'error', title: 'Could not save your vote', body: errorMessage(err) })
      }
    },
    onSettled: (_res, _err, { productId }) => {
      quietUntil.set(productId, Date.now() + QUIET_AFTER_VOTE_MS)
      setTimeout(() => (quietUntil.get(productId) ?? 0) <= Date.now() && quietUntil.delete(productId), QUIET_AFTER_VOTE_MS + 50)
      track(productId, -1)
    },
  })
}
