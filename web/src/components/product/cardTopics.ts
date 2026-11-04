import type { Topic } from '@/data/types'

/** The topics of a card (API cards embed them). */
export function cardTopics(p: { topics?: Topic[] }): Topic[] {
  return p.topics ?? []
}
