import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { api, auth, idempotencyKey } from './client'
import type { Archive, Memory, MemoryInput, TimelinePage, YearCount } from './types'

/**
 * One place for every cache key, so an invalidation cannot miss a view by
 * spelling its key slightly differently.
 */
export const keys = {
  archive: ['archive'] as const,
  timeline: (year: number | null) => ['timeline', year] as const,
  years: ['years'] as const,
  albums: ['albums'] as const,
  memory: (id: string) => ['memory', id] as const,
}

export function useArchive() {
  return useQuery({
    queryKey: keys.archive,
    queryFn: ({ signal }) => api.get<{ data: Archive }>('/api/archive', signal),
    select: (response) => response.data,
    staleTime: 5 * 60 * 1000,
  })
}

/**
 * The timeline, walked with a cursor.
 *
 * Cursors rather than page numbers: adding a memory shifts every offset, and
 * someone mid-scroll would see a card twice or miss one entirely.
 */
export function useTimeline(year: number | null) {
  return useInfiniteQuery({
    queryKey: keys.timeline(year),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam, signal }) => {
      const params = new URLSearchParams()
      if (year !== null) params.set('year', String(year))
      if (pageParam) params.set('cursor', pageParam)

      return api.get<TimelinePage>(`/api/timeline?${params.toString()}`, signal)
    },
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor,
    staleTime: 60 * 1000,
  })
}

export function useYears() {
  return useQuery({
    queryKey: keys.years,
    queryFn: ({ signal }) => api.get<{ data: YearCount[] }>('/api/timeline/years', signal),
    select: (response) => response.data,
    staleTime: 60 * 1000,
  })
}

/** Albums already in use, so one can be reused rather than retyped exactly. */
export function useAlbums() {
  return useQuery({
    queryKey: keys.albums,
    queryFn: ({ signal }) => api.get<{ data: string[] }>('/api/albums', signal),
    select: (response) => response.data,
    staleTime: 60 * 1000,
  })
}

export function useMemory(id: string | null) {
  return useQuery({
    queryKey: keys.memory(id ?? ''),
    queryFn: ({ signal }) => api.get<{ data: Memory }>(`/api/memories/${id}`, signal),
    select: (response) => response.data,
    enabled: id !== null,
    staleTime: 60 * 1000,
  })
}

/**
 * Fetch a memory before it is opened.
 *
 * Opening one costs a request for the memory and then a request for its
 * largest photograph, one after the other, and the second cannot start until
 * the first has answered. Pointing at a memory is a reliable signal that it is
 * about to be opened, so both are started then — by the time the click lands
 * the memory is usually already there and the photograph is on its way.
 *
 * Deliberately cheap to be wrong about: a prefetch that is never used costs
 * one small response, and a failed one is swallowed rather than surfaced.
 */
export function usePrefetchMemory() {
  const client = useQueryClient()

  return (id: string, preview?: { full?: string; display?: string; thumb?: string; poster?: string }) => {
    void client
      .prefetchQuery({
        queryKey: keys.memory(id),
        queryFn: ({ signal }) => api.get<{ data: Memory }>(`/api/memories/${id}`, signal),
        staleTime: 60 * 1000,
      })
      .catch(() => undefined)

    const source = preview?.full ?? preview?.display ?? preview?.poster

    if (source) {
      const image = new Image()
      image.fetchPriority = 'low'
      image.decoding = 'async'
      image.src = source
    }
  }
}

/**
 * Anything that changes the archive retires every cached read of it. The
 * server does the same thing on its side, for the same reason: a timeline that
 * still shows a deleted memory is worse than a slower one.
 */
function useArchiveInvalidation() {
  const client = useQueryClient()

  return () => {
    void client.invalidateQueries({ queryKey: ['timeline'] })
    void client.invalidateQueries({ queryKey: keys.years })
    void client.invalidateQueries({ queryKey: keys.albums })
    void client.invalidateQueries({ queryKey: ['memory'] })
  }
}

