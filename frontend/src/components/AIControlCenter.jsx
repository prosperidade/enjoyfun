import { useEffect, useState } from "react";
import { Bot, Key, Save, Server, ShieldCheck } from "lucide-react";
import toast from "react-hot-toast";
import {
  getOrganizerAIConfig,
  listOrganizerAIProviders,
  updateOrganizerAIConfig,
  updateOrganizerAIProvider,
} from "../api/ai";

const INITIAL_CONFIG = {
  provider: "openai",
  system_prompt: "",
  is_active: true,
};

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
  const [config, setConfig] = useState(INITIAL_CONFIG);
  const [providerDrafts, setProviderDrafts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [savingConfig, setSavingConfig] = useState(false);
  const [savingProviderKey, setSavingProviderKey] = useState("");

  const loadData = async ({ silent = false } = {}) => {
    if (!silent) {
      setLoading(true);
    }

    try {
      const [legacyConfig, providers] = await Promise.all([
        getOrganizerAIConfig(),
        listOrganizerAIProviders(),
      ]);

      setConfig((current) => ({ ...current, ...legacyConfig }));
      setProviderDrafts(providers.map(normalizeProviderDraft));
    } catch (error) {
      toast.error(
        error.response?.data?.message || "Erro ao carregar configuracoes de IA."
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

  const handleConfigChange = (event) => {
    const { name, type, value, checked } = event.target;
    setConfig((current) => ({
      ...current,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleProviderChange = (providerKey, field, value) => {
    setProviderDrafts((current) =>
      current.map((provider) =>
        provider.provider === providerKey ? { ...provider, [field]: value } : provider
      )
    );
  };

  const handleSaveConfig = async (event) => {
    event.preventDefault();
    setSavingConfig(true);

    try {
      const updated = await updateOrganizerAIConfig(config);
      setConfig((current) => ({ ...current, ...updated }));
      toast.success("Runtime operacional de IA salvo com sucesso.");
    } catch (error) {
      toast.error(
        error.response?.data?.message || "Erro ao salvar runtime operacional de IA."
      );
      await loadData({ silent: true });
    } finally {
      setSavingConfig(false);
    }
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

  const defaultProvider =
    providerDrafts.find((provider) => provider.is_default)?.label || "nao definido";

  if (loading) {
    return <div className="text-gray-500 animate-pulse">Carregando configuracao de IA...</div>;
  }

  return (
    <div className="space-y-8 fade-in">
      <div className="card max-w-4xl space-y-8 p-8">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 className="section-title flex items-center gap-2">
              <Bot size={20} className="text-brand" /> Runtime operacional legado
            </h2>
            <p className="text-sm text-gray-400 mt-1">
              Esta configuracao continua alimentando o fluxo atual de{" "}
              <code>/ai/insight</code>. O provider escolhido aqui deve existir e estar
              configurado na camada nova de providers logo abaixo.
            </p>
          </div>
          <div className="flex items-center gap-3 bg-gray-900 px-4 py-2 rounded-lg border border-gray-800">
            <span className="text-sm text-gray-300">IA operacional</span>
            <label className="relative inline-flex items-center cursor-pointer">
              <input
                type="checkbox"
                name="is_active"
                checked={Boolean(config.is_active)}
                onChange={handleConfigChange}
                className="sr-only peer"
              />
              <div className="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500" />
            </label>
          </div>
        </div>

        <form onSubmit={handleSaveConfig} className="space-y-6">
          <div>
            <label className="input-label flex items-center gap-2">
              <Key size={14} className="text-gray-400" /> Provider do runtime
            </label>
            <select
              name="provider"
              value={config.provider || "openai"}
              onChange={handleConfigChange}
              className="input"
            >
              {providerDrafts.map((provider) => (
                <option key={provider.provider} value={provider.provider}>
                  {provider.label}
                  {provider.is_default ? " (Padrao)" : ""}
                  {!provider.is_configured ? " - sem chave" : ""}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="input-label">Prompt base do organizer</label>
            <p className="text-xs text-gray-500 mb-2">
              Instrucoes extras para o fluxo operacional legado. Exemplo: "Seja
              objetivo e sempre priorize lucro com seguranca operacional."
            </p>
            <textarea
              name="system_prompt"
              value={config.system_prompt || ""}
              onChange={handleConfigChange}
              rows="5"
              className="input min-h-[120px]"
              placeholder="Insira customizacoes para o runtime operacional atual..."
            />
          </div>

          <div className="pt-4 flex items-center justify-between gap-3 flex-wrap">
            <span className="text-xs text-gray-500">
              Provider padrao atual da camada nova: {defaultProvider}
            </span>
            <button
              type="submit"
              disabled={savingConfig}
              className="btn-primary px-8 py-3 flex items-center gap-2"
            >
              <Save size={18} />
              {savingConfig ? "Salvando..." : "Salvar runtime operacional"}
            </button>
          </div>
        </form>
      </div>

      <div className="card space-y-6 p-8">
        <div className="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 className="section-title flex items-center gap-2">
              <Server size={20} className="text-brand" /> Providers do organizer
            </h2>
            <p className="text-sm text-gray-400 mt-1">
              Credenciais, modelo, base URL e provider padrao da fundacao nova publicada em{" "}
              <code>/api/organizer-ai-providers</code>.
            </p>
          </div>
          <div className="text-xs text-gray-500">
            Campo de API Key em branco preserva o segredo ja salvo.
          </div>
        </div>

        <div className="grid xl:grid-cols-3 gap-5">
          {providerDrafts.map((provider) => (
            <ProviderCard
              key={provider.provider}
              provider={provider}
              saving={savingProviderKey === provider.provider}
              onChange={handleProviderChange}
              onSave={handleSaveProvider}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

function ProviderCard({ provider, saving, onChange, onSave }) {
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
          <p className="text-sm text-gray-200">Provider padrao</p>
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
        <input
          type="text"
          className="input"
          value={provider.model}
          onChange={(event) => onChange(provider.provider, "model", event.target.value)}
          placeholder="Modelo default do provider"
        />
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

      <div className="mt-auto pt-2 flex justify-end">
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
