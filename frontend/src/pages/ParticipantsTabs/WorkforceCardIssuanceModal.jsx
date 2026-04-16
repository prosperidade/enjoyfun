import { useCallback, useEffect, useMemo, useState } from "react";
import { AlertTriangle, CheckCircle2, CreditCard, Loader2, ShieldAlert, X } from "lucide-react";
import toast from "react-hot-toast";
import {
  issueWorkforceCardIssuanceApi,
  previewWorkforceCardIssuanceApi,
} from "../../api/workforceCardIssuance";
import { reportOperationalTelemetry } from "../../lib/operationalTelemetry";

const PREVIEW_STATUS_META = {
  eligible: {
    label: "Elegivel",
    badgeClass: "border-emerald-700/60 bg-emerald-500/10 text-emerald-300",
  },
  already_has_active_card: {
    label: "Ja possui cartao",
    badgeClass: "border-sky-700/60 bg-sky-500/10 text-sky-300",
  },
  legacy_conflict_review_required: {
    label: "Revisao legado",
    badgeClass: "border-amber-700/60 bg-amber-500/10 text-amber-300",
  },
  missing_identity: {
    label: "Identidade pendente",
    badgeClass: "border-orange-700/60 bg-orange-500/10 text-orange-300",
  },
  out_of_scope: {
    label: "Fora do escopo",
    badgeClass: "border-slate-700/60 bg-slate-500/10 text-slate-300",
  },
  error: {
    label: "Erro",
    badgeClass: "border-red-700/60 bg-red-500/10 text-red-300",
  },
};

const ISSUE_STATUS_META = {
  issued: {
    label: "Emitido",
    badgeClass: "border-emerald-700/60 bg-emerald-500/10 text-emerald-300",
  },
  skipped: {
    label: "Pulado",
    badgeClass: "border-sky-700/60 bg-sky-500/10 text-sky-300",
  },
  failed: {
    label: "Falhou",
    badgeClass: "border-red-700/60 bg-red-500/10 text-red-300",
  },
};

