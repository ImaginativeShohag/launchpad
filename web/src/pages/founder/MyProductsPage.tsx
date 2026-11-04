import { AnimatePresence, motion } from 'motion/react'
import { BarChart3, CalendarClock, CalendarPlus, Clock, ExternalLink, FileEdit, Hourglass, Lock, MessageSquareWarning, Pencil, Plus, Rocket, Trash2, Undo2, XCircle } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useDeleteDraft, useEditLiveProduct, useScheduleLaunch, useWithdrawProduct } from '@/api/hooks/useFounderMutations'
import { useMyProduct, useMyProducts } from '@/api/hooks/useMyProducts'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { isApiError } from '@/api/errors'
import { founderMessage } from '@/api/founderErrors'
import type { LinkKind } from '@/api/types'
import type { FounderProduct, ProductStatus } from '@/data/types'
import { formatDay, relativeDay, timeAgo, today } from '@/lib/date'
import { DESC_MAX, isHttpsUrl, newKey, type ProductLinkInput } from '@/lib/productForm'
import { useServerErrors } from '@/lib/useServerErrors'
import { Badge, Button, EmptyState, ErrorState, Input, LoadMore, Modal, Skeleton, Tabs, Textarea, cn, useToast, type TabItem } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'
import { StatusPipeline } from './components/StatusPipeline'
import { LaunchDayPicker } from './components/LaunchDayPicker'
import { ProductLinksEditor } from './components/SubmitFields'

/** The per-day launch cap is a setting the API enforces; this is only the hint on the calendar (the default). */
const LAUNCH_CAP_HINT = 2

type Group = 'all' | 'draft' | 'pending' | 'changes' | 'approved' | 'scheduled' | 'live' | 'taken_down' | 'rejected'

const GROUPS: { value: Group; label: string; status?: ProductStatus }[] = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Drafts', status: 'draft' },
  { value: 'pending', label: 'In review', status: 'pending' },
  { value: 'changes', label: 'Needs changes', status: 'changes_requested' },
  { value: 'approved', label: 'Pick a day', status: 'approved' },
  { value: 'scheduled', label: 'Scheduled', status: 'scheduled' },
  { value: 'live', label: 'Live & past', status: 'live' },
  { value: 'taken_down', label: 'Taken down', status: 'taken_down' },
  { value: 'rejected', label: 'Rejected', status: 'rejected' },
]

/** Order on the "All" tab: things needing action first. */
const PRIORITY: Record<ProductStatus, number> = { changes_requested: 0, approved: 1, draft: 2, scheduled: 3, pending: 4, live: 5, taken_down: 6, rejected: 7 }

const STATUS_BADGE: Record<ProductStatus, { tone: 'neutral' | 'brand' | 'green' | 'amber' | 'red' | 'blue' | 'violet'; label: string }> = {
  draft: { tone: 'neutral', label: 'Draft' },
  pending: { tone: 'blue', label: 'In review' },
  changes_requested: { tone: 'amber', label: 'Needs changes' },
  rejected: { tone: 'red', label: 'Not approved' },
  approved: { tone: 'green', label: 'Approved' },
  scheduled: { tone: 'violet', label: 'Scheduled' },
  live: { tone: 'brand', label: 'Live' },
  taken_down: { tone: 'red', label: 'Taken down' },
}

function useNow(ms = 30_000) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), ms)
    return () => clearInterval(t)
  }, [ms])
  return now
}

/** The launch starts at 00:00 UTC of its day. */
function countdown(launchDate: string, now: number) {
  const diff = Date.parse(`${launchDate}T00:00:00Z`) - now
  if (diff <= 0) return 'Launching now'
  const d = Math.floor(diff / 86_400_000)
  const h = Math.floor((diff % 86_400_000) / 3_600_000)
  const m = Math.floor((diff % 3_600_000) / 60_000)
  return d > 0 ? `Launches in ${d}d ${h}h` : `Launches in ${h}h ${m}m`
}

