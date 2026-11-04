import { Fingerprint as FingerprintIcon, ShieldAlert } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { useReviewRiskSignal, useRiskMembers, useRiskSignal, useRiskSignals } from '@/api/hooks/useStaffRisk'
import { staffFieldMessage, staffMessage } from '@/api/staffErrors'
import type { RiskSignalDto, RiskStatusDto } from '@/api/types'
import { Avatar, Badge, Button, EmptyState, LoadMore, Modal, Skeleton, Tabs, Textarea, useToast } from '@/components/ui'
import { hueFromId } from '@/lib/hue'
import { timeAgo } from '@/lib/date'
import { Fingerprint, OutcomeBadge, VoteStatusBadge } from './components/riskBits'
import { kindOptions, labelOfKind, plainValue } from './components/riskKinds'
import { InlineError, PageHeader, RiskMeter, SelectField, StaffLoadError } from './components/shared'

type Tab = 'signals' | 'members'

export default function RiskPage() {
  const [tab, setTab] = useState<Tab>('signals')
  return (
    <div>
      <PageHeader title="Suspicious voting" subtitle="Signals raised by the anti-cheat checks, and the members they point to. Nothing here is automatic: staff decide." />
      <Tabs<Tab>
        className="mb-5"
        value={tab}
        onChange={setTab}
        items={[
          { value: 'signals', label: 'Signals' },
          { value: 'members', label: 'Members by risk' },
        ]}
      />
      {tab === 'signals' ? <Signals /> : <Members />}
    </div>
  )
}

function Signals() {
  const [status, setStatus] = useState<RiskStatusDto>('open')
  const [kind, setKind] = useState('any')
  const [openId, setOpenId] = useState<string | null>(null)
  const list = useRiskSignals({ status, kind: kind === 'any' ? undefined : kind })

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <SelectField<RiskStatusDto>
          label="Signal status"
          value={status}
          onChange={setStatus}
          options={[
            { value: 'open', label: 'Open' },
            { value: 'reviewed', label: 'Reviewed' },
            { value: 'all', label: 'All' },
          ]}
        />
        <SelectField label="Signal kind" value={kind} onChange={setKind} options={[{ value: 'any', label: 'Any kind' }, ...kindOptions]} />
      </div>

      {list.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : list.isError && list.items.length === 0 ? (
        <StaffLoadError error={list.error} onRetry={() => void list.refetch()} />
      ) : list.items.length === 0 ? (
        <EmptyState icon={<ShieldAlert className="size-6" />} title="No signals" body={status === 'open' ? 'Nothing is waiting for review.' : 'No signals match.'} />
      ) : (
        <>
          <ul className="space-y-2.5">
            {list.items.map((s) => (
              <li key={s.id}>
                <SignalRow signal={s} onOpen={() => setOpenId(s.id)} />
              </li>
            ))}
          </ul>
          <LoadMore hasNextPage={!!list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}

      <SignalModal id={openId} onClose={() => setOpenId(null)} />
    </>
  )
}

function SignalRow({ signal: s, onOpen }: { signal: RiskSignalDto; onOpen: () => void }) {
  return (
    <button type="button" onClick={onOpen} className="surface block w-full cursor-pointer p-4 text-left transition hover:-translate-y-0.5 hover:border-violet-200 hover:shadow-lift dark:hover:border-violet-500/30">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="font-semibold">{labelOfKind(s.kind)}</span>
        <Badge tone={s.weight >= 30 ? 'red' : s.weight >= 15 ? 'amber' : 'neutral'}>weight {s.weight}</Badge>
        <OutcomeBadge outcome={s.outcome} status={s.status} />
        {s.member && <span className="text-ink-500">@{s.member.handle ?? 'deleted member'}</span>}
        {s.product && <span className="text-ink-500">on {s.product.name}</span>}
        <time className="ml-auto text-xs text-ink-400" dateTime={s.detected_at}>
          {timeAgo(s.detected_at)}
        </time>
      </div>
      {(s.observed !== null || s.threshold !== null) && (
        <p className="mt-1 text-xs text-ink-500">
          observed {plainValue(s.observed)}
          {s.threshold !== null && <> (threshold {plainValue(s.threshold)})</>}
        </p>
      )}
    </button>
  )
}

function SignalModal({ id, onClose }: { id: string | null; onClose: () => void }) {
  const toast = useToast()
  const detail = useRiskSignal(id ?? undefined)
  const review = useReviewRiskSignal()
  const [note, setNote] = useState('')

  const close = () => {
    review.reset()
    setNote('')
    onClose()
  }
  const decide = (outcome: 'confirmed' | 'dismissed') => {
    if (!id) return
    review.mutate(
      { id, outcome, note: note.trim() || null },
      {
        onSuccess: () => {
          toast({ tone: 'success', title: outcome === 'confirmed' ? 'Signal confirmed' : 'Signal dismissed', body: 'Recorded in the audit log.' })
          close()
        },
      },
    )
  }

  const s = detail.data
  return (
    <Modal open={id !== null} onClose={close} size="lg" title={s ? labelOfKind(s.kind) : 'Risk signal'} description={s ? `Detected ${timeAgo(s.detected_at)} · weight ${s.weight}` : undefined}>
      {detail.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : !s ? (
        <StaffLoadError error={detail.error} onRetry={() => void detail.refetch()} backTo="/staff/risk" />
      ) : (
        <div className="space-y-4 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <OutcomeBadge outcome={s.outcome} status={s.status} />
            {s.reviewer && (
              <span className="text-ink-500">
                reviewed by @{s.reviewer.handle}
                {s.reviewed_at && <> {timeAgo(s.reviewed_at)}</>}
              </span>
            )}
          </div>
          {s.review_note && <p className="rounded-lg border-l-2 border-ink-200 bg-ink-50 px-2.5 py-1 whitespace-pre-line text-ink-600 dark:border-white/10 dark:bg-white/5 dark:text-ink-300">{s.review_note}</p>}
          {(s.observed !== null || s.threshold !== null) && (
            <p className="text-ink-600 dark:text-ink-300">
              Observed <strong>{plainValue(s.observed)}</strong>
              {s.threshold !== null && <> against a threshold of <strong>{plainValue(s.threshold)}</strong></>}.
            </p>
          )}

          {s.evidence.product && (
            <p>
              Product: <span className="font-semibold">{s.evidence.product.name}</span>
              {s.product && (
                <>
                  {' · '}
                  <Link to={`/staff/products/${s.product.id}/integrity`} onClick={close} className="font-semibold text-violet-700 hover:underline dark:text-violet-300">
                    Vote integrity
                  </Link>
                </>
              )}
            </p>
          )}

          {s.evidence.members.length > 0 && (
            <div>
              <h3 className="mb-1.5 text-xs font-bold tracking-wide text-ink-400 uppercase">Members involved</h3>
              <ul className="space-y-1.5">
                {s.evidence.members.map((m) => (
                  <li key={m.id} className="flex items-center gap-2">
                    <Avatar name={m.name} hue={hueFromId(m.id)} size="xs" className="ring-0" />
                    <Link to={`/staff/risk/members/${m.id}`} onClick={close} className="font-semibold hover:text-violet-600">
                      @{m.handle}
                    </Link>
                    {m.suspended && <Badge tone="red">Suspended</Badge>}
                    {m.deleted && <Badge tone="neutral">Deleted</Badge>}
                    <RiskMeter score={m.risk_score} className="ml-auto" />
                  </li>
                ))}
              </ul>
            </div>
          )}

          {s.evidence.votes.length > 0 && (
            <div>
              <h3 className="mb-1.5 text-xs font-bold tracking-wide text-ink-400 uppercase">Votes (device and network fingerprints)</h3>
              <ul className="space-y-1">
                {s.evidence.votes.map((v) => (
                  <li key={v.id} className="flex flex-wrap items-center gap-2 text-xs">
                    <VoteStatusBadge status={v.status} />
                    <time dateTime={v.voted_at} className="text-ink-500">
                      {timeAgo(v.voted_at)}
                    </time>
                    <span className="text-ink-400">device</span> <Fingerprint value={v.device_fp} />
                    <span className="text-ink-400">network</span> <Fingerprint value={v.ip_fp} />
                  </li>
                ))}
              </ul>
            </div>
          )}

          <details className="text-xs text-ink-500">
            <summary className="cursor-pointer select-none">Raw details</summary>
            <pre className="mt-1 overflow-x-auto rounded-lg bg-ink-50 p-2 font-mono text-[11px] whitespace-pre-wrap dark:bg-white/5">{JSON.stringify(s.details, null, 2)}</pre>
          </details>

          <div className="space-y-2 border-t border-ink-100 pt-4 dark:border-white/5">
            <Textarea label="Note" hint="Optional · saved to audit log" value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} placeholder="What did you check?" error={staffFieldMessage(review.error, 'note')} className="min-h-16" />
            {review.isError && <InlineError>{staffMessage(review.error)}</InlineError>}
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button variant="outline" disabled={review.isPending} onClick={() => decide('dismissed')}>
                Dismiss as harmless
              </Button>
              <Button variant="danger" loading={review.isPending} onClick={() => decide('confirmed')}>
                Confirm abuse
              </Button>
            </div>
          </div>
        </div>
      )}
    </Modal>
  )
}

