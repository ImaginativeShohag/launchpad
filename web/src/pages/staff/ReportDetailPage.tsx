import { ArrowLeft, Ban, EyeOff, Lock, ShieldAlert, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useReportDetail, useResolveReport } from '@/api/hooks/useStaffReports'
import { staffFieldMessage, staffMessage } from '@/api/staffErrors'
import type { ReportActionDto, ReportTypeDto, StaffReportDetailDto, StaffReportItemDto } from '@/api/types'
import { Badge, EmptyState, Button, Skeleton, useToast } from '@/components/ui'
import { timeAgo } from '@/lib/date'
import { useSession } from '@/session/SessionProvider'
import { TypeIcon } from './components/reportBits'
import { reasonTone } from './components/reportTone'
import { ActionButton, ConfirmModal, StaffLoadError, StatusBadge } from './components/shared'
import type { ProductStatus } from '@/data/types'

const isType = (t: string | undefined): t is ReportTypeDto => t === 'comment' || t === 'product'

export default function ReportDetailPage() {
  const { type, id } = useParams()
  if (!isType(type) || !id) {
    return <EmptyState icon={<ShieldAlert className="size-6" />} title="Not found" body="There is no such report target." action={<Link to="/staff/reports"><Button variant="outline">Back to reports</Button></Link>} />
  }
  return <ReportDetail key={`${type}:${id}`} type={type} id={id} />
}

const TAKEDOWN_REASONS = ['Misleading claims', 'Spam or scam', 'Copyright / trademark complaint', 'Unsafe or malicious website']
const REMOVE_REASONS = ['Spam', 'Harassment or abuse', 'Misleading or off-topic']

