import { motion } from 'motion/react'
import { BadgeCheck, Fingerprint, MailWarning, RotateCcw, ShieldAlert, UserCog, UserX, Users } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { useAdminMembers, useChangeRole, useRestoreMember, useSuspendMember } from '@/api/hooks/useStaffMembers'
import { useRiskMembers } from '@/api/hooks/useStaffRisk'
import { staffFieldMessage, staffMessage } from '@/api/staffErrors'
import type { AdminMemberDto } from '@/api/types'
import { Avatar, Badge, EmptyState, LoadMore, Skeleton, Tabs, cn, useToast } from '@/components/ui'
import { hueFromId } from '@/lib/hue'
import { formatDay } from '@/lib/date'
import { useDebouncedValue } from '@/lib/useDebouncedValue'
import { useSession } from '@/session/SessionProvider'
import { ActionButton, ConfirmModal, PageHeader, cleanQuery, RiskMeter, SearchField, SelectField, StaffLoadError } from './components/shared'

type Filter = 'all' | 'active' | 'suspended' | 'deleted'
type RoleFilter = 'any' | 'member' | 'reviewer' | 'admin'
type Pending = { member: AdminMemberDto; kind: 'suspend' | 'restore' | 'role' }

const roleTone = { member: 'neutral', reviewer: 'violet', admin: 'violet' } as const
const SUSPEND_REASONS = ['Vote manipulation (ring / fake accounts)', 'Spam comments', 'Harassment', 'Impersonation']

