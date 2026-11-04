import '@fontsource-variable/inter'
import '@fontsource-variable/bricolage-grotesque/opsz.css'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router'
import { AppProviders } from './AppProviders'
import { removeLegacyStorage } from './lib/legacyStorage'
import { queryClient } from './lib/queryClient'
import { router } from './routes'
import './index.css'

removeLegacyStorage()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppProviders client={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>
  </StrictMode>,
)
