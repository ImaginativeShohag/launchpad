import { errorMessage, isApiError } from './errors'

/** Text for the founder API's stable error codes (read from `code`, never from the server's message); everything else is the normalised text. */
export function founderMessage(e: unknown, fallback?: string): string {
  if (isApiError(e)) {
    switch (e.code) {
      case 'invalid_status':
        return 'This product is not in a state where that works. Reload the page to see where it stands.'
      case 'product_locked':
        return 'This product can no longer be changed that way.'
      case 'pending_limit':
        return 'You have too many products waiting for review. Wait for a decision or withdraw one first.'
      case 'maker_unavailable':
        return 'Your account cannot do that right now.'
      case 'try_again':
        return 'Something changed while this was running. Please try again.'
      case 'export_not_ready':
        return 'This export is not ready to download yet.'
      case 'export_expired':
        return 'This export has expired. Start a new one.'
      case 'has_active_product':
        return 'Withdraw or finish your products first.'
    }
    if (e.status === 404) return 'We could not find that product. It may have been deleted.'
  }
  return errorMessage(e, fallback)
}
