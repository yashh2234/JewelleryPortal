import { api } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import type { CartSummary } from './types'

/** The shop's cart (server-side, shared by the shop's logins; cached for offline viewing). */
export function useCart() {
  return useQuery({ queryKey: ['portal', 'cart'], queryFn: () => api.get<CartSummary>('/api/portal/v1/cart'), meta: { persist: true } })
}
