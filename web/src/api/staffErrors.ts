import { errorMessage, isApiError } from './errors'

/**
 * Text for the staff API's stable error codes (read from `code`, never from the server's message). Everything else is the
 * normalised text: 403 (role), 404 (gone or hidden: the same answer), 422 (the field errors), 429 (the wait).
 */
export function staffMessage(e: unknown, fallback?: string): string {
  if (isApiError(e)) {
    switch (e.code) {
      case 'stale_submission':
        return 'The founder changed this product after you opened it. Reload it and review again.'
      case 'invalid_status':
        return 'This product is no longer in a state where that works. Someone may have decided it already.'
      case 'try_again':
        return 'Something changed while this was running. Please try again.'
      case 'own_product':
        return 'You cannot review your own product.'
      case 'maker_unavailable':
        return 'The maker of this product is suspended or deleted.'
      case 'own_signal':
        return 'You cannot review a signal about your own account or product.'
      case 'admin_only_signal':
        return 'Only an admin can review a signal about a staff member.'
      case 'cannot_suspend':
        return 'This account cannot be suspended (yourself or an admin).'
      case 'day_frozen':
        return 'That launch day is frozen. Its votes can no longer change.'
    }
    if (e.status === 403) return 'You are not allowed to do that.'
    if (e.status === 404) return 'That no longer exists, or you cannot see it.'
  }
  return errorMessage(e, fallback)
}

/** The first message of a 422 field, else the generic text. */
export function staffFieldMessage(e: unknown, field: string): string | undefined {
  return isApiError(e) ? e.fields[field]?.[0] : undefined
}

export const isForbidden = (e: unknown) => isApiError(e) && e.status === 403
export const isGone = (e: unknown) => isApiError(e) && e.status === 404
