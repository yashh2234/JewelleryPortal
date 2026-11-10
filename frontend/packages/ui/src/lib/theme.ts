import { useCallback, useEffect, useSyncExternalStore } from 'react'

export type ThemeChoice = 'light' | 'dark' | 'system'
export type Theme = 'light' | 'dark'

/** Same key as the pre-paint script in index.html. */
const KEY = 'theme'
const listeners = new Set<() => void>()
const media = () => window.matchMedia('(prefers-color-scheme: dark)')

function read(): ThemeChoice {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'light' || v === 'dark' ? v : 'system'
  } catch {
    return 'system'
  }
}

let choice: ThemeChoice = typeof window === 'undefined' ? 'system' : read()

const resolve = (c: ThemeChoice): Theme => (c === 'system' ? (media().matches ? 'dark' : 'light') : c)

function apply(animate: boolean) {
  const root = document.documentElement
  if (animate) {
    root.classList.add('theme-transition')
    window.setTimeout(() => root.classList.remove('theme-transition'), 250)
  }
  root.dataset.theme = resolve(choice)
  listeners.forEach((l) => l())
}

export function setTheme(next: ThemeChoice) {
  choice = next
  try {
    if (next === 'system') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, next)
  } catch {
    /* private mode: still applies for this visit */
  }
  apply(true)
}

const subscribe = (l: () => void) => {
  listeners.add(l)
  return () => listeners.delete(l)
}

/** Current choice (light / dark / system) and the theme actually shown. */
export function useTheme() {
  const current = useSyncExternalStore(subscribe, () => choice, () => 'system' as ThemeChoice)
  const resolved = useSyncExternalStore(subscribe, () => resolve(choice), () => 'light' as Theme)

  // Follow the OS while on "system".
  useEffect(() => {
    const mq = media()
    const onChange = () => choice === 'system' && apply(true)
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [])

  const toggle = useCallback(() => setTheme(resolve(choice) === 'dark' ? 'light' : 'dark'), [])

  return { choice: current, theme: resolved, setTheme, toggle }
}
