import { Monitor, Moon, Sun } from 'lucide-react'
import { cn } from '../lib/utils'
import { useTheme, type ThemeChoice } from '../lib/theme'

/** One-tap light ↔ dark button for headers. `onBrand` for use on a brand-coloured bar. */
export function ThemeToggle({ className, onBrand }: { className?: string; onBrand?: boolean }) {
  const { theme, toggle } = useTheme()
  const dark = theme === 'dark'
  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
      title={dark ? 'Light mode' : 'Dark mode'}
      className={cn(
        'relative grid size-9 shrink-0 place-items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        onBrand ? 'text-current hover:bg-white/15' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
        className,
      )}
    >
      <Sun className={cn('size-[1.125rem] transition-all duration-300', dark ? 'rotate-90 scale-0 opacity-0' : 'rotate-0 scale-100 opacity-100')} />
      <Moon className={cn('absolute size-[1.125rem] transition-all duration-300', dark ? 'rotate-0 scale-100 opacity-100' : '-rotate-90 scale-0 opacity-0')} />
    </button>
  )
}

const OPTIONS: { value: ThemeChoice; label: string; icon: typeof Sun }[] = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'system', label: 'Auto', icon: Monitor },
  { value: 'dark', label: 'Dark', icon: Moon },
]

/** Light / Auto / Dark segmented switch for menus and settings pages. */
export function ThemeSwitcher({ className, compact }: { className?: string; compact?: boolean }) {
  const { choice, setTheme } = useTheme()
  return (
    <div role="radiogroup" aria-label="Appearance" className={cn('grid grid-cols-3 gap-0.5 rounded-lg bg-muted p-0.5', className)}>
      {OPTIONS.map(({ value, label, icon: Icon }) => {
        const active = choice === value
        return (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={active}
            title={label}
            onClick={() => setTheme(value)}
            className={cn(
              'flex h-8 items-center justify-center gap-1.5 rounded-md text-xs font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              active ? 'bg-card text-foreground shadow-card' : 'text-muted-foreground hover:text-foreground',
            )}
          >
            <Icon className="size-3.5" />
            {!compact && label}
          </button>
        )
      })}
    </div>
  )
}
