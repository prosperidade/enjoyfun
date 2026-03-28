import { useEffect, useState } from "react";
import toast from "react-hot-toast";
import {
  Bot,
  TrendingUp,
  Truck,
  Settings,
  BarChart2,
  FileText,
  MessageSquare,
  Save,
  Zap,
} from "lucide-react";
import AIBlueprintWorkbench from "../components/AIBlueprintWorkbench";
import AIControlCenter from "../components/AIControlCenter";
import AIExecutionFeed from "../components/AIExecutionFeed";
import {
  listOrganizerAIAgents,
  listOrganizerAIProviders,
  updateOrganizerAIAgent,
} from "../api/ai";

const AGENT_PRESENTATION = {
  marketing: {
    icon: TrendingUp,
    color: "from-pink-700 to-rose-700",
    border: "border-pink-800/40",
    emoji: "MKT",
    example:
      "Analise a demanda comercial do evento e sugira uma acao de comunicacao de maior impacto no curto prazo.",
  },
  logistics: {
    icon: Truck,
    color: "from-blue-700 to-cyan-700",
    border: "border-blue-800/40",
    emoji: "LOG",
    example:
      "Aponte gargalos operacionais em filas, abastecimento e deslocamento dentro do evento.",
  },
  management: {
    icon: BarChart2,
    color: "from-purple-700 to-indigo-700",
    border: "border-purple-800/40",
    emoji: "OPS",
    example:
      "Resuma os principais riscos e oportunidades do evento atual com foco executivo.",
  },
  bar: {
    icon: Settings,
    color: "from-amber-700 to-orange-700",
    border: "border-amber-800/40",
    emoji: "PDV",
    example:
      "Analise ruptura, mix e ritmo de venda do bar para apontar a melhor acao operacional.",
  },
  contracting: {
    icon: FileText,
    color: "from-green-700 to-teal-700",
    border: "border-green-800/40",
    emoji: "CTR",
    example:
      "Compare fornecedores e aponte a alternativa mais segura para contratacao neste evento.",
  },
  feedback: {
    icon: MessageSquare,
    color: "from-violet-700 to-purple-700",
    border: "border-violet-800/40",
    emoji: "FBK",
    example:
      "Consolide os sinais de participantes e operacao para identificar os 3 problemas mais recorrentes.",
  },
};

const AGENT_ORDER = [
  "marketing",
  "logistics",
  "management",
  "bar",
  "contracting",
  "feedback",
];

const APPROVAL_OPTIONS = [
  {
    value: "confirm_write",
    label: "Confirmar escrita",
    description: "Permite leitura livre e exige confirmacao antes de qualquer acao de escrita.",
  },
  {
    value: "manual_confirm",
    label: "Confirmar tudo",
    description: "Exige confirmacao explicita antes de qualquer acao do agente.",
  },
  {
    value: "auto_read_only",
    label: "Auto leitura",
    description: "Leituras podem rodar automaticamente, sem permissao de escrita.",
  },
];

function sortAgents(list) {
  return [...list].sort((left, right) => {
    const leftIndex = AGENT_ORDER.indexOf(left.agent_key);
    const rightIndex = AGENT_ORDER.indexOf(right.agent_key);

    if (leftIndex === -1 && rightIndex === -1) {
      return String(left.label || left.agent_key).localeCompare(
        String(right.label || right.agent_key),
        "pt-BR"
      );
    }
    if (leftIndex === -1) return 1;
    if (rightIndex === -1) return -1;
    return leftIndex - rightIndex;
  });
}

function formatUpdatedAt(value) {
  if (!value) return "Ainda nao configurado";

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Atualizacao indisponivel";

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
}

function resolveAgentStatus(agent) {
  if (agent.is_enabled) return { label: "Ativo", className: "badge-green" };
  if (agent.source === "tenant") return { label: "Desligado", className: "badge-gray" };
  return { label: "Catalogo", className: "badge-gray" };
}

function resolveProviderLabel(providers, providerKey, defaultProvider) {
  if (!providerKey) {
    return defaultProvider?.label
      ? `${defaultProvider.label} (padrao)`
      : "Provider padrao do organizer";
  }

  const match = providers.find((item) => item.provider === providerKey);
  return match?.label || providerKey;
}