export default function MyProductsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const promptVerify = useVerifyPrompt()
  const [tab, setTab] = useState<Group>('all')
  const [deleting, setDeleting] = useState<FounderProduct | null>(null)
  const [withdrawing, setWithdrawing] = useState<FounderProduct | null>(null)
  const [picking, setPicking] = useState<FounderProduct | null>(null)
  const [editing, setEditing] = useState<FounderProduct | null>(null)
  const now = useNow()
  const del = useDeleteDraft()
  const withdraw = useWithdrawProduct()
  const schedule = useScheduleLaunch()

  // "All" is read once and drives the tab counts; a status tab asks the API for just that status.
  const all = useMyProducts()
  const status = GROUPS.find((g) => g.value === tab)?.status
  const filtered = useMyProducts(status, tab !== 'all')
  const active = tab === 'all' ? all : filtered

  const mine = all.items
  const counted = !all.hasNextPage
  const items: TabItem<Group>[] = GROUPS.map((g) => ({ value: g.value, label: g.label, count: counted ? mine.filter((p) => !g.status || p.status === g.status).length : undefined })).filter(
    (it) => it.value === 'all' || it.value === tab || !counted || (it.count ?? 0) > 0,
  )

  const list = useMemo(
    () => (tab === 'all' ? [...active.items].sort((a, b) => PRIORITY[a.status] - PRIORITY[b.status] || (b.launchDate ?? b.updatedAt).localeCompare(a.launchDate ?? a.updatedAt)) : active.items),
    [active.items, tab],
  )

  const needsAction = mine.filter((p) => p.status === 'approved' || p.status === 'changes_requested').length

  const fail = (title: string, e: unknown) => {
    if (isApiError(e) && e.code === 'email_unverified') return promptVerify()
    toast({ tone: 'error', title, body: founderMessage(e) })
  }

  if (all.isPending) {
    return (
      <div className="mx-auto max-w-3xl space-y-3">
        <Skeleton className="mb-6 h-10 w-56" />
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }
  if (all.isError) {
    return (
      <div className="mx-auto max-w-3xl">
        <h1 className="mb-6 text-3xl font-bold">My products</h1>
        <ErrorState error={all.error} onRetry={() => void all.refetch()} title="Could not load your products" />
      </div>
    )
  }
  if (mine.length === 0) {
    return (
      <div className="mx-auto max-w-3xl">
        <h1 className="mb-6 text-3xl font-bold">My products</h1>
        <EmptyState
          icon={<Rocket className="size-6" />}
          title="Launch your first product"
          body="Submit it once, our reviewers take a look, then you pick the day it goes live. Drafts save as you type."
          action={<Link to="/submit"><Button icon={<Plus className="size-4" />}>Submit a product</Button></Link>}
        />
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl">
      <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">My products</h1>
          <p className="mt-1 text-sm text-ink-500">
            {needsAction > 0 ? (
              <>
                <span className="font-semibold text-brand-600">{needsAction} need{needsAction === 1 ? 's' : ''} your attention</span> · {mine.length} total
              </>
            ) : (
              `${mine.length}${all.hasNextPage ? '+' : ''} products · everything is on track`
            )}
          </p>
        </div>
        <Link to="/submit">
          <Button icon={<Plus className="size-4" />}>New product</Button>
        </Link>
      </div>

      <Tabs items={items} value={tab} onChange={setTab} className="mb-5 w-full sm:w-auto" />

      {tab !== 'all' && active.isPending ? (
        <div className="space-y-3">
          <Skeleton className="h-40 w-full" />
        </div>
      ) : tab !== 'all' && active.isError ? (
        <ErrorState error={active.error} onRetry={() => void active.refetch()} title="Could not load this tab" />
      ) : (
        <>
          <motion.ul layout className="space-y-3">
            <AnimatePresence mode="popLayout" initial={false}>
              {list.map((p, i) => (
                <motion.li
                  key={p.id}
                  layout
                  initial={{ opacity: 0, y: 12 }}
                  animate={{ opacity: 1, y: 0, transition: { delay: i * 0.04 } }}
                  exit={{ opacity: 0, scale: 0.96, transition: { duration: 0.18 } }}
                >
                  <ProductCard
                    product={p}
                    now={now}
                    onDelete={() => setDeleting(p)}
                    onWithdraw={() => setWithdrawing(p)}
                    onPick={() => setPicking(p)}
                    onEditLive={() => setEditing(p)}
                    onContinue={() => navigate(`/submit/${p.id}`)}
                  />
                </motion.li>
              ))}
            </AnimatePresence>
          </motion.ul>
          {list.length === 0 && <p className="py-12 text-center text-sm text-ink-500">Nothing here right now.</p>}
          <LoadMore hasNextPage={!!active.hasNextPage} loading={active.isFetchingNextPage} failed={active.isFetchNextPageError} onClick={() => void active.fetchNextPage()} />
        </>
      )}

      <Modal
        open={!!deleting}
        onClose={() => setDeleting(null)}
        size="sm"
        title="Delete this draft?"
        description={deleting ? `“${deleting.name}” will be gone for good. This can’t be undone.` : undefined}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>Keep it</Button>
            <Button
              variant="danger"
              loading={del.isPending}
              icon={<Trash2 className="size-4" />}
              onClick={async () => {
                if (!deleting) return
                try {
                  await del.mutateAsync(deleting.id)
                  toast({ tone: 'success', title: 'Draft deleted' })
                  setDeleting(null)
                } catch (e) {
                  fail('Could not delete the draft', e)
                  setDeleting(null)
                }
              }}
            >
              Delete draft
            </Button>
          </>
        }
      >
        {deleting && (
          <div className="flex items-center gap-3 rounded-2xl bg-ink-50 p-3 dark:bg-white/5">
            <ProductLogo name={deleting.name} src={deleting.logoUrl} hue={deleting.hue} size="sm" />
            <div className="min-w-0">
              <p className="truncate text-sm font-bold">{deleting.name}</p>
              <p className="truncate text-xs text-ink-500">Last edited {timeAgo(deleting.updatedAt)}</p>
            </div>
          </div>
        )}
      </Modal>

      <Modal
        open={!!withdrawing}
        onClose={() => setWithdrawing(null)}
        size="sm"
        title={withdrawing?.status === 'scheduled' ? 'Unschedule this launch?' : 'Withdraw this product?'}
        description={
          withdrawing
            ? withdrawing.status === 'pending'
              ? `“${withdrawing.name}” leaves the review queue and goes back to your drafts. You can submit it again any time.`
              : `“${withdrawing.name}” goes back to a draft and loses its launch day. It needs a new review before it can launch.`
            : undefined
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setWithdrawing(null)}>Keep it</Button>
            <Button
              variant="danger"
              loading={withdraw.isPending}
              icon={<Undo2 className="size-4" />}
              onClick={async () => {
                if (!withdrawing) return
                try {
                  await withdraw.mutateAsync(withdrawing.id)
                  toast({ tone: 'success', title: withdrawing.status === 'scheduled' ? 'Launch unscheduled' : 'Withdrawn', body: 'It is a draft again.' })
                } catch (e) {
                  fail('Could not withdraw it', e)
                }
                setWithdrawing(null)
              }}
            >
              {withdrawing?.status === 'scheduled' ? 'Unschedule' : 'Withdraw'}
            </Button>
          </>
        }
      >
        {null}
      </Modal>

      {picking && (
        <LaunchDayPicker
          key={picking.id}
          open
          onClose={() => setPicking(null)}
          product={picking}
          myProducts={mine}
          cap={LAUNCH_CAP_HINT}
          onPick={async (date) => {
            await schedule.mutateAsync({ id: picking.id, date })
            toast({ tone: 'success', title: `${picking.name} is scheduled`, body: `Goes live ${formatDay(date)} at 00:00 UTC. We’ll remind you the day before.` })
          }}
        />
      )}

      {editing && <EditLiveModal key={editing.id} product={editing} onClose={() => setEditing(null)} />}
    </div>
  )
}

