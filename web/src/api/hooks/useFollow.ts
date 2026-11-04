import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query'
import { useVerifyPrompt } from '@/components/layout/AuthGate'
import { useToast } from '@/components/ui'
import type { Topic } from '@/data/types'
import { followTopic, followUser, unfollowTopic, unfollowUser } from '../endpoints/follows'
import { errorMessage, isApiError } from '../errors'
import { keys } from '../keys'
import type { ApiItem, ApiList, ApiPage, FollowedUserDto, PublicUserDto, TopicDto } from '../types'

type FollowedUsers = InfiniteData<ApiPage<FollowedUserDto>>

function failure(err: unknown, toast: ReturnType<typeof useToast>, promptVerify: () => void, who: string) {
  if (isApiError(err) && err.code === 'email_unverified') return promptVerify()
  if (isApiError(err) && err.code === 'self_follow') return toast({ tone: 'info', title: 'That is you', body: 'You cannot follow yourself.' })
  if (isApiError(err) && err.code === 'member_unavailable') return toast({ tone: 'info', title: 'Cannot follow this member', body: 'This account is not available right now.' })
  if (isApiError(err) && err.status === 404) return toast({ tone: 'info', title: `${who} was not found`, body: 'It may have been removed.' })
  if (isApiError(err) && err.status === 401) return toast({ tone: 'error', title: 'Please sign in again', body: 'Your session ended.' })
  toast({ tone: 'error', title: 'Could not update your follow', body: errorMessage(err) })
}

/**
 * Optimistic follow / unfollow of a member: `is_following` and `followers_count` change on the profile at once, an unfollow
 * leaves the "You follow" list too. The response sets both fields; an error puts only those two fields back.
 */
export function useFollowUser() {
  const qc = useQueryClient()
  const toast = useToast()
  const promptVerify = useVerifyPrompt()

  return useMutation({
    mutationFn: ({ handle, follow }: { handle: string; follow: boolean }) => (follow ? followUser(handle) : unfollowUser(handle)),
    onMutate: async ({ handle, follow }) => {
      await qc.cancelQueries({ queryKey: keys.user(handle) })
      const before = qc.getQueryData<ApiItem<PublicUserDto>>(keys.user(handle))
      const was = before?.data.is_following ?? false
      qc.setQueryData<ApiItem<PublicUserDto>>(keys.user(handle), (old) =>
        old ? { ...old, data: { ...old.data, is_following: follow, followers_count: Math.max(0, (old.data.followers_count ?? 0) + (was === follow ? 0 : follow ? 1 : -1)) } } : old,
      )
      const lists = follow ? [] : qc.getQueriesData<FollowedUsers>({ queryKey: keys.followedUsers })
      for (const [key, data] of lists) {
        if (!data?.pages) continue
        qc.setQueryData<FollowedUsers>(key, { ...data, pages: data.pages.map((p) => ({ ...p, data: p.data.filter((u) => u.handle !== handle) })) })
      }
      return { was, lists }
    },
    onSuccess: (res, { handle }) => {
      qc.setQueryData<ApiItem<PublicUserDto>>(keys.user(handle), (old) => (old ? { ...old, data: { ...old.data, is_following: res.following, followers_count: res.followers_count } } : old))
    },
    onError: (err, { handle, follow }, ctx) => {
      qc.setQueryData<ApiItem<PublicUserDto>>(keys.user(handle), (old) =>
        old ? { ...old, data: { ...old.data, is_following: ctx?.was ?? !follow, followers_count: Math.max(0, (old.data.followers_count ?? 0) + (ctx?.was === follow ? 0 : follow ? -1 : 1)) } } : old,
      )
      for (const [key, data] of ctx?.lists ?? []) qc.setQueryData(key, data)
      failure(err, toast, promptVerify, 'This member')
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: keys.followedUsers })
      void qc.invalidateQueries({ queryKey: keys.feed })
    },
  })
}

const toDto = (t: Topic): TopicDto => ({ id: t.id, slug: t.slug, name: t.name, emoji: t.emoji, description: t.description ?? null, position: t.position ?? 0 })

/** Optimistic follow / unfollow of a topic: the member's topic list changes at once; an error puts it back. */
export function useFollowTopic() {
  const qc = useQueryClient()
  const toast = useToast()
  const promptVerify = useVerifyPrompt()

  const set = (topic: Topic, follow: boolean) =>
    qc.setQueryData<ApiList<TopicDto>>(keys.followedTopics, (old) => {
      if (!old) return old
      const without = old.data.filter((t) => t.slug !== topic.slug)
      return { ...old, data: follow ? [...without, toDto(topic)].sort((a, b) => a.position - b.position || a.name.localeCompare(b.name)) : without }
    })

  return useMutation({
    mutationFn: ({ topic, follow }: { topic: Topic; follow: boolean }) => (follow ? followTopic(topic.slug) : unfollowTopic(topic.slug)),
    onMutate: async ({ topic, follow }) => {
      await qc.cancelQueries({ queryKey: keys.followedTopics })
      set(topic, follow)
    },
    onError: (err, { topic, follow }) => {
      set(topic, !follow)
      failure(err, toast, promptVerify, 'This topic')
    },
    onSettled: () => void qc.invalidateQueries({ queryKey: keys.feed }),
  })
}
