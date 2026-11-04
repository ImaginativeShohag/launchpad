export const reasonTone = (r: string) => (r === 'spam' || r === 'abuse' ? 'red' : r === 'misleading' || r === 'inappropriate' ? 'amber' : 'neutral') as 'red' | 'amber' | 'neutral'
