import { AnimatePresence, motion } from 'motion/react'
import { Ban, ExternalLink, Lock, PackageSearch, RotateCcw, ShieldAlert } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { useRestoreProduct, useStaffProducts, useTakeDown } from '@/api/hooks/useStaffReview'
import { staffFieldMessage, staffMessage } from '@/api/staffErrors'
import type { StaffProduct } from '@/data/types'
import { Avatar, EmptyState, LoadMore, Skeleton, Tabs, cn, useToast } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'
import { useDebouncedValue } from '@/lib/useDebouncedValue'
import { relativeDay } from '@/lib/date'
import { useSession } from '@/session/SessionProvider'
import { ActionButton, ConfirmModal, InlineError, PageHeader, cleanQuery, SearchField, StaffLoadError, StatusBadge } from './components/shared'

type Group = 'all' | 'live' | 'scheduled' | 'pending' | 'taken_down'

const TAKEDOWN_REASONS = ['Misleading claims', 'Spam or scam', 'Copyright / trademark complaint', 'Unsafe or malicious website']

export default function StaffProductsPage() {
  const { isAdmin } = useSession()
  const toast = useToast()
  const [q, setQ] = useState('')
  const [group, setGroup] = useState<Group>('all')
  const [target, setTarget] = useState<StaffProduct | null>(null)
  const [restoreError, setRestoreError] = useState<{ id: string; message: string } | null>(null)
  const takeDown = useTakeDown()
  const restore = useRestoreProduct()

  // The API matches names and slugs from two characters on; one character would be a 422.
  const needle = useDebouncedValue(cleanQuery(q), 300)
  const list = useStaffProducts({ status: group === 'all' ? undefined : group, q: needle.length >= 2 ? needle : undefined })

  const closeTakeDown = () => {
    setTarget(null)
    takeDown.reset()
  }

  return (
    <div>
      <PageHeader title="Products" subtitle="Every submitted product, any status except drafts. Latest change first." />

      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Tabs<Group>
          value={group}
          onChange={setGroup}
          items={[
            { value: 'all', label: 'All' },
            { value: 'live', label: 'Live' },
            { value: 'scheduled', label: 'Scheduled' },
            { value: 'pending', label: 'Pending' },
            { value: 'taken_down', label: 'Taken down' },
          ]}
        />
        <SearchField value={q} onChange={setQ} placeholder="Search product name or slug…" className="sm:w-72" />
      </div>
      {q.trim().length === 1 && <p className="mb-3 text-xs text-ink-400">Type at least two characters to search.</p>}

      {list.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : list.isError && list.items.length === 0 ? (
        <StaffLoadError error={list.error} onRetry={() => void list.refetch()} />
      ) : list.items.length === 0 ? (
        <EmptyState icon={<PackageSearch className="size-6" />} title="No products match" body="Try another search or status filter." />
      ) : (
        <>
          <div className="surface overflow-hidden">
            <div className="hidden grid-cols-[minmax(0,1fr)_150px_130px_90px_auto] gap-4 border-b border-ink-100 px-4 py-2.5 text-xs font-semibold tracking-wide text-ink-400 uppercase md:grid dark:border-white/5">
              <span>Product</span>
              <span>Status</span>
              <span>Launch</span>
              <span className="text-right">Votes</span>
              <span className="w-[200px] text-right">Actions</span>
            </div>
            <ul className="divide-y divide-ink-100 dark:divide-white/5">
              <AnimatePresence initial={false}>
                {list.items.map((p) => {
                  const canTakeDown = p.status === 'live'
                  return (
                    <motion.li
                      key={p.id}
                      layout="position"
                      initial={{ opacity: 0 }}
                      animate={{ opacity: 1 }}
                      exit={{ opacity: 0, height: 0 }}
                      className={cn('grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-4 py-3 transition-colors md:grid-cols-[minmax(0,1fr)_150px_130px_90px_auto]', p.status === 'taken_down' && 'bg-red-50/40 dark:bg-red-500/[0.04]')}
                    >
                      <div className="flex min-w-0 items-center gap-3">
                        <ProductLogo name={p.name} src={p.logoUrl} hue={p.hue} size="sm" className={cn(p.status === 'taken_down' && 'grayscale')} />
                        <div className="min-w-0">
                          <div className="flex items-center gap-1.5">
                            {p.status === 'live' ? (
                              <Link to={`/p/${p.slug}`} className="group inline-flex items-center gap-1 truncate font-semibold hover:text-violet-600">
                                {p.name}
                                <ExternalLink className="size-3 opacity-0 transition group-hover:opacity-100" />
                              </Link>
                            ) : (
                              <span className={cn('truncate font-semibold', p.status === 'taken_down' && 'text-ink-500 line-through')}>{p.name}</span>
                            )}
                          </div>
                          <p className="flex items-center gap-1.5 truncate text-xs text-ink-500">
                            {p.maker && <Avatar name={p.maker.name} hue={p.maker.hue} size="xs" className="size-4 text-[7px] ring-0" />}
                            {p.maker?.name ?? 'Unknown'} · {p.tagline}
                          </p>
                          {p.status === 'taken_down' && p.takedownReason && <p className="truncate text-xs text-red-600 dark:text-red-400">Taken down: {p.takedownReason}</p>}
                        </div>
                      </div>
                      <div>
                        <StatusBadge status={p.status} />
                      </div>
                      <span className="hidden text-sm text-ink-600 md:block dark:text-ink-300">{p.launchDate ? relativeDay(p.launchDate) : '—'}</span>
                      <span className="hidden text-right text-sm font-semibold tabular-nums md:block">{(p.votes ?? 0).toLocaleString()}</span>
                      <div className="col-span-2 flex flex-wrap items-center justify-end gap-2 md:col-span-1 md:w-[200px]">
                        <Link to={`/staff/review/${p.id}`} className="text-xs font-semibold text-violet-700 hover:underline dark:text-violet-300">
                          Open
                        </Link>
                        <Link to={`/staff/products/${p.id}/integrity`} aria-label={`Vote integrity of ${p.name}`} title="Vote integrity" className="text-ink-400 hover:text-violet-600">
                          <ShieldAlert className="size-4" />
                        </Link>
                        {canTakeDown && isAdmin && (
                          <ActionButton tone="softRed" icon={<Ban />} onClick={() => setTarget(p)}>
                            Take down
                          </ActionButton>
                        )}
                        {p.status === 'taken_down' &&
                          (isAdmin ? (
                            <ActionButton
                              tone="soft"
                              icon={<RotateCcw />}
                              disabled={restore.isPending && restore.variables === p.id}
                              onClick={() => {
                                setRestoreError(null)
                                restore.mutate(p.id, {
                                  onSuccess: () => toast({ tone: 'success', title: `${p.name} restored`, body: 'It is live again.' }),
                                  onError: (e) => setRestoreError({ id: p.id, message: staffMessage(e) }),
                                })
                              }}
                            >
                              Restore
                            </ActionButton>
                          ) : (
                            <span title="Only admins can restore products" className="text-ink-300">
                              <Lock className="size-4" />
                            </span>
                          ))}
                        {restoreError?.id === p.id && (
                          <div className="w-full">
                            <InlineError>{restoreError.message}</InlineError>
                          </div>
                        )}
                      </div>
                    </motion.li>
                  )
                })}
              </AnimatePresence>
            </ul>
          </div>
          <LoadMore hasNextPage={!!list.hasNextPage} loading={list.isFetchingNextPage} failed={list.isFetchNextPageError} onClick={() => void list.fetchNextPage()} />
        </>
      )}

      {!isAdmin && <p className="mt-3 text-center text-xs text-ink-400">Takedowns and restores are admin-only.</p>}

      <ConfirmModal
        open={!!target}
        onClose={closeTakeDown}
        title={`Take down ${target?.name ?? 'product'}?`}
        description="It is removed from every public page immediately, including past days and leaderboards. You can restore it later."
        confirmLabel="Take down"
        tone="danger"
        pending={takeDown.isPending}
        error={takeDown.isError ? (staffFieldMessage(takeDown.error, 'reason') ?? staffMessage(takeDown.error)) : null}
        note={{ label: 'Reason', required: true, maxLength: 500, placeholder: 'Why is this product being removed?', reasons: TAKEDOWN_REASONS }}
        onConfirm={(reason) => {
          if (!target) return
          takeDown.mutate(
            { id: target.id, reason },
            {
              onSuccess: () => {
                toast({ tone: 'success', title: `${target.name} taken down`, body: 'Recorded in the audit log.' })
                closeTakeDown()
              },
            },
          )
        }}
      />
    </div>
  )
}