function ProductCard({
  product: p,
  now,
  onDelete,
  onWithdraw,
  onPick,
  onEditLive,
  onContinue,
}: {
  product: FounderProduct
  now: number
  onDelete: () => void
  onWithdraw: () => void
  onPick: () => void
  onEditLive: () => void
  onContinue: () => void
}) {
  const badge = STATUS_BADGE[p.status]
  const isToday = p.launchDate === today()
  return (
    <div className="group surface overflow-hidden transition-shadow hover:shadow-lift">
      <div className="flex items-start gap-3 p-4 sm:gap-4 sm:p-5">
        <motion.div whileHover={{ rotate: -8, scale: 1.06 }} transition={{ type: 'spring', stiffness: 400, damping: 14 }}>
          <ProductLogo name={p.name} src={p.logoUrl} hue={p.hue} />
        </motion.div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="truncate font-sans text-base font-bold tracking-normal">{p.name}</h3>
            <Badge tone={badge.tone}>
              {p.status === 'live' && isToday && <span className="relative mr-0.5 flex size-1.5"><span className="absolute inline-flex size-full animate-ping rounded-full bg-brand-500 opacity-75" /><span className="relative inline-flex size-1.5 rounded-full bg-brand-500" /></span>}
              {p.status === 'live' && isToday ? 'Live today' : badge.label}
            </Badge>
          </div>
          <p className="mt-0.5 line-clamp-1 text-sm text-ink-500">{p.tagline || <span className="italic">No pitch yet</span>}</p>
        </div>
      </div>

      <div className="px-4 pb-1 sm:px-5">
        <StatusPipeline status={p.status} />
      </div>

      <div className="border-t border-ink-100 bg-ink-50/60 px-4 py-3 sm:px-5 dark:border-white/5 dark:bg-white/2">
        <StatusFooter p={p} now={now} onDelete={onDelete} onWithdraw={onWithdraw} onPick={onPick} onEditLive={onEditLive} onContinue={onContinue} />
      </div>
    </div>
  )
}

