import { AnimatePresence, Reorder, motion, useAnimationControls, useDragControls } from 'motion/react'
import { ArrowDown, ArrowUp, Check, GripVertical, ImagePlus, Link2, Loader2, MessageCircle, Plus, RefreshCw, Sparkles, Trash2, Upload, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTopics } from '@/api/hooks/useTopics'
import type { LinkKind } from '@/api/types'
import { MAX_PRODUCT_LINKS, MAX_SHOTS, MAX_TOPICS, newKey, PRODUCT_LINK_KINDS, isHttpsUrl, type ProductLinkInput } from '@/lib/productForm'
import type { FounderScreenshot, Topic } from '@/data/types'
import { Button, Skeleton, cn } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'
import { TopicChip } from '@/components/product/TopicChip'

/* ---------- Logo ---------- */

export function LogoPicker({
  name,
  hue,
  logoUrl,
  progress,
  error,
  disabled,
  onUpload,
  onRemove,
}: {
  name: string
  hue: number
  logoUrl?: string
  /** 0-100 while a file is going up, otherwise undefined. */
  progress?: number
  error?: string
  disabled?: boolean
  onUpload: (f: File) => void
  onRemove: () => void
}) {
  const fileRef = useRef<HTMLInputElement>(null)
  const busy = progress !== undefined
  return (
    <div className="grid gap-5 sm:grid-cols-[auto_1fr]">
      <div className="flex flex-col items-center gap-3">
        <motion.div key={logoUrl ?? 'none'} initial={{ scale: 0.7, rotate: -12 }} animate={{ scale: 1, rotate: 0 }} transition={{ type: 'spring', stiffness: 420, damping: 16 }}>
          <ProductLogo name={name || 'P'} hue={hue} src={logoUrl} size="xl" />
        </motion.div>
        <input
          ref={fileRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          aria-label="Logo file"
          className="hidden"
          onChange={(e) => {
            const f = e.target.files?.[0]
            if (f) onUpload(f)
            e.target.value = ''
          }}
        />
        {busy ? (
          <div className="w-32" role="progressbar" aria-label="Uploading logo" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100}>
            <div className="h-1.5 overflow-hidden rounded-full bg-ink-100 dark:bg-white/10">
              <div className="h-full rounded-full bg-brand-500 transition-[width]" style={{ width: `${progress}%` }} />
            </div>
            <p className="mt-1 text-center text-xs text-ink-500">Uploading {progress}%</p>
          </div>
        ) : logoUrl ? (
          <button type="button" disabled={disabled} onClick={onRemove} className="inline-flex cursor-pointer items-center gap-1 rounded-full bg-ink-100 px-3 py-1 text-xs font-medium text-ink-600 hover:bg-ink-200 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white/8 dark:text-ink-300">
            <X className="size-3 shrink-0" /> Remove logo
          </button>
        ) : (
          <button
            type="button"
            disabled={disabled}
            onClick={() => fileRef.current?.click()}
            className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-dashed border-ink-300 px-3 py-1 text-xs font-semibold text-ink-600 transition hover:border-brand-400 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/15 dark:text-ink-300"
          >
            <Upload className="size-3.5" /> Upload image
          </button>
        )}
        {logoUrl && !busy && (
          <button type="button" disabled={disabled} onClick={() => fileRef.current?.click()} className="cursor-pointer text-xs font-semibold text-brand-600 hover:underline disabled:cursor-not-allowed disabled:opacity-50">
            Replace
          </button>
        )}
      </div>
      <div className="space-y-2 text-sm text-ink-500">
        <p className="font-semibold text-ink-800 dark:text-ink-100">What works best</p>
        <ul className="list-disc space-y-1 pl-5">
          <li>A square image, at least 128 px on each side.</li>
          <li>JPEG, PNG or WebP, up to 2 MB. Animated images are not accepted.</li>
          <li>Reviewers need a logo before they look at a submission.</li>
        </ul>
        <AnimatePresence>{error && <motion.p initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="text-xs font-medium text-red-500">{error}</motion.p>}</AnimatePresence>
      </div>
    </div>
  )
}

