import { createQueryClient, QueryProvider, TenantProvider, UpdatePrompt, WorkspaceBanner } from '@b2b/ui'
import { lazy, StrictMode, Suspense, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, Navigate, RouterProvider } from 'react-router'
import './index.css'
import Login from './Login'
import { ForgotPassword, ResetPassword } from './PasswordPages'
import { HOME, useSession, type SessionType } from './session'

// Each area loads only when its user opens it, so a kaarigar's phone never
// downloads the staff screens.
const StaffApp = lazy(() => import('./staff/StaffApp'))
const KaarigarApp = lazy(() => import('./kaarigar/KaarigarApp'))
const PortalApp = lazy(() => import('./portal/PortalApp'))
const Register = lazy(() => import('./portal/Register'))
const AdminApp = lazy(() => import('./admin/AdminApp'))

const Loading = () => (
  <div className="grid min-h-dvh place-items-center" role="status" aria-label="Loading">
    <span className="size-7 animate-spin rounded-full border-[2.5px] border-brand/20 border-t-brand" />
  </div>
)
const suspend = (node: ReactNode) => <Suspense fallback={<Loading />}>{node}</Suspense>

/** Lets in only the right kind of user; everyone else goes to their own home or to login. */
function Only({ type, children }: { type: SessionType; children: ReactNode }) {
  const { data: session, isLoading } = useSession()

  if (isLoading) return <Loading />
  if (!session) return <Navigate to="/login" replace />
  if (session.type !== type) return <Navigate to={HOME[session.type]} replace />

  return suspend(children)
}

function Home() {
  const { data: session, isLoading } = useSession()
  if (isLoading) return <Loading />
  return <Navigate to={session ? HOME[session.type] : '/login'} replace />
}

const router = createBrowserRouter(
  [
    { index: true, element: <Home /> },
    { path: 'login', element: <Login /> },
    { path: 'forgot', element: <ForgotPassword /> },
    { path: 'reset', element: <ResetPassword /> },
    { path: 'register', element: suspend(<Register />) },
    { path: 'staff/*', element: <Only type="staff"><StaffApp /></Only> },
    { path: 'kaarigar/*', element: <Only type="kaarigar"><KaarigarApp /></Only> },
    { path: 'portal/*', element: <Only type="retailer"><PortalApp /></Only> },
    { path: '*', element: <Navigate to="/" replace /> },
  ],
  { basename: '/' },
)

const queryClient = createQueryClient()

// The super-admin console lives on the central domain and belongs to no workspace.
const isAdmin = window.location.pathname.startsWith('/admin')

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryProvider client={queryClient} storageKey="app-cache">
      {isAdmin ? (
        suspend(<AdminApp />)
      ) : (
        <TenantProvider>
          <WorkspaceBanner />
          <RouterProvider router={router} />
        </TenantProvider>
      )}
      <UpdatePrompt />
    </QueryProvider>
  </StrictMode>,
)
