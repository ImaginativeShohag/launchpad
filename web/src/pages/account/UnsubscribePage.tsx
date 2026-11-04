import { Link } from 'react-router'
import { unsubscribe } from '@/api/endpoints/me'
import { errorMessage, isApiError } from '@/api/errors'
import { useEmailedLink } from '@/lib/useStripQuery'
import { Button } from '@/components/ui'
import { LinkResult } from '@/pages/auth/components/LinkResult'

const labels: Record<string, string> = {
  review_outcome: 'review outcome',
  launch_reminder: 'launch reminder',
  product_live: 'product live',
  new_comment: 'new comment',
  daily_top5: 'daily top 5',
  weekly_best: 'weekly best',
  export_ready: 'export ready',
}

/** One-click unsubscribe from a mail's footer: public, authorised by the link's signature. */
export default function UnsubscribePage() {
  const { state } = useEmailedLink(unsubscribe)

  if (state.phase === 'pending') return <LinkResult phase="pending" title="Updating your emails…" message="One moment." />
  if (state.phase === 'failed') {
    const forged = isApiError(state.error) && state.error.status === 403
    return (
      <LinkResult phase="failed" title="We couldn’t use that link" message={forged ? 'This unsubscribe link is not valid.' : errorMessage(state.error)}>
        <Link to="/settings?tab=email"><Button size="lg" className="w-full">Manage emails in Settings</Button></Link>
      </LinkResult>
    )
  }
  const name = labels[state.data.type] ?? 'these'
  return (
    <LinkResult phase="done" title="You’re unsubscribed" message={`We won’t send you ${name} emails any more. You can turn them back on any time in Settings.`}>
      <Link to="/settings?tab=email"><Button size="lg" variant="outline" className="w-full">Manage email settings</Button></Link>
    </LinkResult>
  )
}
