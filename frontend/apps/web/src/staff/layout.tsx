import { Button, cn, PushButton, useTenant } from '@b2b/ui'
import {
  ArrowDownLeft, ArrowUpRight, BadgeCheck, BookUser, Calculator, FileSpreadsheet, Receipt, BarChart3, BookOpen, Columns3, Factory, Package, ShoppingCart, Truck, ClipboardList, Gem, Hammer, Inbox, LayoutDashboard, ListOrdered,
  LogOut, Menu, Palette, Scale, Settings2, Store, TrendingUp, Users, Vault, X,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { NavLink, Outlet } from 'react-router'
import { useLogout } from '../session'
import { useCan, useMe } from './auth'

interface NavItem {
  to: string
  label: string
  icon: ReactNode
  permission?: string
}

const NAV: { section?: string; items: NavItem[] }[] = [
  {
    items: [
      { to: '/staff', label: 'Dashboard', icon: <LayoutDashboard /> },
      { to: '/staff/kaarigars', label: 'Kaarigars', icon: <Hammer />, permission: 'kaarigars.view' },
      { to: '/staff/designs', label: 'Designs', icon: <Gem /> },
      { to: '/staff/retailers', label: 'Retailers', icon: <Store />, permission: 'retailers.manage' },
    ],
  },
  {
    section: 'Production',
    items: [
      { to: '/staff/orders', label: 'Orders', icon: <ShoppingCart />, permission: 'orders.manage|production.manage' },
      { to: '/staff/production', label: 'Production board', icon: <Columns3 />, permission: 'production.manage|metal.issue' },
      { to: '/staff/work-orders', label: 'Work orders', icon: <Factory />, permission: 'production.manage|metal.issue' },
      { to: '/staff/finished', label: 'Finished goods', icon: <Package />, permission: 'production.manage|orders.manage' },
      { to: '/staff/hallmarking', label: 'Hallmarking', icon: <BadgeCheck />, permission: 'production.manage' },
      { to: '/staff/dispatches', label: 'Dispatches', icon: <Truck />, permission: 'orders.manage|production.manage' },
    ],
  },
  {
    section: 'Accounts',
    items: [
      { to: '/staff/accounts/vouchers', label: 'Vouchers', icon: <Receipt />, permission: 'accounts.manage|reports.view' },
      { to: '/staff/accounts/parties', label: 'Parties & ledgers', icon: <BookUser />, permission: 'accounts.manage|reports.view' },
      { to: '/staff/accounts/reports', label: 'Reports & GST', icon: <FileSpreadsheet />, permission: 'accounts.manage|reports.view' },
      { to: '/staff/accounts/settings', label: 'Accounting settings', icon: <Calculator />, permission: 'accounts.manage' },
    ],
  },
  {
    section: 'Gold',
    items: [
      { to: '/staff/metal/issue', label: 'Issue gold', icon: <ArrowUpRight />, permission: 'metal.issue' },
      { to: '/staff/metal/receive', label: 'Receive gold', icon: <ArrowDownLeft />, permission: 'metal.receive' },
      { to: '/staff/metal/job-cards', label: 'Job cards', icon: <ClipboardList />, permission: 'kaarigars.view' },
      { to: '/staff/metal/requests', label: 'Kaarigar requests', icon: <Inbox />, permission: 'metal.receive' },
      { to: '/staff/metal/vault', label: 'Vault & stock', icon: <Vault />, permission: 'kaarigars.view' },
      { to: '/staff/metal/daybook', label: 'Day-book & closing', icon: <BookOpen />, permission: 'kaarigars.view' },
      { to: '/staff/metal/transactions', label: 'All transactions', icon: <ListOrdered />, permission: 'kaarigars.view' },
      { to: '/staff/metal/wastage', label: 'Wastage report', icon: <BarChart3 />, permission: 'reports.view' },
    ],
  },
  {
    section: 'Settings',
    items: [
      { to: '/staff/settings/karats', label: 'Karats', icon: <Scale />, permission: 'settings.manage' },
      { to: '/staff/settings/processes', label: 'Processes & wastage', icon: <Settings2 />, permission: 'settings.manage' },
      { to: '/staff/settings/gold-rates', label: 'Gold rate', icon: <TrendingUp />, permission: 'settings.manage' },
      { to: '/staff/settings/users', label: 'Users & roles', icon: <Users />, permission: 'users.manage' },
      { to: '/staff/settings/branding', label: 'Business & branding', icon: <Palette />, permission: 'settings.manage' },
    ],
  },
]

export function Layout() {
  const tenant = useTenant()
  const { data: me } = useMe()
  const can = useCan()
  const logout = useLogout()
  const [open, setOpen] = useState(false)

  const nav = (
    <nav className="flex flex-col gap-4 p-3">
      {NAV.map((group, i) => {
        const items = group.items.filter((it) => !it.permission || can(it.permission))
        if (!items.length) return null
        return (
          <div key={i} className="grid gap-0.5">
            {group.section && <p className="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{group.section}</p>}
            {items.map((it) => (
              <NavLink
                key={it.to}
                to={it.to}
                end={it.to === '/staff'}
                onClick={() => setOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm [&_svg]:size-4',
                    isActive ? 'bg-brand/10 font-medium text-brand' : 'text-foreground/80 hover:bg-muted',
                  )
                }
              >
                {it.icon}
                {it.label}
              </NavLink>
            ))}
          </div>
        )
      })}
    </nav>
  )

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[15rem_1fr]">
      {/* Sidebar (desktop) / drawer (mobile) */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 w-64 overflow-y-auto border-r bg-card transition-transform lg:sticky lg:top-0 lg:h-dvh lg:w-auto lg:translate-x-0',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className="flex h-14 items-center gap-2 border-b px-4">
          {tenant.logo_url ? (
            <img src={tenant.logo_url} alt="" className="size-8 rounded object-contain" />
          ) : (
            <div className="grid size-8 place-items-center rounded bg-brand text-sm font-bold text-brand-foreground">
              {tenant.name.charAt(0)}
            </div>
          )}
          <span className="truncate font-semibold">{tenant.name}</span>
          <button className="ml-auto lg:hidden" onClick={() => setOpen(false)} aria-label="Close menu"><X className="size-5" /></button>
        </div>
        {nav}
      </aside>
      {open && <div className="fixed inset-0 z-30 bg-black/30 lg:hidden" onClick={() => setOpen(false)} />}

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b bg-card/90 px-4 backdrop-blur">
          <button className="lg:hidden" onClick={() => setOpen(true)} aria-label="Open menu"><Menu className="size-5" /></button>
          <div className="ml-auto flex items-center gap-3 text-sm">
            <PushButton label="Alerts" />
            <span className="hidden text-muted-foreground sm:inline">
              {me?.name} · <span className="capitalize">{me?.roles.join(', ').replaceAll('_', ' ')}</span>
            </span>
            <Button variant="ghost" size="sm" onClick={() => logout.mutate()} loading={logout.isPending}>
              <LogOut /> Log out
            </Button>
          </div>
        </header>
        <main className="mx-auto w-full max-w-6xl flex-1 p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

export function PageTitle({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h1 className="text-xl font-semibold">{title}</h1>
      {children && <div className="flex flex-wrap gap-2">{children}</div>}
    </div>
  )
}
