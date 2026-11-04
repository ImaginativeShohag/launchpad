import { Bell, Clock, Download, MessageCircle, PartyPopper, Rocket, ShieldAlert } from 'lucide-react'
import type { AppNotification } from '@/data/types'

const icons: Record<AppNotification['type'], React.ReactNode> = {
  review_approved: <PartyPopper className="size-4 text-emerald-500" />,
  review_rejected: <ShieldAlert className="size-4 text-red-500" />,
  review_changes_requested: <ShieldAlert className="size-4 text-amber-500" />,
  launch_reminder: <Clock className="size-4 text-sky-500" />,
  product_live: <Rocket className="size-4 text-brand-500" />,
  new_comment: <MessageCircle className="size-4 text-violet-500" />,
  export_ready: <Download className="size-4 text-emerald-500" />,
  unknown: <Bell className="size-4 text-ink-400" />,
}

export function NotificationIcon({ type }: { type: AppNotification['type'] }) {
  return icons[type]
}
