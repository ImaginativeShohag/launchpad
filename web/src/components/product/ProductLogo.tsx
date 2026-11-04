import { useState } from 'react'
import { cn } from '@/components/ui'

const sizes = { sm: 'size-10 text-lg rounded-xl', md: 'size-14 text-2xl rounded-2xl', lg: 'size-20 text-4xl rounded-3xl', xl: 'size-28 text-5xl rounded-[2rem]' }

/** The uploaded logo; without one a gradient with the product's initial. */
export function ProductLogo({ name, hue, src, size = 'md', className }: { name?: string; hue: number; src?: string | null; size?: keyof typeof sizes; className?: string }) {
  const [failed, setFailed] = useState<string | null>(null)
  if (src && failed !== src) {
    return <img src={src} alt="" aria-hidden onError={() => setFailed(src)} className={cn('shrink-0 object-cover shadow-soft ring-1 ring-black/5', sizes[size], className)} />
  }
  return (
    <span
      aria-hidden
      className={cn('relative inline-flex shrink-0 items-center justify-center overflow-hidden shadow-soft ring-1 ring-black/5', sizes[size], className)}
      style={{ background: `linear-gradient(140deg, hsl(${hue} 95% 72%), hsl(${(hue + 50) % 360} 85% 55%))` }}
    >
      <span className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgb(255_255_255/0.55),transparent_60%)]" />
      <span className="relative font-display font-bold text-white drop-shadow-sm">{name?.trim().charAt(0).toUpperCase()}</span>
    </span>
  )
}
