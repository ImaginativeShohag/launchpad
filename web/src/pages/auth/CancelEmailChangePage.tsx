import { useEffect } from 'react'
import { Link } from 'react-router'
import { cancelEmailChange } from '@/api/endpoints/auth'
import { errorMessage } from '@/api/errors'
import { useEmailedLink } from '@/lib/useStripQuery'
import { useSession } from '@/session/SessionProvider'
import { Button } from '@/components/ui'
import { LinkResult } from './components/LinkResult'

/** From the "cancel email change" mail sent to the OLD address. The API says plainly when there was nothing left to cancel. */
export default function CancelEmailChangePage() {
  const { state } = useEmailedLink(cancelEmailChange)
  const { refresh } = useSession()
  useEffect(() => {
    if (state.phase === 'done') void refresh()
  }, [state.phase, refresh])

  if (state.phase === 'pending') return <LinkResult phase="pending" title="Cancelling the change…" message="One moment." />
  if (state.phase === 'failed') {
    return (
      <LinkResult phase="failed" title="We couldn’t use that link" message={errorMessage(state.error)}>
        <Link to="/"><Button size="lg" className="w-full">Back to Launchpad</Button></Link>
      </LinkResult>
    )
  }
  const nothing = state.data.code === 'no_pending_change'
  return (
    <LinkResult phase={nothing ? 'failed' : 'done'} title={nothing ? 'Nothing to cancel' : 'Change cancelled'} message={state.data.message}>
      <div className="space-y-2">
        {nothing && <Link to="/forgot-password"><Button size="lg" className="w-full">Reset my password</Button></Link>}
        <Link to="/"><Button size="lg" variant={nothing ? 'outline' : 'primary'} className="w-full">Back to Launchpad</Button></Link>
      </div>
    </LinkResult>
  )
}
