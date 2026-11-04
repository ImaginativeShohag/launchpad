import { AnimatePresence, motion } from 'motion/react'
import { useMemo, useRef, useState } from 'react'
import { cn } from '@/components/ui'

const W = 600
const H = 220
const PAD = { l: 34, r: 12, t: 14, b: 26 }

function hourLabel(h: number) {
  const hh = h % 24
  if (hh === 0) return '12am'
  if (hh === 12) return '12pm'
  return hh < 12 ? `${hh}am` : `${hh - 12}pm`
}

function niceMax(v: number) {
  if (v <= 4) return 4
  const pow = Math.pow(10, Math.floor(Math.log10(v)))
  const n = v / pow
  const step = n <= 2 ? 2 : n <= 5 ? 5 : 10
  return step * pow
}

/** Catmull-Rom → cubic bezier for a smooth line through the points. */
function smoothPath(pts: [number, number][]) {
  if (pts.length === 0) return ''
  if (pts.length === 1) return `M${pts[0][0]},${pts[0][1]}`
  let d = `M${pts[0][0]},${pts[0][1]}`
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] ?? pts[i]
    const p1 = pts[i]
    const p2 = pts[i + 1]
    const p3 = pts[i + 2] ?? p2
    const c1x = p1[0] + (p2[0] - p0[0]) / 6
    const c1y = p1[1] + (p2[1] - p0[1]) / 6
    const c2x = p2[0] - (p3[0] - p1[0]) / 6
    const c2y = p2[1] - (p3[1] - p1[1]) / 6
    d += ` C${c1x},${Math.min(c1y, H - PAD.b)} ${c2x},${Math.min(c2y, H - PAD.b)} ${p2[0]},${p2[1]}`
  }
  return d
}

