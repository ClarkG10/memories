import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ApiError } from './api/client'
import { ErrorBoundary } from './components/ErrorBoundary'
import { ToastProvider } from './components/Toasts'
import { ArchivePage } from './pages/ArchivePage'

/**
 * A sign-in that is no longer valid — an expired token, or one revoked by
 * `archive:owner` — must take the owner's controls away with it. The token is
 * already cleared by the API client; this is what makes the interface catch up
 * instead of offering an "Add memory" button that cannot work.
 */
function onUnauthorised(error: unknown) {
  if (error instanceof ApiError && error.status === 401) {
    void client.invalidateQueries()
  }
}

const client = new QueryClient({
  queryCache: new QueryCache({ onError: onUnauthorised }),
  mutationCache: new MutationCache({ onError: onUnauthorised }),

  defaultOptions: {
    queries: {
      /*
       | Identical requests made close together are answered once. Opening a
       | memory, closing it and opening it again should not re-fetch it, and
       | the server has already cached the same reads on its side.
       */
      staleTime: 60 * 1000,
      gcTime: 10 * 60 * 1000,
      refetchOnWindowFocus: false,

      retry: (failureCount, error) => {
        // Nothing is gained by asking again for something that is missing,
        // forbidden, or rejected.
        if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false

        return failureCount < 2
      },
    },
  },
})

export function App() {
  return (
    <QueryClientProvider client={client}>
      <ToastProvider>
        <ErrorBoundary>
          <BrowserRouter>
            <a className="visually-hidden" href="#main">
              Skip to the memories
            </a>

            <Routes>
              <Route path="/" element={<ArchivePage />} />
              {/* A memory opens as its own address, so it can be shared and the
                  back button closes the viewer. */}
              <Route path="/m/:memoryId" element={<ArchivePage />} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </BrowserRouter>
        </ErrorBoundary>
      </ToastProvider>
    </QueryClientProvider>
  )
}

export default App