function ReportDetail({ type, id }: { type: ReportTypeDto; id: string }) {
  const { isAdmin } = useSession()
  const navigate = useNavigate()
  const toast = useToast()
  const detail = useReportDetail(type, id)
  const resolve = useResolveReport()
  const [action, setAction] = useState<ReportActionDto | null>(null)

  const close = () => {
    setAction(null)
    resolve.reset()
  }

  const confirm = (note: string) => {
    if (!action) return
    resolve.mutate(
      { type, id, action, note: note || null },
      {
        onSuccess: (outcome) => {
          close()
          if (outcome.closed === 0) toast({ tone: 'info', title: 'Nothing left to resolve', body: 'Another staff member already handled this one.' })
          else if (action === 'dismiss') toast({ tone: 'info', title: 'Report dismissed', body: 'No action taken. Logged to the audit trail.' })
          else toast({ tone: 'success', title: action === 'take_down' ? 'Product taken down' : 'Comment removed', body: `${outcome.closed} report${outcome.closed === 1 ? '' : 's'} resolved and logged.` })
          navigate('/staff/reports')
        },
      },
    )
  }

  if (detail.isPending) return <Skeleton className="h-64 w-full" />
  if (!detail.data) return <StaffLoadError error={detail.error} onRetry={() => void detail.refetch()} backTo="/staff/reports" />
  const d = detail.data
  const t = d.target
  const isOpen = d.open_total > 0
  const alreadyDone = type === 'comment' ? t.state !== 'visible' : t.status === 'taken_down'

  return (
    <div>
      <Link to="/staff/reports" className="mb-4 inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-semibold text-ink-500 transition hover:bg-ink-100 hover:text-ink-900 dark:hover:bg-white/5 dark:hover:text-white">
        <ArrowLeft className="size-4" /> Reports
      </Link>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <article className="surface overflow-hidden">
          <div className="flex items-center gap-2 border-b border-ink-100 px-4 py-2.5 text-xs font-semibold tracking-wide text-ink-400 uppercase dark:border-white/5">
            <TypeIcon type={type} /> Reported {type}
          </div>
          <div className="p-4 sm:p-6">{type === 'comment' ? <CommentTarget d={d} /> : <ProductTarget d={d} />}</div>
        </article>

        <aside className="space-y-4 lg:sticky lg:top-36 lg:self-start">
          <section className="surface p-4">
            <h3 className="mb-3 text-sm font-bold">Decision</h3>
            {isOpen ? (
              <div className="flex flex-wrap gap-2">
                {type === 'comment' ? (
                  <ActionButton tone="red" icon={<Trash2 />} onClick={() => setAction('remove_comment')}>
                    {alreadyDone ? 'Mark resolved' : 'Remove comment'}
                  </ActionButton>
                ) : isAdmin ? (
                  <ActionButton tone="red" icon={<Ban />} onClick={() => setAction('take_down')}>
                    {alreadyDone ? 'Mark resolved' : 'Take down'}
                  </ActionButton>
                ) : null}
                <ActionButton tone="soft" icon={<X />} onClick={() => setAction('dismiss')}>
                  Dismiss
                </ActionButton>
                {type === 'product' && !isAdmin && (
                  <p className="flex w-full items-center gap-1.5 text-xs text-ink-400">
                    <Lock className="size-3.5" /> Takedowns are admin-only. Dismiss, or leave it open for an admin.
                  </p>
                )}
              </div>
            ) : (
              <p className="text-sm text-ink-500">No open reports. Nothing to decide.</p>
            )}
            <p className="mt-3 text-xs text-ink-400">One decision closes every open report of this {type} together.</p>
          </section>

          <section className="surface p-4">
            <h3 className="mb-3 text-sm font-bold">
              Open reports <span className="text-ink-400">({d.open_total})</span>
            </h3>
            {d.open_reports.length === 0 ? <p className="text-sm text-ink-500">None.</p> : <ReportItems items={d.open_reports} />}
            {d.open_total > d.open_reports.length && <p className="mt-2 text-xs text-ink-400">Showing the oldest {d.open_reports.length} of {d.open_total}.</p>}
          </section>

          {d.closed_reports.length > 0 && (
            <section className="surface p-4">
              <h3 className="mb-3 text-sm font-bold">Earlier decisions</h3>
              <ReportItems items={d.closed_reports} />
            </section>
          )}
        </aside>
      </div>

      <ConfirmModal
        open={action !== null}
        onClose={close}
        title={action === 'take_down' ? 'Take this product down?' : action === 'remove_comment' ? (alreadyDone ? 'Mark these reports resolved?' : 'Remove this comment?') : 'Dismiss these reports?'}
        description={
          action === 'take_down'
            ? 'It is hidden from every public page immediately. An admin can restore it later.'
            : action === 'remove_comment'
              ? 'The comment is replaced by a placeholder for everyone. The reason is saved to the audit log.'
              : 'No action is taken on the reported item.'
        }
        confirmLabel={action === 'dismiss' ? 'Dismiss' : action === 'take_down' ? 'Take down' : alreadyDone ? 'Mark resolved' : 'Remove comment'}
        tone={action === 'dismiss' ? 'primary' : 'danger'}
        pending={resolve.isPending}
        error={resolve.isError ? (staffFieldMessage(resolve.error, 'note') ?? staffFieldMessage(resolve.error, 'action') ?? staffMessage(resolve.error)) : null}
        note={{
          label: action === 'dismiss' ? 'Note' : 'Reason',
          required: action !== 'dismiss',
          maxLength: 500,
          placeholder: action === 'dismiss' ? 'Optional note for the audit log' : action === 'take_down' ? 'Why is this product being removed?' : 'Why is this comment being removed?',
          reasons: action === 'take_down' ? TAKEDOWN_REASONS : action === 'remove_comment' ? REMOVE_REASONS : undefined,
        }}
        onConfirm={confirm}
      />
    </div>
  )
}

