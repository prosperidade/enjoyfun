import { BrowserRouter, Routes, Route } from "react-router-dom";
import { Toaster } from "react-hot-toast";
import { AuthProvider } from "./context/AuthContext";
import { EventScopeProvider } from "./context/EventScopeContext";
import PrivateRoute from "./components/PrivateRoute";
import DashboardLayout from "./layouts/DashboardLayout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import AnalyticalDashboard from "./pages/AnalyticalDashboard";
import Events from "./pages/Events";
import EventDetails from "./pages/EventDetails";
import Tickets from "./pages/Tickets";
import Cards from "./pages/Cards";
import SuperAdminPanel from "./pages/SuperAdminPanel";
import Guests from "./pages/Guests";
import GuestTicket from "./pages/GuestTicket";
import AppVersionGuard from "./components/AppVersionGuard";
import { useOfflineSync } from "./hooks/useOfflineSync";

// Nossos arquivos independentes
import Bar from "./pages/Bar";
import Food from "./pages/Food";
import Shop from "./pages/Shop";
import Parking from "./pages/Parking";
import Messaging from "./pages/Messaging";
import AIAgents from "./pages/AIAgents";
import Users from "./pages/Users";
import Settings from "./pages/Settings";
import Scanner from "./pages/Operations/Scanner";
import ParticipantsHub from "./pages/ParticipantsHub";
import MealsControl from "./pages/MealsControl";
import ArtistsCatalog from "./pages/ArtistsCatalog";
import ArtistDetail from "./pages/ArtistDetail";
import ArtistImport from "./pages/ArtistImport";
import OrganizerFiles from "./pages/OrganizerFiles";
import CustomerLogin from "./pages/CustomerApp/CustomerLogin";
import CustomerDashboard from "./pages/CustomerApp/CustomerDashboard";
import CustomerRecharge from "./pages/CustomerApp/CustomerRecharge";
import CustomerPrivateRoute from "./components/CustomerPrivateRoute";

// Módulo Financeiro do Evento
import EventFinanceDashboard from "./pages/EventFinanceDashboard";
import EventFinancePayables from "./pages/EventFinancePayables";
import EventFinancePayableDetail from "./pages/EventFinancePayableDetail";
import EventFinanceSuppliers from "./pages/EventFinanceSuppliers";
import EventFinanceBudget from "./pages/EventFinanceBudget";
import EventFinanceImport from "./pages/EventFinanceImport";
import EventFinanceExport from "./pages/EventFinanceExport";
import EventFinanceSettings from "./pages/EventFinanceSettings";

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
        <Routes>
          <Route path="/login" element={<Login />} />

          {/* ── Customer App (WebApp Mobile) ────────────────────────── */}
          <Route path="/app/:slug" element={<CustomerLogin />} />
          {/* H14 — Customer pages protected by auth guard */}
          <Route path="/app/:slug" element={<CustomerPrivateRoute />}>
            <Route path="home"     element={<CustomerDashboard />} />
            <Route path="recharge" element={<CustomerRecharge />} />
          </Route>
          <Route path="/invite" element={<GuestTicket />} />

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
              <Route path="/ai" element={<AIAgents />} />
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
      </AuthProvider>
    </BrowserRouter>
  );
}
