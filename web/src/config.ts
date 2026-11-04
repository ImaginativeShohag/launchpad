/** Typed reads of the Vite env. Fails loudly at boot instead of calling a wrong host. */
function apiUrl(): string {
  const raw = (import.meta.env.VITE_API_URL as string | undefined)?.trim()
  if (raw) return raw.replace(/\/+$/, '')
  if (import.meta.env.MODE === 'test') return 'http://api.test'
  throw new Error('VITE_API_URL is not set. Copy web/.env.example to web/.env.local and restart the dev server.')
}

const env = import.meta.env

export const config = {
  apiUrl: apiUrl(),
  reverb: {
    appKey: ((env.VITE_REVERB_APP_KEY as string | undefined) ?? '').trim(),
    host: ((env.VITE_REVERB_HOST as string | undefined) ?? 'localhost').trim(),
    port: Number(env.VITE_REVERB_PORT ?? 8080),
    scheme: ((env.VITE_REVERB_SCHEME as string | undefined) ?? 'http').trim(),
  },
}
