import { useState, useEffect, useCallback } from 'react';
import { toast } from 'react-hot-toast';
import {
  Sparkles, TrendingUp, Truck, BarChart2, Beer, FileText,
  MessageSquare, Database, PenLine, Image, FileSpreadsheet,
  MicVocal, Plane, ToggleLeft, ToggleRight, MessageCircle,
  Settings2, ChevronDown, Loader2, Bot, Cpu, Star, X, Check, Brain
} from 'lucide-react';
import {
  listOrganizerAIAgents,
  updateOrganizerAIAgent,
  listOrganizerAIProviders,
  updateAgentProvider,
  getOrganizerAIDna,
  updateOrganizerAIDna,
} from '../api/ai';
import api from '../lib/api';
import AIUsageSummary from '../components/AIUsageSummary';
import { getRecommendation, isRecommended } from '../lib/aiAgentRecommendations';
import { getModelsForProvider, PROVIDER_LABELS } from '../lib/aiModelsCatalog';

// Icon mapping by agent_key
const AGENT_ICONS = {
  marketing: TrendingUp,
  logistics: Truck,
  management: BarChart2,
  bar: Beer,
  contracting: FileText,
  feedback: MessageSquare,
  data_analyst: Database,
  content: PenLine,
  media: Image,
  documents: FileSpreadsheet,
  artists: MicVocal,
  artists_travel: Plane,
};

// Gradient mapping by agent_key
const AGENT_GRADIENTS = {
  marketing: 'from-pink-600 to-rose-600',
  logistics: 'from-blue-600 to-cyan-600',
  management: 'from-purple-600 to-indigo-600',
  bar: 'from-amber-600 to-orange-600',
  contracting: 'from-green-600 to-teal-600',
  feedback: 'from-violet-600 to-purple-600',
  data_analyst: 'from-cyan-600 to-blue-600',
  content: 'from-fuchsia-600 to-pink-600',
  media: 'from-orange-600 to-red-600',
  documents: 'from-lime-600 to-green-600',
  artists: 'from-emerald-600 to-green-600',
  artists_travel: 'from-sky-600 to-blue-600',
};

// Friendly labels
const FRIENDLY_LABELS = {
  marketing: 'Vendas e Divulgacao',
  logistics: 'Operacoes do Evento',
  management: 'Gestao Executiva',
  bar: 'Bar e Estoque',
  contracting: 'Contratos e Fornecedores',
  feedback: 'Qualidade e Feedback',
  data_analyst: 'Analise de Dados',
  content: 'Textos e Posts',
  media: 'Imagens e Visual',
  documents: 'Documentos e Planilhas',
  artists: 'Artistas',
  artists_travel: 'Viagens de Artistas',
};

// Example prompts
const EXAMPLE_PROMPTS = {
  marketing: 'Como estao as vendas de ingresso? Devo abrir um novo lote?',
  logistics: 'Existe algum gargalo operacional no evento agora?',
  management: 'Qual o resumo executivo do evento hoje?',
  bar: 'Quais produtos estao acabando no bar?',
  contracting: 'Tem pagamento pendente para algum fornecedor?',
  feedback: 'Quais os problemas mais recorrentes do publico?',
  data_analyst: 'Compare este evento com o anterior em faturamento.',
  content: 'Crie um post para o Instagram anunciando o line-up.',
  media: 'Gere um briefing visual para o banner do evento.',
  documents: 'Leia a planilha de custos e organize por categoria.',
  artists: 'Qual artista tem logistica pendente?',
  artists_travel: 'Organize hotel e transfer para todos os artistas.',
};

// Approval mode translations
const APPROVAL_LABELS = {
  confirm_write: 'Pedir permissao para acoes',
  manual_confirm: 'Pedir permissao para tudo',
  auto_read_only: 'Apenas consultas (sem acoes)',
};

const APPROVAL_DESCRIPTIONS = {
  confirm_write: 'A IA pode consultar dados livremente, mas pede sua aprovacao antes de fazer qualquer alteracao.',
  manual_confirm: 'A IA pede sua aprovacao para tudo, inclusive consultas.',
  auto_read_only: 'A IA so pode consultar dados. Nao pode fazer nenhuma alteracao.',
};