/* ---------- Screenshots ---------- */

/** A file on its way up (or one that failed): not a screenshot yet. */
export interface UploadRow {
  key: string
  name: string
  pct: number
  error?: string
  /** A failed upload can be tried again; a file the browser refused cannot. */
  retryable?: boolean
}

function ShotItem({
  shot,
  caption,
  index,
  count,
  disabled,
  onMove,
  onCommit,
  onRemove,
  onCaption,
}: {
  shot: FounderScreenshot
  caption: string
  index: number
  count: number
  disabled?: boolean
  onMove: (dir: -1 | 1) => void
  onCommit: () => void
  onRemove: () => void
  onCaption: (l: string) => void
}) {
  const controls = useDragControls()
  return (
    <Reorder.Item
      value={shot}
      dragListener={false}
      dragControls={controls}
      onDragEnd={onCommit}
      initial={{ opacity: 0, scale: 0.95 }}
      animate={{ opacity: 1, scale: 1 }}
      exit={{ opacity: 0, scale: 0.9, transition: { duration: 0.15 } }}
      whileDrag={{ scale: 1.02, boxShadow: '0 16px 40px -12px rgb(16 16 40 / 0.3)' }}
      className="surface flex items-center gap-3 p-2 pr-3"
    >
      <button type="button" disabled={disabled} onPointerDown={(e) => controls.start(e)} aria-label="Drag to reorder" className="hidden cursor-grab touch-none p-1 text-ink-300 hover:text-ink-600 active:cursor-grabbing sm:block">
        <GripVertical className="size-4" />
      </button>
      <div className="relative w-24 shrink-0 sm:w-28">
        <img src={shot.url} alt="" className="aspect-[16/10] w-full rounded-xl object-cover ring-1 ring-black/5" />
        {index === 0 && <span className="absolute -top-1.5 -left-1.5 rounded-full bg-brand-500 px-1.5 py-0.5 text-[9px] font-bold text-white uppercase shadow">Cover</span>}
      </div>
      <input
        value={caption}
        maxLength={200}
        disabled={disabled}
        onChange={(e) => onCaption(e.target.value)}
        placeholder="Describe this screenshot"
        aria-label="Screenshot caption"
        className="min-w-0 flex-1 rounded-lg bg-transparent px-2 py-1 text-sm font-medium focus:bg-ink-50 focus:outline-none dark:focus:bg-white/5"
      />
      <div className="flex items-center">
        <button type="button" disabled={disabled || index === 0} onClick={() => onMove(-1)} aria-label="Move up" className="cursor-pointer rounded-lg p-1.5 text-ink-400 hover:bg-ink-100 hover:text-ink-700 disabled:cursor-default disabled:opacity-30 dark:hover:bg-white/5">
          <ArrowUp className="size-4" />
        </button>
        <button type="button" disabled={disabled || index === count - 1} onClick={() => onMove(1)} aria-label="Move down" className="cursor-pointer rounded-lg p-1.5 text-ink-400 hover:bg-ink-100 hover:text-ink-700 disabled:cursor-default disabled:opacity-30 dark:hover:bg-white/5">
          <ArrowDown className="size-4" />
        </button>
        <button type="button" disabled={disabled} onClick={onRemove} aria-label="Remove screenshot" className="cursor-pointer rounded-lg p-1.5 text-ink-400 hover:bg-red-50 hover:text-red-600 disabled:cursor-default disabled:opacity-30 dark:hover:bg-red-500/10">
          <Trash2 className="size-4" />
        </button>
      </div>
    </Reorder.Item>
  )
}

