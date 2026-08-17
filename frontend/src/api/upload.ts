import { ApiError, api, auth } from './client'
import type { UploadSession } from './types'

export interface UploadProgress {
  /** 0–1, across the whole file. */
  fraction: number
  bytesSent: number
  bytesTotal: number
}

export interface UploadOptions {
  onProgress?: (progress: UploadProgress) => void
  signal?: AbortSignal
  /** Called as soon as the server has a session, so a retry can resume it. */
  onSession?: (sessionId: string) => void
  /** A session from a previous attempt; only its missing chunks are sent. */
  resume?: string
}

/** How many times a single chunk is re-sent before the upload gives up. */
const CHUNK_ATTEMPTS = 3

/**
 * Carry one file to the server in chunks and return the finished session id.
 *
 * Chunking is what makes a large video survivable: each request is small
 * enough to get through every body-size limit in the way, a dropped connection
 * costs one piece rather than the whole transfer, and the server can say
 * exactly which pieces it is still waiting for.
 */
export async function uploadFile(file: File, options: UploadOptions = {}): Promise<string> {
  const session = await openOrResume(file, options)

  options.onSession?.(session.id)

  const { chunk_size: chunkSize, total_chunks: totalChunks } = session

  /*
   | On a resumed upload the server tells us which pieces it never received.
   | Re-sending a two-gigabyte video from the beginning because it failed at
   | ninety percent is the difference between a retry that works and one
   | nobody waits for.
   */
  const outstanding = new Set(
    session.status === 'pending' && session.missing_chunks.length > 0
      ? session.missing_chunks
      : Array.from({ length: totalChunks }, (_, index) => index),
  )

  const alreadyDone = totalChunks - outstanding.size
  let sent = Math.min(alreadyDone * chunkSize, file.size)

  for (let index = 0; index < totalChunks; index++) {
    if (!outstanding.has(index)) continue

    throwIfAborted(options.signal)

    const start = index * chunkSize
    const blob = file.slice(start, Math.min(start + chunkSize, file.size))

    await sendChunkWithRetries(session.id, index, blob, options, {
      onBytes: (bytesInChunk) => {
        options.onProgress?.({
          fraction: file.size === 0 ? 1 : Math.min(1, (sent + bytesInChunk) / file.size),
          bytesSent: sent + bytesInChunk,
          bytesTotal: file.size,
        })
      },
    })

    sent += blob.size
  }

  throwIfAborted(options.signal)

  // The server re-reads the assembled file here: this is where a wrong file
  // type or a corrupt transfer is caught, before anything reaches Drive.
  await api.send('POST', `/api/uploads/${session.id}/complete`)

  options.onProgress?.({ fraction: 1, bytesSent: file.size, bytesTotal: file.size })

  return session.id
}

/**
 * A session to send this file into: the one a previous attempt started if it
 * is still usable, otherwise a fresh one.
 */
async function openOrResume(file: File, options: UploadOptions): Promise<UploadSession> {
  if (options.resume) {
    try {
      const existing = await api.get<{ data: UploadSession }>(
        `/api/uploads/${options.resume}`,
        options.signal,
      )

      // Expired or already spent sessions cannot be continued.
      if (existing.data.status === 'pending' || existing.data.status === 'ready') {
        return existing.data
      }
    } catch {
      // Gone, or no longer ours. Start again rather than fail the upload.
    }
  }

  const opened = await api.send<{ data: UploadSession }>('POST', '/api/uploads', {
    file_name: file.name,
    size: file.size,
    mime_type: file.type || null,
  })

  return opened.data
}

/**
 * Tell the server to forget an upload the person removed before saving.
 * Best-effort: the hourly sweep catches anything this misses.
 */
export async function discardUpload(sessionId: string): Promise<void> {
  try {
    await api.send('DELETE', `/api/uploads/${sessionId}`)
  } catch {
    /* Nothing useful to do, and nothing depends on it. */
  }
}

async function sendChunkWithRetries(
  sessionId: string,
  index: number,
  blob: Blob,
  options: UploadOptions,
  hooks: { onBytes: (bytes: number) => void },
): Promise<void> {
  let lastError: unknown

  for (let attempt = 1; attempt <= CHUNK_ATTEMPTS; attempt++) {
    try {
      await putChunk(sessionId, index, blob, options.signal, hooks.onBytes)

      return
    } catch (error) {
      lastError = error

      // A rejected chunk (wrong size, closed session) will be rejected again;
      // only transport failures are worth repeating.
      if (error instanceof ApiError && !error.retryable) throw error
      if (options.signal?.aborted) throw error
      if (attempt === CHUNK_ATTEMPTS) break

      await delay(400 * attempt)
    }
  }

  throw lastError
}

/**
 * XMLHttpRequest rather than fetch, purely for upload progress: fetch cannot
 * report how much of a request body has been sent, and a four-megabyte chunk
 * with no feedback looks like a stall.
 */
function putChunk(
  sessionId: string,
  index: number,
  blob: Blob,
  signal: AbortSignal | undefined,
  onBytes: (bytes: number) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest()

    request.open('PUT', api.url(`/api/uploads/${sessionId}/chunks/${index}`), true)
    request.setRequestHeader('Accept', 'application/json')
    request.setRequestHeader('Content-Type', 'application/octet-stream')

    const token = auth.token()
    if (token) request.setRequestHeader('Authorization', `Bearer ${token}`)

    const abort = () => request.abort()
    signal?.addEventListener('abort', abort)

    const done = () => signal?.removeEventListener('abort', abort)

    request.upload.onprogress = (event) => {
      if (event.lengthComputable) onBytes(event.loaded)
    }

    request.onload = () => {
      done()

      if (request.status >= 200 && request.status < 300) {
        resolve()

        return
      }

      reject(parseXhrError(request))
    }

    request.onerror = () => {
      done()
      reject(new ApiError('The connection dropped while sending this file.', 0))
    }

    request.onabort = () => {
      done()
      reject(new ApiError('Upload cancelled.', 0, false))
    }

    request.send(blob)
  })
}

function parseXhrError(request: XMLHttpRequest): ApiError {
  try {
    const body = JSON.parse(request.responseText)
    const errors = (body?.errors ?? {}) as Record<string, string[]>
    const message =
      Object.values(errors)[0]?.[0] ?? body?.message ?? 'That piece of the file was not accepted.'

    return new ApiError(message, request.status, request.status >= 500, errors)
  } catch {
    return new ApiError('That piece of the file was not accepted.', request.status)
  }
}

function throwIfAborted(signal?: AbortSignal): void {
  if (signal?.aborted) throw new ApiError('Upload cancelled.', 0, false)
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}
