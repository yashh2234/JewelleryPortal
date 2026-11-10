import { expect, test, type Page } from '@playwright/test'

/**
 * Every sign-in path, every screen in each user's menu, and sign-out — watching
 * for console errors, uncaught exceptions and unexpected failed requests.
 */

type Problem = string

/** Requests that are allowed to fail (expected 401 before login, etc.). */
const EXPECTED = [
  /\/api\/auth\/me$/, // 401 while signed out
  /\/api\/platform\/admin\/me$/,
  /\/api\/push\/key$/,
]

function watch(page: Page): Problem[] {
  const problems: Problem[] = []
  page.on('pageerror', (e) => problems.push(`uncaught: ${e.message}`))
  page.on('console', (m) => {
    if (m.type() !== 'error') return
    const text = m.text()
    if (text.startsWith('Failed to load resource')) return // tracked via responses below
    problems.push(`console: ${text.slice(0, 300)}`)
  })
  page.on('response', (r) => {
    const url = new URL(r.url())
    if (!url.pathname.startsWith('/api/') && !url.pathname.startsWith('/sanctum/')) return
    const s = r.status()
    if (s < 400) return
    if (s === 401 && EXPECTED.some((re) => re.test(url.pathname))) return
    problems.push(`${r.request().method()} ${url.pathname}${url.search} → ${s}`)
  })
  return problems
}

async function signInEmail(page: Page, email: string, password = 'password') {
  await page.goto('/login')
  await page.getByPlaceholder(/you@shop.com/).fill(email)
  await page.getByRole('button', { name: /continue/i }).click()
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: /^sign in$/i }).click()
}

async function signInMobile(page: Page, phone: string) {
  await page.goto('/login')
  await page.getByPlaceholder(/you@shop.com/).fill(phone)
  await page.getByRole('button', { name: /continue/i }).click()
  // Local dev returns the OTP and fills it in.
  await expect(page.getByLabel(/6-digit OTP/)).toHaveValue(/^\d{6}$/)
  await page.getByRole('button', { name: /^sign in$/i }).click()
}

async function expectSignedOut(page: Page) {
  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByPlaceholder(/you@shop.com/)).toBeVisible()
  // Session really gone: protected pages bounce back to login.
  for (const path of ['/staff', '/portal', '/kaarigar']) {
    await page.goto(path)
    await expect(page).toHaveURL(/\/login$/)
  }
}

/** Open each link, wait for it to settle, and tag any new problem with the page. */
async function visitAll(page: Page, hrefs: string[], problems: Problem[]) {
  for (const href of hrefs) {
    const before = problems.length
    await page.goto(href)
    await page.waitForLoadState('networkidle')
    await expect(page.locator('body')).not.toContainText(/something went wrong|cannot read|undefined is not/i)
    await expect(page).not.toHaveURL(/\/login$/)   // still signed in
    for (let i = before; i < problems.length; i++) problems[i] = `[${href}] ${problems[i]}`
  }
}

async function staffLinks(page: Page): Promise<string[]> {
  await expect(page.locator('aside nav a[href="/staff"]')).toBeVisible()
  const links = await page.locator('aside nav a[href^="/staff"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')!))
  return [...new Set(links)]
}