export default function MembersPage() {
  const { user: me } = useSession()
  const toast = useToast()
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [role, setRole] = useState<RoleFilter>('any')
  const [pending, setPending] = useState<Pending | null>(null)
  const suspend = useSuspendMember()
  const restore = useRestoreMember()
  const changeRole = useChangeRole()

  const needle = useDebouncedValue(cleanQuery(q), 300)
  const list = useAdminMembers({ q: needle.length >= 2 ? needle : undefined, role: role === 'any' ? undefined : role, status: filter === 'all' ? undefined : filter })
  const risky = useRiskMembers(false)

  const mutation = pending?.kind === 'suspend' ? suspend : pending?.kind === 'restore' ? restore : changeRole
  const close = () => {
    setPending(null)
    suspend.reset()
    restore.reset()
    changeRole.reset()
  }

  const canModerate = (m: AdminMemberDto) => m.id !== me?.id && m.role !== 'admin' && !m.deleted_at

  const confirm = (reason: string) => {
    if (!pending) return
    const { member, kind } = pending
    const done = (title: string) => () => {
      toast({ tone: 'success', title, body: 'Recorded in the audit log.' })
      close()
    }
    if (kind === 'suspend') suspend.mutate({ handle: member.handle, reason }, { onSuccess: done(`@${member.handle} suspended`) })
    else if (kind === 'restore') restore.mutate(member.handle, { onSuccess: done(`@${member.handle} restored`) })
    else changeRole.mutate({ handle: member.handle, role: member.role === 'reviewer' ? 'member' : 'reviewer' }, { onSuccess: done(`@${member.handle} is now a ${member.role === 'reviewer' ? 'member' : 'reviewer'}`) })
  }

  const flagged = risky.items

  return (
    <div>
      <PageHeader title="Members" subtitle="Accounts and vote-fraud signals. Admin only." />

      {flagged.length > 0 && (
        <motion.section
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          aria-label="Suspicious voting"
          className="mb-6 overflow-hidden rounded-2xl border border-red-200 bg-gradient-to-br from-red-50 to-white shadow-soft dark:border-red-500/25 dark:from-red-500/10 dark:to-ink-900"
        >
          <div className="flex flex-wrap items-start justify-between gap-3 p-4 sm:p-5">
            <div className="flex items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-600 text-white shadow-[0_6px_16px_-6px_rgb(220_38_38/0.7)]">
                <ShieldAlert className="size-5" />
              </span>
              <div>
                <h2 className="text-lg font-bold">Suspicious voting</h2>
                <p className="text-sm text-ink-600 dark:text-ink-300">
                  {flagged.length}
                  {risky.hasNextPage ? '+' : ''} active account{flagged.length > 1 ? 's' : ''} above the risk threshold
                </p>
              </div>
            </div>
            <Link to="/staff/risk" className="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">
              Open the risk panel
            </Link>
          </div>
          <ul className="grid gap-2 px-4 pb-4 sm:grid-cols-2 sm:px-5 sm:pb-5">
            {flagged.slice(0, 6).map((u) => (
              <li key={u.id}>
                <Link to={`/staff/risk/members/${u.id}`} className="flex items-center gap-3 rounded-xl bg-white p-3 ring-1 ring-ink-200/60 transition hover:ring-red-300 dark:bg-ink-900 dark:ring-white/5">
                  <Avatar name={u.name} hue={hueFromId(u.id)} size="sm" className="ring-0" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">@{u.handle}</p>
                    <p className="text-xs text-ink-500">
                      {u.open_signals} open signal{u.open_signals === 1 ? '' : 's'} · {u.flagged_votes} flagged vote{u.flagged_votes === 1 ? '' : 's'}
                    </p>
                  </div>
                  <RiskMeter score={u.risk_score} className="hidden sm:flex" />
                </Link>
              </li>
            ))}
          </ul>
        </motion.section>
      )}

      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <Tabs<Filter>
          value={filter}
          onChange={setFilter}
          items={[
            { value: 'all', label: 'All' },
            { value: 'active', label: 'Active' },
            { value: 'suspended', label: 'Suspended' },
            { value: 'deleted', label: 'Deleted' },
          ]}
        />
        <div className="flex flex-col gap-2 sm:flex-row">
          <SelectField
            label="Filter by role"
            value={role}
            onChange={setRole}
            options={[
              { value: 'any', label: 'Any role' },
              { value: 'member', label: 'Members' },
              { value: 'reviewer', label: 'Reviewers' },
              { value: 'admin', label: 'Admins' },
            ]}
          />
          <SearchField value={q} onChange={setQ} placeholder="Search name or @handle…" className="sm:w-72" />
        </div>
      </div>
      {q.trim().length === 1 && <p className="mb-3 text-xs text-ink-400">Type at least two characters to search.</p>}

      {list.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : list.isError && list.items.length === 0 ? (
        <StaffLoadError error={list.error} onRetry={() => void list.refetch()} />
      ) : list.items.length === 0 ? (
        <EmptyState icon={<Users className="size-6" />} title="No members match" body="Try a different search or filter." />
      ) : (
        <>
          <div className="surface overflow-hidden">
            <div className="hidden grid-cols-[minmax(0,1fr)_100px_120px_90px_110px_auto] gap-4 border-b border-ink-100 px-4 py-2.5 text-xs font-semibold tracking-wide text-ink-400 uppercase lg:grid dark:border-white/5">
              <span>Member</span>
              <span>Role</span>
              <span>Joined</span>
              <span>Email</span>
              <span>Risk</span>
              <span className="w-[230px] text-right">Actions</span>
            </div>
            <ul className="divide-y divide-ink-100 dark:divide-white/5">
              {list.items.map((u) => (
                <li
                  key={u.id}
                  className={cn(
                    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-4 py-3 lg:grid-cols-[minmax(0,1fr)_100px_120px_90px_110px_auto]',
                    u.is_suspended && 'bg-red-50/50 dark:bg-red-500/[0.05]',
                  )}
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <Avatar name={u.name} hue={hueFromId(u.id)} size="sm" className={cn('ring-0', (u.is_suspended || u.deleted_at) && 'grayscale')} />
                    <div className="min-w-0">
                      <div className="flex items-center gap-1.5">
                        <Link to={`/u/${u.handle}`} className="truncate font-semibold hover:text-violet-600">
                          {u.name}
                        </Link>
                        {u.is_suspended && <Badge tone="red">Suspended</Badge>}
                        {u.deleted_at && <Badge tone="neutral">Deleted</Badge>}
                        {u.id === me?.id && <Badge tone="violet">You</Badge>}
                      </div>
                      <p className="truncate text-xs text-ink-500">
                        @{u.handle} · {u.email}
                      </p>
                      {u.is_suspended && u.suspended_reason && <p className="truncate text-xs text-red-600 dark:text-red-400">Suspended: {u.suspended_reason}</p>}
                    </div>
                  </div>
                  <div className="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-ink-500 lg:contents">
                    <span>
                      <Badge tone={roleTone[u.role]} className="capitalize">
                        {u.role}
                      </Badge>
                    </span>
                    <span className="text-sm lg:text-ink-600 dark:lg:text-ink-300">{u.created_at ? formatDay(u.created_at.slice(0, 10), { month: 'short', day: 'numeric', year: 'numeric' }) : '—'}</span>
                    <span className="flex items-center gap-1.5">
                      {u.email_verified_at ? <BadgeCheck className="size-4 text-emerald-500" aria-label="Email verified" /> : <MailWarning className="size-4 text-amber-500" aria-label="Email unverified" />}
                      <span className="text-ink-600 dark:text-ink-300">{u.email_verified_at ? 'Verified' : 'Unverified'}</span>
                    </span>
                    <RiskMeter score={u.risk_score} />
                    <div className="flex flex-wrap items-center justify-end gap-2 lg:ml-auto lg:w-[230px]">
                      <Link to={`/staff/risk/members/${u.id}`} aria-label={`Risk summary of @${u.handle}`} title="Risk summary" className="text-ink-400 hover:text-violet-600">
                        <Fingerprint className="size-4" />
                      </Link>
                      {canModerate(u) ? (
                        <>
                          <ActionButton tone="soft" icon={<UserCog />} onClick={() => setPending({ member: u, kind: 'role' })}>
                            {u.role === 'reviewer' ? 'Make member' : 'Make reviewer'}
                          </ActionButton>
                          {u.is_suspended ? (
                            <ActionButton tone="soft" icon={<RotateCcw />} onClick={() => setPending({ member: u, kind: 'restore' })}>
                              Restore
                            </ActionButton>
                          ) : (
                            <>
                              <ActionButton tone="softRed" icon={<UserX />} onClick={() => setPending({ member: u, kind: 'suspend' })}>
                                Suspend
                              </ActionButton>
                              <Link to={`/staff/risk/members/${u.id}`} className="w-full text-right text-xs font-semibold text-red-700 hover:underline dark:text-red-300">
                                Suspend for abuse (void votes)
                              </Link>
                            </>
                          )}
                        </>
                      ) : (
                        <span className="text-xs text-ink-300 dark:text-ink-600">—</span>
                      )}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          </div>
          <LoadMore hasNextPage={!!list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}

      <ConfirmModal
        open={!!pending}
        onClose={close}
        title={
          pending?.kind === 'suspend' ? `Suspend @${pending.member.handle}?` : pending?.kind === 'restore' ? `Restore @${pending.member.handle}?` : `Change the role of @${pending?.member.handle}?`
        }
        description={
          pending?.kind === 'suspend'
            ? 'Suspended members are signed out and cannot sign in, vote, comment or launch.'
            : pending?.kind === 'restore'
              ? 'The member can sign in again. They have to sign in again; the old sessions do not come back.'
              : pending?.member.role === 'reviewer'
                ? 'They lose access to the staff tools.'
                : 'They get access to the review queue, reports and the risk panel.'
        }
        confirmLabel={pending?.kind === 'suspend' ? 'Suspend' : pending?.kind === 'restore' ? 'Restore access' : pending?.member.role === 'reviewer' ? 'Make member' : 'Make reviewer'}
        tone={pending?.kind === 'suspend' ? 'danger' : 'primary'}
        pending={mutation.isPending}
        error={mutation.isError ? (staffFieldMessage(mutation.error, 'reason') ?? staffFieldMessage(mutation.error, 'role') ?? staffMessage(mutation.error)) : null}
        note={pending?.kind === 'suspend' ? { label: 'Reason', required: true, maxLength: 500, placeholder: 'Why is this account being suspended?', reasons: SUSPEND_REASONS } : undefined}
        onConfirm={confirm}
      />
    </div>
  )
}
