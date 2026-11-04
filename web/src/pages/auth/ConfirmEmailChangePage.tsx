import { Link } from 'react-router'
import { confirmEmailChange } from '@/api/endpoints/auth'
import { errorMessage, isApiError } from '@/api/errors'
import { useEmailedLink } from '@/lib/useStripQuery'
import { useSession } from '@/session/SessionProvider'
import { Button } from '@/components/ui'
import { LinkResult } from './components/LinkResult'
import { useEffect } from 'react'

/** From the "confirm new email" mail. Guest route: the signed link is the credential; every session ends, so sign in again. */
export default function ConfirmEmailChangePage() {
  const { state } = useEmailedLink(confirmEmailChange)
  const { refresh } = useSession()
  useEffect(() => {
    if (state.phase === 'done') void refresh()
  }, [state.phase, refresh])

  if (state.phase === 'pending') return <LinkResult phase="pending" title="Confirming your new email…" message="One moment." />
  if (state.phase === 'done') {
    return (
      <LinkResult phase="done" title="Email updated" message="Your email address was changed. For your safety you were signed out everywhere. Sign in again with the new address.">
        <Link to="/signin"><Button size="lg" className="w-full">Sign in</Button></Link>
      </LinkResult>
    )
  }
  const taken = isApiError(state.error) && state.error.status === 409
  return (
    <LinkResult phase="failed" title="We couldn’t confirm that link" message={taken ? 'That email address is already in use by another account.' : isApiError(state.error) && state.error.status === 422 ? 'This link is invalid or has expired. Request the change again from Settings.' : errorMessage(state.error)}>
      <Link to="/settings?tab=account"><Button size="lg" className="w-full">Go to Settings</Button></Link>
    </LinkResult>
  )
}
