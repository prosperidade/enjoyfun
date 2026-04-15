import { useState } from "react";
import {
  Music,
  Layers,
  Store,
  ParkingSquare,
  LayoutGrid,
  Mail,
  Building2,
  Calendar,
  CalendarRange,
  Award,
  Heart,
  Mic,
  CreditCard,
  Ticket,
  Users,
  UtensilsCrossed,
  DollarSign,
  Car,
  MapPin,
  Map,
  ChevronDown,
  ChevronUp,
  Check,
  Plus,
} from "lucide-react";

/**
 * Group 1 — Configuracao do Evento: modules with inline config in the event form.
 */
const CONFIG_MODULES = [
  { key: "stages", label: "Palcos / Salas", icon: "Music", description: "Palcos, salas, auditorios" },
  { key: "sectors", label: "Setores", icon: "Layers", description: "Pista, VIP, Camarote, Backstage" },
  { key: "pdv_points", label: "PDV Distribuido", icon: "Store", description: "Bares e lojas por palco" },
  { key: "parking_config", label: "Estacionamento", icon: "ParkingSquare", description: "Precos e vagas por tipo" },
  { key: "seating", label: "Mapa de Mesas", icon: "LayoutGrid", description: "Mesas com lugares marcados" },
  { key: "sessions", label: "Agenda / Sessoes", icon: "Calendar", description: "Palestras, workshops" },
  { key: "exhibitors", label: "Expositores", icon: "Building2", description: "Empresas e stands" },
  { key: "ceremony", label: "Cerimonial", icon: "Heart", description: "Timeline de momentos do evento" },
  { key: "sub_events", label: "Sub-Eventos", icon: "CalendarRange", description: "Pre-festa, colacao, despedida, after party" },
  { key: "location", label: "Localizacao", icon: "MapPin", description: "GPS, mapa, venue" },
  { key: "maps", label: "Mapas / Uploads", icon: "Map", description: "Mapa 3D, planta, assentos" },
  { key: "certificates", label: "Certificados", icon: "Award", description: "Emissao por participacao" },
];

/**
 * Group 2 — Modulos Operacionais: enable existing pages (toggle only, no inline form).
 */
const OPERATIONAL_MODULES = [
  { key: "artists", label: "Atracoes", icon: "Mic", description: "Lineup de artistas" },
  { key: "cashless", label: "Cashless", icon: "CreditCard", description: "Cartao digital" },
  { key: "tickets", label: "Ingressos", icon: "Ticket", description: "Tipos, lotes, setores" },
  { key: "workforce", label: "Equipe", icon: "Users", description: "Gestao de equipe" },
  { key: "meals", label: "Refeicoes", icon: "UtensilsCrossed", description: "Controle de refeicoes" },
  { key: "finance", label: "Financeiro", icon: "DollarSign", description: "Orcamento e contas" },
  { key: "parking", label: "Estacionamento Op.", icon: "Car", description: "Controle de veiculos" },
  { key: "invitations", label: "Convites / RSVP", icon: "Mail", description: "Lista de convidados com confirmacao" },
];

/** Full catalog (both groups combined) for external consumers. */
const MODULE_CATALOG = [...CONFIG_MODULES, ...OPERATIONAL_MODULES];

/**
 * Icon name to component mapping.
 */
const ICON_MAP = {
  Music,
  Layers,
  Store,
  ParkingSquare,
  LayoutGrid,
  Mail,
  Building2,
  Calendar,
  CalendarRange,
  Award,
  Heart,
  Mic,
  CreditCard,
  Ticket,
  Users,
  UtensilsCrossed,
  DollarSign,
  Car,
  MapPin,
  Map,
};

/**
 * Pre-configured module sets per event type.
 */
export const MODULE_PRESETS = {
  festival: ["stages", "sectors", "pdv_points", "parking_config", "artists", "cashless", "tickets", "workforce", "meals", "finance", "parking", "location", "maps"],
  show: ["stages", "artists", "cashless", "tickets", "pdv_points", "finance", "parking", "location"],
  corporate: ["sessions", "certificates", "sectors", "workforce", "meals", "finance", "tickets", "location"],
  wedding: ["seating", "invitations", "ceremony", "sub_events", "finance", "location", "maps"],
  graduation: ["seating", "invitations", "ceremony", "sub_events", "certificates", "finance", "location"],
  sports_stadium: ["sectors", "parking_config", "tickets", "cashless", "parking", "finance", "location", "maps"],
  expo: ["exhibitors", "sessions", "sectors", "tickets", "finance", "location", "maps"],
  congress: ["sessions", "certificates", "sectors", "tickets", "workforce", "meals", "finance", "location"],
  theater: ["seating", "sectors", "tickets", "finance", "location"],
  sports_gym: ["sectors", "seating", "tickets", "cashless", "parking", "finance", "location"],
  rodeo: ["stages", "pdv_points", "parking_config", "artists", "cashless", "tickets", "workforce", "meals", "finance", "parking", "location"],
  custom: [],
};

/**
 * EventModulesSelector
 *
 * Grid of toggle chips for event modules. Works alongside EventTemplateSelector:
 * when a template is selected, modules are pre-activated via MODULE_PRESETS.
 * The organizer can then customize by toggling individual modules.
 *
 * Props:
 *   modules  (string[]) — currently active module keys
 *   onToggle (fn)       — called with module key when toggled
 *   disabled (boolean)  — disable interactions during save
 */
