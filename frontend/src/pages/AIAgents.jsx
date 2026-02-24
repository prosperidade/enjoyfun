import { Bot, TrendingUp, Truck, Settings, BarChart2, FileText, MessageSquare, Zap } from 'lucide-react';

const agents = [
  {
    id: 'marketing',
    icon: TrendingUp,
    title: 'Agente de Marketing',
    color: 'from-pink-700 to-rose-700',
    border: 'border-pink-800/40',
    emoji: '📢',
    description: 'Segmentação avançada de público, otimização de campanhas, geração de conteúdo e previsão de vendas.',
    capabilities: ['Segmentação de público', 'Otimização de campanhas', 'Geração de conteúdo', 'Análise de sentimento', 'Previsão de vendas'],
    status: 'Configurar',
    prompt_example: 'Analise as vendas de ingressos desta semana e sugira uma estratégia de comunicação para aumentar as recargas de crédito no evento.',
  },
  {
    id: 'logistics',
    icon: Truck,
    title: 'Agente de Logística',
    color: 'from-blue-700 to-cyan-700',
    border: 'border-blue-800/40',
    emoji: '🚛',
    description: 'Otimização de layout, previsão de demanda, gerenciamento de filas e coordenação de fornecedores.',
    capabilities: ['Otimização de layout', 'Previsão de demanda', 'Gestão de filas', 'Planejamento de contingência', 'Coordenação de fornecedores'],
    status: 'Configurar',
    prompt_example: 'Com base nos dados de vendas do bar nas últimas 2 horas, preveja a demanda para as próximas 3 horas e sugira ajustes de estoque.',
  },
  {
    id: 'management',
    icon: BarChart2,
    title: 'Agente de Gestão',
    color: 'from-purple-700 to-indigo-700',
    border: 'border-purple-800/40',
    emoji: '📊',
    description: 'Assistente executivo com dashboards de KPIs em tempo real, previsão financeira e gestão de riscos.',
    capabilities: ['KPIs em tempo real', 'Previsão financeira', 'Assistente de decisão', 'Automação de relatórios', 'Gestão de riscos'],
    status: 'Ativo',
    prompt_example: 'Gere um relatório executivo do evento atual com as principais métricas financeiras e recomendações de ação.',
  },
  {
    id: 'bar',
    icon: Settings,
    title: 'Agente de Bar & Estoque',
    color: 'from-amber-700 to-orange-700',
    border: 'border-amber-800/40',
    emoji: '🍺',
    description: 'Projeção de demanda, otimização de inventário, preços dinâmicos e monitoramento de qualidade.',
    capabilities: ['Previsão de demanda', 'Otimização de inventário', 'Preços dinâmicos', 'Monitoramento de desempenho', 'Controle de qualidade'],
    status: 'Configurar',
    prompt_example: 'Analise quais produtos estão com baixo estoque e calcule a projeção de consumo até o fim do evento.',
  },
  {
    id: 'contracting',
    icon: FileText,
    title: 'Agente de Contratação',
    color: 'from-green-700 to-teal-700',
    border: 'border-green-800/40',
    emoji: '📝',
    description: 'Comparação de propostas, verificação de reputação, geração de contratos e monitoramento de conformidade.',
    capabilities: ['Análise de propostas', 'Verificação de fornecedores', 'Geração de contratos', 'Monitoramento de conformidade', 'Otimização de custos'],
    status: 'Configurar',
    prompt_example: 'Compare as propostas dos 3 fornecedores de bebidas e recomende a melhor opção baseada em custo-benefício.',
  },
  {
    id: 'feedback',
    icon: MessageSquare,
    title: 'Agente de Feedback',
    color: 'from-violet-700 to-purple-700',
    border: 'border-violet-800/40',
    emoji: '💬',
    description: 'Coleta de feedback de múltiplos canais, análise de sentimento, identificação de temas e recomendações.',
    capabilities: ['Análise de sentimento', 'Identificação de temas', 'Priorização de problemas', 'Geração de recomendações', 'Insights de participantes'],
    status: 'Configurar',
    prompt_example: 'Analise os comentários do WhatsApp desta semana e identifique os 3 principais problemas relatados pelos participantes.',
  },
];

export default function AIAgents() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title flex items-center gap-2">
          <Bot size={22} className="text-purple-400" /> Agentes de Inteligência Artificial
        </h1>
        <p className="text-gray-500 text-sm mt-1">6 agentes especializados para otimizar todos os aspectos do seu evento</p>
      </div>

      <div className="card bg-gradient-to-r from-purple-900/30 to-pink-900/30 border-purple-800/40 flex items-start gap-4">
        <div className="w-10 h-10 rounded-xl bg-purple-700 flex items-center justify-center flex-shrink-0">
          <Zap size={18} className="text-white" />
        </div>
        <div>
          <p className="font-medium text-white">Arquitetura baseada em API direta</p>
          <p className="text-sm text-gray-400 mt-1">
            Os agentes consultam modelos de linguagem (OpenAI GPT-4, Anthropic Claude ou outros) via API direta, sem intermediários.
            Configure sua API Key nas <span className="text-purple-400">Configurações</span> para ativar cada agente.
          </p>
        </div>
      </div>

      <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
        {agents.map(agent => (
          <AgentCard key={agent.id} agent={agent} />
        ))}
      </div>
    </div>
  );
}

function AgentCard({ agent }) {
  const Icon = agent.icon;
  return (
    <div className={`card-hover flex flex-col ${agent.border}`}>
      <div className={`w-12 h-12 rounded-2xl bg-gradient-to-br ${agent.color} flex items-center justify-center text-xl mb-4 flex-shrink-0`}>
        {agent.emoji}
      </div>
      <h3 className="font-bold text-white mb-1">{agent.title}</h3>
      <p className="text-xs text-gray-400 mb-4 leading-relaxed">{agent.description}</p>

      <div className="space-y-1.5 mb-4">
        {agent.capabilities.map(cap => (
          <div key={cap} className="flex items-center gap-2 text-xs text-gray-400">
            <span className="w-1 h-1 rounded-full bg-purple-500 flex-shrink-0" />
            {cap}
          </div>
        ))}
      </div>

      <div className="mt-auto pt-4 border-t border-gray-800">
        <div className="bg-gray-800/50 rounded-lg p-3 mb-3">
          <p className="text-xs text-gray-500 mb-1">Exemplo de consulta:</p>
          <p className="text-xs text-gray-400 italic">"{agent.prompt_example.slice(0, 80)}..."</p>
        </div>
        <div className="flex items-center justify-between">
          <span className={`badge ${agent.status === 'Ativo' ? 'badge-green' : 'badge-gray'}`}>{agent.status}</span>
          <button className={`btn btn-sm ${agent.status === 'Ativo' ? 'btn-primary' : 'btn-outline'}`}>
            {agent.status === 'Ativo' ? <><Icon size={12} /> Consultar</> : 'Configurar API'}
          </button>
        </div>
      </div>
    </div>
  );
}
