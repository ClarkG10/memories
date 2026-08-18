import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { vi } from 'vitest'
import { ToastProvider } from '../components/Toasts'
import { ArchivePage } from '../pages/ArchivePage'
import type { Archive, Media, Memory, TimelineMemory } from '../api/types'

/**
 * Everything the tests need to stand the archive up without a server.
 */

export interface Handler {
  method?: string
  /** Matched against the path, so tests do not repeat the base URL. */
  match: RegExp
  status?: number
  body: unknown | ((url: string) => unknown)
}

/**
 * Replaces fetch with a small router.
 *
 * A stub per test is more honest than one global fake: each test states the
 * exact server behaviour it is about — including the failures, which are the
 * cases most worth covering.
 */
export function mockApi(handlers: Handler[]) {
  const calls: { method: string; url: string; body?: unknown }[] = []

  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : input.toString()
    const method = (init?.method ?? 'GET').toUpperCase()

    calls.push({
      method,
      url,
      body: typeof init?.body === 'string' ? JSON.parse(init.body) : undefined,
    })

    const handler = handlers.find(
      (candidate) =>
        candidate.match.test(url) && (candidate.method ?? 'GET').toUpperCase() === method,
    )

    if (!handler) {
      return new Response(JSON.stringify({ message: `No handler for ${method} ${url}` }), {
        status: 501,
        headers: { 'Content-Type': 'application/json' },
      })
    }

    const body = typeof handler.body === 'function' ? handler.body(url) : handler.body

    return new Response(JSON.stringify(body), {
      status: handler.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })

  vi.stubGlobal('fetch', fetchMock)

  return { calls, fetchMock }
}

/**
 * The current address, written somewhere a test can read it.
 *
 * MemoryRouter never touches window.location, so a test asserting that a
 * phrase reached the address bar has nothing to look at without this.
 */
/* oxlint-disable-next-line react/only-export-components -- a test harness is
   not a module anything fast-refreshes. */
function LocationProbe() {
  const location = useLocation()

  return (
    <span
      data-testid="location"
      data-path={location.pathname}
      data-search={location.search}
      hidden
    />
  )
}

/** What the address bar would say, if there were one. */
export function currentLocation(): { path: string; search: string } {
  const probe = screen.getByTestId('location')

  return {
    path: probe.getAttribute('data-path') ?? '',
    search: probe.getAttribute('data-search') ?? '',
  }
}

/**
 * @param initialPath where to start
 * @param options.fromLink true to simulate arriving from a shared link, with
 *   no timeline behind it in the history
 */
export function renderArchive(initialPath = '/', options: { fromLink?: boolean } = {}) {
  const client = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  })

  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        {/* Normally the timeline sits below in the history, so closing the
            viewer with a back navigation lands somewhere real. */}
        <MemoryRouter
          initialEntries={
            initialPath === '/' || options.fromLink ? [initialPath] : ['/', initialPath]
          }
        >
          {/*
            | MemoryRouter never touches window.location, so a test asserting
            | that something reached the address bar has nothing to read. This
            | writes it somewhere observable instead.
          */}
          <LocationProbe />

          <Routes>
            <Route path="/" element={<ArchivePage />} />
            <Route path="/m/:memoryId" element={<ArchivePage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

/* --- Fixtures ---------------------------------------------------------- */

export function anArchive(overrides: Partial<Archive> = {}): Archive {
  return {
    title: 'Our Memories',
    quote: 'Every moment is worth remembering.',
    public: true,
    can_manage: false,
    storage_connected: true,
    upload: {
      chunk_bytes: 4194304,
      max_files: 200,
      max_image_bytes: 262144000,
      max_video_bytes: 8589934592,
      accepts: ['image/jpeg', 'video/mp4'],
    },
    text: { title: 500, description: 50_000, location: 255, album: 190 },
    storage: null,
    ...overrides,
  }
}

export function anImage(overrides: Partial<Media> = {}): Media {
  return {
    id: 'media-1',
    type: 'image',
    width: 1600,
    height: 1067,
    aspect_ratio: 1.5,
    duration_ms: null,
    placeholder: 'data:image/jpeg;base64,placeholder',
    urls: {
      thumb: 'http://api.test/api/media/media-1/image?w=640',
      display: 'http://api.test/api/media/media-1/image?w=1600',
      full: 'http://api.test/api/media/media-1/image?w=2400',
    },
    ...overrides,
  }
}

export function aVideo(overrides: Partial<Media> = {}): Media {
  return {
    id: 'media-video',
    type: 'video',
    width: 1920,
    height: 1080,
    aspect_ratio: 1.777,
    duration_ms: 42_000,
    placeholder: null,
    urls: {
      poster: 'http://api.test/api/media/media-video/poster',
      stream: 'http://api.test/api/media/media-video/stream',
    },
    ...overrides,
  }
}

export function aTimelineMemory(overrides: Partial<TimelineMemory> = {}): TimelineMemory {
  return {
    id: 'memory-1',
    title: 'That Beautiful Evening',
    memory_date: '2026-08-10',
    year: 2026,
    month: 8,
    location: 'Butuan',
    album: null,
    media_count: 1,
    preview: [anImage()],
    ...overrides,
  }
}

export function aMemory(overrides: Partial<Memory> = {}): Memory {
  return {
    id: 'memory-1',
    title: 'That Beautiful Evening',
    description: 'One of those evenings we wish we could replay.',
    memory_date: '2026-08-10',
    year: 2026,
    location: 'Butuan',
    album: null,
    media_count: 1,
    media: [anImage()],
    created_at: '2026-08-10T10:00:00+00:00',
    ...overrides,
  }
}

export function timelinePage(memories: TimelineMemory[], nextCursor: string | null = null) {
  return {
    data: memories,
    meta: { next_cursor: nextCursor, has_more: nextCursor !== null },
  }
}
