import { useState, useEffect, useCallback } from 'react';
import { toast } from 'react-hot-toast';
import {
  Sparkles, TrendingUp, Truck, BarChart2, Beer, FileText,
  MessageSquare, Database, PenLine, Image, FileSpreadsheet,
  MicVocal, Plane, ToggleLeft, ToggleRight, MessageCircle,
  Settings2, ChevronDown, Loader2, Bot
} from 'lucide-react';
import { listOrganizerAIAgents, updateOrganizerAIAgent, listOrganizerAIProviders } from '../api/ai';
import AIUsageSummary from '../components/AIUsageSummary';

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

  const openChat = (agentKey) => {
    // Dispatch custom event to open UnifiedAIChat with pre-filled context
    window.dispatchEvent(new CustomEvent('enjoyfun:open-ai-chat', {
      detail: { agent_key: agentKey }
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
          <div>
            <h1 className="text-2xl font-bold text-white">Assistente IA</h1>
            <p className="text-sm text-gray-400">
              Seus assistentes inteligentes para o evento.
              {activeCount > 0 && <span className="text-purple-400 ml-1">{activeCount} ativos</span>}
            </p>
          </div>
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
    </div>
  );
}
