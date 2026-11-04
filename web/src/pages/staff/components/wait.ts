/** Hours since an ISO time (0 for a missing or future one). */
export function hoursSince(iso: string | undefined): number {
  if (!iso) return 0
  return Math.max(0, (Date.now() - new Date(iso).getTime()) / 3_600_000)
}

export function formatWait(hours: number): string {
  if (hours < 1) return '<1h'
  const h = Math.floor(hours)
  if (h < 48) return `${h}h`
  return `${Math.floor(h / 24)}d ${h % 24}h`
}

/** Color escalation for time spent waiting in the queue (24h target). */
export function waitTone(hours: number): 'green' | 'amber' | 'red' {
  if (hours >= 24) return 'red'
  if (hours >= 12) return 'amber'
  return 'green'
}
