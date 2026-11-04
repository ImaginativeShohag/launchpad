import { ArrowLeft, Ban, Link2, Lock, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { useRiskMember, useSuspendForAbuse, useVoidMemberVotes, type VoidTotals } from '@/api/hooks/useStaffRisk'
import { staffFieldMessage, staffMessage } from '@/api/staffErrors'
import { Avatar, Badge, Skeleton, useToast } from '@/components/ui'
import { hueFromId } from '@/lib/hue'
import { formatDay, timeAgo } from '@/lib/date'
import { useSession } from '@/session/SessionProvider'
import { OutcomeBadge } from './components/riskBits'
import { labelOfKind, plainValue } from './components/riskKinds'
import { ActionButton, ConfirmModal, InlineError, RiskMeter, StaffLoadError } from './components/shared'

export default function MemberRiskPage() {
  const { id } = useParams()
  return <MemberRisk key={id} id={id ?? ''} />
}

type Dialog = 'void' | 'suspend' | null

function MemberRisk({ id }: { id: string }) {
  const { isAdmin } = useSession()
  const toast = useToast()
  const q = useRiskMember(id)
  const voidVotes = useVoidMemberVotes()
  const suspend = useSuspendForAbuse()
  const [dialog, setDialog] = useState<Dialog>(null)
  const [alsoVoid, setAlsoVoid] = useState(true)
  const [progress, setProgress] = useState<VoidTotals | null>(null)
  const [result, setResult] = useState<(VoidTotals & { stalled: boolean }) | null>(null)
  const [partial, setPartial] = useState<number | null>(null)
  const [cleanupFailed, setCleanupFailed] = useState(false)
  const progressRef = useRef<VoidTotals | null>(null)
  const abort = useRef<AbortController | null>(null)
  // Leaving the page stops the void loop after the round in flight.
  useEffect(() => () => abort.current?.abort(), [])

  const close = () => {
    setDialog(null)
    voidVotes.reset()
    suspend.reset()
    setProgress(null)
  }

  if (q.isPending) return <Skeleton className="h-64 w-full" />
  if (!q.data) return <StaffLoadError error={q.error} onRetry={() => void q.refetch()} backTo="/staff/risk" />
  const m = q.data
  const breakdown = Object.entries(m.breakdown)

  return (
    <div>
      <Link to="/staff/risk" className="mb-4 inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-semibold text-ink-500 transition hover:bg-ink-100 hover:text-ink-900 dark:hover:bg-white/5 dark:hover:text-white">
        <ArrowLeft className="size-4" /> Risk
      </Link>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="space-y-5">
          <section className="surface p-4 sm:p-6">
            <div className="flex flex-wrap items-center gap-4">
              <Avatar name={m.name} hue={hueFromId(m.id)} size="lg" />
              <div className="min-w-0 flex-1">
                <h2 className="truncate text-2xl font-bold">{m.name}</h2>
                <p className="flex flex-wrap items-center gap-2 text-sm text-ink-500">
                  <Link to={`/u/${m.handle}`} className="hover:text-violet-600">
                    @{m.handle}
                  </Link>
                  <Badge tone={m.role === 'member' ? 'neutral' : 'violet'} className="capitalize">
                    {m.role}
                  </Badge>
                  {m.suspended && <Badge tone="red">Suspended</Badge>}
                  {m.deleted && <Badge tone="neutral">Deleted</Badge>}
                  {m.created_at && <span>joined {formatDay(m.created_at.slice(0, 10), { month: 'short', day: 'numeric', year: 'numeric' })}</span>}
                </p>
                {m.suspended && m.suspended_reason && (
                  <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                    Suspended{m.suspended_at ? ` ${timeAgo(m.suspended_at)}` : ''}: {m.suspended_reason}
                  </p>
                )}
              </div>
              <div className="text-right">
                <p className="mb-1 text-xs font-semibold tracking-wide text-ink-400 uppercase">Risk score</p>
                <RiskMeter score={m.risk_score} />
              </div>
            </div>
          </section>

          <section className="surface p-4 sm:p-6">
            <h3 className="mb-3 text-sm font-bold">Where the score comes from</h3>
            {breakdown.length === 0 ? (
              <p className="text-sm text-ink-500">No signals count towards the score.</p>
            ) : (
              <table className="w-full text-sm">
                <thead className="text-left text-xs tracking-wide text-ink-400 uppercase">
                  <tr>
                    <th className="py-1 font-semibold">Signal</th>
                    <th className="py-1 text-right font-semibold">Points</th>
                    <th className="py-1 text-right font-semibold">Open</th>
                    <th className="py-1 text-right font-semibold">Confirmed</th>
                    <th className="py-1 text-right font-semibold">Dismissed</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink-100 tabular-nums dark:divide-white/5">
                  {breakdown.map(([kind, b]) => (
                    <tr key={kind}>
                      <td className="py-1.5">{labelOfKind(kind)}</td>
                      <td className="py-1.5 text-right font-semibold">{b.contribution}</td>
                      <td className="py-1.5 text-right">{b.open}</td>
                      <td className="py-1.5 text-right">{b.confirmed}</td>
                      <td className="py-1.5 text-right">{b.dismissed}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>

          <section className="surface p-4 sm:p-6">
            <h3 className="mb-3 text-sm font-bold">Latest signals</h3>
            {m.signals.length === 0 ? (
              <p className="text-sm text-ink-500">None.</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {m.signals.map((s) => (
                  <li key={s.id} className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold">{labelOfKind(s.kind)}</span>
                    <Badge tone="neutral">weight {s.weight}</Badge>
                    <OutcomeBadge outcome={s.outcome} status={s.status} />
                    {s.product && <span className="text-ink-500">on {s.product.name}</span>}
                    {s.observed !== null && <span className="text-xs text-ink-400">observed {plainValue(s.observed)}</span>}
                    <time className="ml-auto text-xs text-ink-400" dateTime={s.detected_at}>
                      {timeAgo(s.detected_at)}
                    </time>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        <aside className="space-y-4 lg:sticky lg:top-36 lg:self-start">
          <section className="surface p-4">
            <h3 className="mb-3 text-sm font-bold">Votes</h3>
            <dl className="grid grid-cols-2 gap-2 text-xs">
              {(
                [
                  ['Active', m.votes.active],
                  ['Flagged', m.votes.flagged],
                  ['Voided', m.votes.voided],
                  ['Withdrawn', m.votes.withdrawn],
                  ['Distinct makers', m.votes.distinct_makers],
                  ['Top maker share', `${Math.round(m.votes.top_maker_share * 100)}%`],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="rounded-xl bg-ink-50 p-2 dark:bg-white/5">
                  <dt className="text-ink-500">{label}</dt>
                  <dd className="mt-0.5 text-sm font-semibold tabular-nums">{value}</dd>
                </div>
              ))}
            </dl>
          </section>

          <section className="surface p-4">
            <h3 className="mb-3 text-sm font-bold">Sign-ins</h3>
            <dl className="grid grid-cols-2 gap-2 text-xs">
              {(
                [
                  ['Devices, 24 h', m.sign_ins.devices_24h],
                  ['Devices, 7 d', m.sign_ins.devices_7d],
                  ['Networks, 24 h', m.sign_ins.ip_groups_24h],
                  ['Networks, 7 d', m.sign_ins.ip_groups_7d],
                ] as const
              ).map(([label, value]) => (
                <div key={label} className="rounded-xl bg-ink-50 p-2 dark:bg-white/5">
                  <dt className="text-ink-500">{label}</dt>
                  <dd className="mt-0.5 text-sm font-semibold tabular-nums">{value}</dd>
                </div>
              ))}
            </dl>
          </section>

          <section className="surface p-4">
            <h3 className="mb-3 text-sm font-bold">Linked accounts</h3>
            {m.linked_accounts.length === 0 ? (
              <p className="text-sm text-ink-500">Nobody voted from the same device or network in the last 48 hours.</p>
            ) : (
              <ul className="space-y-1.5 text-sm">
                {m.linked_accounts.map((l) => (
                  <li key={`${l.id}-${l.via}`} className="flex items-center gap-2">
                    <Link2 className="size-3.5 text-ink-400" />
                    <Link to={`/staff/risk/members/${l.id}`} className="font-semibold hover:text-violet-600">
                      @{l.handle}
                    </Link>
                    <span className="text-xs text-ink-400">same {l.via === 'ip' ? 'network' : 'device'}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="surface p-4">
            <h3 className="mb-3 text-sm font-bold">Actions</h3>
            {isAdmin ? (
              <div className="space-y-2">
                <div className="flex flex-wrap gap-2">
                  <ActionButton tone="softRed" icon={<Trash2 />} onClick={() => setDialog('void')}>
                    Void votes
                  </ActionButton>
                  {!m.suspended && !m.deleted && (
                    <ActionButton tone="red" icon={<Ban />} onClick={() => setDialog('suspend')}>
                      Suspend for abuse
                    </ActionButton>
                  )}
                </div>
                {partial !== null && (
                  <p role="status" className="text-xs text-ink-600 dark:text-ink-300">
                    Voided {partial} before it stopped. Run it again to continue.
                  </p>
                )}
                {cleanupFailed && <InlineError>Suspended, but the vote clean-up was not queued. Use Void votes.</InlineError>}
                {result && (
                  <p role="status" className="text-xs text-ink-600 dark:text-ink-300">
                    Voided {result.voided} vote{result.voided === 1 ? '' : 's'}
                    {result.skipped_frozen > 0 && `, ${result.skipped_frozen} on frozen days were left alone`}
                    {result.failed > 0 && `, ${result.failed} failed`}.{result.stalled && ' Stopped early: the remaining votes could not be voided.'}
                  </p>
                )}
              </div>
            ) : (
              <p className="flex items-start gap-1.5 text-xs text-ink-400">
                <Lock className="mt-0.5 size-3.5 shrink-0" /> Voiding votes and suspending are admin-only. You can read everything on this page.
              </p>
            )}
          </section>
        </aside>
      </div>

      <ConfirmModal
        open={dialog === 'void'}
        onClose={close}
        title={`Void the votes of @${m.handle}?`}
        description="Their votes on launch days that are not frozen stop counting. Frozen days are never touched. Large accounts take several rounds of up to 100 votes."
        confirmLabel="Void votes"
        tone="danger"
        pending={voidVotes.isPending}
        error={voidVotes.isError ? (staffFieldMessage(voidVotes.error, 'reason') ?? staffMessage(voidVotes.error)) : null}
        note={{ label: 'Reason', required: true, minLength: 5, maxLength: 255, placeholder: 'Why are these votes being voided?', reasons: ['Vote ring (shared devices)', 'Coordinated voting', 'Fake accounts'] }}
        onConfirm={(reason) => {
          if (voidVotes.isPending) return
          abort.current = new AbortController()
          setPartial(null)
          voidVotes.mutate(
            {
              id,
              reason,
              signal: abort.current.signal,
              onProgress: (t) => {
                progressRef.current = t
                setProgress(t)
              },
            },
            {
              onError: () => setPartial(progressRef.current?.voided ?? 0),
              onSuccess: (r) => {
                setPartial(null)
                setResult(r)
                toast({ tone: r.stalled ? 'info' : 'success', title: `${r.voided} vote${r.voided === 1 ? '' : 's'} voided`, body: r.stalled ? 'Some could not be voided. See the page for details.' : 'Recorded in the audit log.' })
                close()
              },
            },
          )
        }}
      >
        {progress && voidVotes.isPending && (
          <p role="status" className="text-sm text-ink-600 dark:text-ink-300">
            Round {progress.calls}: {progress.voided} voided so far…
          </p>
        )}
        {progress && voidVotes.isError && (
          <p role="status" className="text-sm text-ink-600 dark:text-ink-300">
            Voided {progress.voided} before it stopped.
          </p>
        )}
      </ConfirmModal>

      <ConfirmModal
        open={dialog === 'suspend'}
        onClose={close}
        title={`Suspend @${m.handle} for abuse?`}
        description="They are signed out everywhere and cannot sign in, vote, comment or launch. Open signals about them are confirmed."
        confirmLabel="Suspend"
        tone="danger"
        pending={suspend.isPending}
        error={suspend.isError ? (staffFieldMessage(suspend.error, 'reason') ?? staffFieldMessage(suspend.error, 'void_votes') ?? staffMessage(suspend.error)) : null}
        note={{ label: 'Reason', required: true, minLength: 5, maxLength: 500, placeholder: 'Why is this account being suspended?', reasons: ['Vote manipulation (ring / fake accounts)', 'Spam comments', 'Harassment'] }}
        onConfirm={(reason) =>
          suspend.mutate(
            { id, reason, voidVotes: alsoVoid },
            {
              onSuccess: (res) => {
                setCleanupFailed(res.meta.void_votes === 'dispatch_failed')
                toast({
                  tone: res.meta.void_votes === 'dispatch_failed' ? 'info' : 'success',
                  title: `@${m.handle} suspended`,
                  body: res.meta.void_votes === 'queued' ? 'Their votes are being voided in the background.' : res.meta.void_votes === 'dispatch_failed' ? 'Suspended, but the vote clean-up could not be queued. Void the votes here.' : 'Recorded in the audit log.',
                })
                close()
              },
            },
          )
        }
      >
        <label className="flex cursor-pointer items-start gap-2.5 rounded-xl bg-ink-50 p-3 text-sm dark:bg-white/5">
          <input type="checkbox" checked={alsoVoid} onChange={(e) => setAlsoVoid(e.target.checked)} className="mt-0.5 size-4 accent-violet-600" />
          <span>
            <span className="font-semibold">Also void their votes</span>
            <span className="block text-xs text-ink-500">Runs in the background on days that are not frozen.</span>
          </span>
        </label>
      </ConfirmModal>
    </div>
  )
}
