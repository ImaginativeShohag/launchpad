import { Badge } from '@/components/ui'

export function OutcomeBadge({ outcome, status }: { outcome: string | null; status: string }) {
  if (outcome === 'confirmed') return <Badge tone="red">Confirmed</Badge>
  if (outcome === 'dismissed') return <Badge tone="neutral">Dismissed</Badge>
  return <Badge tone="amber">{status === 'open' ? 'Open' : status}</Badge>
}

export function VoteStatusBadge({ status }: { status: string }) {
  return <Badge tone={status === 'counted' ? 'green' : status === 'flagged' ? 'amber' : 'neutral'}>{status}</Badge>
}

/** An 8-character fingerprint exactly as the API sent it, or a dash once the 90-day purge removed it. */
export function Fingerprint({ value }: { value: string | null }) {
  return value && /^[0-9a-f]{8}$/.test(value) ? <code className="rounded bg-ink-100 px-1.5 py-0.5 font-mono text-xs dark:bg-white/10">{value}</code> : <span className="text-ink-300">—</span>
}