export default function EventModulesSelector({
  modules = [],
  onToggle,
  disabled = false,
}) {
  const [expanded, setExpanded] = useState(true);

  const activeCount = modules.length;

  return (
    <div className="space-y-3">
      {/* Section header */}
      <button
        type="button"
        onClick={() => setExpanded(!expanded)}
        className="flex items-center gap-2 w-full text-left"
      >
        <Layers size={16} className="text-purple-400" />
        <span className="input-label mb-0">Modulos do Evento</span>
        {activeCount > 0 && (
          <span className="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-purple-500/20 text-purple-300">
            {activeCount} ativo{activeCount !== 1 ? "s" : ""}
          </span>
        )}
        <span className="ml-auto text-gray-500">
          {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
        </span>
      </button>

      {expanded && (
        <>
          {/* Group 1 — Configuracao do Evento */}
          <div className="space-y-2">
            <div>
              <h4 className="text-xs font-semibold text-purple-300 uppercase tracking-wide">Configuracao do Evento</h4>
              <p className="text-[10px] text-gray-500">Estes modulos abrem formularios de configuracao abaixo.</p>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
              {CONFIG_MODULES.map((mod) => {
                const isActive = modules.includes(mod.key);
                const IconComponent = ICON_MAP[mod.icon] || Calendar;
                return (
                  <button
                    key={mod.key}
                    type="button"
                    disabled={disabled}
                    onClick={() => onToggle(mod.key)}
                    className={`
                      relative rounded-xl border p-3 text-left transition-all duration-200
                      hover:scale-[1.02]
                      ${isActive
                        ? "border-purple-500/40 bg-purple-500/10 shadow-sm shadow-purple-500/10"
                        : "border-gray-800 bg-gray-950/40 hover:border-gray-700 hover:bg-gray-900/60"
                      }
                      ${disabled ? "opacity-50 cursor-not-allowed" : "cursor-pointer"}
                    `}
                  >
                    <div className="absolute top-2 right-2">
                      {isActive ? (
                        <div className="w-5 h-5 rounded-full bg-purple-500/20 flex items-center justify-center">
                          <Check size={12} className="text-purple-400" />
                        </div>
                      ) : (
                        <div className="w-5 h-5 rounded-full bg-gray-800/60 flex items-center justify-center">
                          <Plus size={12} className="text-gray-600" />
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-2 mb-1.5 pr-6">
                      <div className={`w-7 h-7 rounded-lg flex items-center justify-center ${isActive ? "bg-purple-500/20 text-purple-400" : "bg-gray-800/60 text-gray-500"}`}>
                        <IconComponent size={14} />
                      </div>
                      <p className={`font-medium text-xs truncate ${isActive ? "text-white" : "text-gray-400"}`} title={mod.label}>
                        {mod.label}
                      </p>
                    </div>
                    <p className="text-[10px] text-gray-600 line-clamp-1 pl-9">{mod.description}</p>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Group 2 — Modulos Operacionais */}
          <div className="space-y-2 mt-4">
            <div>
              <h4 className="text-xs font-semibold text-gray-300 uppercase tracking-wide">Modulos Operacionais</h4>
              <p className="text-[10px] text-gray-500">Habilitam funcionalidades em paginas dedicadas do sistema.</p>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
              {OPERATIONAL_MODULES.map((mod) => {
                const isActive = modules.includes(mod.key);
                const IconComponent = ICON_MAP[mod.icon] || Calendar;
                return (
                  <button
                    key={mod.key}
                    type="button"
                    disabled={disabled}
                    onClick={() => onToggle(mod.key)}
                    className={`
                      relative rounded-xl border p-3 text-left transition-all duration-200
                      hover:scale-[1.02]
                      ${isActive
                        ? "border-gray-500/40 bg-gray-700/20 shadow-sm shadow-gray-500/10"
                        : "border-gray-800 bg-gray-950/40 hover:border-gray-700 hover:bg-gray-900/60"
                      }
                      ${disabled ? "opacity-50 cursor-not-allowed" : "cursor-pointer"}
                    `}
                  >
                    <div className="absolute top-2 right-2">
                      {isActive ? (
                        <div className="w-5 h-5 rounded-full bg-gray-500/20 flex items-center justify-center">
                          <Check size={12} className="text-gray-300" />
                        </div>
                      ) : (
                        <div className="w-5 h-5 rounded-full bg-gray-800/60 flex items-center justify-center">
                          <Plus size={12} className="text-gray-600" />
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-2 mb-1.5 pr-6">
                      <div className={`w-7 h-7 rounded-lg flex items-center justify-center ${isActive ? "bg-gray-600/30 text-gray-300" : "bg-gray-800/60 text-gray-500"}`}>
                        <IconComponent size={14} />
                      </div>
                      <p className={`font-medium text-xs truncate ${isActive ? "text-white" : "text-gray-400"}`} title={mod.label}>
                        {mod.label}
                      </p>
                    </div>
                    <div className="flex items-center gap-1.5 pl-9">
                      <p className="text-[10px] text-gray-600 line-clamp-1">{mod.description}</p>
                      <span className="text-[8px] text-gray-600 bg-gray-800 px-1.5 py-0.5 rounded whitespace-nowrap">Pagina dedicada</span>
                    </div>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Bottom hint */}
          <p className="text-xs text-gray-500 italic mt-2">
            Selecione os modulos necessarios para o seu evento.
          </p>
        </>
      )}
    </div>
  );
}
