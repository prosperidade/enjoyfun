import { useEffect, useState } from "react";
import { Save, Server, ShieldCheck, Plug, Loader2 } from "lucide-react";
import toast from "react-hot-toast";
import {
  listOrganizerAIProviders,
  updateOrganizerAIProvider,
  testOrganizerAIProvider,
} from "../api/ai";
import {
  getModelsForProvider,
  findModelMeta,
  formatModelOptionLabel,
  SUPPORTED_PROVIDER_KEYS,
  PROVIDER_LABELS,
} from "../lib/aiModelsCatalog";

const PROVIDER_TOOL_USE_DEFAULT = {
  openai: true,
  gemini: true,
  claude: true,
};

function buildEmptyDraft(providerKey) {
  return {
    provider: providerKey,
    label: PROVIDER_LABELS[providerKey] || providerKey,
    supports_tool_use: PROVIDER_TOOL_USE_DEFAULT[providerKey] || false,
    model: "",
    base_url: "",
    is_active: false,
    is_default: false,
    is_configured: false,
    settings: {},
    api_key: "",
  };
}

function ensureAllProviders(drafts) {
  const byKey = new Map(drafts.map((d) => [d.provider, d]));
  return SUPPORTED_PROVIDER_KEYS.map(
    (key) => byKey.get(key) || buildEmptyDraft(key)
  );
}

function normalizeProviderDraft(provider) {
  return {
    provider: provider.provider,
    label: provider.label,
    supports_tool_use: Boolean(provider.supports_tool_use),
    model: provider.model || "",
    base_url: provider.base_url || "",
    is_active: Boolean(provider.is_active),
    is_default: Boolean(provider.is_default),
    is_configured: Boolean(provider.is_configured),
    settings: provider.settings || {},
    api_key: "",
  };
}

