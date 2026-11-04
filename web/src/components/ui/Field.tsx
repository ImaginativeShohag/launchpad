import { useId, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { cn } from './cn'

const control =
  'w-full rounded-xl border border-ink-200 bg-white px-3.5 text-sm text-ink-900 placeholder:text-ink-400 transition focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-brand-500/15 dark:border-white/10 dark:bg-ink-950 dark:text-white aria-[invalid=true]:border-red-400 aria-[invalid=true]:ring-red-500/15'

interface FieldShellProps {
  label?: string
  hint?: ReactNode
  error?: string
  counter?: { value: number; max: number }
  children: (id: string) => ReactNode
}

export function FieldShell({ label, hint, error, counter, children }: FieldShellProps) {
  const id = useId()
  return (
    <div className="space-y-1.5">
      {(label || counter) && (
        <div className="flex items-baseline justify-between">
          {label && (
            <label htmlFor={id} className="text-sm font-semibold text-ink-800 dark:text-ink-100">
              {label}
            </label>
          )}
          {counter && (
            <span className={cn('text-xs tabular-nums', counter.value > counter.max ? 'text-red-500' : 'text-ink-400')}>
              {counter.value}/{counter.max}
            </span>
          )}
        </div>
      )}
      {children(id)}
      <AnimatePresence mode="wait" initial={false}>
        {error ? (
          <motion.p key="e" initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="text-xs font-medium text-red-500">
            {error}
          </motion.p>
        ) : hint ? (
          <motion.p key="h" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="text-xs text-ink-500">
            {hint}
          </motion.p>
        ) : null}
      </AnimatePresence>
    </div>
  )
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & Omit<FieldShellProps, 'children'> & { leading?: ReactNode; trailing?: ReactNode }

export function Input({ label, hint, error, counter, leading, trailing, className, ...rest }: InputProps) {
  return (
    <FieldShell label={label} hint={hint} error={error} counter={counter}>
      {(id) => (
        <div className="relative">
          {leading && <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">{leading}</span>}
          <input id={id} aria-invalid={!!error} className={cn(control, 'h-11', leading && 'pl-10', trailing && 'pr-11', className)} {...rest} />
          {trailing && <span className="absolute inset-y-0 right-2 flex items-center">{trailing}</span>}
        </div>
      )}
    </FieldShell>
  )
}

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & Omit<FieldShellProps, 'children'>

export function Textarea({ label, hint, error, counter, className, ...rest }: TextareaProps) {
  return (
    <FieldShell label={label} hint={hint} error={error} counter={counter}>
      {(id) => <textarea id={id} aria-invalid={!!error} className={cn(control, 'min-h-28 resize-y py-3 leading-relaxed', className)} {...rest} />}
    </FieldShell>
  )
}
