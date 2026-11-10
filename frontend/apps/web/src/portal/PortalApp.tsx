import { api, Button, cn, NotificationBell, PushButton, ThemeToggle, useTenant } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { LayoutGrid, LifeBuoy, LogOut, Package, ShoppingCart, Wallet } from 'lucide-react'
import { Link, Navigate, NavLink, Outlet, Route, Routes, useNavigate } from 'react-router'
import { useLogout, useSession } from '../session'
import { AccountPage } from './AccountPage'
import { CartPage } from './CartPage'
import { CataloguePage, DesignPage } from './CataloguePages'
import { OrderPage, OrdersPage } from './OrderPages'
import { useCart } from './cart'
import { NewQueryPage, QueriesPage, QueryPage } from './QueryPages'

/** Retailer portal, mounted at /portal/*. Mobile-first with a bottom tab bar. */
export default function PortalApp() {
  return (
    <Routes>
      <Route element={<Shell />}>
        <Route index element={<CataloguePage />} />
        <Route path="designs/:code" element={<DesignPage />} />
        <Route path="cart" element={<CartPage />} />
        <Route path="orders" element={<OrdersPage />} />
        <Route path="orders/:id" element={<OrderPage />} />
        <Route path="account" element={<AccountPage />} />
        <Route path="queries" element={<QueriesPage />} />
        <Route path="queries/new" element={<NewQueryPage />} />
        <Route path="queries/:id" element={<QueryPage />} />
        <Route path="*" element={<Navigate to="/portal" replace />} />
      </Route>
    </Routes>
  )
}

function Shell() {
  const tenant = useTenant()
  const { data: session } = useSession()
  const logout = useLogout()
  const navigate = useNavigate()
  const cart = useCart()
  const rate = useQuery({
    queryKey: ['portal', 'gold-rate'],
    queryFn: () => api.get<{ data: { karat: string; rate_per_g: string }[] }>('/api/portal/v1/gold-rate'),
    meta: { persist: true },
  })
  const r22 = rate.data?.data.find((r) => r.karat === '22K') ?? rate.data?.data[0]
  const pieces = cart.data?.totals.pieces ?? 0
  const shop = session?.type === 'retailer' ? session.user.retailer.shop_name : ''

  const tabs = [
    { to: '/portal', label: 'Catalogue', icon: <LayoutGrid />, end: true },
    { to: '/portal/cart', label: 'Cart', icon: <ShoppingCart />, badge: pieces },
    { to: '/portal/orders', label: 'Orders', icon: <Package /> },
    { to: '/portal/account', label: 'Account', icon: <Wallet /> },
  ]

  const rateText = r22 && <>{r22.karat} <span className="tabular">₹{Number(r22.rate_per_g).toLocaleString('en-IN')}</span>/g today</>

  return (
    <div className="min-h-dvh pb-24 md:pb-0">
      <header className="sticky top-0 z-20 border-b bg-background/80 backdrop-blur-xl">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
          {tenant.logo_url
            ? <img src={tenant.logo_url} alt="" className="size-9 rounded-lg object-contain" />
            : <div className="bg-brand-sheen grid size-9 place-items-center rounded-lg font-display text-base font-semibold text-brand-foreground shadow-xs">{tenant.name[0]}</div>}
          <div className="min-w-0">
            <p className="truncate font-display text-[1.0625rem] font-semibold leading-tight">{tenant.name}</p>
            <p className="truncate text-xs text-muted-foreground">{shop}</p>
          </div>
          <nav className="ml-auto hidden items-center gap-0.5 rounded-xl bg-muted/70 p-1 md:flex">
            {tabs.map((t) => (
              <NavLink
                key={t.to} to={t.to} end={t.end}
                className={({ isActive }) => cn('relative flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-medium transition-all', isActive ? 'bg-card text-foreground shadow-card' : 'text-muted-foreground hover:text-foreground')}
              >
                {t.label}{!!t.badge && <span className="tabular grid min-w-5 place-items-center rounded-full bg-brand px-1.5 text-[0.6875rem] leading-5 text-brand-foreground">{t.badge}</span>}
              </NavLink>
            ))}
          </nav>
          <div className="ml-auto flex items-center gap-1 md:ml-2">
            {r22 && <span className="mr-1 hidden items-center gap-1.5 rounded-full border border-brand/25 bg-brand/[0.07] px-3 py-1 text-xs font-medium text-brand lg:inline-flex"><span className="size-1.5 rounded-full bg-brand" />{rateText}</span>}
            <PushButton label="Alerts" className="hidden sm:inline-flex" />
            <Link to="/portal/queries" aria-label="Help & requests" title="Help & requests" className="grid size-9 place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"><LifeBuoy className="size-[1.125rem]" /></Link>
            <NotificationBell base="/api/portal/v1" onOpen={(url) => navigate(url)} />
            <ThemeToggle />
            <Button variant="ghost" size="icon" className="size-9 rounded-full text-muted-foreground" onClick={() => logout.mutate()} aria-label="Log out" title="Log out"><LogOut /></Button>
          </div>
        </div>
        {r22 && <p className="border-t border-brand/10 bg-brand/[0.06] py-1.5 text-center text-xs font-medium text-brand lg:hidden">{rateText}</p>}
      </header>

      <main className="animate-fade-up mx-auto max-w-6xl px-4 py-5"><Outlet /></main>

      <nav className="fixed inset-x-3 bottom-3 z-20 grid grid-cols-4 rounded-2xl border bg-elevated/90 p-1 shadow-pop backdrop-blur-xl md:hidden" style={{ marginBottom: 'env(safe-area-inset-bottom)' }}>
        {tabs.map((t) => (
          <NavLink
            key={t.to} to={t.to} end={t.end}
            className={({ isActive }) => cn('relative flex flex-col items-center gap-0.5 rounded-xl py-2 text-[0.6875rem] font-medium transition-colors [&_svg]:size-5', isActive ? 'bg-brand/10 text-brand' : 'text-muted-foreground')}
          >
            {t.icon}{t.label}
            {!!t.badge && <span className="tabular absolute right-[22%] top-1 grid min-w-[1.125rem] place-items-center rounded-full bg-brand px-1 text-[0.625rem] leading-[1.125rem] text-brand-foreground ring-2 ring-elevated">{t.badge}</span>}
          </NavLink>
        ))}
      </nav>
    </div>
  )
}
