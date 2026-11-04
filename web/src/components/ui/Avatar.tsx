import { useState } from 'react'
import { cn } from './cn'

const sizes = { xs: 'size-6 text-[10px]', sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-16 text-xl', xl: 'size-24 text-3xl' }

/** The member's photo when there is one, otherwise a gradient with initials. */
export function Avatar({ name, hue, src, size = 'md', className }: { name: string; hue: number; src?: string | null; size?: keyof typeof sizes; className?: string }) {
  const [failed, setFailed] = useState<string | null>(null)
  const initials = name
    .split(' ')
    .map((w) => w[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()
  const shared = cn('inline-flex shrink-0 items-center justify-center rounded-full ring-2 ring-white dark:ring-ink-900', sizes[size], className)
  if (src && failed !== src) {
    return <img src={src} alt="" aria-hidden onError={() => setFailed(src)} className={cn(shared, 'object-cover')} />
  }
  return (
    <span aria-hidden className={cn(shared, 'font-bold text-white')} style={{ background: `linear-gradient(135deg, hsl(${hue} 85% 62%), hsl(${(hue + 40) % 360} 80% 48%))` }}>
      {initials}
    </span>
  )
}
