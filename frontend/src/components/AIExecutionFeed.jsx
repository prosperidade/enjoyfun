import { useEffect, useState, useCallback } from "react";
import { Activity, Bot, Clock3, RefreshCw, ShieldCheck, ShieldX, AlertTriangle, CheckCircle, XCircle } from "lucide-react";
import { listAIExecutions, approveAIExecution, rejectAIExecution } from "../api/ai";
import toast from "react-hot-toast";

const EXECUTION_STATUS_META = {
  succeeded: { label: "Sucesso", className: "badge-green" },
  failed: { label: "Falha", className: "badge-red" },
  blocked: { label: "Bloqueado", className: "badge-yellow" },
  pending: { label: "Pendente", className: "badge-yellow" },
  running: { label: "Executando", className: "badge-blue" },
};

const APPROVAL_STATUS_META = {
  not_required: { label: "Sem aprovacao", className: "badge-gray" },
  pending: { label: "Aguardando aprovacao", className: "badge-yellow" },
  approved: { label: "Aprovado", className: "badge-green" },
  rejected: { label: "Rejeitado", className: "badge-red" },
};

const RISK_LEVEL_META = {
  none: { label: "Nenhum", className: "text-gray-300 border-gray-800/50 bg-gray-950/40" },
  read: { label: "Leitura", className: "text-green-300 border-green-800/50 bg-green-950/30" },
  write: { label: "Escrita", className: "text-amber-300 border-amber-800/50 bg-amber-950/30" },
  destructive: { label: "Destrutivo", className: "text-red-200 border-red-600/50 bg-red-900/40" },
};

function resolveExecutionStatusMeta(status) {
  return EXECUTION_STATUS_META[status] || { label: status || "Desconhecido", className: "badge-gray" };
}

function resolveApprovalStatusMeta(status) {
  return APPROVAL_STATUS_META[status] || { label: status || "Indefinido", className: "badge-gray" };
}

function resolveRiskMeta(level) {
  return RISK_LEVEL_META[String(level || "").toLowerCase()] || { label: level || "n/d", className: "text-gray-400 border-gray-800 bg-gray-950/30" };
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
      if (execution.approval_status === "pending") {
        summary.pendingApproval += 1;
      }
      return summary;
    },
    { total: 0, succeeded: 0, attention: 0, pendingApproval: 0 }
  );
}

function normalizePreview(value, fallback) {
  const text = String(value || "").trim();
  return text || fallback;
}

