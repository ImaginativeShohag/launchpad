import { useQuery } from '@tanstack/react-query'
import { listTopics } from '../endpoints/topics'
import { keys } from '../keys'
import { toTopic } from '../mappers'

export function useTopics() {
  return useQuery({ queryKey: keys.topics, queryFn: async () => (await listTopics()).map(toTopic) })
}
