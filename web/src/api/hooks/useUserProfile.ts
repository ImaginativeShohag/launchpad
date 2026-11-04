import { useQuery } from '@tanstack/react-query'
import { getUser } from '../endpoints/products'
import { keys } from '../keys'
import { toUser } from '../mappers'
import type { ApiItem, PublicUserDto } from '../types'

const select = (r: ApiItem<PublicUserDto>) => toUser(r.data)

/** GET /users/{handle}: counts, `is_following` (absent for guests), the reduced card of a suspended member. 404 = not found. */
export function useUserProfile(handle: string | undefined, enabled = true) {
  return useQuery({ queryKey: keys.user(handle ?? ''), queryFn: ({ signal }) => getUser(handle ?? '', signal), select, enabled: enabled && !!handle })
}