export function ScreenshotsField({
  shots,
  captions,
  uploads,
  error,
  disabled,
  onFiles,
  onOrder,
  onCommitOrder,
  onRemove,
  onCaption,
  onRetryUpload,
  onDismissUpload,
}: {
  shots: FounderScreenshot[]
  captions: Record<string, string>
  uploads: UploadRow[]
  error?: string
  disabled?: boolean
  onFiles: (files: File[]) => void
  /** The list in its new order while a drag is going on (not saved yet). */
  onOrder: (shots: FounderScreenshot[]) => void
  /** The order to save: after a drag ends or an arrow was pressed. */
  onCommitOrder: (ids: string[]) => void
  onRemove: (id: string) => void
  onCaption: (id: string, caption: string) => void
  onRetryUpload: (key: string) => void
  onDismissUpload: (key: string) => void
}) {
  const [over, setOver] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)
  const full = shots.length + uploads.filter((u) => !u.error).length >= MAX_SHOTS
  const blocked = full || !!disabled

  const move = (i: number, dir: -1 | 1) => {
    const next = [...shots]
    const [s] = next.splice(i, 1)
    next.splice(i + dir, 0, s)
    onOrder(next)
    onCommitOrder(next.map((x) => x.id))
  }

  return (
    <div className="space-y-3">
      <motion.div
        onDragOver={(e) => {
          e.preventDefault()
          if (!blocked) setOver(true)
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => {
          e.preventDefault()
          setOver(false)
          if (!blocked) onFiles(Array.from(e.dataTransfer.files))
        }}
        onClick={() => !blocked && fileRef.current?.click()}
        animate={{ scale: over ? 1.02 : 1 }}
        className={cn(
          'flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-4 py-8 text-center transition-colors',
          over ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10' : 'border-ink-200 hover:border-brand-300 hover:bg-ink-50 dark:border-white/10 dark:hover:bg-white/3',
          blocked && 'cursor-not-allowed opacity-50',
          error && 'border-red-300',
        )}
      >
        <motion.div animate={over ? { y: -6, rotate: -8 } : { y: [0, -4, 0] }} transition={over ? { type: 'spring' } : { repeat: Infinity, duration: 2.6 }} className="mb-2 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10">
          <ImagePlus className="size-6" />
        </motion.div>
        <p className="text-sm font-semibold">{full ? `That’s the maximum of ${MAX_SHOTS}` : over ? 'Drop to add' : 'Drag images here or tap to upload'}</p>
        <p className="mt-0.5 text-xs text-ink-500">JPEG, PNG or WebP up to 4 MB, at least 320 px wide · up to {MAX_SHOTS} images</p>
        <input
          ref={fileRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          multiple
          aria-label="Screenshot files"
          className="hidden"
          onClick={(e) => e.stopPropagation()}
          onChange={(e) => {
            onFiles(Array.from(e.target.files ?? []))
            e.target.value = ''
          }}
        />
      </motion.div>
      <AnimatePresence>{error && <motion.p initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="text-xs font-medium text-red-500">{error}</motion.p>}</AnimatePresence>

      {uploads.length > 0 && (
        <ul className="space-y-2" aria-label="Uploads">
          {uploads.map((u) => (
            <li key={u.key} className="surface flex items-center gap-3 p-3 text-sm">
              {u.error ? <X className="size-4 shrink-0 text-red-500" /> : <Loader2 className="size-4 shrink-0 animate-spin text-brand-500" />}
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{u.name}</p>
                {u.error ? (
                  <p className="text-xs font-medium text-red-500">{u.error}</p>
                ) : (
                  <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100 dark:bg-white/10" role="progressbar" aria-label={`Uploading ${u.name}`} aria-valuenow={u.pct} aria-valuemin={0} aria-valuemax={100}>
                    <div className="h-full rounded-full bg-brand-500 transition-[width]" style={{ width: `${u.pct}%` }} />
                  </div>
                )}
              </div>
              {u.error && (
                <>
                  {u.retryable && <button type="button" onClick={() => onRetryUpload(u.key)} aria-label={`Retry ${u.name}`} className="cursor-pointer rounded-lg p-1.5 text-ink-400 hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-white/5">
                    <RefreshCw className="size-4" />
                  </button>}
                  <button type="button" onClick={() => onDismissUpload(u.key)} aria-label={`Dismiss ${u.name}`} className="cursor-pointer rounded-lg p-1.5 text-ink-400 hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-white/5">
                    <X className="size-4" />
                  </button>
                </>
              )}
            </li>
          ))}
        </ul>
      )}

      {shots.length > 0 && (
        <>
          <p className="text-xs text-ink-500">The first image is your cover. Drag or use the arrows to reorder.</p>
          <Reorder.Group axis="y" values={shots} onReorder={onOrder} className="space-y-2">
            <AnimatePresence initial={false}>
              {shots.map((s, i) => (
                <ShotItem
                  key={s.id}
                  shot={s}
                  caption={captions[s.id] ?? s.altText}
                  index={i}
                  count={shots.length}
                  disabled={disabled}
                  onMove={(d) => move(i, d)}
                  onCommit={() => onCommitOrder(shots.map((x) => x.id))}
                  onRemove={() => onRemove(s.id)}
                  onCaption={(c) => onCaption(s.id, c)}
                />
              ))}
            </AnimatePresence>
          </Reorder.Group>
        </>
      )}
    </div>
  )
}

/* ---------- Topics ---------- */

function TopicButton({ id, name, emoji, selected, locked, onToggle }: { id: string; name: string; emoji: string; selected: boolean; locked: boolean; onToggle: (id: string) => boolean }) {
  const controls = useAnimationControls()
  return (
    <motion.button
      type="button"
      animate={controls}
      whileTap={{ scale: 0.92 }}
      aria-pressed={selected}
      aria-disabled={locked}
      onClick={() => {
        if (!onToggle(id)) controls.start({ x: [0, -7, 7, -5, 5, 0], transition: { duration: 0.4 } })
      }}
      className={cn(
        'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-semibold transition-colors',
        selected
          ? 'border-transparent bg-ink-900 text-white dark:bg-white dark:text-ink-900'
          : locked
            ? 'cursor-not-allowed border-ink-100 text-ink-300 dark:border-white/5 dark:text-ink-600'
            : 'border-ink-200 bg-white text-ink-700 hover:border-brand-300 hover:text-brand-600 dark:border-white/10 dark:bg-ink-900 dark:text-ink-200',
      )}
    >
      <span>{emoji}</span>
      {name}
      <AnimatePresence>
        {selected && (
          <motion.span initial={{ width: 0, opacity: 0 }} animate={{ width: 'auto', opacity: 1 }} exit={{ width: 0, opacity: 0 }} className="overflow-hidden">
            <Check className="size-3.5" strokeWidth={3} />
          </motion.span>
        )}
      </AnimatePresence>
    </motion.button>
  )
}

export function TopicPicker({ value, error, disabled, onChange }: { value: string[]; error?: string; disabled?: boolean; onChange: (ids: string[]) => void }) {
  const [hint, setHint] = useState(false)
  const topics = useTopics()
  const toggle = (id: string) => {
    if (disabled) return false
    if (value.includes(id)) {
      onChange(value.filter((x) => x !== id))
      setHint(false)
      return true
    }
    if (value.length >= MAX_TOPICS) {
      setHint(true)
      return false
    }
    onChange([...value, id])
    return true
  }
  return (
    <div>
      <div className="mb-2 flex items-baseline justify-between">
        <p className="text-sm font-semibold">Topics</p>
        <span className="flex gap-1">
          {Array.from({ length: MAX_TOPICS }, (_, i) => (
            <motion.span key={i} animate={{ scale: i < value.length ? 1 : 0.7 }} className={cn('size-2 rounded-full', i < value.length ? 'bg-brand-500' : 'bg-ink-200 dark:bg-white/10')} />
          ))}
        </span>
      </div>
      {topics.isPending ? (
        <div className="flex flex-wrap gap-2">
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-9 w-24 rounded-full" />
          ))}
        </div>
      ) : topics.isError ? (
        <p className="text-sm text-red-600 dark:text-red-400">
          Could not load the topics.{' '}
          <button type="button" onClick={() => void topics.refetch()} className="cursor-pointer font-semibold underline">
            Retry
          </button>
        </p>
      ) : (
        <div className="flex flex-wrap gap-2">
          {topics.data.map((t) => (
            <TopicButton key={t.id} id={t.id} name={t.name} emoji={t.emoji} selected={value.includes(t.id)} locked={!value.includes(t.id) && value.length >= MAX_TOPICS} onToggle={toggle} />
          ))}
        </div>
      )}
      <AnimatePresence mode="wait">
        {hint && value.length >= MAX_TOPICS ? (
          <motion.p key="max" initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="mt-2 text-xs font-medium text-amber-600">
            You can pick up to three topics. Remove one to choose another.
          </motion.p>
        ) : error ? (
          <motion.p key="err" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="mt-2 text-xs font-medium text-red-500">{error}</motion.p>
        ) : (
          <motion.p key="h" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="mt-2 text-xs text-ink-500">Pick up to three that best describe what it does. People browse and follow topics.</motion.p>
        )}
      </AnimatePresence>
    </div>
  )
}

