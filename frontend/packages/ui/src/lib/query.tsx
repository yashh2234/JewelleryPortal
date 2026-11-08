import { QueryClient } from '@tanstack/react-query'
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'
import { del, get, set } from 'idb-keyval'
import type { ReactNode } from 'react'
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
  const persister = createAsyncStoragePersister({
    key: storageKey,
    storage: { getItem: (k) => get(k), setItem: (k, v) => set(k, v), removeItem: (k) => del(k) },
  })

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
