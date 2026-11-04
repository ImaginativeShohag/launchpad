import type { RiskSignalKindDto } from '@/api/types'

export const kindLabel: Record<RiskSignalKindDto, string> = {
  shared_device: 'Shared device',
  ip_cluster: 'IP cluster',
  vote_burst: 'Vote burst',
  fresh_account: 'Fresh account',
  disposable_email: 'Disposable email',
  single_maker_voter: 'Votes for one maker',
  login_churn: 'Login churn',
  coordinated_votes: 'Coordinated votes',
}

export const kindOptions = (Object.keys(kindLabel) as RiskSignalKindDto[]).map((k) => ({ value: k, label: kindLabel[k] }))

export const labelOfKind = (k: string) => (k in kindLabel ? kindLabel[k as RiskSignalKindDto] : k.replace(/_/g, ' '))

/** Text of an `observed` / `threshold` value from the API (a number, a string or a small object). */
export function plainValue(v: unknown): string {
  if (v === null || v === undefined) return ''
  if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') return String(v)
  return JSON.stringify(v)
}