function Note({ tone, icon, title, children }: { tone: 'amber' | 'red'; icon: React.ReactNode; title: string; children: React.ReactNode }) {
  return (
    <div className={cn('mb-3 flex gap-2.5 rounded-xl p-3 text-sm', tone === 'amber' ? 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-red-50 text-red-900 dark:bg-red-500/10 dark:text-red-200')}>
      <span className="mt-0.5 shrink-0">{icon}</span>
      <div>
        <p className="font-semibold">{title}</p>
        <p className="mt-0.5 opacity-90">{children}</p>
      </div>
    </div>
  )
}

/** The list does not carry the review history, so a card that needs the reviewer's note reads the single product (cached, one request). */
function useReviewNote(p: FounderProduct) {
  const needs = p.status === 'changes_requested' || p.status === 'rejected'
  return useMyProduct(p.id, needs).data?.reviewNote
}

function StatusFooter({
  p,
  now,
  onDelete,
  onWithdraw,
  onPick,
  onEditLive,
  onContinue,
}: {
  p: FounderProduct
  now: number
  onDelete: () => void
  onWithdraw: () => void
  onPick: () => void
  onEditLive: () => void
  onContinue: () => void
}) {
  const note = useReviewNote(p)
  const row = 'flex flex-wrap items-center justify-between gap-2'
  const meta = 'inline-flex items-center gap-1.5 text-xs text-ink-500'
  switch (p.status) {
    case 'draft':
      return (
        <div className={row}>
          <span className={meta}><FileEdit className="size-3.5" />Edited {timeAgo(p.updatedAt)}</span>
          <div className="flex gap-2">
            <Button size="sm" variant="ghost" onClick={onDelete} icon={<Trash2 className="size-3.5" />} className="hover:text-red-600">Delete</Button>
            <Button size="sm" onClick={onContinue} icon={<Pencil className="size-3.5" />}>Continue editing</Button>
          </div>
        </div>
      )
    case 'pending':
      return (
        <div className={row}>
          <span className="inline-flex items-center gap-2 text-sm font-medium text-sky-700 dark:text-sky-300">
            <motion.span animate={{ rotate: [0, 180, 180, 360] }} transition={{ repeat: Infinity, duration: 3, times: [0, 0.4, 0.6, 1] }}>
              <Hourglass className="size-4" />
            </motion.span>
            In review — usually within 24h
          </span>
          <div className="flex items-center gap-3">
            {p.submittedAt && <span className={meta}>Submitted {timeAgo(p.submittedAt)}</span>}
            <Button size="sm" variant="ghost" onClick={onWithdraw} icon={<Undo2 className="size-3.5" />}>Withdraw</Button>
          </div>
        </div>
      )
    case 'changes_requested':
      return (
        <>
          {note && <Note tone="amber" icon={<MessageSquareWarning className="size-4" />} title="Reviewer’s note">“{note}”</Note>}
          <div className={row}>
            <span className={meta}>Fix what’s asked and send it back. It goes to the back of the review queue.</span>
            <Button size="sm" onClick={onContinue} icon={<Pencil className="size-3.5" />}>Fix &amp; resubmit</Button>
          </div>
        </>
      )
    case 'rejected':
      return <Note tone="red" icon={<XCircle className="size-4" />} title="Not approved">{note ?? 'This submission doesn’t meet our guidelines.'}</Note>
    case 'approved':
      return (
        <div className={row}>
          <span className="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-300">Approved! Choose when it goes live.</span>
          <div className="flex items-center gap-2">
            <Button size="sm" variant="ghost" onClick={onWithdraw} icon={<Undo2 className="size-3.5" />}>Withdraw</Button>
            <motion.div animate={{ scale: [1, 1.04, 1] }} transition={{ repeat: Infinity, duration: 2.2 }}>
              <Button size="sm" onClick={onPick} icon={<CalendarPlus className="size-3.5" />}>Pick launch day</Button>
            </motion.div>
          </div>
        </div>
      )
    case 'scheduled':
      return (
        <div className={row}>
          <div>
            <p className="inline-flex items-center gap-1.5 text-sm font-semibold text-violet-700 dark:text-violet-300">
              <Clock className="size-4" />
              {p.launchDate ? countdown(p.launchDate, now) : 'Scheduled'}
            </p>
            {p.launchDate && <p className="text-xs text-ink-500">{formatDay(p.launchDate)} at 00:00 UTC · you can change it until then</p>}
          </div>
          <div className="flex items-center gap-2">
            <Button size="sm" variant="ghost" onClick={onWithdraw} icon={<Undo2 className="size-3.5" />}>Unschedule</Button>
            <Button size="sm" variant="outline" onClick={onPick} icon={<CalendarClock className="size-3.5" />}>Change day</Button>
          </div>
        </div>
      )
    case 'live':
      return (
        <div className={row}>
          <span className={meta}>{p.launchDate ? `Launched ${relativeDay(p.launchDate).toLowerCase()}` : 'Live'}</span>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="ghost" onClick={onEditLive} icon={<Pencil className="size-3.5" />}>Edit</Button>
            <Link to={`/p/${p.slug}`}>
              <Button size="sm" variant="outline" icon={<ExternalLink className="size-3.5" />}>Page</Button>
            </Link>
            <Link to={`/my/products/${p.id}/dashboard`}>
              <Button size="sm" icon={<BarChart3 className="size-3.5" />}>Dashboard</Button>
            </Link>
          </div>
        </div>
      )
    case 'taken_down':
      return (
        <>
          <Note tone="red" icon={<XCircle className="size-4" />} title="Taken down by staff">
            {p.takedownReason ? `“${p.takedownReason}”` : 'This product is no longer visible on the site.'} Contact support if you think this is a mistake.
          </Note>
          <div className={row}>
            <span className={meta}>Your numbers stay available.</span>
            <Link to={`/my/products/${p.id}/dashboard`}>
              <Button size="sm" variant="outline" icon={<BarChart3 className="size-3.5" />}>Dashboard</Button>
            </Link>
          </div>
        </>
      )
  }
}

/** After launch the API accepts only the live fields (`editable_fields`): description, website and links. */
function EditLiveModal({ product, onClose }: { product: FounderProduct; onClose: () => void }) {
  const toast = useToast()
  const edit = useEditLiveProduct()
  const full = useMyProduct(product.id)
  const server = useServerErrors()
  const loaded = full.data
  const [draft, setDraft] = useState<{ description: string; website: string; links: ProductLinkInput[] } | null>(null)

  // The list row has no links; fill the form once the single read arrives.
  const form = draft ?? (loaded ? { description: loaded.description, website: loaded.website, links: (loaded.links ?? []).map((l) => ({ key: newKey(), kind: l.kind as LinkKind, url: l.url })) } : null)
  const can = (f: string) => (loaded ?? product).editableFields.includes(f)

  const descOk = !form || (form.description.trim().length > 0 && form.description.length <= DESC_MAX)
  const urlOk = !form || !can('website_url') || isHttpsUrl(form.website.trim())
  const linksOk = !form || form.links.every((l) => !l.url.trim() || l.url.trim() === 'https://' || isHttpsUrl(l.url.trim()))
  const cleanLinks = (ls: { kind: string; url: string }[]) => ls.filter((l) => l.url.trim() && l.url.trim() !== 'https://').map((l) => [l.kind, l.url.trim()])
  const changed =
    !!form &&
    !!loaded &&
    (form.description.trim() !== loaded.description || form.website.trim() !== loaded.website || JSON.stringify(cleanLinks(form.links)) !== JSON.stringify(cleanLinks(loaded.links ?? [])))

  const locked = [
    { label: 'Name', value: product.name },
    { label: 'Pitch', value: product.tagline },
    { label: 'Logo, screenshots & topics', value: loaded ? `${loaded.screenshots?.length ?? 0} images · ${loaded.topics?.length ?? 0} topics` : '…' },
  ]

  const save = async () => {
    if (!form || !loaded) return
    const patch: Parameters<typeof edit.mutateAsync>[0]['patch'] = {}
    if (can('description') && form.description.trim() !== loaded.description) patch.description = form.description.trim()
    if (can('website_url') && form.website.trim() !== loaded.website) patch.website_url = form.website.trim()
    if (can('links') && JSON.stringify(cleanLinks(form.links)) !== JSON.stringify(cleanLinks(loaded.links ?? []))) {
      patch.links = form.links.filter((l) => l.url.trim() && l.url.trim() !== 'https://').map((l) => ({ kind: l.kind, url: l.url.trim() }))
    }
    try {
      await edit.mutateAsync({ id: product.id, patch })
      toast({ tone: 'success', title: 'Changes published', body: 'Your product page is updated.' })
      onClose()
    } catch (e) {
      if (isApiError(e) && e.status === 422) server.set(e)
      else toast({ tone: 'error', title: 'Could not save the changes', body: founderMessage(e) })
    }
  }

  const linkErrors: Record<number, string> = {}
  for (const [name, msgs] of Object.entries(server.fields)) {
    const m = /^links\.(\d+)\./.exec(name)
    if (m && msgs[0]) linkErrors[Number(m[1])] = msgs[0]
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Edit description & links"
      description="After launch only the description, website and links can change."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={edit.isPending} disabled={!changed || !descOk || !urlOk || !linksOk} onClick={() => void save()}>
            Save changes
          </Button>
        </>
      }
    >
      {full.isError ? (
        <ErrorState error={full.error} onRetry={() => void full.refetch()} title="Could not load this product" />
      ) : !form ? (
        <div className="space-y-3">
          <Skeleton className="h-32 w-full" />
          <Skeleton className="h-11 w-full" />
        </div>
      ) : (
        <div className="space-y-4">
          {can('description') && (
            <Textarea
              label="Description"
              rows={6}
              value={form.description}
              counter={{ value: form.description.length, max: DESC_MAX }}
              error={server.get('description') ?? (!descOk ? `Required, up to ${DESC_MAX} characters.` : undefined)}
              onChange={(e) => {
                server.clear('description')
                setDraft({ ...form, description: e.target.value })
              }}
            />
          )}
          {can('website_url') && (
            <Input
              label="Website"
              type="url"
              value={form.website}
              error={server.get('website_url') ?? (!urlOk ? 'Enter a full https link, like https://yourproduct.com' : undefined)}
              onChange={(e) => {
                server.clear('website_url')
                setDraft({ ...form, website: e.target.value })
              }}
            />
          )}
          {can('links') && (
            <div>
              <p className="mb-2 text-sm font-semibold">Links</p>
              <ProductLinksEditor links={form.links} onChange={(links) => setDraft({ ...form, links })} serverErrors={linkErrors} />
            </div>
          )}
          <div className="rounded-2xl border border-dashed border-ink-200 p-3 dark:border-white/10">
            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-ink-500">
              <Lock className="size-3.5" /> Locked after launch
            </p>
            <ul className="space-y-1.5">
              {locked.map((l) => (
                <li key={l.label} title="After launch only the description, website and links can change" className="flex cursor-not-allowed items-center justify-between gap-3 rounded-lg bg-ink-50 px-3 py-2 text-sm dark:bg-white/3">
                  <span className="text-ink-400">{l.label}</span>
                  <span className="truncate text-right text-ink-500">{l.value}</span>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-ink-400">People voted on what they saw. Keeping these fixed keeps launches fair.</p>
          </div>
        </div>
      )}
    </Modal>
  )
}
