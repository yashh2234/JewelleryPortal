import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError } from './api'

export interface TenantBranding {
  name: string
  subdomain: string
  logo_url: string | null
  primary_color: string
  status: 'trial' | 'active' | 'suspended' | 'cancelled'
  trial_ends_at: string | null
  /** Trial ended: everything is viewable, nothing can be saved. */
  read_only: boolean
}

declare global {
  interface Window {
    /** Injected by Laravel's SpaController on deep links; absent on cached/static loads. */
    __BOOT__?: { tenant: TenantBranding; app: string }
  }
}

const CACHE_KEY = 'tenant-branding'
const TenantContext = createContext<TenantBranding | null>(null)

function cached(): TenantBranding | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY)
    return raw ? (JSON.parse(raw) as TenantBranding) : null
  } catch {
    return null
  }
}

function applyBrand(t: TenantBranding) {
  document.documentElement.style.setProperty('--brand', t.primary_color)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', t.primary_color)
  try {
    localStorage.setItem(CACHE_KEY, JSON.stringify(t))
  } catch {
    /* private mode */
  }
}

/**
 * Loads the tenant (workspace) branding: from the server-injected boot data, else
 * from /api/tenant, else from the last cached copy (offline start).
 */
export function TenantProvider({ children, appTitle }: { children: ReactNode; appTitle?: string }) {
  const [tenant, setTenant] = useState<TenantBranding | null>(() => window.__BOOT__?.tenant ?? null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (tenant) return
    api
      .get<{ data: TenantBranding }>('/api/tenant')
      .then((r) => setTenant(r.data))
      .catch((e: unknown) => {
        const fallback = cached()
        if (fallback) setTenant(fallback)
        else setError(e instanceof ApiError ? e.message : 'Could not reach the server.')
      })
  }, [tenant])

  useEffect(() => {
    if (!tenant) return
    applyBrand(tenant)
    document.title = appTitle ? `${appTitle} · ${tenant.name}` : tenant.name
  }, [tenant, appTitle])

  if (error) {
    return (
      <div className="flex min-h-dvh items-center justify-center p-6 text-center">
        <div>
          <p className="text-lg font-semibold">Workspace not available</p>
          <p className="mt-1 text-sm text-muted-foreground">{error}</p>
        </div>
      </div>
    )
  }

  if (!tenant) {
    return (
      <div className="grid min-h-dvh place-items-center" role="status" aria-label="Loading">
        <span className="size-7 animate-spin rounded-full border-[2.5px] border-brand/20 border-t-brand" />
      </div>
    )
  }

  return <TenantContext.Provider value={tenant}>{children}</TenantContext.Provider>
}

export function useTenant(): TenantBranding {
  const t = useContext(TenantContext)
  if (!t) throw new Error('useTenant() outside <TenantProvider>')
  return t
}
