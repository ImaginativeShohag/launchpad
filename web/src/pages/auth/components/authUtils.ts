export const EMAIL_RE = /^[^\s@]+@[^\s@]+$/

/** a••••a@demo */
export function maskEmail(email: string): string {
  const [local, domain = ''] = email.split('@')
  if (local.length <= 2) return `${local[0] ?? ''}•@${domain}`
  return `${local[0]}${'•'.repeat(Math.min(6, local.length - 2))}${local.at(-1)}@${domain}`
}

export function validateEmail(email: string): string | undefined {
  if (!email.trim()) return 'Enter your email address'
  if (!EMAIL_RE.test(email.trim())) return 'That doesn’t look like an email address'
}

/** What a member can be asked to fix in a password, in the API's own words when it sent them. */
export const PASSWORD_HELP = 'Use at least 12 characters with a mix of letters, numbers or symbols'

/** 3-30 lowercase letters, numbers or underscores, like the API's Handle rule. */
export const HANDLE_RE = /^[a-z0-9_]{3,30}$/

export function suggestHandle(source: string): string {
  const base = source.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 30)
  return base.length >= 3 ? base : ''
}

/** Messages for the `?error=` codes Google sign-in sends back (api/README.md). */
export const googleErrors: Record<string, string> = {
  google_email_unverified: 'Google could not confirm that email address, so we did not sign you in.',
  account_unavailable: 'This account is unavailable.',
  google_identity_mismatch: 'This account is already linked to a different Google identity.',
  google_failed: 'Google sign-in was cancelled or failed. Please try again.',
}

export function googleErrorMessage(code: string | null): string | undefined {
  if (!code) return undefined
  return Object.hasOwn(googleErrors, code) ? googleErrors[code] : 'Google sign-in did not work. Please try again.'
}
