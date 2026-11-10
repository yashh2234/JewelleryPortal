import { api, ApiError, clearPersistedCache } from '@b2b/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { KaarigarMe } from './kaarigar/me'
import type { RetailerMe } from './portal/auth'
import type { StaffUser } from './staff/types'

/** Who is signed in on this device. One login; the server decides the type. */
export type Session =
  | { type: 'staff'; user: StaffUser }
  | { type: 'retailer'; user: RetailerMe }
  | { type: 'kaarigar'; user: KaarigarMe }

export type SessionType = Session['type']

export const sessionKey = ['session'] as const

/** Where each kind of user lands after signing in. */
export const HOME: Record<SessionType, string> = {
  staff: '/staff',
  retailer: '/portal',
  kaarigar: '/kaarigar',
}

export function useSession() {
  return useQuery({
    queryKey: sessionKey,
    queryFn: async (): Promise<Session | null> => {
      try {
        return await api.get<Session>('/api/auth/me')
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
    // Remembered offline, so a kaarigar can open the app without network.
    meta: { persist: true },
    staleTime: 60_000,
  })
}

/**
 * Sign out on this device. Even if the request fails (offline, expired session),
 * the device forgets everything: the in-memory cache, the offline copy in
 * IndexedDB, and then a clean reload of the login page — so nothing from the
 * previous person stays on a shared phone or counter PC.
 */
export function useLogout() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => api.post('/api/auth/logout'),
    onSettled: async () => {
      qc.setQueryData(sessionKey, null)
      await qc.cancelQueries()
      qc.clear()
      await clearPersistedCache()
      window.location.replace('/login')
    },
  })
}