export function useCreateMemory() {
  const invalidate = useArchiveInvalidation()

  return useMutation({
    mutationFn: async (input: MemoryInput & { uploads: string[]; requestKey: string }) => {
      const { uploads, requestKey, ...memory } = input

      const response = await api.send<{ data: Memory }>(
        'POST',
        '/api/memories',
        { ...memory, uploads },
        { 'Idempotency-Key': requestKey },
      )

      return response.data
    },
    onSuccess: invalidate,
  })
}

export function useUpdateMemory() {
  const invalidate = useArchiveInvalidation()

  return useMutation({
    mutationFn: async ({ id, ...input }: MemoryInput & { id: string }) => {
      const response = await api.send<{ data: Memory }>('PATCH', `/api/memories/${id}`, input)

      return response.data
    },
    onSuccess: invalidate,
  })
}

/**
 * Everything that can change about a memory's photographs, in one act.
 *
 * The order matters and is not obvious. Additions go first, so that removing
 * what is being replaced never trips the rule that a memory must keep at least
 * one file. Removals come next. The new order is sent last, once the set it
 * describes is finally settled — an order sent before that names files that
 * are about to disappear.
 */
export function useReviseMedia() {
  const invalidate = useArchiveInvalidation()
  const client = useQueryClient()

  return useMutation({
    mutationFn: async ({
      id,
      add,
      remove,
      order,
    }: {
      id: string
      /** Upload session ids for files already on the server. */
      add: string[]
      /** Media ids to let go of. */
      remove: string[]
      /**
       * Every surviving media id, in the order they should appear. New files
       * are identified by their session id here and swapped for their real
       * media id once the server has answered.
       */
      order: string[]
    }) => {
      let memory: Memory | null = null

      if (add.length > 0) {
        const response = await api.send<{ data: Memory }>(
          'POST',
          `/api/memories/${id}/media`,
          { uploads: add },
          { 'Idempotency-Key': idempotencyKey() },
        )

        memory = response.data
      }

      for (const mediaId of remove) {
        await api.send('DELETE', `/api/media/${mediaId}`)
      }

      /*
       | The new files were named by session id, because that was all the
       | interface knew about them. The server has now given them real ids, and
       | they are the ones it appended — in the order they were sent.
       */
      const appended = memory
        ? memory.media.map((item) => item.id).slice(memory.media.length - add.length)
        : []

      let cursor = 0
      const settled = order
        .map((entry) => (add.includes(entry) ? appended[cursor++] : entry))
        .filter((entry): entry is string => typeof entry === 'string')
        .filter((entry) => !remove.includes(entry))

      if (settled.length > 1) {
        const response = await api.send<{ data: Memory }>(
          'PUT',
          `/api/memories/${id}/media/order`,
          { order: settled },
        )

        memory = response.data
      }

      // Whatever happened, the memory that is open must be re-read.
      await client.invalidateQueries({ queryKey: keys.memory(id) })

      return memory
    },
    onSuccess: invalidate,
  })
}

export function useDeleteMemory() {
  const invalidate = useArchiveInvalidation()

  return useMutation({
    mutationFn: (id: string) => api.send('DELETE', `/api/memories/${id}`),
    onSuccess: invalidate,
  })
}

export function useSignIn() {
  const client = useQueryClient()

  return useMutation({
    mutationFn: async (credentials: { email: string; password: string }) => {
      const response = await api.send<{ data: { token: string; user: { name: string } } }>(
        'POST',
        '/api/auth/login',
        { ...credentials, device_name: 'browser' },
      )

      /*
       | Stored here rather than by the caller, because onSuccess fires — and
       | refetches everything — before the caller's `await` resumes. Setting it
       | afterwards means those refetches go out unauthenticated and the app
       | reports a successful sign-in while still showing the signed-out
       | archive until someone reloads.
       */
      auth.setToken(response.data.token)

      return response.data
    },
    onSuccess: () => {
      void client.invalidateQueries()
    },
  })
}

export function useSignOut() {
  const client = useQueryClient()

  return useMutation({
    mutationFn: () => api.send('POST', '/api/auth/logout'),

    // The token is cleared only after the request has gone out. Clearing it
    // first would send the sign-out unauthenticated, so the server would never
    // revoke it and it would keep working anywhere else it had been copied to.
    onSettled: () => {
      auth.setToken(null)
      void client.invalidateQueries()
    },
  })
}

export { idempotencyKey }