/** Votes per slot (an hour of the launch day by default, or a day), with a hover tooltip and live "now" pulse. */
export function VotesChart({
  timeline,
  live,
  slots = 24,
  tickAt = hourLabel,
  ticks = [0, 6, 12, 18, 23],
  tipTitle = (i) => `${hourLabel(i)} – ${hourLabel(i + 1)}`,
  unit = 'votes',
}: {
  timeline: number[]
  live?: boolean
  /** How many slots the x axis spans (24 for a day by the hour). */
  slots?: number
  tickAt?: (i: number) => string
  ticks?: number[]
  tipTitle?: (i: number) => string
  unit?: string
}) {
  const ref = useRef<HTMLDivElement>(null)
  const [hover, setHover] = useState<number | null>(null)
  const hours = Math.max(2, slots)
  const max = niceMax(Math.max(1, ...timeline))
  const x = (i: number) => PAD.l + (i / (hours - 1)) * (W - PAD.l - PAD.r)
  const y = (v: number) => PAD.t + (1 - v / max) * (H - PAD.t - PAD.b)
  const pts = timeline.map((v, i) => [x(i), y(v)] as [number, number])
  const line = smoothPath(pts)
  const area = pts.length > 1 ? `${line} L${pts[pts.length - 1][0]},${H - PAD.b} L${pts[0][0]},${H - PAD.b} Z` : ''
  const last = pts[pts.length - 1]
  const cumulative = useMemo(() => timeline.reduce<number[]>((acc, v, i) => [...acc, (acc[i - 1] ?? 0) + v], []), [timeline])

  const onMove = (e: React.PointerEvent) => {
    const r = ref.current?.getBoundingClientRect()
    if (!r || !timeline.length) return
    const sx = ((e.clientX - r.left) / r.width) * W
    const i = Math.round(((sx - PAD.l) / (W - PAD.l - PAD.r)) * (hours - 1))
    setHover(Math.max(0, Math.min(timeline.length - 1, i)))
  }

  return (
    <div ref={ref} className="relative touch-pan-y select-none" onPointerMove={onMove} onPointerDown={onMove} onPointerLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full overflow-visible" role="img" aria-label={`${unit} chart`}>
        <defs>
          <linearGradient id="vc-fill" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#ff5112" stopOpacity="0.35" />
            <stop offset="100%" stopColor="#ff5112" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="vc-stroke" x1="0" x2="1">
            <stop offset="0%" stopColor="#ff5112" />
            <stop offset="60%" stopColor="#ec4899" />
            <stop offset="100%" stopColor="#8b5cf6" />
          </linearGradient>
        </defs>
        {[0, 0.25, 0.5, 0.75, 1].map((f) => (
          <g key={f}>
            <line x1={PAD.l} x2={W - PAD.r} y1={y(max * f)} y2={y(max * f)} className="stroke-ink-100 dark:stroke-white/6" strokeDasharray={f === 0 ? undefined : '3 4'} />
            <text x={PAD.l - 8} y={y(max * f) + 4} textAnchor="end" className="fill-ink-400 text-[10px] tabular-nums">
              {Math.round(max * f)}
            </text>
          </g>
        ))}
        {ticks.map((h) => (
          <text key={h} x={x(h)} y={H - 6} textAnchor="middle" className="fill-ink-400 text-[10px]">
            {tickAt(h)}
          </text>
        ))}
        {area && <motion.path d={area} fill="url(#vc-fill)" initial={{ opacity: 0 }} animate={{ opacity: 1, d: area }} transition={{ duration: 0.8 }} />}
        {line && (
          <motion.path
            d={line}
            fill="none"
            stroke="url(#vc-stroke)"
            strokeWidth={3}
            strokeLinecap="round"
            initial={{ pathLength: 0 }}
            animate={{ pathLength: 1, d: line }}
            transition={{ pathLength: { duration: 1.4, ease: 'easeInOut' }, d: { duration: 0.5 } }}
          />
        )}
        {live && last && (
          <g>
            <motion.circle cx={last[0]} cy={last[1]} r={10} fill="#ff5112" animate={{ r: [5, 16], opacity: [0.45, 0] }} transition={{ repeat: Infinity, duration: 1.6 }} />
            <motion.circle cx={last[0]} cy={last[1]} r={5} fill="#ff5112" stroke="white" strokeWidth={2} animate={{ cx: last[0], cy: last[1] }} />
          </g>
        )}
        {hover !== null && pts[hover] && (
          <g>
            <line x1={pts[hover][0]} x2={pts[hover][0]} y1={PAD.t} y2={H - PAD.b} className="stroke-ink-300 dark:stroke-white/20" strokeDasharray="3 3" />
            <circle cx={pts[hover][0]} cy={pts[hover][1]} r={5} fill="white" stroke="#ec4899" strokeWidth={3} />
          </g>
        )}
      </svg>
      <AnimatePresence>
        {hover !== null && pts[hover] && (
          <motion.div
            initial={{ opacity: 0, y: 4, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1, left: `${(pts[hover][0] / W) * 100}%` }}
            exit={{ opacity: 0 }}
            transition={{ type: 'spring', stiffness: 600, damping: 40 }}
            className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-xl bg-ink-900 px-3 py-2 text-xs text-white shadow-lift dark:bg-white dark:text-ink-900"
          >
            <p className="font-semibold">{tipTitle(hover)}</p>
            <p className="mt-0.5 tabular-nums"><b>{timeline[hover]}</b> {unit} · {cumulative[hover]} total</p>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  )
}

export interface BarDatum {
  label: string
  value: number
  color: string
  icon?: React.ReactNode
}

/** Horizontal bars that grow in, staggered. */
export function ReferrerBars({ data }: { data: BarDatum[] }) {
  const total = data.reduce((s, d) => s + d.value, 0) || 1
  const max = Math.max(1, ...data.map((d) => d.value))
  return (
    <ul className="space-y-3">
      {data.map((d, i) => (
        <li key={d.label} className="group">
          <div className="mb-1 flex items-center justify-between text-sm">
            <span className="flex items-center gap-2 font-medium">
              {d.icon}
              {d.label}
            </span>
            <span className="text-ink-500 tabular-nums">
              {d.value.toLocaleString()} <span className="text-ink-400">· {Math.round((d.value / total) * 100)}%</span>
            </span>
          </div>
          <div className="h-2.5 overflow-hidden rounded-full bg-ink-100 dark:bg-white/6">
            <motion.div
              className="h-full rounded-full transition-[filter] group-hover:brightness-110"
              style={{ background: `linear-gradient(90deg, ${d.color}, color-mix(in oklab, ${d.color} 60%, white))` }}
              initial={{ width: 0 }}
              animate={{ width: `${(d.value / max) * 100}%` }}
              transition={{ delay: 0.15 + i * 0.08, type: 'spring', stiffness: 120, damping: 20 }}
            />
          </div>
        </li>
      ))}
    </ul>
  )
}

/** Step chart of rank over the launch day (rank 1 at the top). A `null` is a mark outside the top 500: the line breaks there. */
export function RankStepChart({ ranks, className, endLabel = 'now' }: { ranks: (number | null)[]; className?: string; endLabel?: string }) {
  const w = 300
  const h = 120
  const pad = { l: 22, r: 8, t: 10, b: 18 }
  const known = ranks.filter((r): r is number => r !== null)
  const worst = Math.max(5, ...known)
  const n = Math.max(2, ranks.length)
  const x = (i: number) => pad.l + (i / (n - 1)) * (w - pad.l - pad.r)
  const y = (r: number) => pad.t + ((r - 1) / (worst - 1)) * (h - pad.t - pad.b)
  const segments: string[] = []
  let current = ''
  let last = -1
  ranks.forEach((r, i) => {
    if (r === null) {
      if (current) segments.push(current)
      current = ''
      return
    }
    current += current ? ` H${x(i)} V${y(r)}` : `M${x(i)},${y(r)}`
    last = i
  })
  if (current) segments.push(current)
  const ticks = Array.from(new Set([1, Math.ceil(worst / 2), worst]))
  return (
    <svg viewBox={`0 0 ${w} ${h}`} className={cn('h-auto w-full overflow-visible', className)} role="img" aria-label="Rank through the day">
      {ticks.map((t) => (
        <g key={t}>
          <line x1={pad.l} x2={w - pad.r} y1={y(t)} y2={y(t)} className="stroke-ink-100 dark:stroke-white/6" strokeDasharray="2 4" />
          <text x={pad.l - 6} y={y(t) + 3} textAnchor="end" className="fill-ink-400 text-[9px]">#{t}</text>
        </g>
      ))}
      {segments.map((d, i) => (
        <motion.path
          key={i}
          d={d}
          fill="none"
          stroke="#8b5cf6"
          strokeWidth={2.5}
          strokeLinejoin="round"
          initial={{ pathLength: 0 }}
          animate={{ pathLength: 1 }}
          transition={{ duration: 1.2, ease: 'easeInOut', delay: 0.2 }}
        />
      ))}
      {last >= 0 && (
        <motion.circle cx={x(last)} cy={y(ranks[last] as number)} r={4} fill="#8b5cf6" stroke="white" strokeWidth={2} initial={{ scale: 0 }} animate={{ scale: 1 }} transition={{ delay: 1.3 }} />
      )}
      <text x={pad.l} y={h - 3} className="fill-ink-400 text-[9px]">12am</text>
      <text x={w - pad.r} y={h - 3} textAnchor="end" className="fill-ink-400 text-[9px]">{endLabel}</text>
    </svg>
  )
}
