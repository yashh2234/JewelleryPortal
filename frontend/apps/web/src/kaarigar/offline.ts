import { api, ApiError, newIdempotencyKey } from '@b2b/ui'
import { del, entries, set } from 'idb-keyval'

/**
 * Offline queue for low-risk kaarigar actions (accept / dispute an issue, return
 * requests with photos). Each item keeps one idempotency key for all its retries,
 * so a replay can never create a second request. Gold postings themselves are
 * always online and done by the store.
 */
export interface QueuedAction {
  id: string
  path: string
  /** JSON body, or form fields (strings and photo blobs) for multipart. */
  json?: unknown
  form?: [string, string | Blob][]
  label: string
  createdAt: number
}

const PREFIX = 'kq:'
const listeners = new Set<() => void>()

export function onQueueChange(fn: () => void) {
  listeners.add(fn)
  return () => {
    listeners.delete(fn)
  }
}
const notify = () => listeners.forEach((fn) => fn())

export async function queued(): Promise<QueuedAction[]> {
  const all = await entries<string, QueuedAction>()
  return all.filter(([k]) => String(k).startsWith(PREFIX)).map(([, v]) => v).sort((a, b) => a.createdAt - b.createdAt)
}

async function send(a: QueuedAction) {
  if (a.form) {
    const fd = new FormData()
    a.form.forEach(([k, v]) => fd.append(k, v))
    return api.post(a.path, fd, { idempotencyKey: a.id })
  }
  return api.post(a.path, a.json, { idempotencyKey: a.id })
}

/** Try now; if the network is down, keep it for later. Returns 'sent' or 'queued'. */
export async function submit(action: Omit<QueuedAction, 'id' | 'createdAt'>): Promise<'sent' | 'queued'> {
  const a: QueuedAction = { ...action, id: newIdempotencyKey(), createdAt: Date.now() }
  if (a.form) a.form.push(['client_key', a.id])

  if (navigator.onLine) {
    try {
      await send(a)
      return 'sent'
    } catch (e) {
      if (e instanceof ApiError) throw e // server refused: show the error, don't queue
    }
  }

  await set(PREFIX + a.id, a)
  notify()
  return 'queued'
}

let flushing = false

/** Send everything that is waiting. Refused items are dropped (and reported). */
export async function flush(onRefused?: (a: QueuedAction, e: ApiError) => void) {
  if (flushing || !navigator.onLine) return
  flushing = true
  try {
    for (const a of await queued()) {
      try {
        await send(a)
        await del(PREFIX + a.id)
      } catch (e) {
        if (e instanceof ApiError && e.status < 500) {
          await del(PREFIX + a.id)
          onRefused?.(a, e)
        } else {
          break // still offline / server down: try again later
        }
      }
    }
  } finally {
    flushing = false
    notify()
  }
}
