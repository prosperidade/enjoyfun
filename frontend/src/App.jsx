import { BrowserRouter, Routes, Route } from "react-router-dom";
import { Toaster } from "react-hot-toast";
import { AuthProvider } from "./context/AuthContext";
import PrivateRoute from "./components/PrivateRoute";
import DashboardLayout from "./layouts/DashboardLayout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Events from "./pages/Events";
import EventDetails from "./pages/EventDetails";
import Tickets from "./pages/Tickets";
import Cards from "./pages/Cards";
import SuperAdminPanel from "./pages/SuperAdminPanel";
import Guests from "./pages/Guests";
import GuestTicket from "./pages/GuestTicket";

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
import CustomerLogin from "./pages/CustomerApp/CustomerLogin";
import CustomerDashboard from "./pages/CustomerApp/CustomerDashboard";
import CustomerRecharge from "./pages/CustomerApp/CustomerRecharge";

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

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
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
        <Routes>
          <Route path="/login" element={<Login />} />

          {/* ── Rotas Públicas do Cliente (WebApp Mobile) ───────────── */}
          <Route path="/app/:slug"          element={<CustomerLogin />} />
          <Route path="/app/:slug/home"     element={<CustomerDashboard />} />
          <Route path="/app/:slug/recharge" element={<CustomerRecharge />} />
          <Route path="/invite" element={<GuestTicket />} />

          <Route element={<PrivateRoute />}>
            <Route element={<DashboardLayout />}>
              <Route path="/" element={<Dashboard />} />
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
              <Route path="/users" element={<Users />} />
              <Route path="/guests" element={<Guests />} />
              <Route path="/participants" element={<ParticipantsHub />} />
              <Route path="/meals-control" element={<MealsControl />} />
              <Route path="/scanner" element={<Scanner />} />
              
              {/* Rota de Configurações Final */}
              <Route path="/settings" element={<Settings />} />
            </Route>
          </Route>

          <Route path="*" element={<NotFound />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