function parseToolCalls(execution) {
  if (Array.isArray(execution.tool_calls)) return execution.tool_calls;
  if (typeof execution.tool_calls === "string") {
    try {
      const parsed = JSON.parse(execution.tool_calls);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }
  return [];
}

function buildApprovalPayload(execution, reason) {
  const payload = {};

  if (Number(execution?.event_id) > 0) {
    payload.event_id = Number(execution.event_id);
  }

  if (execution?.approval_scope_key) {
    payload.approval_scope_key = execution.approval_scope_key;
  }

  if (typeof reason === "string" && reason.trim() !== "") {
    payload.reason = reason.trim();
  }

  return payload;
}

function formatToolPreview(tool) {
  const preview = tool?.arguments_preview ?? tool?.arguments ?? tool?.params ?? null;
  if (!preview) return null;

  if (typeof preview === "string") {
    return preview.slice(0, 220);
  }

  try {
    return JSON.stringify(preview).slice(0, 220);
  } catch {
    return null;
  }
}

function buildImpactSummaryFromToolCalls(toolCalls) {
  if (!Array.isArray(toolCalls) || toolCalls.length === 0) return null;

  const summary = toolCalls.slice(0, 3).map((tool) => {
    const name = tool?.tool_name || tool?.name || "tool desconhecida";
    const risk = tool?.risk_level ? `risco=${tool.risk_level}` : null;
    const preview = formatToolPreview(tool);
    return [name, risk, preview].filter(Boolean).join(" | ");
  });

  if (toolCalls.length > 3) {
    summary.push(`+${toolCalls.length - 3} tool(s)`);
  }

  return summary.join(" || ");
}

function parseResultSummary(execution) {
  if (execution.execution_status === "failed") {
    return normalizePreview(execution.error_message, "Sem detalhe de erro salvo.");
  }
  if (execution.execution_status === "succeeded" && execution.approval_status === "approved") {
    return normalizePreview(execution.response_preview, "Execucao concluida com sucesso.");
  }
  return null;
}

export default function AIExecutionFeed() {
  const [executions, setExecutions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  const loadExecutions = useCallback(async ({ silent = false } = {}) => {
    if (silent) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    try {
      const data = await listAIExecutions({ limit: 12 });
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
  }, []);

  useEffect(() => {
    loadExecutions();
  }, [loadExecutions]);

  const handleApprove = useCallback(async (execution) => {
    try {
      const payload = buildApprovalPayload(execution);
      const result = await approveAIExecution(execution.id, payload);
      toast.success("Execucao aprovada e processada.");
      setExecutions((prev) =>
        prev.map((ex) =>
          ex.id === execution.id
            ? { ...ex, ...result }
            : ex
        )
      );
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao aprovar execucao.");
    }
  }, []);

  const handleReject = useCallback(async (execution, reason) => {
    try {
      const payload = buildApprovalPayload(execution, reason);
      const result = await rejectAIExecution(execution.id, payload);
      toast.success("Execucao rejeitada.");
      setExecutions((prev) =>
        prev.map((ex) =>
          ex.id === execution.id
            ? {
                ...ex,
                ...result,
                approval_status: result?.approval_status || "rejected",
                execution_status: result?.execution_status || "blocked",
                approval_decision_reason: payload.reason || ex.approval_decision_reason,
              }
            : ex
        )
      );
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao rejeitar execucao.");
    }
  }, []);

  const summary = summarizeExecutions(executions);

  const pendingApprovals = executions.filter((ex) => ex.approval_status === "pending");
  const otherExecutions = executions.filter((ex) => ex.approval_status !== "pending");

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
          {summary.pendingApproval > 0 ? (
            <span className="px-3 py-1 rounded-full border border-yellow-700/60 bg-yellow-950/40 text-yellow-200 font-semibold animate-pulse">
              {summary.pendingApproval} aguardando aprovacao
            </span>
          ) : null}
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

      {/* Pending Approvals Section */}
      {pendingApprovals.length > 0 ? (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <AlertTriangle size={16} className="text-yellow-400" />
            <h3 className="text-sm font-semibold text-yellow-200">
              Aguardando sua decisao ({pendingApprovals.length})
            </h3>
          </div>
          <div className="grid xl:grid-cols-2 gap-5">
            {pendingApprovals.map((execution) => (
              <PendingApprovalCard
                key={execution.id}
                execution={execution}
                onApprove={handleApprove}
                onReject={handleReject}
              />
            ))}
          </div>
        </div>
      ) : null}

      {/* Other Executions */}
      {otherExecutions.length === 0 && pendingApprovals.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-gray-800 bg-gray-950/60 p-6 text-sm text-gray-400">
          Nenhuma execucao recente materializada ainda para este organizer.
        </div>
      ) : otherExecutions.length > 0 ? (
        <div className="space-y-4">
          {pendingApprovals.length > 0 ? (
            <h3 className="text-sm font-semibold text-gray-400">Historico de execucoes</h3>
          ) : null}
          <div className="grid xl:grid-cols-2 gap-5">
            {otherExecutions.map((execution) => (
              <ExecutionCard key={execution.id} execution={execution} />
            ))}
          </div>
        </div>
      ) : null}
    </section>
  );
}

function PendingApprovalCard({ execution, onApprove, onReject }) {
  const [acting, setActing] = useState(false);
  const [rejectReason, setRejectReason] = useState("");
  const [showRejectInput, setShowRejectInput] = useState(false);

  const toolCalls = parseToolCalls(execution);
  const riskMeta = resolveRiskMeta(execution.approval_risk_level);
  const scopeKey = execution.approval_scope_key || execution.agent_key || "escopo n/d";
  const impactSummary = normalizePreview(
    execution.impact_summary || buildImpactSummaryFromToolCalls(toolCalls),
    "Sem resumo de impacto calculado."
  );

  const handleApprove = async () => {
    setActing(true);
    try {
      await onApprove(execution);
    } finally {
      setActing(false);
    }
  };

  const handleReject = async () => {
    setActing(true);
    try {
      await onReject(execution, rejectReason || undefined);
    } finally {
      setActing(false);
      setShowRejectInput(false);
    }
  };

  return (
    <article className="flex flex-col gap-4 rounded-2xl border-2 border-yellow-800/60 bg-yellow-950/10 p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex flex-wrap gap-2 mb-2">
            <span className="badge badge-yellow font-semibold">Aguardando aprovacao</span>
            <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${riskMeta.className}`}>
              Risco: {riskMeta.label}
            </span>
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

      {/* Scope and context */}
      <div className="rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2 text-xs">
        <span className="text-gray-500">Escopo: </span>
        <span className="text-gray-200 font-medium">{scopeKey}</span>
        {execution.event_id ? (
          <>
            <span className="text-gray-600 mx-2">|</span>
            <span className="text-gray-500">Evento: </span>
            <span className="text-gray-200">{execution.event_id}</span>
          </>
        ) : null}
      </div>

      {/* Tool calls detail */}
      {toolCalls.length > 0 ? (
        <div className="rounded-xl border border-gray-800 bg-gray-950/70 p-3 space-y-2">
          <p className="text-[11px] uppercase tracking-[0.2em] text-gray-500">
            Tools que serao executadas ({toolCalls.length})
          </p>
          {toolCalls.map((tool, idx) => (
            <div key={idx} className="flex items-start gap-2 text-xs">
              <span className="shrink-0 mt-0.5 w-5 h-5 flex items-center justify-center rounded bg-gray-800 text-gray-400 text-[10px] font-mono">
                {idx + 1}
              </span>
              <div className="min-w-0">
                <p className="font-semibold text-white">{tool.tool_name || tool.name || "tool desconhecida"}</p>
                {formatToolPreview(tool) ? (
                  <p className="text-gray-400 mt-0.5 break-all">
                    {formatToolPreview(tool)}
                  </p>
                ) : null}
              </div>
            </div>
          ))}
        </div>
      ) : null}

      {/* Prompt preview */}
      <PreviewBox
        title="Prompt / Contexto"
        text={normalizePreview(execution.prompt_preview, "Sem preview de prompt salvo.")}
      />

      {/* Diff / impact summary */}
      <PreviewBox
        title="Impacto esperado"
        text={impactSummary}
        tone="warning"
      />

      {/* Action buttons */}
      <div className="flex flex-col gap-3 mt-1">
        {showRejectInput ? (
          <div className="flex flex-col gap-2">
            <textarea
              className="w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-gray-500 focus:outline-none"
              placeholder="Motivo da rejeicao (opcional)"
              rows={2}
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
            />
            <div className="flex gap-2">
              <button
                type="button"
                disabled={acting}
                onClick={handleReject}
                className="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600 disabled:opacity-60 transition-colors"
              >
                <XCircle size={14} />
                {acting ? "Rejeitando..." : "Confirmar rejeicao"}
              </button>
              <button
                type="button"
                onClick={() => setShowRejectInput(false)}
                className="text-sm text-gray-400 hover:text-gray-200 transition-colors"
              >
                Cancelar
              </button>
            </div>
          </div>
        ) : (
          <div className="flex gap-2">
            <button
              type="button"
              disabled={acting}
              onClick={handleApprove}
              className="inline-flex items-center gap-2 rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600 disabled:opacity-60 transition-colors"
            >
              <ShieldCheck size={14} />
              {acting ? "Aprovando..." : "Aprovar e executar"}
            </button>
            <button
              type="button"
              disabled={acting}
              onClick={() => setShowRejectInput(true)}
              className="inline-flex items-center gap-2 rounded-lg border border-red-800/60 bg-red-950/30 px-4 py-2 text-sm font-semibold text-red-200 hover:bg-red-900/40 disabled:opacity-60 transition-colors"
            >
              <ShieldX size={14} />
              Rejeitar
            </button>
          </div>
        )}
      </div>
    </article>
  );
}

function ExecutionCard({ execution }) {
  const statusMeta = resolveExecutionStatusMeta(execution.execution_status);
  const approvalMeta = resolveApprovalStatusMeta(execution.approval_status);
  const resultSummary = parseResultSummary(execution);
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
            {execution.approval_status === "approved" && execution.execution_status === "succeeded" ? (
              <span className="inline-flex items-center gap-1 text-[11px] text-green-400">
                <CheckCircle size={12} /> Executado
              </span>
            ) : null}
            {execution.approval_status === "approved" && execution.execution_status === "failed" ? (
              <span className="inline-flex items-center gap-1 text-[11px] text-red-400">
                <XCircle size={12} /> Execucao falhou
              </span>
            ) : null}
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

      {/* Show execution result after approval */}
      {resultSummary && execution.approval_status === "approved" ? (
        <div className={`rounded-xl border p-3 ${
          execution.execution_status === "succeeded"
            ? "border-green-900/50 bg-green-950/20"
            : "border-red-900/50 bg-red-950/20"
        }`}>
          <p className="text-[11px] uppercase tracking-[0.2em] text-gray-500 mb-2">
            Resultado da execucao pos-aprovacao
          </p>
          <p className={`text-xs leading-relaxed whitespace-pre-wrap break-words ${
            execution.execution_status === "succeeded" ? "text-green-200" : "text-red-200"
          }`}>
            {resultSummary}
          </p>
        </div>
      ) : null}

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

function MetaBox({ icon, label, value }) {
  const IconComponent = icon;

  return (
    <div className="rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-2">
      <div className="flex items-center gap-2 text-gray-500 mb-1">
        <IconComponent size={12} />
        <span>{label}</span>
      </div>
      <p className="text-gray-200">{value}</p>
    </div>
  );
}

function PreviewBox({ title, text, tone = "default" }) {
  const textClassName =
    tone === "danger" ? "text-red-200" :
    tone === "warning" ? "text-amber-200" :
    "text-gray-300";
  const borderClassName =
    tone === "danger" ? "border-red-900/50 bg-red-950/20" :
    tone === "warning" ? "border-amber-900/50 bg-amber-950/20" :
    "border-gray-800 bg-gray-950/70";

  return (
    <div className={`rounded-xl border p-3 ${borderClassName}`}>
      <p className="text-[11px] uppercase tracking-[0.2em] text-gray-500 mb-2">{title}</p>
      <p className={`text-xs leading-relaxed whitespace-pre-wrap break-words ${textClassName}`}>
        {text}
      </p>
    </div>
  );
}
