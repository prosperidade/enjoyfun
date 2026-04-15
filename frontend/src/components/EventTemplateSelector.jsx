import { useState, useEffect } from "react";
import { listEventTemplates } from "../api/eventTemplates";
import {
  Music,
  Mic,
  Building2,
  Heart,
  GraduationCap,
  Store,
  Trophy,
  Calendar,
  BookOpen,
  Drama,
  Dumbbell,
  Flame,
  Puzzle,
  Sparkles,
  ChevronDown,
  ChevronUp,
} from "lucide-react";

/**
 * Icon mapping for template types.
 */
const TEMPLATE_ICONS = {
  music: Music,
  mic: Mic,
  building: Building2,
  heart: Heart,
  "graduation-cap": GraduationCap,
  store: Store,
  trophy: Trophy,
  calendar: Calendar,
  "book-open": BookOpen,
  drama: Drama,
  dumbbell: Dumbbell,
  flame: Flame,
  puzzle: Puzzle,
};

/**
 * Short feature highlights per template — shown on cards.
 */
const TEMPLATE_HIGHLIGHTS = {
  festival: ["Lineup de Artistas", "Cashless / Bar", "Estoque", "Equipe"],
  show: ["Palco Unico", "Artistas", "Cashless", "Ingressos"],
  corporate: ["Agenda de Sessoes", "Certificados", "Networking", "Credenciamento"],
  wedding: ["Convites RSVP", "Mapa de Mesas", "Cerimonial", "Fornecedores"],
  graduation: ["Convites", "Mapa de Mesas", "Cerimonial", "Certificados"],
  sports_stadium: ["Setores", "Tabela de Jogos", "Credenciais", "Ingressos"],
  expo: ["Estandes", "Expositores", "Captura de Leads", "Palestras"],
  congress: ["Trilhas Tematicas", "Certificados", "Sessoes", "Posters"],
  theater: ["Mapa de Assentos", "Sessoes", "Meia-Entrada", "Ingressos"],
  sports_gym: ["Layout Flexivel", "Setores", "Cashless", "Ingressos"],
  rodeo: ["Arena", "Shows Noturnos", "Praca Alimentacao", "Leilao"],
  custom: ["Monte do zero", "Modulos a la carte"],
};

/**
 * EventTemplateSelector
 *
 * Visual card selector for event templates. Shown when creating a new event.
 * Each card shows icon, label, description, and key features included.
 *
 * Props:
 *   selected (string) — currently selected template_key
 *   onSelect (fn)     — callback with template_key
 *   disabled (boolean) — disable interactions
 */
