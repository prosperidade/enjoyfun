import { useEffect, useState } from "react";
import { Activity, Bot, Clock3, RefreshCw } from "lucide-react";
import { listAIExecutions } from "../api/ai";

const EXECUTION_STATUS_META = {
  succeeded: { label: "Sucesso", className: "badge-green" },
  failed: { label: "Falha", className: "badge-red" },
  blocked: { label: "Bloqueado", className: "badge-yellow" },
  pending: { label: "Pendente", className: "badge-yellow" },
};

const APPROVAL_STATUS_META = {
  not_required: { label: "Sem aprovacao", className: "badge-gray" },
  pending: { label: "Aguardando aprovacao", className: "badge-yellow" },
  approved: { label: "Aprovado", className: "badge-green" },
  rejected: { label: "Rejeitado", className: "badge-red" },
};

function resolveExecutionStatusMeta(status) {
  return EXECUTION_STATUS_META[status] || { label: status || "Desconhecido", className: "badge-gray" };
}

function resolveApprovalStatusMeta(status) {
  return APPROVAL_STATUS_META[status] || { label: status || "Indefinido", className: "badge-gray" };
}

function formatTimestamp(value) {
  if (!value) return "Horario indisponivel";

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Horario invalido";

  return new Intl.DateTimeFormat("pt-BR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(date);
}

function formatDuration(value) {
  const duration = Number(value || 0);
  if (!Number.isFinite(duration) || duration <= 0) {
    return "duracao n/d";
  }

  if (duration < 1000) {
    return `${duration}ms`;
  }

  return `${(duration / 1000).toFixed(duration >= 10000 ? 0 : 1)}s`;
}

function summarizeExecutions(executions) {
  return executions.reduce(
    (summary, execution) => {
      summary.total += 1;
      if (execution.execution_status === "succeeded") {
        summary.succeeded += 1;
      } else {
        summary.attention += 1;
      }
      return summary;
    },
    { total: 0, succeeded: 0, attention: 0 }
  );
}

function normalizePreview(value, fallback) {
  const text = String(value || "").trim();
  return text || fallback;
}

export default function AIExecutionFeed() {
  const [executions, setExecutions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  const loadExecutions = async ({ silent = false } = {}) => {
    if (silent) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    try {
      const data = await listAIExecutions({ limit: 8 });
      setExecutions(data);
      setErrorMessage("");
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message || "Erro ao carregar execucoes recentes de IA."
      );
      if (!silent) {
        setExecutions([]);
      }
    } finally {
      if (silent) {
        setRefreshing(false);
      } else {
        setLoading(false);
      }
    }
  };

  useEffect(() => {
    loadExecutions();
  }, []);

  const summary = summarizeExecutions(executions);

  if (loading) {
    return <div className="text-gray-500 animate-pulse">Carregando execucoes recentes de IA...</div>;
  }

  return (
    <section className="card space-y-6 p-8">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h2 className="section-title flex items-center gap-2">
            <Activity size={20} className="text-brand" /> Execucoes recentes
          </h2>
          <p className="text-sm text-gray-400 mt-1">
            Trilha operacional materializada em <code>ai_agent_executions</code> para acompanhar
            o comportamento real do runtime.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className="px-3 py-1 rounded-full border border-gray-800 bg-gray-900 text-gray-300">
            {summary.total} recentes
          </span>
          <span className="px-3 py-1 rounded-full border border-green-900/60 bg-green-950/40 text-green-300">
            {summary.succeeded} sucesso
          </span>
          <span className="px-3 py-1 rounded-full border border-amber-900/60 bg-amber-950/40 text-amber-300">
            {summary.attention} com atencao
          </span>
          <button
            type="button"
            onClick={() => loadExecutions({ silent: true })}
            disabled={refreshing}
            className="px-3 py-1 rounded-full border border-gray-700 bg-gray-950 text-gray-300 hover:border-gray-500 disabled:opacity-60"
          >
            <span className="inline-flex items-center gap-2">
              <RefreshCw size={13} className={refreshing ? "animate-spin" : ""} />
              {refreshing ? "Atualizando..." : "Atualizar"}
            </span>
          </button>
        </div>
      </div>

      {errorMessage ? (
        <div className="rounded-2xl border border-red-900/70 bg-red-950/30 p-4 text-sm text-red-200">
          {errorMessage}
        </div>
      ) : null}

      {executions.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-gray-800 bg-gray-950/60 p-6 text-sm text-gray-400">
          Nenhuma execucao recente materializada ainda para este organizer.
        </div>
      ) : (
        <div className="grid xl:grid-cols-2 gap-5">
          {executions.map((execution) => (
            <ExecutionCard key={execution.id} execution={execution} />
          ))}
        </div>
      )}
    </section>
  );
}