export default function AIControlCenter() {
  const [providerDrafts, setProviderDrafts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [savingProviderKey, setSavingProviderKey] = useState("");
  const [testingProviderKey, setTestingProviderKey] = useState("");

  const loadData = async ({ silent = false } = {}) => {
    if (!silent) {
      setLoading(true);
    }

    try {
      const providers = await listOrganizerAIProviders();
      setProviderDrafts(ensureAllProviders(providers.map(normalizeProviderDraft)));
    } catch (error) {
      toast.error(
        error.response?.data?.message || "Erro ao carregar providers de IA."
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

  const handleProviderChange = (providerKey, field, value) => {
    setProviderDrafts((current) =>
      current.map((provider) =>
        provider.provider === providerKey ? { ...provider, [field]: value } : provider
      )
    );
  };

  const handleSaveProvider = async (provider) => {
    setSavingProviderKey(provider.provider);

    try {
      const payload = {
        model: provider.model.trim() || null,
        base_url: provider.base_url.trim() || null,
        is_active: Boolean(provider.is_active),
        is_default: Boolean(provider.is_default),
      };

      if (provider.api_key.trim()) {
        payload.api_key = provider.api_key.trim();
      }

      await updateOrganizerAIProvider(provider.provider, payload);
      await loadData({ silent: true });
      toast.success(`Provider ${provider.label} salvo com sucesso.`);
    } catch (error) {
      toast.error(error.response?.data?.message || "Erro ao salvar provider de IA.");
      await loadData({ silent: true });
    } finally {
      setSavingProviderKey("");
    }
  };

  const handleTestConnection = async (provider) => {
    setTestingProviderKey(provider.provider);
    try {
      const result = await testOrganizerAIProvider(provider.provider);
      if (result?.ok) {
        toast.success(
          `Conexão OK\nLatência: ${result.latency_ms}ms · Modelo: ${result.model_used || "desconhecido"}`,
          { duration: 5000 }
        );
      } else {
        toast.error(
          result?.error
            ? `Falha no teste (${provider.label}): ${result.error}`
            : `Falha ao testar ${provider.label}. Verifique a API key e o modelo.`,
          { duration: 6000 }
        );
      }
    } catch (error) {
      const status = error?.response?.status;
      const message = error?.response?.data?.message;
      if (status === 429) {
        toast(
          message || "Muitas tentativas. Aguarde 1 minuto.",
          { icon: "⏳", duration: 5000 }
        );
      } else if (status === 400) {
        toast.error(message || "Configure a API key e salve antes de testar.");
      } else if (error?.code === "ECONNABORTED") {
        toast.error("Tempo esgotado ao aguardar o provider. Tente novamente.");
      } else {
        toast.error(message || "Erro ao testar conexão com o provider.");
      }
    } finally {
      setTestingProviderKey("");
    }
  };

  if (loading) {
    return <div className="text-gray-500 animate-pulse">Carregando providers de IA...</div>;
  }

  return (
    <div className="space-y-8 fade-in">
      <div className="card space-y-6 p-8">
        <div className="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 className="section-title flex items-center gap-2">
              <Server size={20} className="text-brand" /> Providers de IA
            </h2>
            <p className="text-sm text-gray-400 mt-1">
              Configure as 3 plataformas que preferir. Depois escolha qual cada agente
              vai usar direto na página de Assistentes. O "padrão" só é usado como
              fallback se um agente não tiver provider explícito.
            </p>
          </div>
          <div className="text-xs text-gray-500">
            Campo de API Key em branco preserva o segredo já salvo.
          </div>
        </div>

        <div className="grid xl:grid-cols-3 gap-5">
          {providerDrafts.map((provider) => (
            <ProviderCard
              key={provider.provider}
              provider={provider}
              saving={savingProviderKey === provider.provider}
              testing={testingProviderKey === provider.provider}
              onChange={handleProviderChange}
              onSave={handleSaveProvider}
              onTestConnection={handleTestConnection}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

function ProviderCard({ provider, saving, testing, onChange, onSave, onTestConnection }) {
  const catalogModels = getModelsForProvider(provider.provider);
  const currentModel = provider.model || "";
  const knownMeta = findModelMeta(provider.provider, currentModel);
  const isCustomLegacy = currentModel && !knownMeta;
  const selectedMeta = knownMeta;

  return (
    <div className="card-hover flex flex-col gap-4 border-gray-800">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="font-bold text-white">{provider.label}</h3>
          <p className="text-xs text-gray-400 mt-1">
            {provider.supports_tool_use
              ? "Suporta tool use e configuracao futura do orchestrator."
              : "Provider catalogado sem tool use ativo."}
          </p>
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          <span className={`badge ${provider.is_configured ? "badge-green" : "badge-gray"}`}>
            {provider.is_configured ? "Configurado" : "Sem chave"}
          </span>
          {provider.is_default ? <span className="badge badge-green">Padrao</span> : null}
        </div>
      </div>

      <label className="flex items-center justify-between gap-3 rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-3">
        <div>
          <p className="text-sm text-gray-200">Provider ativo</p>
          <p className="text-xs text-gray-500">
            Controla se este provider pode ser usado pelo organizer.
          </p>
        </div>
        <input
          type="checkbox"
          className="h-4 w-4 accent-green-500"
          checked={Boolean(provider.is_active)}
          onChange={(event) =>
            onChange(provider.provider, "is_active", event.target.checked)
          }
        />
      </label>

      <label className="flex items-center justify-between gap-3 rounded-xl border border-gray-800 bg-gray-900/60 px-3 py-3">
        <div>
          <p className="text-sm text-gray-200">Fallback padrão</p>
          <p className="text-xs text-gray-500">Usado por agentes sem provider explicito.</p>
        </div>
        <input
          type="checkbox"
          className="h-4 w-4 accent-purple-500"
          checked={Boolean(provider.is_default)}
          onChange={(event) =>
            onChange(provider.provider, "is_default", event.target.checked)
          }
        />
      </label>

      <div>
        <label className="input-label flex items-center gap-2">
          <ShieldCheck size={14} className="text-gray-400" /> API Key / segredo
        </label>
        <input
          type="password"
          className="input"
          value={provider.api_key}
          onChange={(event) => onChange(provider.provider, "api_key", event.target.value)}
          placeholder="Digite apenas se quiser atualizar a chave"
          autoComplete="new-password"
        />
      </div>

      <div>
        <label className="input-label">Modelo</label>
        <select
          className="input"
          value={currentModel}
          onChange={(event) => onChange(provider.provider, "model", event.target.value)}
        >
          {!currentModel && (
            <option value="">Selecione um modelo...</option>
          )}
          {isCustomLegacy && (
            <option value={currentModel}>
              Modelo customizado: {currentModel}
            </option>
          )}
          {catalogModels.map((model) => (
            <option key={model.id} value={model.id}>
              {formatModelOptionLabel(model)}
            </option>
          ))}
        </select>
        {selectedMeta && (
          <p className="mt-1 text-xs text-gray-500">
            {selectedMeta.description} · in ${selectedMeta.input_cost_per_1m.toFixed(2)}/1M · out ${selectedMeta.output_cost_per_1m.toFixed(2)}/1M
          </p>
        )}
        {isCustomLegacy && (
          <p className="mt-1 text-xs text-amber-400">
            Valor legado fora do catálogo — troque por um modelo listado quando possível.
          </p>
        )}
      </div>

      <div>
        <label className="input-label">Base URL (opcional)</label>
        <input
          type="text"
          className="input"
          value={provider.base_url}
          onChange={(event) => onChange(provider.provider, "base_url", event.target.value)}
          placeholder="Use apenas se houver endpoint customizado/proxy"
        />
      </div>

      <div className="mt-auto pt-2 flex justify-end gap-2 flex-wrap">
        <button
          type="button"
          disabled={saving || testing}
          className="btn-secondary flex items-center gap-2"
          onClick={() => onTestConnection(provider)}
          title="Testar conexão com o provider"
        >
          {testing ? (
            <Loader2 size={16} className="animate-spin" />
          ) : (
            <Plug size={16} />
          )}
          {testing ? "Testando..." : "Testar conexão"}
        </button>
        <button
          type="button"
          disabled={saving}
          className="btn-primary flex items-center gap-2"
          onClick={() => onSave(provider)}
        >
          <Save size={16} />
          {saving ? "Salvando..." : "Salvar provider"}
        </button>
      </div>
    </div>
  );
}
