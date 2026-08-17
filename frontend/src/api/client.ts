const BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/$/, '')

const TOKEN_KEY = 'memories.token'

/**
 * An error with something worth showing a person.
 *
 * The API answers failures in sentences; this carries that sentence through to
 * the interface unchanged rather than replacing it with a status code.
 */
export class ApiError extends Error {
  readonly status: number
  readonly retryable: boolean
  readonly errors: Record<string, string[]>

  constructor(
    message: string,
    status: number,
    retryable = true,
    errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.retryable = retryable
    this.errors = errors
  }

  /** The first field-level message, for showing next to an input. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

export const auth = {
  token(): string | null {
    try {
      return window.localStorage.getItem(TOKEN_KEY)
    } catch {
      // Private browsing modes can refuse storage entirely.
      return null
    }
  },

  setToken(token: string | null) {
    try {
      if (token === null) window.localStorage.removeItem(TOKEN_KEY)
      else window.localStorage.setItem(TOKEN_KEY, token)
    } catch {
      /* Sign-in simply will not persist across reloads. */
    }
  },
}

function headers(extra: HeadersInit = {}): Headers {
  const result = new Headers(extra)
  result.set('Accept', 'application/json')

  const token = auth.token()
  if (token) result.set('Authorization', `Bearer ${token}`)

  return result
}

async function toError(response: Response): Promise<ApiError> {
  let message = 'Something went wrong. Please try again.'
  let retryable = true
  let errors: Record<string, string[]> = {}

  try {
    const body = await response.json()

    if (typeof body?.message === 'string' && body.message.length > 0) message = body.message
    if (typeof body?.retryable === 'boolean') retryable = body.retryable
    if (body?.errors && typeof body.errors === 'object') errors = body.errors

    // Laravel puts validation detail under `errors`; the summary line it
    // generates is less useful than the field message itself.
    const firstField = Object.values(errors)[0]?.[0]
    if (firstField && response.status === 422) message = firstField
  } catch {
    /* A response with no JSON body — the default message stands. */
  }

  if (response.status === 401) {
    auth.setToken(null)
    message = 'Please sign in again.'
    retryable = false
  }

  if (response.status === 429) {
    message = 'That was a lot at once. Give it a moment and try again.'
  }

  return new ApiError(message, response.status, retryable, errors)
}

async function send(path: string, init: RequestInit): Promise<Response> {
  let response: Response

  try {
    response = await fetch(`${BASE_URL}${path}`, init)
  } catch {
    // Offline, DNS failure, CORS — indistinguishable from here, and all of
    // them mean the same thing to the person waiting.
    throw new ApiError("We couldn't reach your archive. Check your connection and try again.", 0)
  }

  if (!response.ok) throw await toError(response)

  return response
}

export const api = {
  async get<T>(path: string, signal?: AbortSignal): Promise<T> {
    const response = await send(path, { method: 'GET', headers: headers(), signal })

    return response.json() as Promise<T>
  },

  async send<T>(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    path: string,
    body?: unknown,
    extraHeaders: HeadersInit = {},
  ): Promise<T> {
    const init: RequestInit = { method, headers: headers(extraHeaders) }

    if (body !== undefined) {
      init.headers = headers({ ...extraHeaders, 'Content-Type': 'application/json' })
      init.body = JSON.stringify(body)
    }

    const response = await send(path, init)

    if (response.status === 204) return undefined as T

    return response.json() as Promise<T>
  },

  url(path: string): string {
    return `${BASE_URL}${path}`
  },
}

/**
 * A key that marks one intent, so a double tap or an automatic retry is
 * recognised by the server as the same save rather than a second one.
 */
export function idempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()

  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}