/* ---------- Product links (kind + URL) ---------- */

const linkField =
  'h-10 w-full rounded-xl border border-ink-200 bg-white px-3 text-sm placeholder:text-ink-400 transition focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-brand-500/15 disabled:opacity-60 dark:border-white/10 dark:bg-ink-950'

/** Editable list of a product's extra links. `serverErrors` are the API's messages by row. */
export function ProductLinksEditor({ links, onChange, disabled, serverErrors }: { links: ProductLinkInput[]; onChange: (links: ProductLinkInput[]) => void; disabled?: boolean; serverErrors?: Record<number, string> }) {
  const patch = (i: number, p: Partial<ProductLinkInput>) => onChange(links.map((l, j) => (j === i ? { ...l, ...p } : l)))
  return (
    <div className="space-y-2">
      {links.map((l, i) => {
        const url = l.url.trim()
        const err = serverErrors?.[i] ?? (url && url !== 'https://' && !isHttpsUrl(url) ? 'Use a full URL starting with https://' : undefined)
        return (
          <div key={l.key}>
            <div className="flex items-start gap-2">
              <span className="mt-2.5 hidden text-ink-400 sm:block">
                <Link2 className="size-4" />
              </span>
              <div className="grid flex-1 gap-2 sm:grid-cols-[9rem_1fr]">
                <select aria-label={`Link ${i + 1} type`} disabled={disabled} value={l.kind} onChange={(e) => patch(i, { kind: e.target.value as LinkKind })} className={linkField}>
                  {PRODUCT_LINK_KINDS.map((k) => (
                    <option key={k.value} value={k.value}>
                      {k.label}
                    </option>
                  ))}
                </select>
                <input
                  aria-label={`Link ${i + 1} URL`}
                  aria-invalid={!!err}
                  placeholder="https://"
                  inputMode="url"
                  disabled={disabled}
                  value={l.url}
                  onChange={(e) => patch(i, { url: e.target.value })}
                  className={cn(linkField, err && 'border-red-400')}
                />
              </div>
              <button
                type="button"
                disabled={disabled}
                aria-label={`Remove link ${i + 1}`}
                onClick={() => onChange(links.filter((_, j) => j !== i))}
                className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-xl text-ink-400 transition-colors hover:bg-red-50 hover:text-red-500 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-red-500/10"
              >
                <Trash2 className="size-4" />
              </button>
            </div>
            {err && <p className="mt-1 text-xs font-medium text-red-500 sm:ml-6">{err}</p>}
          </div>
        )
      })}
      {links.length < MAX_PRODUCT_LINKS ? (
        <Button type="button" variant="ghost" size="sm" disabled={disabled} icon={<Plus className="size-4" />} onClick={() => onChange([...links, { key: newKey(), kind: 'github', url: 'https://' }])}>
          Add link
        </Button>
      ) : (
        <p className="text-xs text-ink-400">You can add up to {MAX_PRODUCT_LINKS} links.</p>
      )}
    </div>
  )
}