test.describe('sign-in and sign-out flows', () => {
  test('root opens the login page when signed out', async ({ page }) => {
    const problems = watch(page)
    await page.goto('/')
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('heading', { name: 'Acme Gold Manufacturers' })).toBeVisible()
    expect(problems).toEqual([])
  })

  test('owner by email: every staff screen, then log out', async ({ page }) => {
    const problems = watch(page)
    await signInEmail(page, 'owner@acme.test')
    await expect(page).toHaveURL(/\/staff$/)
    await expect(page.getByRole('heading', { name: /Good (morning|afternoon|evening)/ })).toBeVisible()

    const links = await staffLinks(page)
    expect(links.length).toBeGreaterThan(20)
    await visitAll(page, links, problems)
    // Detail pages reached from lists.
    await visitAll(page, ['/staff/orders/1', '/staff/work-orders/1', '/staff/kaarigars/1/ledger', '/staff/accounts/parties/1'], problems)

    await page.getByRole('button', { name: /log out/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('owner by mobile OTP', async ({ page }) => {
    const problems = watch(page)
    await signInMobile(page, '+91 98765 00010')
    await expect(page).toHaveURL(/\/staff$/)
    await page.getByRole('button', { name: /log out/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('metal manager sees only allowed screens and they all load', async ({ page }) => {
    const problems = watch(page)
    await signInEmail(page, 'metal@acme.test')
    await expect(page).toHaveURL(/\/staff$/)
    const links = await staffLinks(page)
    expect(links).toContain('/staff/metal/issue')
    expect(links).not.toContain('/staff/settings/users')
    await visitAll(page, links, problems)
    await page.getByRole('button', { name: /log out/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('retailer by email: catalogue → cart → order → account, then log out', async ({ page }) => {
    const problems = watch(page)
    await signInEmail(page, 'shop@sharma.test')
    await expect(page).toHaveURL(/\/portal$/)

    await page.getByText('Classic kada').first().click()
    await expect(page).toHaveURL(/\/portal\/designs\//)
    await page.getByRole('button', { name: '2.6', exact: true }).click()
    await page.getByRole('button', { name: /add 1 to cart/i }).click()
    await expect(page.getByText(/added to cart/i)).toBeVisible()

    await page.goto('/portal/cart')
    await page.getByRole('button', { name: /place order/i }).click()
    await expect(page).toHaveURL(/\/portal\/orders\/\d+$/)
    await expect(page.getByText('Received').first()).toBeVisible()

    await visitAll(page, ['/portal', '/portal/cart', '/portal/orders', '/portal/account'], problems)

    await page.getByRole('button', { name: /log out/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('retailer by mobile OTP', async ({ page }) => {
    const problems = watch(page)
    await signInMobile(page, '9822000000')
    await expect(page).toHaveURL(/\/portal$/)
    await page.getByRole('button', { name: /log out/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('kaarigar by mobile: every screen, then log out', async ({ page }) => {
    const problems = watch(page)
    await signInMobile(page, '9876500003')   // Suresh has gold on an open job
    await expect(page).toHaveURL(/\/kaarigar$/)
    await visitAll(page, ['/kaarigar', '/kaarigar/issues', '/kaarigar/jobs', '/kaarigar/khata', '/kaarigar/requests', '/kaarigar/earnings'], problems)
    await page.goto('/kaarigar')
    await page.getByRole('button', { name: /log out|लॉगआउट/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('wrong password and unknown number show clear messages', async ({ page }) => {
    const problems = watch(page)
    await signInEmail(page, 'owner@acme.test', 'wrong-password')
    await expect(page.getByRole('alert')).toContainText(/do not match|incorrect|credentials/i)
    // Only the expected 422 from the failed login.
    expect(problems.filter((p) => !/POST \/api\/auth\/login → 422/.test(p))).toEqual([])

    await page.getByRole('button', { name: /different email or number/i }).click()
    await page.getByPlaceholder(/you@shop.com/).fill('12345')
    await page.getByRole('button', { name: /continue/i }).click()
    await expect(page.getByRole('alert')).toContainText(/10-digit mobile/)
  })

  test('switching users on one device keeps sessions apart', async ({ page }) => {
    const problems = watch(page)
    await signInEmail(page, 'owner@acme.test')
    await expect(page).toHaveURL(/\/staff$/)
    await page.getByRole('button', { name: /log out/i }).click()
    await expect(page).toHaveURL(/\/login$/)

    await signInEmail(page, 'shop@sharma.test')
    await expect(page).toHaveURL(/\/portal$/)
    await page.goto('/staff')
    await expect(page).toHaveURL(/\/portal$/)   // a retailer can't open staff screens
    await page.getByRole('button', { name: /log out/i }).click()
    await expectSignedOut(page)
    expect(problems).toEqual([])
  })

  test('forgot password and retailer sign-up pages', async ({ page }) => {
    const problems = watch(page)
    await page.goto('/forgot')
    await page.getByLabel('Email').fill('owner@acme.test')
    await page.getByRole('button', { name: /send reset link/i }).click()
    await expect(page.getByRole('alert')).toContainText(/reset link/i)

    await page.goto('/register')
    await expect(page.getByRole('heading', { name: /request a retailer account/i })).toBeVisible()
    expect(problems).toEqual([])
  })

  test('super-admin console signs in and out', async ({ page }) => {
    const problems = watch(page)
    await page.goto('/admin')
    await page.getByLabel('Email').fill('admin@platform.test')
    await page.getByLabel('Password').fill('password')
    await page.getByRole('button', { name: /sign in/i }).click()
    await expect(page.getByRole('heading', { name: 'Workspaces' })).toBeVisible()
    await page.getByRole('button', { name: /log out/i }).click()
    await expect(page.getByRole('heading', { name: 'Platform admin' })).toBeVisible()
    expect(problems).toEqual([])
  })
})