function Members() {
  const [scope, setScope] = useState<'active' | 'all'>('active')
  const list = useRiskMembers(scope === 'active' ? false : undefined)
  return (
    <>
      <div className="mb-4">
        <SelectField<'active' | 'all'>
          label="Member scope"
          value={scope}
          onChange={setScope}
          options={[
            { value: 'active', label: 'Active members' },
            { value: 'all', label: 'Including suspended' },
          ]}
        />
      </div>
      {list.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : list.isError && list.items.length === 0 ? (
        <StaffLoadError error={list.error} onRetry={() => void list.refetch()} />
      ) : list.items.length === 0 ? (
        <EmptyState icon={<FingerprintIcon className="size-6" />} title="No members above the threshold" body="Members with a risk score at the flag level show up here." />
      ) : (
        <>
          <ul className="grid gap-2 sm:grid-cols-2">
            {list.items.map((u) => (
              <li key={u.id}>
                <Link to={`/staff/risk/members/${u.id}`} className="surface flex items-center gap-3 p-3 transition hover:border-violet-200 hover:shadow-lift dark:hover:border-violet-500/30">
                  <Avatar name={u.name} hue={hueFromId(u.id)} size="sm" className="ring-0" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">
                      @{u.handle} {u.suspended && <Badge tone="red">Suspended</Badge>}
                    </p>
                    <p className="text-xs text-ink-500">
                      {u.open_signals} open · {u.flagged_votes} flagged vote{u.flagged_votes === 1 ? '' : 's'} · {u.account_age_days}d old
                    </p>
                  </div>
                  <RiskMeter score={u.risk_score} />
                </Link>
              </li>
            ))}
          </ul>
          <LoadMore hasNextPage={!!list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}
    </>
  )
}

