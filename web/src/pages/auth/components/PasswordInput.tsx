import { AnimatePresence, motion } from 'motion/react'
import { Eye, EyeOff, Lock } from 'lucide-react'
import { useState, type ComponentProps } from 'react'
import { Input } from '@/components/ui'

type Props = Omit<ComponentProps<typeof Input>, 'type' | 'leading' | 'trailing'>

/** Password field with an animated show/hide toggle. */
export function PasswordInput(props: Props) {
  const [visible, setVisible] = useState(false)
  return (
    <Input
      {...props}
      type={visible ? 'text' : 'password'}
      leading={<Lock className="size-4" />}
      trailing={
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible}
          className="flex size-8 cursor-pointer items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-white/5 dark:hover:text-ink-200"
        >
          <AnimatePresence mode="wait" initial={false}>
            <motion.span
              key={visible ? 'hide' : 'show'}
              initial={{ scale: 0.5, opacity: 0, rotate: -30 }}
              animate={{ scale: 1, opacity: 1, rotate: 0 }}
              exit={{ scale: 0.5, opacity: 0, rotate: 30 }}
              transition={{ duration: 0.15 }}
            >
              {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </motion.span>
          </AnimatePresence>
        </button>
      }
    />
  )
}
