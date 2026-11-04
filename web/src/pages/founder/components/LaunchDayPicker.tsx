import { AnimatePresence, motion } from 'motion/react'
import { CalendarCheck, ChevronLeft, ChevronRight, Info, Moon } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { firstFieldError } from '@/api/errors'
import { founderMessage } from '@/api/founderErrors'
import type { FounderProduct } from '@/data/types'
import { addDays, formatDay, parseISODate, today } from '@/lib/date'
import { Button, Modal, cn } from '@/components/ui'
import { ProductLogo } from '@/components/product/ProductLogo'

/**
 * How far ahead the API lets a launch be scheduled (`launch.max_days_ahead`, default 60, up to 365). Only a hint that disables the
 * days beyond it; the API decides and its message is shown. TODO: read it from the backend's config endpoint once there is one.
 */
const WINDOW_DAYS = 60
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

interface Props {
  open: boolean
  onClose: () => void
  product: FounderProduct
  /** The maker's loaded products, used for the per-day dots and the cap hint (the server decides). */
  myProducts: FounderProduct[]
  cap: number
  /** Saves the day. A refusal (the cap, a day outside the window) is thrown and shown under the calendar. */
  onPick: (date: string) => Promise<void>
}

/** Calendar for choosing a launch day: past and today disabled, the per-day cap hinted (the API enforces it and its message is shown). */
export function LaunchDayPicker({ open, onClose, product, myProducts, cap, onPick }: Props) {
  const [selected, setSelected] = useState<string | null>(product.launchDate ?? null)
  const [error, setError] = useState<string | null>(null)
  const [hover, setHover] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const t = today()

  const lastDay = addDays(t, WINDOW_DAYS)
  const monthOf = (d: string) => `${d.slice(0, 8)}01`
  const [view, setView] = useState(monthOf(selected && selected > t ? selected : t))
  const [focusDay, setFocusDay] = useState<string | null>(null)
  const moveFocus = useRef(false)
  const grid = useRef<HTMLDivElement>(null)

  // The month as Monday-first weeks: blanks before the 1st and after the last day keep the columns.
  const cells = useMemo(() => {
    const first = parseISODate(view)
    const lead = (first.getDay() + 6) % 7
    const count = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate()
    const days = Array.from({ length: count }, (_, i) => addDays(view, i))
    const all: (string | null)[] = [...Array.from({ length: lead }, () => null), ...days]
    while (all.length % 7) all.push(null)
    return all
  }, [view])
  const prevMonth = addDays(view, -1).slice(0, 8) + '01'
  const nextMonth = addDays(view, 32).slice(0, 8) + '01'
  const canPrev = view > monthOf(t)
  const canNext = nextMonth <= lastDay

  // The one day in the Tab order: the keyboard cursor, else the chosen day, else the first bookable day, of the month on screen.
  const inView = (d: string | null) => !!d && monthOf(d) === view
  const tabDay =
    (inView(focusDay) && focusDay) ||
    (inView(selected) && selected) ||
    cells.find((d): d is string => !!d && d > t && d <= lastDay) ||
    cells.find((d): d is string => !!d) ||
    null

  useEffect(() => {
    if (!moveFocus.current) return
    moveFocus.current = false
    grid.current?.querySelector<HTMLElement>(`[data-day="${focusDay}"]`)?.focus()
  }, [focusDay, view])

  const goTo = (d: string) => {
    const next = d < t ? t : d > lastDay ? lastDay : d
    moveFocus.current = true
    setFocusDay(next)
    setView(monthOf(next))
  }
  const onKey = (e: KeyboardEvent, d: string) => {
    const step: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, PageUp: -30, PageDown: 30 }
    if (e.key in step) {
      e.preventDefault()
      goTo(addDays(d, step[e.key]))
    } else if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault()
      goTo(e.key === 'Home' ? view : addDays(nextMonth, -1))
    }
  }

  const launchesByDay = useMemo(() => {
    const m = new Map<string, FounderProduct[]>()
    for (const p of myProducts) {
      if (p.id === product.id || !p.launchDate) continue
      if (p.status !== 'scheduled' && p.status !== 'live') continue
      m.set(p.launchDate, [...(m.get(p.launchDate) ?? []), p])
    }
    return m
  }, [myProducts, product.id])

  const confirm = async () => {
    if (!selected) return
    setSaving(true)
    setError(null)
    try {
      await onPick(selected)
      onClose()
    } catch (e) {
      // 422 on `launch_date` carries the API's reason (the per-day cap, or a day outside the window).
      setError(firstFieldError(e, 'launch_date') ?? founderMessage(e))
    } finally {
      setSaving(false)
    }
  }

  const monthLabel = parseISODate(view).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={product.launchDate ? 'Change launch day' : 'Pick your launch day'}
      description="Your product goes live automatically at midnight on the day you choose. You can change it any time before then."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button loading={saving} disabled={!selected || selected === product.launchDate} onClick={() => void confirm()} icon={<CalendarCheck className="size-4" />}>
            {selected ? `Launch ${formatDay(selected, { weekday: 'short', month: 'short', day: 'numeric' })}` : 'Choose a day'}
          </Button>
        </>
      }
    >
      <div className="flex items-center gap-3 rounded-2xl bg-ink-50 p-3 dark:bg-white/5">
        <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} size="sm" />
        <div className="min-w-0">
          <p className="truncate text-sm font-bold">{product.name}</p>
          <p className="truncate text-xs text-ink-500">{product.tagline}</p>
        </div>
      </div>

      <div className="mt-4 mb-2 flex items-center justify-between">
        <p className="text-sm font-semibold" aria-live="polite">{monthLabel}</p>
        <div className="flex gap-1">
          <button type="button" disabled={!canPrev} onClick={() => setView(prevMonth)} aria-label="Previous month" className="flex size-8 cursor-pointer items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/5">
            <ChevronLeft className="size-4" />
          </button>
          <button type="button" disabled={!canNext} onClick={() => setView(nextMonth)} aria-label="Next month" className="flex size-8 cursor-pointer items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/5">
            <ChevronRight className="size-4" />
          </button>
        </div>
      </div>
      <div ref={grid} className="grid grid-cols-7 gap-1 text-center">
        {WEEKDAYS.map((w) => (
          <span key={w} className="pb-1 text-[11px] font-semibold text-ink-400 uppercase">{w}</span>
        ))}
        {cells.map((d, i) => {
          if (!d) return <span key={`blank-${i}`} aria-hidden />
          const isPast = d < t
          const isToday = d === t
          const beyond = d > lastDay
          const mine = launchesByDay.get(d) ?? []
          const full = mine.length >= cap
          const disabled = isPast || isToday || beyond || full
          const isSel = selected === d
          const isCurrent = product.launchDate === d
          const dayNum = parseISODate(d).getDate()
          const reason = isPast
            ? 'in the past'
            : isToday
              ? 'launches start at midnight, so today is too late'
              : beyond
                ? `more than ${WINDOW_DAYS} days ahead`
                : full
                  ? `you already have ${cap} launches this day`
                  : null
          const tip = reason
            ? `Unavailable: ${reason}`
            : mine.length
              ? `${mine.map((p) => p.name).join(', ')} launches this day`
              : null
          return (
            <motion.button
              key={d}
              type="button"
              data-day={d}
              tabIndex={d === tabDay ? 0 : -1}
              initial={{ opacity: 0, y: 6 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: Math.min(i * 0.008, 0.3) }}
              whileTap={disabled ? undefined : { scale: 0.9 }}
              aria-disabled={disabled}
              aria-pressed={isSel}
              aria-label={`${formatDay(d, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}${reason ? `, unavailable: ${reason}` : ''}`}
              title={tip ?? undefined}
              onMouseEnter={() => setHover(d)}
              onMouseLeave={() => setHover((h) => (h === d ? null : h))}
              onFocus={() => {
                setHover(d)
                setFocusDay(d)
              }}
              onKeyDown={(e) => onKey(e, d)}
              onClick={() => {
                if (disabled) {
                  setHover(d)
                  return
                }
                setError(null)
                setSelected(d)
              }}
              className={cn(
                'relative flex aspect-square flex-col items-center justify-center rounded-xl text-sm font-semibold tabular-nums transition-colors',
                (isPast || beyond) && 'cursor-not-allowed text-ink-300 dark:text-ink-700',
                (isToday || full) && !isPast && !beyond && 'cursor-not-allowed bg-ink-50 text-ink-400 line-through decoration-ink-300 dark:bg-white/3',
                !disabled && !isSel && 'cursor-pointer hover:bg-brand-50 hover:text-brand-700 dark:hover:bg-brand-500/10',
                isSel && 'text-white',
                isCurrent && !isSel && 'ring-2 ring-brand-300 ring-inset',
              )}
            >
              {isSel && <motion.span layoutId="day-sel" className="absolute inset-0 rounded-xl bg-gradient-to-b from-brand-500 to-brand-600 shadow-glow" transition={{ type: 'spring', stiffness: 500, damping: 34 }} />}
              <span className="relative">{dayNum}</span>
              {isToday && <span className="relative -mt-0.5 text-[8px] font-bold text-brand-500 uppercase no-underline">today</span>}
              {mine.length > 0 && (
                <span className="relative mt-0.5 flex gap-0.5">
                  {mine.map((p) => (
                    <span key={p.id} className="size-1.5 rounded-full" style={{ background: isSel ? '#fff' : `hsl(${p.hue} 80% 55%)` }} />
                  ))}
                </span>
              )}
            </motion.button>
          )
        })}
      </div>

      <div className="mt-3 min-h-10">
        <AnimatePresence mode="wait">
          {error ? (
            <motion.p key="err" role="alert" initial={{ opacity: 0, x: -6 }} animate={{ opacity: 1, x: [0, -6, 6, -3, 0] }} exit={{ opacity: 0 }} className="rounded-xl bg-red-50 px-3 py-2 text-sm font-medium text-red-600 dark:bg-red-500/10">
              {error}
            </motion.p>
          ) : hover && (hover === t || (launchesByDay.get(hover)?.length ?? 0) > 0) ? (
            <motion.p key={hover} initial={{ opacity: 0, y: 4 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
              <Info className="mt-0.5 size-4 shrink-0" />
              {hover === t
                ? 'Launches start at midnight, so today is no longer available.'
                : (launchesByDay.get(hover)?.length ?? 0) >= cap
                  ? `You already have ${cap} launches this day (limit ${cap}).`
                  : `${launchesByDay.get(hover)!.map((p) => p.name).join(', ')} also launches this day. You can add one more.`}
            </motion.p>
          ) : (
            <motion.p key="legend" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-500">
              <span className="inline-flex items-center gap-1.5"><span className="size-1.5 rounded-full bg-brand-500" /> Your other launches</span>
              <span className="inline-flex items-center gap-1.5"><Moon className="size-3" /> Goes live at 00:00 UTC</span>
              <span>Max {cap} launches per day, up to {WINDOW_DAYS} days ahead</span>
            </motion.p>
          )}
        </AnimatePresence>
      </div>
    </Modal>
  )
}