export default function EventTemplateSelector({
  selected,
  onSelect,
  disabled = false,
}) {
  const [templates, setTemplates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState(true);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    listEventTemplates()
      .then((data) => {
        if (mounted) setTemplates(data);
      })
      .catch(() => {
        // Fallback: show static list if API not available
        if (mounted)
          setTemplates([
            { template_key: "festival", label: "Festival de Musica", description: "Festivais, raves e eventos multi-palco.", icon_key: "music", color: "#8b5cf6", is_system: true, skills_count: 33 },
            { template_key: "show", label: "Show Avulso", description: "Shows, casas de show e apresentacoes.", icon_key: "mic", color: "#a855f7", is_system: true, skills_count: 20 },
            { template_key: "corporate", label: "Corporativo", description: "Conferencias, workshops e treinamentos.", icon_key: "building", color: "#3b82f6", is_system: true, skills_count: 18 },
            { template_key: "wedding", label: "Casamento", description: "Casamentos e festas de uniao civil.", icon_key: "heart", color: "#ec4899", is_system: true, skills_count: 11 },
            { template_key: "graduation", label: "Formatura", description: "Formaturas e colacoes de grau.", icon_key: "graduation-cap", color: "#f59e0b", is_system: true, skills_count: 15 },
            { template_key: "sports_stadium", label: "Esportivo / Estadio", description: "Estadios, arenas e competicoes.", icon_key: "trophy", color: "#ef4444", is_system: true, skills_count: 15 },
            { template_key: "expo", label: "Feira / Exposicao", description: "Feiras comerciais e exposicoes.", icon_key: "store", color: "#10b981", is_system: true, skills_count: 20 },
            { template_key: "congress", label: "Congresso / Palestra", description: "Congressos academicos e conferencias.", icon_key: "book-open", color: "#6366f1", is_system: true, skills_count: 18 },
            { template_key: "theater", label: "Teatro / Auditorio", description: "Pecas, espetaculos e sessoes.", icon_key: "drama", color: "#f97316", is_system: true, skills_count: 10 },
            { template_key: "sports_gym", label: "Ginasio / Indoor", description: "Esportes indoor, lutas e shows.", icon_key: "dumbbell", color: "#22c55e", is_system: true, skills_count: 12 },
            { template_key: "rodeo", label: "Rodeio / Agro", description: "Rodeios, exposicoes agropecuarias.", icon_key: "flame", color: "#d97706", is_system: true, skills_count: 25 },
            { template_key: "custom", label: "Evento Customizado", description: "Monte do zero com modulos a la carte.", icon_key: "puzzle", color: "#64748b", is_system: true, skills_count: 0 },
          ]);
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center py-8">
        <div className="spinner w-6 h-6" />
        <span className="ml-2 text-sm text-gray-400">
          Carregando tipos de evento...
        </span>
      </div>
    );
  }

  const systemTemplates = templates.filter((t) => t.is_system);
  const customTemplates = templates.filter((t) => !t.is_system);

  return (
    <div className="space-y-3">
      <button
        type="button"
        onClick={() => setExpanded(!expanded)}
        className="flex items-center gap-2 w-full text-left"
      >
        <Sparkles size={16} className="text-purple-400" />
        <span className="input-label mb-0">Tipo do Evento</span>
        <span className="text-xs text-gray-500 ml-1">
          — a IA ativa automaticamente as ferramentas certas
        </span>
        <span className="ml-auto text-gray-500">
          {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
        </span>
      </button>

      {expanded && (
        <>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            {systemTemplates.map((template) => {
              const IconComponent =
                TEMPLATE_ICONS[template.icon_key] || Calendar;
              const highlights =
                TEMPLATE_HIGHLIGHTS[template.template_key] || [];
              const isSelected = selected === template.template_key;

              return (
                <button
                  key={template.template_key}
                  type="button"
                  disabled={disabled}
                  onClick={() =>
                    onSelect(
                      isSelected ? "" : template.template_key
                    )
                  }
                  className={`
                    relative rounded-xl border p-3 text-left transition-all duration-200
                    hover:scale-[1.02] hover:shadow-lg
                    ${
                      isSelected
                        ? "border-purple-500/60 bg-purple-500/10 shadow-purple-500/10 shadow-md ring-1 ring-purple-500/30"
                        : "border-gray-800 bg-gray-950/40 hover:border-gray-700 hover:bg-gray-900/60"
                    }
                    ${disabled ? "opacity-50 cursor-not-allowed" : "cursor-pointer"}
                  `}
                  style={{
                    borderColor: isSelected ? template.color + "60" : undefined,
                  }}
                >
                  {/* Selected indicator */}
                  {isSelected && (
                    <div
                      className="absolute top-2 right-2 w-2.5 h-2.5 rounded-full animate-pulse"
                      style={{ backgroundColor: template.color }}
                    />
                  )}

                  {/* Icon + Label */}
                  <div className="flex items-center gap-2 mb-2">
                    <div
                      className="w-8 h-8 rounded-lg flex items-center justify-center"
                      style={{
                        backgroundColor: template.color + "20",
                        color: template.color,
                      }}
                    >
                      <IconComponent size={16} />
                    </div>
                    <div className="min-w-0">
                      <p
                        className="font-medium text-sm text-white truncate"
                        title={template.label}
                      >
                        {template.label}
                      </p>
                    </div>
                  </div>

                  {/* Description */}
                  <p className="text-[11px] text-gray-500 line-clamp-2 mb-2">
                    {template.description}
                  </p>

                  {/* Feature highlights */}
                  <div className="flex flex-wrap gap-1">
                    {highlights.slice(0, 3).map((h) => (
                      <span
                        key={h}
                        className="text-[10px] px-1.5 py-0.5 rounded-md bg-gray-800/60 text-gray-400"
                      >
                        {h}
                      </span>
                    ))}
                    {highlights.length > 3 && (
                      <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-gray-800/60 text-gray-500">
                        +{highlights.length - 3}
                      </span>
                    )}
                  </div>

                  {/* Skills count */}
                  <p className="text-[10px] text-gray-600 mt-2">
                    {template.skills_count || "?"} ferramentas incluídas
                  </p>
                </button>
              );
            })}
          </div>

          {/* Custom templates section */}
          {customTemplates.length > 0 && (
            <div className="mt-3">
              <p className="text-xs text-gray-500 mb-2">
                Seus templates personalizados:
              </p>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {customTemplates.map((template) => {
                  const IconComponent =
                    TEMPLATE_ICONS[template.icon_key] || Calendar;
                  const isSelected = selected === template.template_key;

                  return (
                    <button
                      key={`custom-${template.template_key}-${template.id}`}
                      type="button"
                      disabled={disabled}
                      onClick={() =>
                        onSelect(
                          isSelected ? "" : template.template_key
                        )
                      }
                      className={`
                        relative rounded-xl border p-3 text-left transition-all duration-200
                        ${
                          isSelected
                            ? "border-purple-500/60 bg-purple-500/10 ring-1 ring-purple-500/30"
                            : "border-gray-800 bg-gray-950/40 hover:border-gray-700"
                        }
                        ${disabled ? "opacity-50 cursor-not-allowed" : "cursor-pointer"}
                      `}
                    >
                      <div className="flex items-center gap-2">
                        <IconComponent
                          size={14}
                          style={{ color: template.color }}
                        />
                        <p className="font-medium text-sm text-white truncate">
                          {template.label}
                        </p>
                        <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-purple-500/10 text-purple-400 ml-auto">
                          Personalizado
                        </span>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* No selection hint */}
          {!selected && (
            <p className="text-xs text-gray-500 italic">
              💡 Selecione o tipo do evento para que a IA configure
              automaticamente. Ou deixe em branco para acessar todas as
              ferramentas.
            </p>
          )}
        </>
      )}
    </div>
  );
}
