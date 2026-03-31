import { useState } from "react";
import toast from "react-hot-toast";
import { Bot, Briefcase, Send, ShieldAlert, Users } from "lucide-react";
import api from "../lib/api";

function formatSectorLabel(value = "") {
  const normalized = String(value || "").trim();
  if (!normalized) return "geral";
  return normalized.replace(/_/g, " ");
}

export default function WorkforceAIAssistant({
  eventId,
  assignmentsTotal,
  missingBindings,
  managerRootsCount,
  selectedManagerName,
  selectedManagerRoleName,
  selectedManagerEventRoleId,
  selectedManagerRootEventRoleId,
  selectedManagerRoleClass,
  selectedManagerSector,
  selectedManagerPlannedTeamSize,
  selectedManagerFilledTeamSize,
  selectedManagerLeadershipTotal,
  selectedManagerLeadershipFilledTotal,
  selectedManagerOperationalTotal,
  selectedTeamMembersLoaded,
  eventStructure,
  treeUsable,
  loadedFromSnapshot,
  healthStatusLabel,
  syncFailureRate,
}) {
  const [question, setQuestion] = useState("");
  const [messages, setMessages] = useState([]);
  const [loading, setLoading] = useState(false);
  const hasExplicitFocus = Boolean(selectedManagerName || selectedManagerSector);

  const focusLabel = selectedManagerName
    ? `${selectedManagerName}${selectedManagerRoleName ? ` • ${selectedManagerRoleName}` : ""}`
    : selectedManagerSector
      ? `Setor ${formatSectorLabel(selectedManagerSector)}`
      : "Visao geral do evento";

  const handleAsk = async () => {
    const trimmedQuestion = question.trim();
    const normalizedEventId = Number(eventId);

    if (!trimmedQuestion) {
      return;
    }
    if (normalizedEventId <= 0) {
      toast.error("Selecione um evento para consultar o agente de logistica do Workforce.");
      return;
    }

    setQuestion("");
    setMessages((current) => [...current, { role: "user", content: trimmedQuestion }]);
    setLoading(true);

    try {
      const response = await api.post("/ai/insight", {
        question: trimmedQuestion,
        context: {
          event_id: normalizedEventId,
          surface: "workforce",
          agent_key: "logistics",
          assignments_total_hint: Number(assignmentsTotal || 0),
          assignments_missing_bindings_hint: Number(missingBindings || 0),
          manager_roots_count_hint: Number(managerRootsCount || 0),
          selected_manager_name: selectedManagerName || null,
          selected_manager_role_name: selectedManagerRoleName || null,
          selected_manager_event_role_id: Number(selectedManagerEventRoleId || 0),
          selected_manager_root_event_role_id: Number(selectedManagerRootEventRoleId || 0),
          selected_manager_role_class: selectedManagerRoleClass || null,
          selected_manager_sector: selectedManagerSector || null,
          selected_manager_planned_team_size: Number(selectedManagerPlannedTeamSize || 0),
          selected_manager_filled_team_size: Number(selectedManagerFilledTeamSize || 0),
          selected_manager_leadership_total: Number(selectedManagerLeadershipTotal || 0),
          selected_manager_leadership_filled_total: Number(selectedManagerLeadershipFilledTotal || 0),
          selected_manager_operational_total: Number(selectedManagerOperationalTotal || 0),
          selected_team_members_loaded: Number(selectedTeamMembersLoaded || 0),
          event_days_total_hint: Number(eventStructure?.days || 0),
          event_shift_slots_total_hint: Number(eventStructure?.shifts || 0),
          registered_shifts_total_hint: Number(eventStructure?.registeredShifts || 0),
          tree_usable_hint: Boolean(treeUsable),
          loaded_from_snapshot_hint: Boolean(loadedFromSnapshot),
          health_status_hint: healthStatusLabel || null,
          sync_failure_rate_pct_hint: Number(syncFailureRate || 0),
        },
      });

      setMessages((current) => [
        ...current,
        {
          role: "ai",
          content: response.data?.data?.insight || "Sem resposta do agente de logistica.",
        },
      ]);
    } catch (error) {
      const message = error.response?.data?.message || "Erro ao consultar o agente de logistica.";
      setMessages((current) => [...current, { role: "ai", content: `Erro: ${message}` }]);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card border-emerald-900/40 bg-[linear-gradient(135deg,_rgba(6,78,59,0.20),_rgba(15,23,42,0.94))] p-6">
      <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div>
          <div className="flex items-center gap-2 text-emerald-200">
            <Bot size={18} />
            <p className="font-semibold">Agente de Workforce embutido</p>
          </div>
          <p className="mt-2 max-w-3xl text-sm text-gray-300">
            Assistente operacional da estrutura de equipe. Ele usa a leitura real de assignments,
            liderancas, binds e setores do evento para apontar cobertura, lacunas e proximas acoes.
          </p>
        </div>

        <div className="rounded-2xl border border-emerald-900/40 bg-slate-950/55 px-4 py-3 text-xs text-emerald-100">
          <p className="uppercase tracking-[0.24em] text-emerald-300">Foco atual</p>
          <p className="mt-2">{focusLabel}</p>
          <p>Setor: {formatSectorLabel(selectedManagerSector)}</p>
          <p>Tree usable: {treeUsable ? "sim" : "nao"}</p>
        </div>
      </div>

      <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <Users size={15} />
            <p className="text-[10px] uppercase tracking-wider">Assignments</p>
          </div>
          <p className="mt-2 text-xl font-black text-white">
            {Number(assignmentsTotal || 0).toLocaleString("pt-BR")}
          </p>
          <p className="mt-1 text-xs text-gray-500">
            {hasExplicitFocus
              ? `${Number(selectedTeamMembersLoaded || 0).toLocaleString("pt-BR")} pessoas carregadas no foco atual`
              : "Nenhum gerente ou setor selecionado no foco atual"}
          </p>
        </div>

        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <ShieldAlert size={15} />
            <p className="text-[10px] uppercase tracking-wider">Sem vinculo</p>
          </div>
          <p className={`mt-2 text-xl font-black ${Number(missingBindings || 0) > 0 ? "text-amber-300" : "text-white"}`}>
            {Number(missingBindings || 0).toLocaleString("pt-BR")}
          </p>
          <p className="mt-1 text-xs text-gray-500">Pessoas ainda sem root manager definido</p>
        </div>

        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <div className="flex items-center gap-2 text-gray-400">
            <Briefcase size={15} />
            <p className="text-[10px] uppercase tracking-wider">Liderancas</p>
          </div>
          <p className="mt-2 text-xl font-black text-white">
            {Number(managerRootsCount || 0).toLocaleString("pt-BR")}
          </p>
          <p className="mt-1 text-xs text-gray-500">
            Planejado {Number(selectedManagerPlannedTeamSize || 0).toLocaleString("pt-BR")} •
            preenchido {Number(selectedManagerFilledTeamSize || 0).toLocaleString("pt-BR")}
          </p>
        </div>

        <div className="rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
          <p className="text-[10px] uppercase tracking-wider text-gray-500">Sinais do modulo</p>
          <p className="mt-2 text-xl font-black text-white">{healthStatusLabel || "sem leitura"}</p>
          <p className="mt-1 text-xs text-gray-500">
            Sync fail: {Number(syncFailureRate || 0).toLocaleString("pt-BR")}% • Dias{" "}
            {Number(eventStructure?.days || 0).toLocaleString("pt-BR")} • Janelas{" "}
            {Number(eventStructure?.shifts || 0).toLocaleString("pt-BR")}
          </p>
        </div>
      </div>

      <div className="mt-5 rounded-2xl border border-gray-800 bg-gray-950/70 p-4">
        <div className="flex flex-col gap-3 xl:flex-row">
          <textarea
            rows={3}
            className="input min-h-[96px] flex-1"
            placeholder="Ex.: Onde estao as maiores lacunas de cobertura? Ha risco estrutural neste setor ou nessa lideranca?"
            value={question}
            onChange={(event) => setQuestion(event.target.value)}
          />
          <button
            type="button"
            className="btn-primary px-5 xl:self-stretch"
            disabled={loading}
            onClick={handleAsk}
          >
            <span className="flex items-center justify-center gap-2">
              <Send size={16} className={loading ? "animate-pulse" : ""} />
              {loading ? "Analisando..." : "Perguntar"}
            </span>
          </button>
        </div>
      </div>

      <div className="mt-5 space-y-3">
        {messages.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-gray-800 bg-gray-950/60 p-5 text-sm text-gray-400">
            Nenhuma consulta ainda. Use este agente para perguntar sobre lacunas de lideranca,
            distribuicao por setor, binds pendentes, cobertura operacional e risco de montagem da
            equipe.
          </div>
        ) : (
          messages.map((message, index) => (
            <div
              key={`${message.role}-${index}`}
              className={`rounded-2xl border p-4 ${
                message.role === "user"
                  ? "border-emerald-900/50 bg-emerald-950/20 text-emerald-50"
                  : "border-gray-800 bg-gray-950/70 text-gray-200"
              }`}
            >
              <p className="mb-2 text-[11px] uppercase tracking-[0.24em] opacity-70">
                {message.role === "user" ? "Operador" : "Agente"}
              </p>
              <p className="whitespace-pre-wrap text-sm leading-relaxed">{message.content}</p>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