function ExecutionCard({ execution }) {
  const statusMeta = resolveExecutionStatusMeta(execution.execution_status);
  const approvalMeta = resolveApprovalStatusMeta(execution.approval_status);
  const outputLabel = execution.execution_status === "failed" ? "Erro" : "Resposta";
  const outputText =
    execution.execution_status === "failed"
      ? normalizePreview(execution.error_message, "Sem detalhe de erro salvo.")
      : normalizePreview(execution.response_preview, "Sem preview de resposta salvo.");

  return (
    <article className="card-hover flex flex-col gap-4 border-gray-800">
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex flex-wrap gap-2 mb-2">
            <span className={`badge ${statusMeta.className}`}>{statusMeta.label}</span>
            <span className={`badge ${approvalMeta.className}`}>{approvalMeta.label}</span>
          </div>
          <h3 className="font-bold text-white">
            {execution.agent_key || "Fluxo legado / sem agente explicito"}
          </h3>
          <p className="text-xs text-gray-400 mt-1">
            {(execution.surface || "sem superficie").toUpperCase()} • {execution.entrypoint || "ai/insight"}
          </p>
        </div>
        <div className="text-right text-xs text-gray-500">
          <p>{formatTimestamp(execution.created_at)}</p>
          <p className="mt-1">{formatDuration(execution.request_duration_ms)}</p>
        </div>
      </div>

      <div className="grid sm:grid-cols-2 gap-2 text-xs">
        <MetaBox
          icon={Bot}
          label="Runtime"
          value={`${execution.provider || "provider n/d"}${execution.model ? ` • ${execution.model}` : ""}`}
        />
        <MetaBox
          icon={Clock3}
          label="Contexto"
          value={`Evento ${execution.event_id || "-"} • Usuario ${execution.user_id || "-"}`}
        />
      </div>

      <div className="grid gap-3 lg:grid-cols-2">
        <PreviewBox
          title="Prompt"
          text={normalizePreview(execution.prompt_preview, "Sem preview de prompt salvo.")}
        />
        <PreviewBox
          title={outputLabel}
          text={outputText}
          tone={execution.execution_status === "failed" ? "danger" : "default"}
        />
      </div>

      <div className="flex flex-wrap gap-2 text-[11px] text-gray-500">
        <span className="px-2 py-1 rounded-full border border-gray-800 bg-gray-950/70">
          tool calls: {Number(execution.tool_call_count || 0)}
        </span>
        <span className="px-2 py-1 rounded-full border border-gray-800 bg-gray-950/70">
          concluido: {formatTimestamp(execution.completed_at)}
        </span>
      </div>
    </article>
  );
}

function MetaBox({ icon: Icon, label, value }) {
  return (
    <div className="rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2">
      <div className="flex items-center gap-2 text-gray-500 mb-1">
        <Icon size={12} />
        <span>{label}</span>
      </div>
      <p className="text-gray-200">{value}</p>
    </div>
  );
}

function PreviewBox({ title, text, tone = "default" }) {
  const textClassName = tone === "danger" ? "text-red-200" : "text-gray-300";
  const borderClassName = tone === "danger" ? "border-red-900/50 bg-red-950/20" : "border-gray-800 bg-gray-950/70";

  return (
    <div className={`rounded-xl border p-3 ${borderClassName}`}>
      <p className="text-[11px] uppercase tracking-[0.2em] text-gray-500 mb-2">{title}</p>
      <p className={`text-xs leading-relaxed whitespace-pre-wrap break-words ${textClassName}`}>
        {text}
      </p>
    </div>
  );
}
