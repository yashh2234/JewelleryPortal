import { useSession } from '../session'
import type { StaffUser } from './types'

/** The signed-in staff user (null for anyone else). Login/logout are shared: see session.ts. */
export function useMe() {
  const session = useSession()
  const data: StaffUser | null = session.data?.type === 'staff' ? session.data.user : null
  return { ...session, data }
}

export function useCan() {
  const { data } = useMe()
  // "a|b" = either permission (same syntax as the API's permission middleware).
  return (permission: string) => permission.split('|').some((p) => !!data?.permissions.includes(p))
}