/* ---------- Preview ---------- */

export interface PreviewData {
  name: string
  tagline: string
  description: string
  website: string
  hue: number
  logoUrl?: string
  shots: { id: string; url: string; label: string }[]
  topics: Topic[]
}

/** Mirrors the public list row and product header, without links. */
export function PreviewCard({ d }: { d: PreviewData }) {
  const [shot, setShot] = useState(0)
  const current = d.shots[Math.min(shot, d.shots.length - 1)]
  return (
    <div className="space-y-4">
      <div>
        <p className="mb-2 text-xs font-semibold tracking-wide text-ink-400 uppercase">In today’s list</p>
        <div className="group surface flex items-center gap-3 p-3 sm:gap-4 sm:p-4">
          <div className="transition-transform duration-300 group-hover:-rotate-6 group-hover:scale-105">
            <ProductLogo name={d.name || 'P'} hue={d.hue} src={d.logoUrl} size="md" />
          </div>
          <div className="min-w-0 flex-1">
            <h3 className="truncate font-sans text-[15px] font-bold tracking-normal">{d.name || 'Your product name'}</h3>
            <p className="mt-0.5 line-clamp-1 text-sm text-ink-500 dark:text-ink-400">{d.tagline || 'Your one-line pitch'}</p>
            <div className="mt-2 flex flex-wrap items-center gap-1.5">
              <span className="inline-flex items-center gap-1 text-xs text-ink-400">
                <MessageCircle className="size-3.5" />0
              </span>
              <span className="text-ink-300">·</span>
              {d.topics.map((t) => (
                <TopicChip key={t.id} topic={t} asLink={false} />
              ))}
            </div>
          </div>
          <div className="flex h-16 w-14 shrink-0 flex-col items-center justify-center rounded-2xl border border-ink-200 text-sm font-bold text-ink-700 dark:border-white/10 dark:text-ink-200">
            <ArrowUp className="size-5" strokeWidth={2.75} />0
          </div>
        </div>
      </div>
      <div>
        <p className="mb-2 text-xs font-semibold tracking-wide text-ink-400 uppercase">On your product page</p>
        <div className="surface overflow-hidden p-4 sm:p-5">
          <div className="flex items-start gap-4">
            <ProductLogo name={d.name || 'P'} hue={d.hue} src={d.logoUrl} size="lg" />
            <div className="min-w-0">
              <h2 className="text-2xl font-bold">{d.name || 'Your product name'}</h2>
              <p className="mt-1 text-ink-500">{d.tagline}</p>
              {d.website && <p className="mt-1 truncate text-sm font-medium text-brand-600">{d.website.replace(/^https?:\/\//, '')}</p>}
            </div>
          </div>
          {current && (
            <div className="mt-4">
              <AnimatePresence mode="wait">
                <motion.div key={current.id} initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }} transition={{ duration: 0.2 }}>
                  <img src={current.url} alt={current.label} className="aspect-[16/10] w-full rounded-2xl object-cover ring-1 ring-black/5" />
                </motion.div>
              </AnimatePresence>
              {d.shots.length > 1 && (
                <div className="mt-2 flex justify-center gap-1.5">
                  {d.shots.map((s, i) => (
                    <button key={s.id} type="button" onClick={() => setShot(i)} aria-label={`Show screenshot ${i + 1}`} className={cn('h-1.5 cursor-pointer rounded-full transition-all', i === shot ? 'w-6 bg-brand-500' : 'w-1.5 bg-ink-200 dark:bg-white/15')} />
                  ))}
                </div>
              )}
            </div>
          )}
          <p className="mt-4 text-sm leading-relaxed whitespace-pre-line text-ink-700 dark:text-ink-300">{d.description}</p>
        </div>
      </div>
      <p className="flex items-center gap-1.5 text-xs text-ink-400">
        <Sparkles className="size-3.5" /> Votes and comments start at zero on launch day.
      </p>
    </div>
  )
}
