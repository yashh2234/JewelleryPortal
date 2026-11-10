import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'
import { cn } from '../lib/utils'

interface Item { id: number; event: string | null; title: string; body: string | null; url: string | null; read_at: string | null; created_at: string }

function ago(iso: string): string {
  const s = Math.max(0, (Date.now() - new Date(iso.replace(' ', 'T') + (iso.endsWith('Z') ? '' : 'Z')).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)} min ago`
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`
  return `${Math.floor(s / 86400)} d ago`
}

/**
 * In-app notifications (spec §14). `base` is the area's API prefix
 * (/api/v1, /api/portal/v1, /api/kaarigar/v1); `onOpen` navigates in-app.
 */
export function NotificationBell({ base, onOpen, onBrand, className }: { base: string; onOpen: (url: string) => void; onBrand?: boolean; className?: string }) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const key = ['notifications', base]

  const q = useQuery({
    queryKey: key,
    queryFn: () => api.get<{ data: Item[]; unread: number }>(`${base}/notifications`),
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  })
  const read = useMutation({
    mutationFn: (ids?: number[]) => api.post<{ unread: number }>(`${base}/notifications/read`, ids ? { ids } : {}),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  })

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false) }
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc) }
  }, [open])

  const unread = q.data?.unread ?? 0
  const items = q.data?.data ?? []

  return (
    <div ref={ref} className={cn('relative', className)}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}
        aria-expanded={open}
        className={cn(
          'relative grid size-9 place-items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          onBrand ? 'text-current hover:bg-white/15' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
        )}
      >
        <Bell className="size-[1.125rem]" />
        {unread > 0 && (
          <span className="tabular absolute -right-0.5 -top-0.5 grid min-w-[1.125rem] place-items-center rounded-full bg-danger px-1 text-[0.625rem] font-semibold leading-[1.125rem] text-white ring-2 ring-background">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="animate-fade-up absolute right-0 top-11 z-50 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border bg-elevated text-foreground shadow-pop">
          <div className="flex items-center justify-between border-b px-4 py-2.5">
            <p className="text-sm font-semibold">Notifications</p>
            {unread > 0 && (
              <button className="flex items-center gap-1 text-xs font-medium text-brand hover:underline" onClick={() => read.mutate(undefined)}>
                <CheckCheck className="size-3.5" /> Mark all read
              </button>
            )}
          </div>
          <div className="max-h-[60vh] overflow-y-auto">
            {items.length === 0 ? (
              <p className="px-4 py-10 text-center text-sm text-muted-foreground">You're all caught up.</p>
            ) : (
              items.map((n) => (
                <button
                  key={n.id}
                  type="button"
                  onClick={() => {
                    if (!n.read_at) read.mutate([n.id])
                    setOpen(false)
                    if (n.url) onOpen(n.url)
                  }}
                  className={cn('flex w-full gap-3 border-b px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-muted/60', !n.read_at && 'bg-brand/[0.04]')}
                >
                  <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-brand')} />
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium leading-snug">{n.title}</span>
                    {n.body && <span className="mt-0.5 block text-xs text-muted-foreground">{n.body}</span>}
                    <span className="mt-1 block text-[0.6875rem] text-muted-foreground/80">{ago(n.created_at)}</span>
                  </span>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}
