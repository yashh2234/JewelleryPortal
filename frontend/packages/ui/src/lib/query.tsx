import { QueryClient } from '@tanstack/react-query'
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'
import { clear, del, get, set } from 'idb-keyval'
import { useMemo, type ReactNode } from 'react'
import { ApiError } from './api'

/**
 * TanStack Query, persisted to IndexedDB so the last-synced data (khata, jobs,
 * catalogue, orders) can be read offline. Only queries marked meta.persist are saved.
 */
export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 1000 * 60 * 60 * 24 * 7,
        retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
        refetchOnWindowFocus: true,
      },
      mutations: { retry: false },
    },
  })
}

export function QueryProvider({ client, storageKey, children }: { client: QueryClient; storageKey: string; children: ReactNode }) {
  const persister = useMemo(() => createAsyncStoragePersister({
    key: storageKey,
    storage: { getItem: (k) => get(k), setItem: (k, v) => set(k, v), removeItem: (k) => del(k) },
  }), [storageKey])

  return (
    <PersistQueryClientProvider
      client={client}
      persistOptions={{
        persister,
        maxAge: 1000 * 60 * 60 * 24 * 7,
        dehydrateOptions: { shouldDehydrateQuery: (q) => q.state.status === 'success' && q.meta?.persist === true },
      }}
    >
      {children}
    </PersistQueryClientProvider>
  )
}

/**
 * Forget everything stored on this device for the signed-in person: the offline
 * copy of their data and any actions still waiting to be sent. Used on sign-out,
 * so the next person on a shared phone sees nothing of the previous one.
 */
export async function clearPersistedCache(): Promise<void> {
  try {
    await clear()
  } catch {
    /* storage unavailable (private mode): nothing to clear */
  }
}
