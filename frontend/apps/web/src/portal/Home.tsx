import { Button, Card, grams, useTenant } from '@b2b/ui'
import { LogOut, Package, ShoppingBag, Wallet } from 'lucide-react'
import { useLogout, useSession } from '../session'

/**
 * Portal home shell. Catalogue, cart, order tracking and the metal/money account
 * arrive in Phase 5 (B2B portal); the layout is ready for them.
 */
export default function Home() {
  const tenant = useTenant()
  const { data: session } = useSession()
  const logout = useLogout()

  if (session?.type !== 'retailer') return null
  const me = session.user

  return (
    <div className="min-h-dvh">
      <header className="border-b bg-card">
        <div className="mx-auto flex h-14 max-w-5xl items-center gap-3 px-4">
          {tenant.logo_url ? <img src={tenant.logo_url} alt="" className="size-8 object-contain" /> : null}
          <span className="font-semibold">{tenant.name}</span>
          <div className="ml-auto flex items-center gap-2 text-sm">
            <span className="hidden text-muted-foreground sm:inline">{me.retailer.shop_name}</span>
            <Button variant="ghost" size="sm" onClick={() => logout.mutate()}><LogOut /> Log out</Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl p-4">
        <h1 className="text-xl font-semibold">Namaste, {me.name}</h1>
        <p className="text-sm text-muted-foreground">{me.retailer.shop_name}{me.retailer.city ? ` · ${me.retailer.city}` : ''}</p>

        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Card className="p-4">
            <ShoppingBag className="size-5 text-brand" />
            <p className="mt-2 font-medium">Catalogue</p>
            <p className="text-sm text-muted-foreground">Browse designs and order in bulk — coming soon.</p>
          </Card>
          <Card className="p-4">
            <Package className="size-5 text-brand" />
            <p className="mt-2 font-medium">My orders</p>
            <p className="text-sm text-muted-foreground">Track each order from production to dispatch — coming soon.</p>
          </Card>
          <Card className="p-4">
            <Wallet className="size-5 text-brand" />
            <p className="mt-2 font-medium">Account</p>
            <p className="tabular text-sm text-muted-foreground">
              {me.retailer.metal_account_enabled ? <>Metal balance: {grams('0.00', 2)}</> : 'Outstanding, invoices and challans'}
            </p>
          </Card>
        </div>
      </main>
    </div>
  )
}