function CommentTarget({ d }: { d: StaffReportDetailDto }) {
  const t = d.target
  return (
    <div className="space-y-3">
      <p className="flex flex-wrap items-center gap-2 text-sm text-ink-500">
        {t.author && (
          <Link to={`/u/${t.author.handle}`} className="font-semibold text-ink-700 hover:text-violet-600 dark:text-ink-200">
            {t.author.name} (@{t.author.handle})
          </Link>
        )}
        {t.author?.is_suspended && <Badge tone="red">Suspended</Badge>}
        {t.product && (
          <span>
            on{' '}
            <Link to={`/p/${t.product.slug}`} className="font-semibold text-ink-700 hover:text-violet-600 dark:text-ink-200">
              {t.product.name}
            </Link>
          </span>
        )}
        {t.created_at && <time dateTime={t.created_at}>{timeAgo(t.created_at)}</time>}
        {t.state && t.state !== 'visible' && (
          <Badge tone="neutral" icon={<EyeOff className="size-3" />}>
            {t.state}
          </Badge>
        )}
      </p>
      {/* The stored text, even for a removed comment: this is the evidence. It is plain text. */}
      <p className="rounded-xl border border-ink-200/70 bg-ink-50/60 p-4 text-[15px] leading-relaxed whitespace-pre-line text-ink-800 dark:border-white/5 dark:bg-white/[0.03] dark:text-ink-100">{t.body || <em className="text-ink-400">No text</em>}</p>
      {t.removed_at && (
        <p className="text-sm text-ink-500">
          Removed {timeAgo(t.removed_at)}
          {t.removed_by && <> by @{t.removed_by.handle}</>}
          {t.removed_reason && <>: “{t.removed_reason}”</>}
        </p>
      )}
    </div>
  )
}

function ProductTarget({ d }: { d: StaffReportDetailDto }) {
  const t = d.target
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="text-2xl font-bold">{t.name}</h2>
        {t.status && <StatusBadge status={t.status as ProductStatus} />}
      </div>
      {t.tagline && <p className="text-ink-600 dark:text-ink-300">{t.tagline}</p>}
      {t.maker && (
        <p className="flex flex-wrap items-center gap-2 text-sm text-ink-500">
          by{' '}
          <Link to={`/u/${t.maker.handle}`} className="font-semibold text-ink-700 hover:text-violet-600 dark:text-ink-200">
            {t.maker.name} (@{t.maker.handle})
          </Link>
          {t.maker.is_suspended && <Badge tone="red">Suspended</Badge>}
        </p>
      )}
      {t.takedown_reason && <p className="text-sm text-ink-500">Taken down: “{t.takedown_reason}”</p>}
      <p className="flex flex-wrap gap-3 text-sm font-semibold text-violet-700 dark:text-violet-300">
        <Link to={`/staff/review/${t.id}`} className="hover:underline">
          Open the submission
        </Link>
        <Link to={`/staff/products/${t.id}/integrity`} className="hover:underline">
          Vote integrity
        </Link>
      </p>
    </div>
  )
}

function ReportItems({ items }: { items: StaffReportItemDto[] }) {
  return (
    <ul className="space-y-3">
      {items.map((r) => (
        <li key={r.id} className="text-sm">
          <p className="flex flex-wrap items-center gap-2">
            <Badge tone={reasonTone(r.reason)}>{r.reason}</Badge>
            <span className="text-ink-500">@{r.reporter.handle}</span>
            <time className="text-xs text-ink-400" dateTime={r.created_at}>
              {timeAgo(r.created_at)}
            </time>
            {!r.reporter_eligible && (
              <span title="The reporter is unverified, suspended or deleted" className="text-xs font-semibold text-amber-600">
                does not count
              </span>
            )}
            {r.status && <Badge tone={r.status === 'resolved' ? 'green' : 'neutral'}>{r.status}</Badge>}
          </p>
          {r.details && <p className="mt-1 text-ink-600 dark:text-ink-300">“{r.details}”</p>}
          {r.status && (
            <p className="mt-1 text-xs text-ink-400">
              {r.handled_by ? `by @${r.handled_by.handle}` : 'by the system'}
              {r.resolution_note && <>: {r.resolution_note}</>}
            </p>
          )}
        </li>
      ))}
    </ul>
  )
}
