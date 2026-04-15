import { Suspense, lazy } from "react";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import { Toaster } from "react-hot-toast";
import { AuthProvider } from "./context/AuthContext";
import { EventScopeProvider } from "./context/EventScopeContext";
import PrivateRoute from "./components/PrivateRoute";
import DashboardLayout from "./layouts/DashboardLayout";
import AppVersionGuard from "./components/AppVersionGuard";
import CustomerPrivateRoute from "./components/CustomerPrivateRoute";
import { useOfflineSync } from "./hooks/useOfflineSync";

const Login = lazy(() => import("./pages/Login"));
const Dashboard = lazy(() => import("./pages/Dashboard"));
const AnalyticalDashboard = lazy(() => import("./pages/AnalyticalDashboard"));
const Events = lazy(() => import("./pages/Events"));
const EventDetails = lazy(() => import("./pages/EventDetails"));
const Tickets = lazy(() => import("./pages/Tickets"));
const Cards = lazy(() => import("./pages/Cards"));
const SuperAdminPanel = lazy(() => import("./pages/SuperAdminPanel"));
const Guests = lazy(() => import("./pages/Guests"));
const GuestTicket = lazy(() => import("./pages/GuestTicket"));
const Bar = lazy(() => import("./pages/Bar"));
const Food = lazy(() => import("./pages/Food"));
const Shop = lazy(() => import("./pages/Shop"));
const Parking = lazy(() => import("./pages/Parking"));
const Messaging = lazy(() => import("./pages/Messaging"));
const AIAgents = lazy(() => import("./pages/AIAgents"));
const AIAssistants = lazy(() => import("./pages/AIAssistants"));
const Users = lazy(() => import("./pages/Users"));
const Settings = lazy(() => import("./pages/Settings"));
const Scanner = lazy(() => import("./pages/Operations/Scanner"));
const ParticipantsHub = lazy(() => import("./pages/ParticipantsHub"));
const MealsControl = lazy(() => import("./pages/MealsControl"));
const ArtistsCatalog = lazy(() => import("./pages/ArtistsCatalog"));
const ArtistDetail = lazy(() => import("./pages/ArtistDetail"));
const ArtistImport = lazy(() => import("./pages/ArtistImport"));
const OrganizerFiles = lazy(() => import("./pages/OrganizerFiles"));
const CustomerLogin = lazy(() => import("./pages/CustomerApp/CustomerLogin"));
const CustomerDashboard = lazy(() => import("./pages/CustomerApp/CustomerDashboard"));
const CustomerRecharge = lazy(() => import("./pages/CustomerApp/CustomerRecharge"));
const EventFinanceDashboard = lazy(() => import("./pages/EventFinanceDashboard"));
const EventFinancePayables = lazy(() => import("./pages/EventFinancePayables"));
const EventFinancePayableDetail = lazy(() => import("./pages/EventFinancePayableDetail"));
const EventFinanceSuppliers = lazy(() => import("./pages/EventFinanceSuppliers"));
const EventFinanceBudget = lazy(() => import("./pages/EventFinanceBudget"));
const EventFinanceImport = lazy(() => import("./pages/EventFinanceImport"));
const EventFinanceExport = lazy(() => import("./pages/EventFinanceExport"));
const EventFinanceSettings = lazy(() => import("./pages/EventFinanceSettings"));
const Download = lazy(() => import("./pages/Download"));
const PublicInvitation = lazy(() => import("./pages/PublicInvitation"));

function NotFound() {
  return (
    <div className="min-h-screen bg-gray-950 flex items-center justify-center text-center">
      <div>
        <h1 className="text-6xl font-bold gradient-text mb-4">404</h1>
        <p className="text-gray-400 mb-6">Página não encontrada</p>
        <a href="/" className="btn-primary">
          Voltar ao Dashboard
        </a>
      </div>
    </div>
  );
}

/** Monta o background sync global uma única vez dentro do contexto autenticado */
function GlobalSyncMount() {
  useOfflineSync();
  return null;
}

function RouteLoading() {
  return (
    <div className="min-h-screen bg-gray-950 flex items-center justify-center text-center">
      <div className="rounded-2xl border border-gray-800 bg-gray-900/70 px-6 py-5 text-sm text-gray-300">
        Carregando módulo...
      </div>
    </div>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <GlobalSyncMount />
        <Toaster
          position="top-right"
          toastOptions={{
            style: {
              background: "#1f2937",
              color: "#f9fafb",
              border: "1px solid #374151",
            },
            success: { iconTheme: { primary: "#8b5cf6", secondary: "#fff" } },
          }}
        />
        <AppVersionGuard />
        <Suspense fallback={<RouteLoading />}>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/baixar" element={<Download />} />

            {/* ── Customer App (WebApp Mobile) ────────────────────────── */}
            <Route path="/app/:slug" element={<CustomerLogin />} />
            {/* H14 — Customer pages protected by auth guard */}
            <Route path="/app/:slug" element={<CustomerPrivateRoute />}>
              <Route path="home"     element={<CustomerDashboard />} />
              <Route path="recharge" element={<CustomerRecharge />} />
            </Route>
            <Route path="/invite" element={<GuestTicket />} />
            <Route path="/convite/:eventSlug/:guestToken" element={<PublicInvitation />} />

            <Route element={<PrivateRoute />}>
              <Route element={<EventScopeProvider><DashboardLayout /></EventScopeProvider>}>
                <Route path="/" element={<Dashboard />} />
                <Route path="/analytics" element={<AnalyticalDashboard />} />
                <Route path="/events" element={<Events />} />
                <Route path="/events/:id" element={<EventDetails />} />
                <Route path="/tickets" element={<Tickets />} />
                <Route path="/cards" element={<Cards />} />
                <Route path="/superadmin" element={<SuperAdminPanel />} />


                {/* PDVs Independentes */}
                <Route path="/bar" element={<Bar />} />
                <Route path="/food" element={<Food />} />
                <Route path="/shop" element={<Shop />} />

                <Route path="/parking" element={<Parking />} />
                <Route path="/messaging" element={<Messaging />} />
                <Route path="/ai" element={
                  import.meta.env.VITE_FEATURE_AI_V2_UI === 'true' ? <AIAssistants /> : <AIAgents />
                } />
                <Route path="/files" element={<OrganizerFiles />} />
                <Route path="/users" element={<Users />} />
                <Route path="/guests" element={<Guests />} />
                <Route path="/participants" element={<ParticipantsHub />} />
                <Route path="/artists" element={<ArtistsCatalog />} />
                <Route path="/artists/import" element={<ArtistImport />} />
                <Route path="/artists/:id" element={<ArtistDetail />} />
                <Route path="/meals-control" element={<MealsControl />} />
                <Route path="/scanner" element={<Scanner />} />

                {/* Módulo Financeiro do Evento */}
                <Route path="/finance" element={<EventFinanceDashboard />} />
                <Route path="/finance/payables" element={<EventFinancePayables />} />
                <Route path="/finance/payables/:id" element={<EventFinancePayableDetail />} />
                <Route path="/finance/suppliers" element={<EventFinanceSuppliers />} />
                <Route path="/finance/budget" element={<EventFinanceBudget />} />
                <Route path="/finance/import" element={<EventFinanceImport />} />
                <Route path="/finance/export" element={<EventFinanceExport />} />
                <Route path="/finance/settings" element={<EventFinanceSettings />} />

                {/* Configurações */}
                <Route path="/settings" element={<Settings />} />
              </Route>
            </Route>

            <Route path="*" element={<NotFound />} />
          </Routes>
        </Suspense>
      </AuthProvider>
    </BrowserRouter>
  );
}