export default function AIAgents() {
  const [agents, setAgents] = useState([]);
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [savingAgentKey, setSavingAgentKey] = useState("");

  const loadData = async ({ silent = false } = {}) => {
    if (!silent) {
      setLoading(true);
    }

    try {
      const [agentList, providerList] = await Promise.all([
        listOrganizerAIAgents(),
        listOrganizerAIProviders(),
      ]);
      setAgents(sortAgents(agentList));
      setProviders(providerList);
    } catch (error) {
      toast.error(
        error.response?.data?.message || "Erro ao carregar agentes e providers de IA."
      );
    } finally {
      if (!silent) {
        setLoading(false);
      }
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleAgentChange = (agentKey, field, value) => {
    setAgents((current) =>
      current.map((agent) =>
        agent.agent_key === agentKey ? { ...agent, [field]: value } : agent
      )
    );
  };

  const handleSaveAgent = async (agent) => {
    setSavingAgentKey(agent.agent_key);

    try {
      const payload = {
        is_enabled: Boolean(agent.is_enabled),
        approval_mode: agent.approval_mode || "confirm_write",
        provider: agent.provider || "",
      };

      const updated = await updateOrganizerAIAgent(agent.agent_key, payload);
      setAgents((current) =>
        sortAgents(
          current.map((item) =>
            item.agent_key === agent.agent_key ? { ...item, ...updated } : item
          )
        )
      );
      toast.success(`Agente ${agent.label || agent.agent_key} salvo com sucesso.`);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao salvar agente de IA.");
      await loadData({ silent: true });
    } finally {
      setSavingAgentKey("");
    }
  };

  const activeAgents = agents.filter((agent) => agent.is_enabled).length;
  const configuredProviders = providers.filter((provider) => provider.is_configured).length;
  const defaultProvider = providers.find((provider) => provider.is_default) || null;

  if (loading) {
    return <div className="text-gray-500 animate-pulse">Carregando agentes de IA...</div>;
  }

  return (
    <div className="space-y-8">
      <div className="relative overflow-hidden rounded-[2rem] border border-fuchsia-900/30 bg-[radial-gradient(circle_at_top_left,_rgba(168,85,247,0.24),_transparent_30%),linear-gradient(135deg,_rgba(23,23,23,0.95),_rgba(17,24,39,0.98))] p-8">
        <div className="absolute inset-y-0 right-0 w-1/2 bg-[radial-gradient(circle_at_center,_rgba(244,114,182,0.18),_transparent_55%)] pointer-events-none" />
        <div className="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
          <div className="max-w-4xl">
            <span className="inline-flex items-center gap-2 rounded-full border border-fuchsia-800/40 bg-fuchsia-950/30 px-3 py-1 text-[11px] uppercase tracking-[0.28em] text-fuchsia-200">
              <Bot size={13} /> EnjoyFun AI Hub
            </span>
            <h1 className="mt-4 text-4xl font-black tracking-tight text-white sm:text-5xl">
              Agentes, memoria, governanca e automacao do evento em um unico lugar.
            </h1>
            <p className="mt-4 max-w-3xl text-sm leading-relaxed text-gray-300">
              Este hub virou a superficie principal da inteligencia do organizer: providers,
              politicas, roteamento por agente, trilha operacional, memoria viva e relatorio
              automatico de fim de evento.
            </p>
          </div>
          <div className="grid gap-3 sm:grid-cols-3 xl:min-w-[420px]">
            <span className="rounded-2xl border border-gray-800 bg-gray-950/70 px-4 py-3 text-sm text-gray-300">
              <span className="block text-[11px] uppercase tracking-[0.22em] text-gray-500">Agentes ativos</span>
              <span className="mt-1 block text-2xl font-black text-white">{activeAgents}</span>
            </span>
            <span className="rounded-2xl border border-gray-800 bg-gray-950/70 px-4 py-3 text-sm text-gray-300">
              <span className="block text-[11px] uppercase tracking-[0.22em] text-gray-500">Providers</span>
              <span className="mt-1 block text-2xl font-black text-white">{configuredProviders}</span>
            </span>
            <span className="rounded-2xl border border-gray-800 bg-gray-950/70 px-4 py-3 text-sm text-gray-300">
              <span className="block text-[11px] uppercase tracking-[0.22em] text-gray-500">Default</span>
              <span className="mt-1 block text-sm font-bold text-white">
                {defaultProvider?.label || "nao definido"}
              </span>
            </span>
          </div>
        </div>
      </div>

      <div className="card bg-gradient-to-r from-purple-900/30 to-pink-900/30 border-purple-800/40 flex items-start gap-4">
        <div className="w-10 h-10 rounded-xl bg-purple-700 flex items-center justify-center flex-shrink-0">
          <Zap size={18} className="text-white" />
        </div>
        <div className="space-y-2">
          <p className="font-medium text-white">Central de IA do organizer</p>
          <p className="text-sm text-gray-400">
            Configure providers, runtime e agentes em um unico lugar.
          </p>
        </div>
      </div>

      <AIBlueprintWorkbench />
      <AIControlCenter />
      <AIExecutionFeed />

      <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
        {agents.map((agent) => (
          <AgentCard
            key={agent.agent_key}
            agent={agent}
            providers={providers}
            defaultProvider={defaultProvider}
            saving={savingAgentKey === agent.agent_key}
            onChange={handleAgentChange}
            onSave={handleSaveAgent}
          />
        ))}
      </div>
    </div>
  );
}

function AgentCard({
  agent,
  providers,
  defaultProvider,
  saving,
  onChange,
  onSave,
}) {
  const presentation = AGENT_PRESENTATION[agent.agent_key] || {};
  const Icon = presentation.icon || Bot;
  const status = resolveAgentStatus(agent);
  const approvalMeta =
    APPROVAL_OPTIONS.find((option) => option.value === agent.approval_mode) ||
    APPROVAL_OPTIONS[0];
  const runtimeProviderLabel = resolveProviderLabel(
    providers,
    agent.provider,
    defaultProvider
  );
  const selectedProvider = providers.find((provider) => provider.provider === agent.provider);
  const runtimeProvider = selectedProvider || defaultProvider;
  const runtimeReady = runtimeProvider ? runtimeProvider.is_configured : false;

  return (
    <div className={`card-hover flex flex-col ${presentation.border || "border-gray-800"}`}>
      <div className="flex items-start justify-between gap-3 mb-4">
        <div className="flex items-start gap-3">
          <div
            className={`w-12 h-12 rounded-2xl bg-gradient-to-br ${
              presentation.color || "from-gray-700 to-gray-800"
            } flex items-center justify-center text-xs font-black tracking-[0.24em] text-white flex-shrink-0`}
          >
            {presentation.emoji || "AI"}
          </div>
          <div>
            <h3 className="font-bold text-white mb-1">{agent.label || agent.agent_key}</h3>
            <p className="text-xs text-gray-400 leading-relaxed">
              {agent.description || "Agente configuravel para operacao do organizer."}
            </p>
          </div>
        </div>
        <span className={`badge ${status.className}`}>{status.label}</span>
      </div>

      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-2 text-xs">
          <div className="rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2">
            <p className="text-gray-500 mb-1">Provider efetivo</p>
            <p className="text-gray-200">{runtimeProviderLabel}</p>
          </div>
          <div className="rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2">
            <p className="text-gray-500 mb-1">Runtime</p>
            <p className={runtimeReady ? "text-green-400" : "text-amber-300"}>
              {runtimeReady ? "Configurado" : "Sem chave/modelo salvo"}
            </p>
          </div>
        </div>

        <div>
          <p className="text-xs text-gray-500 mb-2">Superficies alvo</p>
          <div className="flex flex-wrap gap-2">
            {(agent.surfaces || []).length > 0 ? (
              agent.surfaces.map((surface) => (
                <span
                  key={surface}
                  className="px-2 py-1 rounded-full border border-gray-800 bg-gray-900 text-[11px] text-gray-300"
                >
                  {surface}
                </span>
              ))
            ) : (
              <span className="px-2 py-1 rounded-full border border-gray-800 bg-gray-900 text-[11px] text-gray-500">
                Nenhuma superficie mapeada
              </span>
            )}
          </div>
        </div>

        <label className="flex items-center justify-between gap-3 rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-3">
          <div>
            <p className="text-sm text-gray-200">Agente habilitado</p>
            <p className="text-xs text-gray-500">Liga ou desliga este agente no escopo do organizer.</p>
          </div>
          <input
            type="checkbox"
            className="h-4 w-4 accent-green-500"
            checked={Boolean(agent.is_enabled)}
            onChange={(event) =>
              onChange(agent.agent_key, "is_enabled", event.target.checked)
            }
          />
        </label>

        <div>
          <label className="input-label">Provider do agente</label>
          <select
            className="input"
            value={agent.provider || ""}
            onChange={(event) => onChange(agent.agent_key, "provider", event.target.value)}
          >
            <option value="">Usar provider padrao do organizer</option>
            {providers.map((provider) => (
              <option key={provider.provider} value={provider.provider}>
                {provider.label}
                {provider.is_default ? " (Padrao)" : ""}
                {!provider.is_configured ? " - sem chave" : ""}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="input-label">Politica de aprovacao</label>
          <select
            className="input"
            value={agent.approval_mode || "confirm_write"}
            onChange={(event) =>
              onChange(agent.agent_key, "approval_mode", event.target.value)
            }
          >
            {APPROVAL_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <p className="text-xs text-gray-500 mt-2">{approvalMeta.description}</p>
        </div>

        <div className="rounded-xl border border-dashed border-gray-800 bg-gray-950/60 p-3">
          <div className="flex items-center gap-2 text-gray-300 text-sm mb-2">
            <Icon size={14} className="text-purple-300" />
            Exemplo de uso
          </div>
          <p className="text-xs text-gray-400 italic">
            "
            {presentation.example ||
              "Use este agente para orientar a operacao do evento com contexto real."}
            "
          </p>
        </div>
      </div>

      <div className="mt-auto pt-4 border-t border-gray-800 flex items-center justify-between gap-3">
        <div className="text-xs text-gray-500">
          Atualizado: {formatUpdatedAt(agent.updated_at)}
        </div>
        <button
          type="button"
          className="btn-primary flex items-center gap-2"
          disabled={saving}
          onClick={() => onSave(agent)}
        >
          <Save size={16} />
          {saving ? "Salvando..." : "Salvar"}
        </button>
      </div>
    </div>
  );
}
