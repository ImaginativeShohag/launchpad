import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { changeRole, restoreMember, suspendMember } from '../endpoints/staff'
import { keys } from '../keys'
import { useCursorList } from '../pagination'
import type { AdminMemberDto, ApiPage, SuspensionDto } from '../types'

export interface MemberFilters {
  q?: string
  role?: string
  status?: string
}

/** GET /admin/users (admins only). `q` needs two characters; the rows carry the e-mail, which only this page shows. */
export function useAdminMembers(filters: MemberFilters) {
  return useCursorList<AdminMemberDto>(keys.adminUsers(), '/admin/users', { q: filters.q, role: filters.role, status: filters.status, per_page: 20 }, { staleTime: 0 })
}

/** Patches one member in every loaded page of the directory (the answer of a write names the member by handle). */
function patchMember(qc: QueryClient, handle: string, patch: Partial<AdminMemberDto>) {
  qc.setQueriesData<{ pages: ApiPage<AdminMemberDto>[]; pageParams: unknown[] }>({ queryKey: ['admin', 'users'] }, (old) =>
    old ? { ...old, pages: old.pages.map((p) => ({ ...p, data: p.data.map((m) => (m.handle === handle ? { ...m, ...patch } : m)) })) } : old,
  )
}

function afterSuspension(qc: QueryClient, dto: SuspensionDto) {
  patchMember(qc, dto.handle, { is_suspended: dto.is_suspended, suspended_at: dto.suspended_at, suspended_reason: dto.suspended_reason })
  void qc.invalidateQueries({ queryKey: ['staff', 'risk'] })
  void qc.invalidateQueries({ queryKey: keys.user(dto.handle) })
}

export function useSuspendMember() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: ({ handle, reason }: { handle: string; reason: string }) => suspendMember(handle, reason), onSuccess: (dto) => afterSuspension(qc, dto) })
}

export function useRestoreMember() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (handle: string) => restoreMember(handle), onSuccess: (dto) => afterSuspension(qc, dto) })
}

export function useChangeRole() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ handle, role }: { handle: string; role: 'member' | 'reviewer' }) => changeRole(handle, role),
    onSuccess: (dto) => patchMember(qc, dto.handle, { role: dto.role }),
  })
}
