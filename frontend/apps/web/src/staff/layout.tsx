import { Avatar, cn, NotificationBell, PageHeader, PushButton, ThemeSwitcher, ThemeToggle, useTenant } from '@b2b/ui'
import {
  ArrowDownLeft, ArrowUpRight, BadgeCheck, BookUser, Calculator, FileSpreadsheet, Receipt, BarChart3, BookOpen, Columns3, Factory, Package, ShoppingCart, Truck, ClipboardList, Gem, Hammer, Inbox, LayoutDashboard, ListOrdered,
  LogOut, Menu, MessagesSquare, Palette, PieChart, Scale, Settings2, ShieldCheck, ShieldAlert, Store, TrendingUp, Users, Vault, X,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router'
import { useLogout } from '../session'
import { SHOW_EXTRAS } from '../workflow'
import { useCan, useMe } from './auth'

interface NavItem {
  to: string
  label: string
  icon: ReactNode
  permission?: string
  /** Outside the workflow blueprint: hidden unless SHOW_EXTRAS is on. */
  extra?: true
}

// Ordered by the blueprint's 7-stage flow (see ../workflow.ts).
const NAV: { section?: string; items: NavItem[] }[] = [
  {
    items: [
      { to: '/staff', label: 'Dashboard', icon: <LayoutDashboard /> },
    ],
  },
  {
    section: 'Retailers & CRM',
    items: [
      { to: '/staff/designs', label: 'Design catalogue', icon: <Gem /> },
      { to: '/staff/retailers', label: 'Retailers', icon: <Store />, permission: 'retailers.manage' },
      { to: '/staff/queries', label: 'Enquiries', icon: <MessagesSquare />, permission: 'retailers.manage' },
    ],
  },
  {
    section: 'Orders & production',
    items: [
      { to: '/staff/orders', label: 'B2B orders', icon: <ShoppingCart />, permission: 'orders.manage|orders.approve|production.manage|qc.manage|dispatch.manage' },
      { to: '/staff/work-orders', label: 'Work orders', icon: <Factory />, permission: 'production.manage|metal.issue' },
      { to: '/staff/production', label: 'Production board', icon: <Columns3 />, permission: 'production.manage|metal.issue' },
    ],
  },
  {
    section: 'Gold & karigars',
    items: [
      { to: '/staff/metal/issue', label: 'Issue gold', icon: <ArrowUpRight />, permission: 'metal.issue' },
      { to: '/staff/metal/receive', label: 'Receive / return gold', icon: <ArrowDownLeft />, permission: 'metal.receive' },
      { to: '/staff/metal/job-cards', label: 'Job cards', icon: <ClipboardList />, permission: 'kaarigars.view' },
      { to: '/staff/kaarigars', label: 'Kaarigars', icon: <Hammer />, permission: 'kaarigars.view' },
      { to: '/staff/metal/vault', label: 'Raw stock & transfers', icon: <Vault />, permission: 'kaarigars.view' },
      { to: '/staff/metal/transactions', label: 'Gold movement ledger', icon: <ListOrdered />, permission: 'kaarigars.view' },
      { to: '/staff/metal/requests', label: 'Kaarigar requests', icon: <Inbox />, permission: 'metal.receive', extra: true },
      { to: '/staff/metal/daybook', label: 'Day-book & closing', icon: <BookOpen />, permission: 'kaarigars.view', extra: true },
      { to: '/staff/metal/wastage', label: 'Wastage report', icon: <BarChart3 />, permission: 'reports.view', extra: true },
    ],
  },
  {
    section: 'QC, inventory & dispatch',
    items: [
      { to: '/staff/finished', label: 'QC & finished goods', icon: <Package />, permission: 'production.manage|qc.manage|dispatch.manage' },
      { to: '/staff/hallmarking', label: 'Hallmarking', icon: <BadgeCheck />, permission: 'production.manage', extra: true },
      { to: '/staff/dispatches', label: 'Dispatch & shipping', icon: <Truck />, permission: 'dispatch.manage|orders.manage' },
    ],
  },
  {
    section: 'Accounts',
    items: [
      { to: '/staff/accounts/vouchers', label: 'Vouchers', icon: <Receipt />, permission: 'accounts.manage|reports.view', extra: true },
      { to: '/staff/accounts/parties', label: 'Parties & ledgers', icon: <BookUser />, permission: 'accounts.manage|reports.view', extra: true },
      { to: '/staff/accounts/reports', label: 'Reports & GST', icon: <FileSpreadsheet />, permission: 'accounts.manage|reports.view', extra: true },
      { to: '/staff/accounts/settings', label: 'Accounting settings', icon: <Calculator />, permission: 'accounts.manage', extra: true },
    ],
  },
  {
    section: 'Insights',
    items: [
      { to: '/staff/reports', label: 'Reports & reconciliation', icon: <PieChart />, permission: 'reports.view' },
      { to: '/staff/audit', label: 'Audit log', icon: <ShieldCheck />, permission: 'audit.view', extra: true },
    ],
  },
  {
    section: 'Settings',
    items: [
      { to: '/staff/settings/karats', label: 'Purities (karats)', icon: <Scale />, permission: 'settings.manage' },
      { to: '/staff/settings/processes', label: 'Production stages', icon: <Settings2 />, permission: 'settings.manage' },
      { to: '/staff/settings/gold-rates', label: 'Gold rate', icon: <TrendingUp />, permission: 'settings.manage' },
      { to: '/staff/settings/gold-rules', label: 'Gold issue rules', icon: <ShieldAlert />, permission: 'settings.manage', extra: true },
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
  const navigate = useNavigate()
  const role = me?.roles.join(', ').replaceAll('_', ' ') ?? ''

  const nav = (
    <nav className="flex flex-col gap-5 px-3 py-4">
      {NAV.map((group, i) => {
        const items = group.items.filter((it) => (SHOW_EXTRAS || !it.extra) && (!it.permission || can(it.permission)))
        if (!items.length) return null
        return (
          <div key={i} className="grid gap-px">
            {group.section && <p className="px-3 pb-1.5 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground/80">{group.section}</p>}
            {items.map((it) => (
              <NavLink
                key={it.to}
                to={it.to}
                end={it.to === '/staff'}
                onClick={() => setOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'group relative flex items-center gap-3 rounded-lg px-3 py-[0.4375rem] text-sm transition-colors [&_svg]:size-4 [&_svg]:shrink-0',
                    isActive
                      ? 'bg-brand/10 font-medium text-brand before:absolute before:inset-y-1.5 before:-left-3 before:w-[3px] before:rounded-r-full before:bg-brand'
                      : 'text-foreground/70 hover:bg-muted hover:text-foreground [&_svg]:text-muted-foreground hover:[&_svg]:text-foreground',
                  )
                }
              >
                {it.icon}
                <span className="truncate">{it.label}</span>
              </NavLink>
            ))}
          </div>
        )
      })}
    </nav>
  )

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[16rem_1fr]">
      {/* Sidebar (desktop) / drawer (mobile) */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-72 flex-col border-r bg-card transition-transform duration-300 ease-out lg:sticky lg:top-0 lg:h-dvh lg:w-auto lg:translate-x-0',
          open ? 'translate-x-0 shadow-pop' : '-translate-x-full',
        )}
      >
        <div className="flex h-16 shrink-0 items-center gap-3 px-5">
          {tenant.logo_url ? (
            <img src={tenant.logo_url} alt="" className="size-9 rounded-lg object-contain" />
          ) : (
            <div className="bg-brand-sheen grid size-9 place-items-center rounded-lg font-display text-base font-semibold text-brand-foreground shadow-xs">
              {tenant.name.charAt(0)}
            </div>
          )}
          <span className="truncate font-display text-[1.0625rem] font-semibold">{tenant.name}</span>
          <button className="ml-auto grid size-8 place-items-center rounded-md text-muted-foreground hover:bg-muted lg:hidden" onClick={() => setOpen(false)} aria-label="Close menu"><X className="size-5" /></button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto">{nav}</div>

        <div className="shrink-0 space-y-3 border-t p-3">
          <ThemeSwitcher />
          <div className="flex items-center gap-3 rounded-lg px-1.5 py-1">
            <Avatar name={me?.name ?? ''} />
            <div className="min-w-0 flex-1 leading-tight">
              <p className="truncate text-sm font-medium">{me?.name}</p>
              <p className="truncate text-xs capitalize text-muted-foreground">{role}</p>
            </div>
            <button
              onClick={() => logout.mutate()}
              disabled={logout.isPending}
              aria-label="Log out"
              title="Log out"
              className="grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-danger/10 hover:text-danger disabled:opacity-50"
            >
              <LogOut className="size-4" />
            </button>
          </div>
        </div>
      </aside>
      {open && <div className="fixed inset-0 z-30 bg-black/40 backdrop-blur-[2px] lg:hidden" onClick={() => setOpen(false)} />}

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-2 border-b bg-background/80 px-4 backdrop-blur-xl sm:px-6 lg:border-transparent">
          <button className="-ml-1 grid size-9 place-items-center rounded-md hover:bg-muted lg:hidden" onClick={() => setOpen(true)} aria-label="Open menu"><Menu className="size-5" /></button>
          <span className="truncate font-display font-semibold lg:hidden">{tenant.name}</span>
          <div className="ml-auto flex items-center gap-1.5">
            <PushButton label="Alerts" />
            <NotificationBell base="/api/v1" onOpen={(url) => navigate(url)} />
            <ThemeToggle />
            <Avatar name={me?.name ?? ''} className="lg:hidden" />
          </div>
        </header>
        <main className="animate-fade-up mx-auto w-full max-w-6xl flex-1 px-4 pb-10 pt-2 sm:px-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

export function PageTitle({ title, description, children }: { title: string; description?: ReactNode; children?: ReactNode }) {
  return <PageHeader title={title} description={description}>{children}</PageHeader>
}
