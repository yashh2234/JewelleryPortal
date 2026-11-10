import { Navigate, Route, Routes } from 'react-router'
import { Layout } from './layout'
import DaybookPage from './metal/DaybookPage'
import IssuePage from './metal/IssuePage'
import JobCardsPage from './metal/JobCardsPage'
import KaarigarLedgerPage from './metal/KaarigarLedgerPage'
import ReceivePage from './metal/ReceivePage'
import RequestsPage from './metal/RequestsPage'
import TransactionsPage from './metal/TransactionsPage'
import VaultPage from './metal/VaultPage'
import WastagePage from './metal/WastagePage'
import FinishedPage from './production/FinishedPage'
import { HallmarkingPage, HallmarkLotPage } from './production/HallmarkPages'
import ShipmentsPage from './production/ShipmentsPage'
import OrderDetailPage from './production/OrderDetailPage'
import OrdersPage from './production/OrdersPage'
import WorkOrderPage from './production/WorkOrderPage'
import { BoardPage, WorkOrdersPage } from './production/BoardPage'
import AccountSettingsPage from './accounts/AccountSettingsPage'
import BillPage from './accounts/BillPage'
import { PartiesPage, PartyLedgerPage } from './accounts/PartiesPage'
import ReportsPage from './accounts/ReportsPage'
import VouchersPage from './accounts/VouchersPage'
import Branding from './pages/Branding'
import Dashboard from './pages/Dashboard'
import Designs from './pages/Designs'
import GoldRates from './pages/GoldRates'
import Kaarigars from './pages/Kaarigars'
import Karats from './pages/Karats'
import Processes from './pages/Processes'
import Retailers from './pages/Retailers'
import Users from './pages/Users'
import AnalyticsPage from './pages/AnalyticsPage'
import AuditPage from './pages/AuditPage'
import GoldRules from './pages/GoldRules'
import KaarigarProfile from './pages/KaarigarProfile'
import RetailerProfilePage from './crm/RetailerProfilePage'
import { QueriesPage, QueryPage } from './crm/QueriesPages'

/** Manufacturer dashboard, mounted at /staff/*. */
export default function StaffApp() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="metal/issue" element={<IssuePage />} />
        <Route path="metal/receive" element={<ReceivePage />} />
        <Route path="metal/job-cards" element={<JobCardsPage />} />
        <Route path="metal/vault" element={<VaultPage />} />
        <Route path="metal/daybook" element={<DaybookPage />} />
        <Route path="metal/requests" element={<RequestsPage />} />
        <Route path="metal/transactions" element={<TransactionsPage />} />
        <Route path="metal/wastage" element={<WastagePage />} />
        <Route path="orders" element={<OrdersPage />} />
        <Route path="orders/:id" element={<OrderDetailPage />} />
        <Route path="production" element={<BoardPage />} />
        <Route path="work-orders" element={<WorkOrdersPage />} />
        <Route path="work-orders/:id" element={<WorkOrderPage />} />
        <Route path="finished" element={<FinishedPage />} />
        <Route path="hallmarking" element={<HallmarkingPage />} />
        <Route path="hallmarking/:id" element={<HallmarkLotPage />} />
        <Route path="dispatches" element={<ShipmentsPage />} />
        <Route path="accounts/vouchers" element={<VouchersPage />} />
        <Route path="accounts/bill/:id" element={<BillPage />} />
        <Route path="accounts/parties" element={<PartiesPage />} />
        <Route path="accounts/parties/:id" element={<PartyLedgerPage />} />
        <Route path="accounts/reports" element={<ReportsPage />} />
        <Route path="accounts/settings" element={<AccountSettingsPage />} />
        <Route path="kaarigars" element={<Kaarigars />} />
        <Route path="kaarigars/:id" element={<KaarigarProfile />} />
        <Route path="kaarigars/:id/ledger" element={<KaarigarLedgerPage />} />
        <Route path="designs" element={<Designs />} />
        <Route path="retailers" element={<Retailers />} />
        <Route path="retailers/:id" element={<RetailerProfilePage />} />
        <Route path="queries" element={<QueriesPage />} />
        <Route path="queries/:id" element={<QueryPage />} />
        <Route path="reports" element={<AnalyticsPage />} />
        <Route path="reports/:name" element={<AnalyticsPage />} />
        <Route path="audit" element={<AuditPage />} />
        <Route path="settings/gold-rules" element={<GoldRules />} />
        <Route path="settings/karats" element={<Karats />} />
        <Route path="settings/processes" element={<Processes />} />
        <Route path="settings/gold-rates" element={<GoldRates />} />
        <Route path="settings/users" element={<Users />} />
        <Route path="settings/branding" element={<Branding />} />
        <Route path="*" element={<Navigate to="/staff" replace />} />
      </Route>
    </Routes>
  )
}