const createIdempotencyKey = () => {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  return `workforce-bulk-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const formatSector = (value = "") => {
  const normalized = String(value || "").trim();
  return normalized ? normalized.replace(/_/g, " ") : "geral";
};

const shortenCardId = (value = "") => {
  const normalized = String(value || "").trim();
  if (!normalized) return "";
  if (normalized.length <= 12) return normalized;
  return `${normalized.slice(0, 8)}...${normalized.slice(-4)}`;
};

const formatCurrency = (value = 0) =>
  Number(value || 0).toLocaleString("pt-BR", {
    style: "currency",
    currency: "BRL",
  });

const normalizeMoneyInput = (value = "") => {
  if (value === null || value === undefined || value === "") {
    return 0;
  }

  if (typeof value === "number") {
    return Number.isFinite(value) && value >= 0 ? Math.round(value * 100) / 100 : null;
  }

  const raw = String(value).trim();
  if (!raw) {
    return 0;
  }

  const sanitized = raw.replace(/[^\d,.-]/g, "");
  if (!sanitized) {
    return null;
  }

  const lastComma = sanitized.lastIndexOf(",");
  const lastDot = sanitized.lastIndexOf(".");
  const decimalIndex = Math.max(lastComma, lastDot);

  let normalizedValue = sanitized;
  if (decimalIndex >= 0) {
    const integerPart = sanitized.slice(0, decimalIndex).replace(/[^\d-]/g, "") || "0";
    const fractionPart = sanitized.slice(decimalIndex + 1).replace(/\D/g, "");
    normalizedValue = `${integerPart}.${fractionPart}`;
  } else {
    normalizedValue = sanitized.replace(/[^\d-]/g, "");
  }

  const amount = Number(normalizedValue);
  return Number.isFinite(amount) && amount >= 0 ? Math.round(amount * 100) / 100 : null;
};

const formatMoneyInputValue = (value = 0) => Number(value || 0).toFixed(2).replace(".", ",");

const resolveStatusMeta = (status = "", stage = "preview") => {
  const source = stage === "result" ? ISSUE_STATUS_META : PREVIEW_STATUS_META;
  return source[String(status || "").trim()] || {
    label: String(status || "desconhecido"),
    badgeClass: "border-slate-700/60 bg-slate-500/10 text-slate-300",
  };
};

const SummaryCard = ({ label, value, tone = "default" }) => {
  const toneClass =
    tone === "success"
      ? "border-emerald-800/60 bg-emerald-500/10 text-emerald-200"
      : tone === "warning"
        ? "border-amber-800/60 bg-amber-500/10 text-amber-200"
        : tone === "danger"
          ? "border-red-800/60 bg-red-500/10 text-red-200"
          : "border-slate-800/40 bg-slate-950 text-slate-100";

  return (
    <div className={`flex min-h-[112px] min-w-0 flex-col items-center justify-center rounded-2xl border p-4 text-center ${toneClass}`}>
      <p className="text-[9px] uppercase tracking-[0.18em] opacity-80 sm:text-[10px]">{label}</p>
      <p className="mt-2 break-words text-center text-lg font-black leading-tight sm:text-xl xl:text-[1.35rem]">{value}</p>
    </div>
  );
};

export default function WorkforceCardIssuanceModal({
  isOpen,
  onClose,
  eventId,
  selectedParticipants = [],
  managerEventRoleId = null,
  managerSector = "",
  contextLabel = "",
  onIssued,
}) {
  const [preview, setPreview] = useState(null);
  const [result, setResult] = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [issuing, setIssuing] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");
  const [idempotencyKey, setIdempotencyKey] = useState("");
  const [initialBalanceInput, setInitialBalanceInput] = useState("0,00");

  const participantIds = useMemo(
    () =>
      Array.from(
        new Set(
          (Array.isArray(selectedParticipants) ? selectedParticipants : [])
            .map((participant) => Number(participant?.participant_id || 0))
            .filter((participantId) => participantId > 0)
        )
      ),
    [selectedParticipants]
  );
  const participantIdsKey = participantIds.join(",");
  const participantMap = useMemo(() => {
    const entries = (Array.isArray(selectedParticipants) ? selectedParticipants : []).map((participant) => [
      Number(participant?.participant_id || 0),
      participant,
    ]);
    return new Map(entries.filter(([participantId]) => participantId > 0));
  }, [selectedParticipants]);

  const currentItems = useMemo(() => result?.items || preview?.items || [], [preview?.items, result?.items]);
  const eligibleCount = Number(preview?.summary?.eligible_count || 0);
  const initialBalance = normalizeMoneyInput(initialBalanceInput);
  const initialBalanceValid = initialBalance !== null;
  const previewInitialBalance = initialBalanceValid ? initialBalance : Number(preview?.initial_balance || 0);
  const canIssue = Boolean(preview?.can_issue) && eligibleCount > 0 && !result && initialBalanceValid;
  const previewReady = Boolean(preview) && !result && !previewLoading;
  const activeSummary = result?.summary || preview?.summary || {};
  const existingCardCount = Number(
    activeSummary?.already_has_active_card_count ||
      currentItems.filter((item) => item?.existing_card_id && !item?.issued_card_id).length
  );
  const existingCreditCount = Number(activeSummary?.existing_credit_count || 0);
  const existingCreditTotal = Number(activeSummary?.existing_credit_total || 0);
  const existingCardExamples = useMemo(
    () =>
      currentItems
        .filter((item) => item?.existing_card_id && !item?.issued_card_id)
        .slice(0, 3)
        .map((item) => {
          const label = item?.name || `Participante #${Number(item?.participant_id || 0)}`;
          return `${label} (${formatCurrency(item?.existing_card_balance || 0)})`;
        }),
    [currentItems]
  );
  const summaryCards = useMemo(() => {
    if (result) {
      const resultInitialBalance = Number(result.initial_balance || 0);
      return [
        { label: "Solicitados", value: Number(result.summary?.requested_count || 0) },
        { label: "Elegiveis no preview", value: Number(result.summary?.preview_eligible_count || 0) },
        { label: "Emitidos", value: Number(result.summary?.issued_count || 0), tone: "success" },
        { label: "Pulados", value: Number(result.summary?.skipped_count || 0), tone: "warning" },
        { label: "Falhas", value: Number(result.summary?.failed_count || 0), tone: "danger" },
        { label: "Saldo/cartao", value: formatCurrency(resultInitialBalance) },
        {
          label: "Credito aplicado",
          value: formatCurrency(
            Number(result.summary?.applied_initial_credit_total || 0) ||
              Number(result.summary?.issued_count || 0) * resultInitialBalance
          ),
          tone: resultInitialBalance > 0 ? "success" : "default",
        },
      ];
    }

    return [
      { label: "Selecionados", value: Number(preview?.summary?.requested_count || participantIds.length || 0) },
      { label: "Elegiveis", value: Number(preview?.summary?.eligible_count || 0), tone: "success" },
      {
        label: "Ja possuem cartao",
        value: Number(preview?.summary?.already_has_active_card_count || 0),
        tone: Number(preview?.summary?.already_has_active_card_count || 0) > 0 ? "warning" : "default",
      },
      {
        label: "Revisao legado",
        value: Number(preview?.summary?.legacy_conflict_count || 0),
        tone: "warning",
      },
      {
        label: "Bloqueios/erros",
        value:
          Number(preview?.summary?.missing_identity_count || 0) +
          Number(preview?.summary?.out_of_scope_count || 0) +
          Number(preview?.summary?.error_count || 0),
        tone: "danger",
      },
      { label: "Saldo/cartao", value: formatCurrency(previewInitialBalance) },
      {
        label: "Credito estimado",
        value: formatCurrency(
          Number(preview?.summary?.estimated_initial_credit_total || eligibleCount * previewInitialBalance)
        ),
        tone: previewInitialBalance > 0 ? "success" : "default",
      },
    ];
  }, [eligibleCount, participantIds.length, preview, previewInitialBalance, result]);

  const loadPreview = useCallback(async (balanceOverride = 0) => {
    if (balanceOverride === null) {
      setPreview(null);
      setResult(null);
      setErrorMessage("Informe um saldo inicial valido para continuar.");
      return;
    }

    if (Number(eventId || 0) <= 0 || participantIds.length === 0) {
      setPreview({
        summary: {
          requested_count: participantIds.length,
          eligible_count: 0,
          already_has_active_card_count: 0,
          legacy_conflict_count: 0,
          missing_identity_count: 0,
          out_of_scope_count: 0,
          error_count: 0,
          estimated_initial_credit_total: 0,
        },
        items: [],
        can_issue: false,
        initial_balance: Number(balanceOverride || 0),
      });
      setResult(null);
      setErrorMessage("");
      return;
    }

    setPreviewLoading(true);
    setErrorMessage("");
    setResult(null);

    try {
      const nextPreview = await previewWorkforceCardIssuanceApi({
        eventId,
        participantIds,
        managerEventRoleId,
        initialBalance: balanceOverride,
        sourceContext: {
          selected_count: participantIds.length,
          manager_sector: managerSector || "",
        },
      });
      setPreview(nextPreview);
    } catch (error) {
      console.error(error);
      const nextError = error?.response?.data?.message || "Erro ao gerar preview da emissao.";
      setPreview(null);
      setErrorMessage(nextError);
      void reportOperationalTelemetry("workforce.card_issuance.preview_failed", {
        eventId,
        details: {
          message: nextError,
          participant_count: participantIds.length,
          manager_event_role_id: Number(managerEventRoleId || 0) || null,
          initial_balance: Number(balanceOverride || 0),
        },
      });
      toast.error(nextError);
    } finally {
      setPreviewLoading(false);
    }
  }, [eventId, managerEventRoleId, managerSector, participantIds]);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const defaultInitialBalance = 0;
    setInitialBalanceInput(formatMoneyInputValue(defaultInitialBalance));
    setIdempotencyKey(createIdempotencyKey());
    void loadPreview(defaultInitialBalance);
  }, [isOpen, loadPreview, participantIdsKey]);

  if (!isOpen) {
    return null;
  }

  const handleIssue = async () => {
    if (!canIssue || issuing) {
      return;
    }

    if (initialBalance === null) {
      const nextError = "Informe um saldo inicial valido para emitir os cartoes.";
      setErrorMessage(nextError);
      toast.error(nextError);
      return;
    }

    if (existingCardCount > 0) {
      toast(
        existingCreditTotal > 0
          ? `Aviso: ${existingCardCount} membro(s) ja possuem cartao emitido com ${formatCurrency(
              existingCreditTotal
            )} em saldo e serao pulados nesta emissao.`
          : `Aviso: ${existingCardCount} membro(s) ja possuem cartao emitido neste evento e serao pulados nesta emissao.`
      );
    }

    setIssuing(true);
    setErrorMessage("");

    try {
      const issueResult = await issueWorkforceCardIssuanceApi({
        eventId,
        participantIds,
        managerEventRoleId,
        initialBalance,
        idempotencyKey,
        sourceContext: {
          selected_count: participantIds.length,
          manager_sector: managerSector || "",
        },
      });
      setResult(issueResult);
      setInitialBalanceInput(formatMoneyInputValue(issueResult.initial_balance || initialBalance));

      const issuedCount = Number(issueResult.summary?.issued_count || 0);
      const failedCount = Number(issueResult.summary?.failed_count || 0);
      const skippedCount = Number(issueResult.summary?.skipped_count || 0);
      const appliedInitialCredit = Number(issueResult.summary?.applied_initial_credit_total || 0);
      const creditSuffix =
        appliedInitialCredit > 0 ? ` Credito inicial aplicado: ${formatCurrency(appliedInitialCredit)}.` : "";

      if (failedCount > 0) {
        toast.error(
          `Lote concluido com ${issuedCount} emitidos, ${skippedCount} pulados e ${failedCount} falhas.${creditSuffix}`
        );
      } else if (skippedCount > 0) {
        toast(`Lote concluido com ${issuedCount} emitidos e ${skippedCount} pulados.${creditSuffix}`);
      } else {
        toast.success(`${issuedCount} cartoes emitidos com sucesso.${creditSuffix}`);
      }

      await onIssued?.(issueResult);
    } catch (error) {
      console.error(error);
      const nextError = error?.response?.data?.message || "Erro ao emitir cartoes em massa.";
      setErrorMessage(nextError);
      void reportOperationalTelemetry("workforce.card_issuance.issue_failed", {
        eventId,
        details: {
          message: nextError,
          participant_count: participantIds.length,
          manager_event_role_id: Number(managerEventRoleId || 0) || null,
          initial_balance: initialBalance,
        },
      });
      toast.error(nextError);
    } finally {
      setIssuing(false);
    }
  };

  const closeDisabled = issuing;
  const currentStage = result ? "result" : "preview";
  const issueButtonLabel = issuing ? "Emitindo..." : `Confirmar emissao${eligibleCount > 0 ? ` (${eligibleCount})` : ""}`;
  const issueDisabledReason = !initialBalanceValid
    ? "Informe um saldo inicial valido para liberar a emissao."
    : previewLoading
      ? "Aguarde o preview terminar para confirmar a emissao."
      : result
        ? "Este lote ja foi processado."
        : !preview
          ? "Gere o preview antes de confirmar a emissao."
          : eligibleCount <= 0
            ? "Nao ha participantes elegiveis neste preview."
            : !preview?.can_issue
              ? "Revise os bloqueios do preview antes de emitir."
              : "";

  const handleRefreshPreview = async () => {
    await loadPreview(initialBalanceValid ? initialBalance : null);
    if (initialBalanceValid) {
      toast("Preview atualizado. Nenhum cartao foi emitido ainda; clique em Confirmar emissao para gravar.");
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm">
      <div className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-[28px] border border-slate-800/40 bg-slate-950 shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-slate-800/40 px-5 py-4">
          <div className="min-w-0">
            <div className="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.24em] text-cyan-400">
              <CreditCard size={12} />
              Workforce bulk cards
            </div>
            <h3 className="mt-3 text-xl font-black text-slate-100">Emitir cartoes para a equipe selecionada</h3>
            <p className="mt-1 text-sm text-slate-400">
              Evento #{Number(eventId || 0)} • {participantIds.length} participante(s) selecionado(s)
            </p>
            {String(contextLabel || "").trim() && (
              <p className="mt-1 break-words text-[11px] uppercase tracking-[0.14em] text-cyan-400">{contextLabel}</p>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={closeDisabled}
            className="rounded-xl p-2 text-slate-400 transition hover:bg-slate-800/50 hover:text-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <X size={18} />
          </button>
        </div>

        <div className="max-h-[calc(92vh-148px)] overflow-y-auto px-5 py-5">
          <div className="mb-4 rounded-2xl border border-slate-800/40 bg-slate-900/60 p-4 text-sm text-slate-300">
            <div className="flex items-start gap-3">
              <ShieldAlert size={18} className="mt-0.5 text-amber-300" />
              <div>
                <p className="font-semibold text-slate-100">Preview obrigatorio antes da emissao</p>
                <p className="mt-1 text-slate-400">
                  O preview nao grava nada. A emissao so acontece na confirmacao e continua restrita ao evento atual.
                </p>
              </div>
            </div>
          </div>

          {previewLoading ? (
            <div className="flex min-h-[280px] items-center justify-center">
              <div className="flex items-center gap-3 rounded-2xl border border-slate-800/40 bg-slate-900/60 px-5 py-4 text-sm text-slate-300">
                <Loader2 size={18} className="animate-spin text-cyan-400" />
                Gerando preview da emissao...
              </div>
            </div>
          ) : (
            <>
              {errorMessage && (
                <div className="mb-4 rounded-2xl border border-red-800/60 bg-red-500/10 p-4 text-sm text-red-200">
                  <div className="flex items-start gap-3">
                    <AlertTriangle size={18} className="mt-0.5" />
                    <div>
                      <p className="font-semibold">Nao foi possivel concluir esta etapa.</p>
                      <p className="mt-1 text-red-200/80">{errorMessage}</p>
                    </div>
                  </div>
                </div>
              )}

              <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-7">
                {summaryCards.map((card) => (
                  <SummaryCard key={card.label} label={card.label} value={card.value} tone={card.tone} />
                ))}
              </div>

              {previewReady && (
                <div className="mt-4 rounded-2xl border border-cyan-500/40 bg-cyan-500/10 p-4 text-sm text-cyan-400">
                  <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div className="min-w-0">
                      <p className="font-semibold text-slate-100">Preview concluido, mas nada foi gravado ainda.</p>
                      <p className="mt-1 text-cyan-400/90">
                        A emissao real so acontece quando voce clicar em <strong>Confirmar emissao</strong>.
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={handleIssue}
                      disabled={!canIssue || issuing || previewLoading || !initialBalanceValid}
                      className="btn-primary h-10 px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      <span className="inline-flex items-center gap-2">
                        {issuing ? <Loader2 size={16} className="animate-spin" /> : <CheckCircle2 size={16} />}
                        {issueButtonLabel}
                      </span>
                    </button>
                  </div>
                  {issueDisabledReason && (
                    <p className="mt-2 text-xs text-cyan-400/80">{issueDisabledReason}</p>
                  )}
                </div>
              )}

              {existingCardCount > 0 && (
                <div
                  className={`mt-4 rounded-2xl border p-4 text-sm ${
                    existingCreditCount > 0
                      ? "border-amber-800/60 bg-amber-500/10 text-amber-100"
                      : "border-sky-800/60 bg-sky-500/10 text-sky-100"
                  }`}
                >
                  <div className="flex items-start gap-3">
                    <AlertTriangle
                      size={18}
                      className={`mt-0.5 ${existingCreditCount > 0 ? "text-amber-300" : "text-sky-300"}`}
                    />
                    <div className="min-w-0">
                      <p className="font-semibold text-slate-100">
                        {existingCreditCount > 0
                          ? "Aviso: ja existem cartoes emitidos com saldo ativo."
                          : "Aviso: ja existem cartoes emitidos neste evento."}
                      </p>
                      <p className={existingCreditCount > 0 ? "mt-1 text-amber-100/80" : "mt-1 text-sky-100/80"}>
                        {existingCardCount} membro(s) ja possuem cartao emitido neste evento. Saldo total atual desses
                        cartoes: {formatCurrency(existingCreditTotal)}. Eles serao pulados nesta emissao; use recarga
                        se quiser adicionar saldo.
                      </p>
                      {existingCardExamples.length > 0 && (
                        <p
                          className={`mt-2 break-words text-xs ${
                            existingCreditCount > 0 ? "text-amber-200/90" : "text-sky-200/90"
                          }`}
                        >
                          Exemplos: {existingCardExamples.join(", ")}.
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              )}

              <div className="mt-4 rounded-2xl border border-slate-800/40 bg-slate-900/60 p-4">
                <div className="grid gap-4 md:grid-cols-[240px_minmax(0,1fr)]">
                  <label className="block">
                    <span className="text-[10px] font-bold uppercase tracking-[0.24em] text-slate-500">
                      Saldo inicial por cartao
                    </span>
                    <input
                      type="text"
                      inputMode="decimal"
                      value={initialBalanceInput}
                      disabled={issuing || Boolean(result)}
                      onChange={(event) => setInitialBalanceInput(event.target.value)}
                      onBlur={() => {
                        const normalizedAmount = normalizeMoneyInput(initialBalanceInput);
                        if (normalizedAmount !== null) {
                          setInitialBalanceInput(formatMoneyInputValue(normalizedAmount));
                        }
                      }}
                      className={`mt-2 h-11 w-full rounded-xl border bg-slate-950 px-3 text-sm text-slate-100 outline-none transition ${
                        initialBalanceValid
                          ? "border-slate-700/50 focus:border-cyan-500"
                          : "border-red-700/70 focus:border-red-500"
                      }`}
                      placeholder="0,00"
                    />
                  </label>
                  <div className="rounded-2xl border border-slate-800/40 bg-slate-950/60 p-4 text-sm text-slate-300">
                    <p className="font-semibold text-slate-100">Carga inicial registrada no extrato</p>
                    <p className="mt-1 text-slate-400">
                      Cada cartao novo nasce com o saldo configurado aqui. O valor entra como credito real no wallet do
                      participante.
                    </p>
                    <p className="mt-3 text-xs uppercase tracking-[0.18em] text-cyan-400">
                      {result
                        ? `Credito aplicado no lote: ${formatCurrency(
                            Number(result.summary?.applied_initial_credit_total || 0)
                          )}`
                        : `Credito estimado no preview: ${formatCurrency(
                            Number(preview?.summary?.estimated_initial_credit_total || eligibleCount * previewInitialBalance)
                          )}`}
                    </p>
                    {!initialBalanceValid && (
                      <p className="mt-2 text-xs text-red-300">Informe um valor numerico valido, por exemplo 15,00.</p>
                    )}
                  </div>
                </div>
              </div>

              {result?.replayed && (
                <div className="mt-4 rounded-2xl border border-sky-800/60 bg-sky-500/10 p-4 text-sm text-sky-100">
                  Este lote foi reaproveitado por idempotencia. Nenhuma nova emissao foi criada nesta repeticao.
                </div>
              )}

              {result && Number(result.initial_balance || 0) > 0 && (
                <div className="mt-4 rounded-2xl border border-emerald-800/60 bg-emerald-500/10 p-4 text-sm text-emerald-100">
                  Cada cartao emitido neste lote recebeu {formatCurrency(result.initial_balance)} de saldo inicial.
                </div>
              )}

              <div className="mt-5 rounded-[24px] border border-slate-800/40 bg-slate-950/60">
                <div className="flex items-center justify-between gap-3 border-b border-slate-800/40 px-4 py-3">
                  <div>
                    <h4 className="text-sm font-bold text-slate-100">
                      {currentStage === "result" ? "Resultado do lote" : "Preview por participante"}
                    </h4>
                    <p className="mt-1 text-xs text-slate-500">
                      {currentStage === "result"
                        ? "Cada linha mostra o resultado final da tentativa de emissao."
                        : "Revise os bloqueios antes de confirmar a emissao."}
                    </p>
                  </div>
                  {currentStage === "preview" && (
                    <button
                      type="button"
                      onClick={() => void handleRefreshPreview()}
                      className="btn-secondary h-9 px-3 text-xs"
                    >
                      Regerar preview
                    </button>
                  )}
                </div>

                <div className="max-h-[360px] overflow-auto">
                  {currentItems.length === 0 ? (
                    <div className="px-4 py-10 text-center text-sm text-slate-500">
                      Nenhum participante selecionado para emissao.
                    </div>
                  ) : (
                    <table className="w-full text-left text-sm text-slate-300">
                      <thead className="bg-slate-950/80 text-[10px] uppercase tracking-[0.24em] text-slate-500">
                        <tr>
                          <th className="px-4 py-3">Participante</th>
                          <th className="px-4 py-3">Setor</th>
                          <th className="px-4 py-3">Status</th>
                          <th className="px-4 py-3">Detalhe</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-800/40">
                        {currentItems.map((item) => {
                          const participantId = Number(item?.participant_id || 0);
                          const participant = participantMap.get(participantId) || null;
                          const statusMeta = resolveStatusMeta(item?.status, currentStage);
                          const displayName = item?.name || participant?.name || participant?.person_name || "Participante";
                          const initialCreditApplied = Number(item?.initial_credit_applied || 0);
                          const existingCardBalance = Number(item?.existing_card_balance || 0);
                          const detailMessage =
                            item?.reason_message ||
                            (item?.issued_card_id
                              ? initialCreditApplied > 0
                                ? `Cartao ${shortenCardId(item.issued_card_id)} emitido com ${formatCurrency(
                                    initialCreditApplied
                                  )} de saldo inicial.`
                                : `Cartao ${shortenCardId(item.issued_card_id)} emitido.`
                              : item?.existing_card_id
                                ? `Cartao atual ${shortenCardId(item.existing_card_id)} com saldo de ${formatCurrency(
                                    existingCardBalance
                                  )}.`
                                : "Sem observacoes.");

                          return (
                            <tr key={`${currentStage}-${participantId}-${item?.issued_card_id || item?.existing_card_id || "row"}`}>
                              <td className="px-4 py-4 align-top">
                                <p className="font-semibold text-slate-100">{displayName}</p>
                                <p className="mt-1 text-[10px] uppercase tracking-[0.2em] text-slate-500">
                                  REF #{participantId || "n/d"}
                                </p>
                                {Number(item?.event_role_id || participant?.event_role_id || 0) > 0 && (
                                  <p className="mt-2 text-xs text-slate-500">
                                    Estrutura #{Number(item?.event_role_id || participant?.event_role_id || 0)}
                                  </p>
                                )}
                              </td>
                              <td className="px-4 py-4 align-top text-xs uppercase text-slate-400">
                                {formatSector(item?.sector || participant?.sector || managerSector)}
                              </td>
                              <td className="px-4 py-4 align-top">
                                <span className={`inline-flex rounded-full border px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.18em] ${statusMeta.badgeClass}`}>
                                  {statusMeta.label}
                                </span>
                              </td>
                              <td className="px-4 py-4 align-top">
                                <p className="text-sm text-slate-300">{detailMessage}</p>
                                {item?.issued_card_id && (
                                  <p className="mt-1 text-[10px] uppercase tracking-[0.18em] text-emerald-400">
                                    Novo cartao: {shortenCardId(item.issued_card_id)}
                                  </p>
                                )}
                                {initialCreditApplied > 0 && (
                                  <p className="mt-1 text-[10px] uppercase tracking-[0.18em] text-emerald-300">
                                    Saldo inicial aplicado: {formatCurrency(initialCreditApplied)}
                                  </p>
                                )}
                                {item?.existing_card_id && !item?.issued_card_id && (
                                  <p className="mt-1 text-[10px] uppercase tracking-[0.18em] text-sky-400">
                                    Cartao existente: {shortenCardId(item.existing_card_id)}
                                  </p>
                                )}
                                {item?.existing_card_id && !item?.issued_card_id && (
                                  <p className="mt-1 text-[10px] uppercase tracking-[0.18em] text-amber-300">
                                    Saldo atual no cartao: {formatCurrency(existingCardBalance)}
                                  </p>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  )}
                </div>
              </div>
            </>
          )}
        </div>

        <div className="flex items-center justify-between gap-3 border-t border-slate-800/40 px-5 py-4">
          <div className="min-w-0 break-words text-xs text-slate-500">
            {result ? (
              <>Idempotency key: {result.idempotency_key || idempotencyKey}</>
            ) : (
              <>
                {previewInitialBalance > 0
                  ? `Cada novo cartao sera emitido com ${formatCurrency(previewInitialBalance)} de saldo inicial.`
                  : "A emissao cria no maximo um cartao ativo por participante neste evento."}
              </>
            )}
          </div>
          <div className="flex items-center gap-2">
            <button type="button" onClick={onClose} disabled={closeDisabled} className="btn-secondary h-10 px-4 text-sm">
              {result ? "Fechar" : "Cancelar"}
            </button>
            {!result && (
              <button
                type="button"
                onClick={handleIssue}
                disabled={!canIssue || issuing || previewLoading || !initialBalanceValid}
                className="btn-primary h-10 px-4 text-sm disabled:cursor-not-allowed disabled:opacity-50"
              >
                <span className="inline-flex items-center gap-2">
                  {issuing ? <Loader2 size={16} className="animate-spin" /> : <CheckCircle2 size={16} />}
                  {issueButtonLabel}
                </span>
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
