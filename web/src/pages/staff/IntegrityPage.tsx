import { ArrowLeft, Layers } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useProductIntegrity, useProductVotes } from '@/api/hooks/useStaffRisk'
import { Badge, LoadMore, Skeleton } from '@/components/ui'
import { timeAgo } from '@/lib/date'
import { Fingerprint, VoteStatusBadge } from './components/riskBits'
import { SelectField, StaffLoadError, StatTile } from './components/shared'

export default function IntegrityPage() {
  const { id } = useParams()
  return <Integrity key={id} id={id ?? ''} />
}

const bandTone = { clean: 'green', watch: 'amber', alert: 'red' } as const

function Integrity({ id }: { id: string }) {
  const q = useProductIntegrity(id)
  const [status, setStatus] = useState('any')
  const votes = useProductVotes(id, status === 'any' ? undefined : status)

  if (q.isPending) return <Skeleton className="h-64 w-full" />
  if (!q.data) return <StaffLoadError error={q.error} onRetry={() => void q.refetch()} />
  const d = q.data
  const max = Math.max(1, ...d.buckets.map((b) => b.votes))

  return (
    <div>
      <Link to="/staff/products" className="mb-4 inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-semibold text-ink-500 transition hover:bg-ink-100 hover:text-ink-900 dark:hover:bg-white/5 dark:hover:text-white">
        <ArrowLeft className="size-4" /> Products
      </Link>

      <div className="mb-5 flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-bold sm:text-[28px]">Vote integrity{d.product && <>: {d.product.name}</>}</h1>
        <Badge tone={bandTone[d.band]} className="capitalize">
          {d.band} · {Math.round(d.integrity_ratio * 100)}%
        </Badge>
      </div>

      <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <StatTile label="Counted" value={d.counted} tone="neutral" />
        <StatTile label="Flagged" value={d.flagged} tone={d.flagged ? 'amber' : 'neutral'} />
        <StatTile label="Voided" value={d.voided} tone="neutral" />
        <StatTile label="Withdrawn" value={d.removed} tone="neutral" />
        <StatTile label="Open signals" value={d.open_signals} tone={d.open_signals ? 'red' : 'neutral'} />
      </div>

      <section className="surface mb-5 p-4 sm:p-6">
        <h2 className="mb-3 text-sm font-bold">Votes per hour, last 24 hours</h2>
        <ol className="flex h-28 items-end gap-1" aria-label="Votes per hour">
          {d.buckets.map((b) => (
            <li key={b.hour} className="flex h-full flex-1 flex-col justify-end" title={`${new Date(b.hour).toLocaleString()}: ${b.votes} vote${b.votes === 1 ? '' : 's'}${b.burst ? ' (burst detected)' : ''}`}>
              <span className={b.burst ? 'block rounded-t bg-red-500' : 'block rounded-t bg-violet-400 dark:bg-violet-500'} style={{ height: `${Math.max(2, (b.votes / max) * 100)}%` }} />
            </li>
          ))}
        </ol>
        <p className="mt-2 text-xs text-ink-400">Red bars mark an hour with a detected burst.</p>
      </section>

      <div className="mb-5 grid gap-5 md:grid-cols-2">
        <Groups title="Shared devices" rows={d.groups.device} />
        <Groups title="Shared networks" rows={d.groups.ip} />
      </div>

      <section className="surface p-4 sm:p-6">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-bold">Votes</h2>
          <SelectField
            label="Vote status"
            value={status}
            onChange={setStatus}
            options={[
              { value: 'any', label: 'Any status' },
              { value: 'counted', label: 'Counted' },
              { value: 'flagged', label: 'Flagged' },
              { value: 'voided', label: 'Voided' },
            ]}
          />
        </div>
        {votes.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : votes.isError && votes.items.length === 0 ? (
          <StaffLoadError error={votes.error} onRetry={() => void votes.refetch()} />
        ) : votes.items.length === 0 ? (
          <p className="text-sm text-ink-500">No votes.</p>
        ) : (
          <>
            <ul className="divide-y divide-ink-100 text-sm dark:divide-white/5">
              {votes.items.map((v) => (
                <li key={v.id} className="flex flex-wrap items-center gap-2 py-2">
                  {v.voter.id ? (
                    <Link to={`/staff/risk/members/${v.voter.id}`} className="font-semibold hover:text-violet-600">
                      @{v.voter.handle ?? 'deleted member'}
                    </Link>
                  ) : (
                    <span className="text-ink-400">unknown</span>
                  )}
                  <VoteStatusBadge status={v.status} />
                  {v.removed && <Badge tone="neutral">withdrawn</Badge>}
                  <span className="text-xs text-ink-400">device</span> <Fingerprint value={v.device_fp} />
                  <span className="text-xs text-ink-400">network</span> <Fingerprint value={v.ip_fp} />
                  <time className="ml-auto text-xs text-ink-400" dateTime={v.voted_at}>
                    {timeAgo(v.voted_at)}
                  </time>
                </li>
              ))}
            </ul>
            <LoadMore hasNextPage={!!votes.hasNextPage} loading={votes.isFetchingNextPage} failed={votes.isFetchNextPageError} onClick={() => void votes.fetchNextPage()} />
          </>
        )}
      </section>
    </div>
  )
}

function Groups({ title, rows }: { title: string; rows: { fingerprint: string; accounts: number; votes: number }[] }) {
  return (
    <section className="surface p-4 sm:p-6">
      <h2 className="mb-3 text-sm font-bold">{title}</h2>
      {rows.length === 0 ? (
        <p className="flex items-center gap-2 text-sm text-ink-500">
          <Layers className="size-4" /> None. No two accounts share one.
        </p>
      ) : (
        <ul className="space-y-1.5 text-sm">
          {rows.map((g) => (
            <li key={g.fingerprint} className="flex items-center gap-2">
              <Fingerprint value={g.fingerprint} />
              <span className="text-ink-500">
                {g.accounts} accounts · {g.votes} votes
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
