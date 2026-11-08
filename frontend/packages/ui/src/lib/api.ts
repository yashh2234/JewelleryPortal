/**
 * Small fetch client for the Laravel API (Sanctum SPA cookie auth).
 *
 * - Same-origin in production (acme.yourapp.in/api/...); Vite proxies in dev.
 * - Fetches /sanctum/csrf-cookie once, then sends X-XSRF-TOKEN on writes.
 * - The tenant comes from the subdomain (the Vite dev proxy adds X-Tenant locally).
 * - Adds an Idempotency-Key to every write so retries can't double-post.
 */

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
    public body: unknown = null,
  ) {
    super(message)
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface RequestOptions {
  /** Reuse a key when retrying the same logical action (offline queue). */
  idempotencyKey?: string
  signal?: AbortSignal
}

let csrfReady: Promise<void> | null = null

function readCookie(name: string): string | undefined {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'))
  return match ? decodeURIComponent(match[1]) : undefined
}

function ensureCsrf(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return Promise.resolve()
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then(() => undefined)
  return csrfReady
}

export function newIdempotencyKey(): string {
  return crypto.randomUUID()
}

async function request<T>(method: Method, path: string, body?: unknown, opts: RequestOptions = {}): Promise<T> {
  const isWrite = method !== 'GET'
  if (isWrite) await ensureCsrf()

  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (isWrite) {
    const xsrf = readCookie('XSRF-TOKEN')
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
    headers['Idempotency-Key'] = opts.idempotencyKey ?? newIdempotencyKey()
  }

  let payload: BodyInit | undefined
  if (body instanceof FormData) {
    payload = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  const res = await fetch(path.startsWith('/') ? path : `/${path}`, {
    method,
    headers,
    body: payload,
    credentials: 'include',
    signal: opts.signal,
  })

  // CSRF token expired (419): refresh it once and retry with the same key.
  if (res.status === 419 && isWrite && !opts.idempotencyKey?.endsWith(':retry')) {
    csrfReady = null
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'
    return request(method, path, body, { ...opts, idempotencyKey: (headers['Idempotency-Key'] ?? '') + ':retry' })
  }

  const text = await res.text()
  const data = text ? safeJson(text) : null

  if (!res.ok) {
    const d = (data ?? {}) as { message?: string; errors?: Record<string, string[]> }
    throw new ApiError(res.status, d.message ?? res.statusText, d.errors ?? {}, data)
  }

  return data as T
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

export const api = {
  get: <T>(path: string, opts?: RequestOptions) => request<T>('GET', path, undefined, opts),
  post: <T>(path: string, body?: unknown, opts?: RequestOptions) => request<T>('POST', path, body, opts),
  put: <T>(path: string, body?: unknown, opts?: RequestOptions) => request<T>('PUT', path, body, opts),
  patch: <T>(path: string, body?: unknown, opts?: RequestOptions) => request<T>('PATCH', path, body, opts),
  delete: <T>(path: string, opts?: RequestOptions) => request<T>('DELETE', path, undefined, opts),
}

/** Laravel paginator shape. */
export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