export default function AIAssistants() {
  const [agents, setAgents] = useState([]);
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState({});
  const [pickerAgent, setPickerAgent] = useState(null);
  const [pickerSelection, setPickerSelection] = useState({ provider: null, model: null });

  const emptyDna = {
    business_description: '',
    tone_of_voice: '',
    business_rules: '',
    target_audience: '',
    forbidden_topics: '',
  };
  const [dnaModalOpen, setDnaModalOpen] = useState(false);
  const [dnaLoading, setDnaLoading] = useState(false);
  const [dnaSaving, setDnaSaving] = useState(false);
  const [dnaTab, setDnaTab] = useState('organizer');
  const [dnaForm, setDnaForm] = useState(emptyDna);
  const [dnaBaseline, setDnaBaseline] = useState(emptyDna);
  const [dnaEvents, setDnaEvents] = useState([]);
  const [dnaEventsLoading, setDnaEventsLoading] = useState(false);
  const [dnaSelectedEventId, setDnaSelectedEventId] = useState('');
  const [dnaEventForm, setDnaEventForm] = useState(emptyDna);
  const [dnaEventBaseline, setDnaEventBaseline] = useState(emptyDna);
  const [dnaEventLoading, setDnaEventLoading] = useState(false);
  const [dnaPendingSwitch, setDnaPendingSwitch] = useState(null);

  const formsEqual = (a, b) => Object.keys(emptyDna).every(k => (a[k] || '') === (b[k] || ''));
  const isOrganizerDirty = !formsEqual(dnaForm, dnaBaseline);
  const isEventDirty = dnaSelectedEventId !== '' && !formsEqual(dnaEventForm, dnaEventBaseline);

  const loadOrganizerDnaForm = async () => {
    try {
      const data = await getOrganizerAIDna();
      const next = {
        business_description: data?.business_description || '',
        tone_of_voice: data?.tone_of_voice || '',
        business_rules: data?.business_rules || '',
        target_audience: data?.target_audience || '',
        forbidden_topics: data?.forbidden_topics || '',
      };
      setDnaForm(next);
      setDnaBaseline(next);
    } catch {
      toast.error('Erro ao carregar DNA do negocio');
    }
  };

  const loadEventsListForDna = async () => {
    setDnaEventsLoading(true);
    try {
      const r = await api.get('/events', { params: { per_page: 200 } });
      const rows = r?.data?.data || r?.data || [];
      const list = Array.isArray(rows) ? rows : (rows.items || []);
      const now = Date.now();
      const withBucket = list.map((ev) => {
        const startTs = ev.start_at ? new Date(ev.start_at).getTime() : null;
        const endTs = ev.end_at ? new Date(ev.end_at).getTime() : null;
        let bucket = 2;
        if (startTs && endTs && startTs <= now && now <= endTs) bucket = 0;
        else if (startTs && startTs > now) bucket = 1;
        return { ...ev, _bucket: bucket, _startTs: startTs || 0 };
      });
      withBucket.sort((a, b) => a._bucket - b._bucket || a._startTs - b._startTs);
      setDnaEvents(withBucket);
    } catch {
      toast.error('Erro ao carregar lista de eventos');
      setDnaEvents([]);
    } finally {
      setDnaEventsLoading(false);
    }
  };

  const loadEventDnaForm = async (eventId) => {
    if (!eventId) {
      setDnaEventForm(emptyDna);
      setDnaEventBaseline(emptyDna);
      return;
    }
    setDnaEventLoading(true);
    try {
      const r = await api.get(`/events/${eventId}/ai-dna`);
      const data = r?.data?.data || {};
      const next = {
        business_description: data?.business_description || '',
        tone_of_voice: data?.tone_of_voice || '',
        business_rules: data?.business_rules || '',
        target_audience: data?.target_audience || '',
        forbidden_topics: data?.forbidden_topics || '',
      };
      setDnaEventForm(next);
      setDnaEventBaseline(next);
    } catch {
      toast.error('Erro ao carregar DNA do evento');
      setDnaEventForm(emptyDna);
      setDnaEventBaseline(emptyDna);
    } finally {
      setDnaEventLoading(false);
    }
  };

  const openDnaModal = async () => {
    setDnaModalOpen(true);
    setDnaTab('organizer');
    setDnaSelectedEventId('');
    setDnaEventForm(emptyDna);
    setDnaEventBaseline(emptyDna);
    setDnaLoading(true);
    try {
      await Promise.all([loadOrganizerDnaForm(), loadEventsListForDna()]);
    } finally {
      setDnaLoading(false);
    }
  };

  const closeDnaModal = () => {
    if (dnaSaving) return;
    if (isOrganizerDirty || isEventDirty) {
      setDnaPendingSwitch({ type: 'close' });
      return;
    }
    setDnaModalOpen(false);
  };

  const handleDnaFieldChange = (field, value) => {
    setDnaForm(prev => ({ ...prev, [field]: value }));
  };

  const handleEventDnaFieldChange = (field, value) => {
    setDnaEventForm(prev => ({ ...prev, [field]: value }));
  };

  const saveOrganizerDna = async () => {
    await updateOrganizerAIDna(dnaForm);
    setDnaBaseline(dnaForm);
  };

  const saveEventDna = async () => {
    if (!dnaSelectedEventId) return;
    const payload = {};
    Object.keys(emptyDna).forEach((k) => {
      payload[k] = dnaEventForm[k]?.trim() ? dnaEventForm[k].trim() : null;
    });
    await api.put(`/events/${dnaSelectedEventId}/ai-dna`, payload);
    setDnaEventBaseline(dnaEventForm);
  };

  const handleDnaSave = async () => {
    setDnaSaving(true);
    try {
      if (dnaTab === 'organizer') {
        await saveOrganizerDna();
        toast.success('DNA do organizador salvo');
      } else {
        if (!dnaSelectedEventId) {
          toast.error('Selecione um evento antes de salvar');
          return;
        }
        await saveEventDna();
        toast.success('DNA do evento salvo');
      }
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erro ao salvar DNA');
    } finally {
      setDnaSaving(false);
    }
  };

  const attemptSwitch = (target) => {
    const currentDirty = dnaTab === 'organizer' ? isOrganizerDirty : isEventDirty;
    if (currentDirty) {
      setDnaPendingSwitch(target);
      return;
    }
    applySwitch(target);
  };

  const applySwitch = (target) => {
    if (!target) return;
    if (target.type === 'close') {
      setDnaModalOpen(false);
      return;
    }
    if (target.type === 'tab') {
      setDnaTab(target.value);
      return;
    }
    if (target.type === 'event') {
      setDnaSelectedEventId(target.value);
      loadEventDnaForm(target.value);
      return;
    }
  };

  const handleConfirmSwitchSave = async () => {
    setDnaSaving(true);
    try {
      if (dnaTab === 'organizer') {
        await saveOrganizerDna();
        toast.success('DNA do organizador salvo');
      } else if (dnaSelectedEventId) {
        await saveEventDna();
        toast.success('DNA do evento salvo');
      }
      const target = dnaPendingSwitch;
      setDnaPendingSwitch(null);
      applySwitch(target);
    } catch (err) {
      toast.error(err?.response?.data?.message || 'Erro ao salvar DNA');
    } finally {
      setDnaSaving(false);
    }
  };

  const handleCancelSwitch = () => setDnaPendingSwitch(null);

  const loadData = useCallback(async () => {
    try {
      const [agentsList, providersList] = await Promise.all([
        listOrganizerAIAgents(),
        listOrganizerAIProviders(),
      ]);
      setAgents(agentsList);
      setProviders(providersList);
    } catch (err) {
      toast.error('Erro ao carregar assistentes');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadData(); }, [loadData]);

  const handleToggle = async (agentKey, currentEnabled) => {
    setSaving(prev => ({ ...prev, [agentKey]: true }));
    try {
      await updateOrganizerAIAgent(agentKey, { is_enabled: !currentEnabled });
      setAgents(prev => prev.map(a =>
        a.agent_key === agentKey ? { ...a, is_enabled: !currentEnabled } : a
      ));
      toast.success(!currentEnabled ? 'Assistente ligado' : 'Assistente desligado');
    } catch {
      toast.error('Erro ao atualizar');
    } finally {
      setSaving(prev => ({ ...prev, [agentKey]: false }));
    }
  };

  const handleApprovalChange = async (agentKey, newMode) => {
    setSaving(prev => ({ ...prev, [agentKey]: true }));
    try {
      await updateOrganizerAIAgent(agentKey, { approval_mode: newMode });
      setAgents(prev => prev.map(a =>
        a.agent_key === agentKey ? { ...a, approval_mode: newMode } : a
      ));
      toast.success('Permissoes atualizadas');
    } catch {
      toast.error('Erro ao atualizar permissoes');
    } finally {
      setSaving(prev => ({ ...prev, [agentKey]: false }));
    }
  };

  const getAgentModel = (agent) => {
    const cfg = agent.config_json || agent.config || {};
    return cfg.model || null;
  };

  const openPicker = (agent) => {
    setPickerAgent(agent);
    setPickerSelection({
      provider: agent.provider || null,
      model: getAgentModel(agent),
    });
  };

  const closePicker = () => {
    setPickerAgent(null);
    setPickerSelection({ provider: null, model: null });
  };

  const handleSavePicker = async () => {
    if (!pickerAgent) return;
    const key = pickerAgent.agent_key;
    setSaving(prev => ({ ...prev, [key]: true }));
    try {
      await updateAgentProvider(key, {
        provider: pickerSelection.provider,
        model: pickerSelection.model,
      });
      setAgents(prev => prev.map(a => {
        if (a.agent_key !== key) return a;
        const nextCfg = { ...(a.config_json || a.config || {}) };
        if (pickerSelection.model) {
          nextCfg.model = pickerSelection.model;
        } else {
          delete nextCfg.model;
        }
        return {
          ...a,
          provider: pickerSelection.provider,
          config_json: a.config_json ? nextCfg : a.config_json,
          config: a.config ? nextCfg : a.config,
        };
      }));
      toast.success('Motor do assistente atualizado');
      closePicker();
    } catch {
      toast.error('Erro ao salvar motor do assistente');
    } finally {
      setSaving(prev => ({ ...prev, [key]: false }));
    }
  };

  const openChat = (agentKey) => {
    // Map agent_key to its primary surface so the backend routes correctly
    const AGENT_SURFACE_MAP = {
      marketing: 'tickets', logistics: 'parking', management: 'dashboard',
      bar: 'bar', contracting: 'artists', feedback: 'dashboard',
      data_analyst: 'dashboard', content: 'dashboard', media: 'dashboard',
      documents: 'documents', artists: 'artists', artists_travel: 'artists',
      platform_guide: 'platform_guide',
    };
    window.dispatchEvent(new CustomEvent('enjoyfun:open-ai-chat', {
      detail: { agent_key: agentKey, surface: AGENT_SURFACE_MAP[agentKey] || 'dashboard' }
    }));
  };

  const activeCount = agents.filter(a => a.is_enabled).length;
  const defaultProvider = providers.find(p => p.is_default);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 size={28} className="animate-spin text-purple-400" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <div className="flex items-center gap-3 mb-1">
          <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-lg shadow-purple-900/30">
            <Sparkles size={20} className="text-white" />
          </div>
          <div className="flex-1">
            <h1 className="text-2xl font-bold text-white">Assistente IA</h1>
            <p className="text-sm text-gray-400">
              Seus assistentes inteligentes para o evento.
              {activeCount > 0 && <span className="text-purple-400 ml-1">{activeCount} ativos</span>}
            </p>
          </div>
          <button
            onClick={openDnaModal}
            className="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-purple-600/20 to-pink-600/20 hover:from-purple-600/30 hover:to-pink-600/30 border border-purple-700/40 hover:border-purple-600 text-purple-200 text-sm font-medium rounded-lg transition-colors"
            title="Definir o DNA do negocio que guia todos os assistentes"
          >
            <Brain size={16} />
            Definir DNA do Negocio
          </button>
        </div>
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-gray-800/40 border border-gray-700/40 rounded-xl p-4">
          <div className="text-xs text-gray-400 mb-1">Assistentes ativos</div>
          <div className="text-2xl font-bold text-white">{activeCount} <span className="text-sm text-gray-500 font-normal">de {agents.length}</span></div>
        </div>
        <div className="bg-gray-800/40 border border-gray-700/40 rounded-xl p-4">
          <div className="text-xs text-gray-400 mb-1">Motor de IA</div>
          <div className="text-lg font-bold text-white">
            {defaultProvider
              ? <span className="capitalize">{defaultProvider.provider}</span>
              : <span className="text-amber-400 text-sm">Nenhum configurado</span>
            }
          </div>
        </div>
        <AIUsageSummary />
      </div>

      {/* Provider picker modal */}
      {pickerAgent && (() => {
        const activeProviders = providers.filter(p => p.is_configured && p.is_active);
        const rec = getRecommendation(pickerAgent.agent_key);
        const isSavingPicker = Boolean(saving[pickerAgent.agent_key]);
        const selectionMatches = (provider, modelId) =>
          pickerSelection.provider === provider && pickerSelection.model === modelId;

        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
            <div className="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-lg max-h-[85vh] overflow-hidden flex flex-col">
              <div className="flex items-center justify-between px-5 py-4 border-b border-gray-800">
                <div>
                  <h3 className="text-white font-semibold text-sm">Motor de IA do assistente</h3>
                  <p className="text-xs text-gray-400 mt-0.5">
                    {pickerAgent.label_friendly || pickerAgent.label}
                  </p>
                </div>
                <button onClick={closePicker} className="text-gray-500 hover:text-gray-300">
                  <X size={18} />
                </button>
              </div>

              <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                {activeProviders.length === 0 ? (
                  <div className="text-center py-6">
                    <p className="text-sm text-amber-400">Nenhum provider configurado</p>
                    <p className="text-xs text-gray-500 mt-1">
                      Configure ao menos um provider em Configuracoes de IA antes de escolher o motor por assistente.
                    </p>
                  </div>
                ) : (
                  <>
                    <button
                      type="button"
                      onClick={() => setPickerSelection({ provider: null, model: null })}
                      className={`w-full text-left rounded-lg border px-3 py-2.5 transition-colors ${
                        pickerSelection.provider === null
                          ? 'border-purple-600 bg-purple-600/10'
                          : 'border-gray-700 hover:border-gray-600 bg-gray-800/40'
                      }`}
                    >
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-white">Usar padrao do organizador</span>
                        {pickerSelection.provider === null && <Check size={14} className="text-purple-400" />}
                      </div>
                      <p className="text-[11px] text-gray-500 mt-0.5">
                        Segue o provider default configurado no organizador.
                      </p>
                    </button>

                    {activeProviders.map((prov) => {
                      const providerKey = prov.provider;
                      const models = getModelsForProvider(providerKey);
                      return (
                        <div key={providerKey} className="space-y-2">
                          <div className="text-[11px] text-gray-400 uppercase tracking-wider font-semibold">
                            {PROVIDER_LABELS[providerKey] || providerKey}
                          </div>
                          {models.length === 0 && (
                            <p className="text-[11px] text-gray-500">Nenhum modelo catalogado.</p>
                          )}
                          {models.map((m) => {
                            const selected = selectionMatches(providerKey, m.id);
                            const recommended = isRecommended(pickerAgent.agent_key, providerKey, m.id);
                            return (
                              <button
                                key={m.id}
                                type="button"
                                onClick={() => setPickerSelection({ provider: providerKey, model: m.id })}
                                className={`w-full text-left rounded-lg border px-3 py-2 transition-colors ${
                                  selected
                                    ? 'border-purple-600 bg-purple-600/10'
                                    : 'border-gray-800 hover:border-gray-600 bg-gray-800/30'
                                }`}
                              >
                                <div className="flex items-center justify-between gap-2">
                                  <div className="flex items-center gap-2 min-w-0">
                                    <span className="text-xs font-medium text-white truncate">{m.label}</span>
                                    {recommended && (
                                      <span className="flex items-center gap-1 text-[10px] text-amber-400 bg-amber-400/10 px-1.5 py-0.5 rounded">
                                        <Star size={9} /> Recomendado
                                      </span>
                                    )}
                                  </div>
                                  {selected && <Check size={14} className="text-purple-400 flex-shrink-0" />}
                                </div>
                                <p className="text-[11px] text-gray-400 mt-0.5">{m.description}</p>
                                <p className="text-[10px] text-gray-500 mt-0.5">
                                  ${m.input_cost_per_1m.toFixed(2)}/1M in · ${m.output_cost_per_1m.toFixed(2)}/1M out
                                </p>
                                {recommended && rec && (
                                  <p className="text-[10px] text-amber-400/80 mt-1">{rec.reason}</p>
                                )}
                              </button>
                            );
                          })}
                        </div>
                      );
                    })}
                  </>
                )}
              </div>

              <div className="flex items-center justify-end gap-2 px-5 py-3 border-t border-gray-800 bg-gray-900/50">
                <button
                  onClick={closePicker}
                  className="px-3 py-1.5 text-xs text-gray-300 hover:text-white"
                >
                  Cancelar
                </button>
                <button
                  onClick={handleSavePicker}
                  disabled={isSavingPicker || activeProviders.length === 0}
                  className="px-4 py-1.5 text-xs font-medium bg-purple-600 hover:bg-purple-500 disabled:bg-gray-700 disabled:text-gray-500 text-white rounded-lg transition-colors flex items-center gap-2"
                >
                  {isSavingPicker && <Loader2 size={12} className="animate-spin" />}
                  Salvar escolha
                </button>
              </div>
            </div>
          </div>
        );
      })()}

      {/* Agent cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {agents.map((agent) => {
          const key = agent.agent_key;
          const Icon = AGENT_ICONS[key] || Bot;
          const gradient = AGENT_GRADIENTS[key] || 'from-gray-600 to-gray-700';
          const friendlyLabel = agent.label_friendly || FRIENDLY_LABELS[key] || agent.label;
          const example = EXAMPLE_PROMPTS[key] || '';
          const isSaving = saving[key];

          return (
            <div
              key={key}
              className={`bg-gray-800/40 border rounded-xl overflow-hidden transition-all ${
                agent.is_enabled
                  ? 'border-gray-700/40 hover:border-purple-700/40'
                  : 'border-gray-800/40 opacity-60'
              }`}
            >
              {/* Card header */}
              <div className="p-4 pb-3">
                <div className="flex items-start justify-between mb-3">
                  <div className="flex items-center gap-3">
                    <div className={`w-9 h-9 rounded-lg bg-gradient-to-br ${gradient} flex items-center justify-center shadow-md`}>
                      <Icon size={18} className="text-white" />
                    </div>
                    <div>
                      <h3 className="text-sm font-semibold text-white leading-tight">{friendlyLabel}</h3>
                      <p className="text-[11px] text-gray-400 leading-tight mt-0.5">{agent.description || ''}</p>
                    </div>
                  </div>

                  {/* Toggle */}
                  <button
                    onClick={() => handleToggle(key, agent.is_enabled)}
                    disabled={isSaving}
                    className="flex-shrink-0 ml-2"
                    title={agent.is_enabled ? 'Desligar' : 'Ligar'}
                  >
                    {agent.is_enabled ? (
                      <ToggleRight size={28} className="text-purple-400 hover:text-purple-300 transition-colors" />
                    ) : (
                      <ToggleLeft size={28} className="text-gray-600 hover:text-gray-400 transition-colors" />
                    )}
                  </button>
                </div>

                {/* Permissions dropdown */}
                {agent.is_enabled && (
                  <div className="mb-3">
                    <label className="text-[10px] text-gray-500 uppercase tracking-wider font-medium mb-1 block">
                      Permissoes
                    </label>
                    <div className="relative">
                      <select
                        value={agent.approval_mode || 'confirm_write'}
                        onChange={(e) => handleApprovalChange(key, e.target.value)}
                        disabled={isSaving}
                        className="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-200 appearance-none cursor-pointer focus:border-purple-600 focus:ring-0 outline-none pr-8"
                      >
                        {Object.entries(APPROVAL_LABELS).map(([mode, label]) => (
                          <option key={mode} value={mode}>{label}</option>
                        ))}
                      </select>
                      <ChevronDown size={14} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none" />
                    </div>
                    <p className="text-[10px] text-gray-500 mt-1">
                      {APPROVAL_DESCRIPTIONS[agent.approval_mode || 'confirm_write']}
                    </p>
                  </div>
                )}

                {/* Provider / Model picker */}
                {agent.is_enabled && (() => {
                  const currentModel = getAgentModel(agent);
                  const currentProvider = agent.provider;
                  const hasOverride = Boolean(currentProvider);
                  const rec = getRecommendation(key);
                  return (
                    <div className="mb-3">
                      <label className="text-[10px] text-gray-500 uppercase tracking-wider font-medium mb-1 block">
                        Motor de IA
                      </label>
                      <button
                        type="button"
                        onClick={() => openPicker(agent)}
                        disabled={isSaving}
                        className="w-full flex items-center justify-between gap-2 bg-gray-800 border border-gray-700 hover:border-purple-600 rounded-lg px-3 py-1.5 text-xs text-gray-200 text-left transition-colors"
                      >
                        <span className="flex items-center gap-2 min-w-0">
                          <Cpu size={12} className="text-purple-400 flex-shrink-0" />
                          {hasOverride ? (
                            <span className="truncate">
                              <span className="capitalize">{PROVIDER_LABELS[currentProvider] || currentProvider}</span>
                              {currentModel && <span className="text-gray-400"> · {currentModel}</span>}
                            </span>
                          ) : (
                            <span className="text-gray-400 truncate">Padrao do organizador</span>
                          )}
                        </span>
                        <Settings2 size={12} className="text-gray-500 flex-shrink-0" />
                      </button>
                      {rec && !hasOverride && (
                        <p className="text-[10px] text-amber-400/80 mt-1 flex items-center gap-1">
                          <Star size={10} /> Recomendado: {PROVIDER_LABELS[rec.provider]} · {rec.model}
                        </p>
                      )}
                    </div>
                  );
                })()}

                {/* Example prompt */}
                {agent.is_enabled && example && (
                  <div className="bg-gray-800/50 rounded-lg px-3 py-2 mb-3">
                    <div className="text-[10px] text-gray-500 mb-0.5">Exemplo de pergunta</div>
                    <p className="text-xs text-gray-300 italic">"{example}"</p>
                  </div>
                )}
              </div>

              {/* Card footer */}
              {agent.is_enabled && (
                <div className="px-4 py-2.5 bg-gray-800/30 border-t border-gray-700/30">
                  <button
                    onClick={() => openChat(key)}
                    className="w-full flex items-center justify-center gap-2 px-3 py-2 bg-purple-600/20 hover:bg-purple-600/30 text-purple-300 hover:text-purple-200 text-xs font-medium rounded-lg transition-colors"
                  >
                    <MessageCircle size={14} />
                    Conversar
                  </button>
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* DNA modal */}
      {dnaModalOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4"
          onClick={closeDnaModal}
        >
          <div
            className="bg-gray-900 border border-purple-700/40 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
            <div className="flex items-start justify-between p-5 border-b border-gray-800">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-lg">
                  <Brain size={20} className="text-white" />
                </div>
                <div>
                  <h2 className="text-lg font-bold text-white">DNA do Negocio</h2>
                  <p className="text-xs text-gray-400">Estas informacoes guiam todos os assistentes de IA.</p>
                </div>
              </div>
              <button
                onClick={closeDnaModal}
                disabled={dnaSaving}
                className="text-gray-500 hover:text-gray-300 transition-colors"
              >
                <X size={20} />
              </button>
            </div>

            {/* Tabs */}
            <div className="flex border-b border-gray-800 px-5">
              <button
                type="button"
                onClick={() => attemptSwitch({ type: 'tab', value: 'organizer' })}
                disabled={dnaSaving}
                className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors ${
                  dnaTab === 'organizer'
                    ? 'border-purple-500 text-white'
                    : 'border-transparent text-gray-400 hover:text-gray-200'
                }`}
              >
                Organizador
                {isOrganizerDirty && dnaTab === 'organizer' && (
                  <span className="ml-1 text-amber-400">•</span>
                )}
              </button>
              <button
                type="button"
                onClick={() => attemptSwitch({ type: 'tab', value: 'event' })}
                disabled={dnaSaving}
                className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors ${
                  dnaTab === 'event'
                    ? 'border-purple-500 text-white'
                    : 'border-transparent text-gray-400 hover:text-gray-200'
                }`}
              >
                Evento específico
                {isEventDirty && dnaTab === 'event' && (
                  <span className="ml-1 text-amber-400">•</span>
                )}
              </button>
            </div>

            {/* Body */}
            <div className="p-5 overflow-y-auto space-y-4">
              {dnaLoading ? (
                <div className="flex items-center justify-center h-32">
                  <Loader2 size={24} className="animate-spin text-purple-400" />
                </div>
              ) : dnaTab === 'organizer' ? (
                <DnaFieldset
                  form={dnaForm}
                  onChange={handleDnaFieldChange}
                  showContext={true}
                />
              ) : (
                <>
                  <div>
                    <label className="block text-xs font-semibold text-gray-300 mb-1.5">
                      Selecione o evento
                    </label>
                    <p className="text-[11px] text-gray-500 mb-2">
                      Campos vazios herdam do DNA do organizador. Apenas o preenchido sobrescreve.
                    </p>
                    <select
                      value={dnaSelectedEventId}
                      onChange={(e) => attemptSwitch({ type: 'event', value: e.target.value })}
                      disabled={dnaSaving || dnaEventsLoading}
                      className="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-100 focus:border-purple-600 focus:ring-0 outline-none disabled:opacity-50"
                    >
                      <option value="">{dnaEventsLoading ? 'Carregando eventos...' : '— escolha um evento —'}</option>
                      {dnaEvents.map((ev) => {
                        const bucketLabel = ev._bucket === 0 ? '🟢 ' : ev._bucket === 1 ? '🔜 ' : '📅 ';
                        return (
                          <option key={ev.id} value={ev.id}>
                            {bucketLabel}{ev.name || `Evento ${ev.id}`}
                          </option>
                        );
                      })}
                    </select>
                  </div>

                  {dnaSelectedEventId ? (
                    dnaEventLoading ? (
                      <div className="flex items-center justify-center h-32">
                        <Loader2 size={24} className="animate-spin text-purple-400" />
                      </div>
                    ) : (
                      <DnaFieldset
                        form={dnaEventForm}
                        onChange={handleEventDnaFieldChange}
                        showContext={false}
                      />
                    )
                  ) : (
                    <div className="text-center py-8 text-sm text-gray-500">
                      Selecione um evento acima para editar o DNA específico dele.
                    </div>
                  )}
                </>
              )}
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-2 p-5 border-t border-gray-800">
              <button
                onClick={closeDnaModal}
                disabled={dnaSaving}
                className="px-4 py-2 text-sm text-gray-300 hover:text-white transition-colors disabled:opacity-50"
              >
                Fechar
              </button>
              <button
                onClick={handleDnaSave}
                disabled={
                  dnaSaving ||
                  dnaLoading ||
                  (dnaTab === 'organizer' ? !isOrganizerDirty : !isEventDirty || !dnaSelectedEventId)
                }
                className="flex items-center gap-2 px-5 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-500 hover:to-pink-500 text-white text-sm font-semibold rounded-lg transition-colors disabled:opacity-50"
              >
                {dnaSaving && <Loader2 size={14} className="animate-spin" />}
                {dnaTab === 'organizer' ? 'Salvar DNA do organizador' : 'Salvar DNA do evento'}
              </button>
            </div>
          </div>

          {/* Inline confirm dialog — unsaved changes */}
          {dnaPendingSwitch && (
            <div
              className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="bg-gray-900 border border-amber-700/50 rounded-2xl shadow-2xl w-full max-w-md p-6">
                <h3 className="text-base font-bold text-white mb-2">Alterações não salvas</h3>
                <p className="text-sm text-gray-300 mb-5">
                  Você tem alterações não salvas nesta aba. Deseja salvar antes de continuar?
                </p>
                <div className="flex justify-end gap-2">
                  <button
                    onClick={handleCancelSwitch}
                    disabled={dnaSaving}
                    className="px-4 py-2 text-sm text-gray-300 hover:text-white transition-colors disabled:opacity-50"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={handleConfirmSwitchSave}
                    disabled={dnaSaving}
                    className="flex items-center gap-2 px-5 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-500 hover:to-pink-500 text-white text-sm font-semibold rounded-lg transition-colors disabled:opacity-50"
                  >
                    {dnaSaving && <Loader2 size={14} className="animate-spin" />}
                    Salvar e continuar
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function DnaFieldset({ form, onChange, showContext }) {
  const fields = [
    {
      key: 'business_description',
      label: 'Descricao do negocio',
      hint: showContext ? 'O que voce faz? Que tipo de eventos organiza?' : 'Sobrescreve só para este evento. Ex: Festa sertaneja country, foco em familia.',
      rows: 3,
      placeholder: showContext
        ? 'Ex: Organizamos festivais de musica eletronica em Sao Paulo, focados em publico jovem 18-35.'
        : 'Vazio: herda do organizador. Ex: Festa sertaneja country, publico familiar do interior.',
    },
    {
      key: 'tone_of_voice',
      label: 'Tom de voz',
      hint: showContext ? 'Como a IA deve se comunicar?' : 'Tom específico deste evento. Vazio herda do organizador.',
      rows: 2,
      placeholder: showContext
        ? 'Ex: Informal, energetico, direto, sem jargao corporativo.'
        : 'Ex: Caloroso, regional, com girias do interior.',
    },
    {
      key: 'business_rules',
      label: 'Regras do negocio',
      hint: showContext ? 'Politicas, limites e praticas obrigatorias.' : 'Regras só deste evento. Vazio herda do organizador.',
      rows: 3,
      placeholder: showContext
        ? 'Ex: Nunca oferecer reembolso sem aprovacao. Ingresso VIP tem limite de 200 por evento.'
        : 'Ex: Bar fecha 30min antes do show, copo descartavel obrigatorio.',
    },
    {
      key: 'target_audience',
      label: 'Publico-alvo',
      hint: showContext ? 'Quem sao seus clientes?' : 'Publico deste evento. Vazio herda do organizador.',
      rows: 2,
      placeholder: showContext
        ? 'Ex: Jovens 18-35 anos, classe B/C, apaixonados por musica eletronica.'
        : 'Ex: Casais 25-45 anos, classe B/C, regiao centro-oeste.',
    },
    {
      key: 'forbidden_topics',
      label: 'Topicos proibidos',
      hint: showContext ? 'Assuntos que a IA nunca deve abordar ou recomendar.' : 'Topicos proibidos só deste evento. Vazio herda.',
      rows: 2,
      placeholder: showContext
        ? 'Ex: Nao falar sobre concorrentes. Nao discutir politica ou religiao.'
        : 'Ex: Politica, religiao, comparacoes com concorrentes.',
    },
  ];

  return (
    <>
      {fields.map((f) => (
        <div key={f.key}>
          <label className="block text-xs font-semibold text-gray-300 mb-1.5">{f.label}</label>
          <p className="text-[11px] text-gray-500 mb-2">{f.hint}</p>
          <textarea
            value={form[f.key]}
            onChange={(e) => onChange(f.key, e.target.value)}
            rows={f.rows}
            maxLength={4000}
            placeholder={f.placeholder}
            className="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-100 focus:border-purple-600 focus:ring-0 outline-none resize-y"
          />
        </div>
      ))}
    </>
  );
}
