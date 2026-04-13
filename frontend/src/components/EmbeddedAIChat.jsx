import { useState, useRef, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Send, Loader2, RotateCcw, Sparkles, History, ChevronDown, FileText, ExternalLink, Quote } from 'lucide-react';
import { sendChatMessage, listChatSessions, getChatSession } from '../api/aiChat';
import { approveAIExecution, rejectAIExecution } from '../api/ai';
import AIResponseRenderer from './AIResponseRenderer';
import AdaptiveUIRenderer from './AdaptiveUIRenderer';
import { loadCatalog } from '../lib/aiActionCatalog';
import { currentLocale } from '../lib/i18n';
import { useEventScope } from '../context/EventScopeContext';

const AGENT_LABELS = {
  marketing: 'Vendas e Divulgacao',
  logistics: 'Operacoes',
  management: 'Gestao Executiva',
  bar: 'Bar e Estoque',
  contracting: 'Contratos',
  feedback: 'Qualidade',
  data_analyst: 'Analise de Dados',
  content: 'Textos e Posts',
  media: 'Imagens',
  documents: 'Documentos',
  artists: 'Artistas',
  artists_travel: 'Viagens',
  platform_guide: 'Guia da Plataforma',
};

/**
 * EmbeddedAIChat — Componente de chat embutido em paginas de dominio.
 *
 * Props:
 *  - surface (string, obrigatorio): identifica o dominio (parking, artists, workforce, etc.)
 *  - eventId (number|string, opcional): override do eventId do EventScopeContext
 *  - title (string, opcional): titulo exibido no header
 *  - description (string, opcional): subtitulo descritivo
 *  - context (object, opcional): contexto extra mergeado no payload
 *  - suggestions (string[], opcional): pills de sugestao no empty state
 *  - accentColor (string, opcional): cor de destaque — 'purple' | 'cyan' | 'emerald' | 'amber' (default: 'purple')
 */
export default function EmbeddedAIChat({
  surface,
  eventId: eventIdProp,
  title,
  description,
  context: extraContext,
  suggestions,
  accentColor = 'purple',
}) {
  const eventScope = useEventScope();
  const effectiveEventId = eventIdProp || eventScope?.eventId || '';
  const navigate = useNavigate();

  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [sessionId, setSessionId] = useState(null);
  const [lastAgent, setLastAgent] = useState(null);
  const [expanded, setExpanded] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const [pastSessions, setPastSessions] = useState([]);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const [deepAnalysisActive, setDeepAnalysisActive] = useState(false);
  const [previewFile, setPreviewFile] = useState(null);

  const messagesEndRef = useRef(null);
  const inputRef = useRef(null);
  const catalogLoaded = useRef(false);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  useEffect(() => {
    if (expanded && !catalogLoaded.current) {
      catalogLoaded.current = true;
      loadCatalog().catch(() => {});
    }
  }, [expanded]);

  // --- Accent color tokens ---
  const accent = {
    purple: {
      gradient: 'from-purple-600 to-pink-600',
      gradientMuted: 'from-purple-600/20 to-pink-600/20',
      border: 'border-purple-700/30',
      borderActive: 'border-purple-600/40',
      text: 'text-purple-400',
      textLight: 'text-purple-300',
      bg: 'bg-purple-600',
      bgHover: 'hover:bg-purple-500',
      bgMuted: 'bg-purple-600/20',
      bgMutedHover: 'hover:bg-purple-600/30',
      bubble: 'bg-purple-600/80',
      shadow: 'shadow-purple-900/30',
      dot: 'bg-purple-400',
    },
    cyan: {
      gradient: 'from-cyan-600 to-blue-600',
      gradientMuted: 'from-cyan-600/20 to-blue-600/20',
      border: 'border-cyan-700/30',
      borderActive: 'border-cyan-600/40',
      text: 'text-cyan-400',
      textLight: 'text-cyan-300',
      bg: 'bg-cyan-600',
      bgHover: 'hover:bg-cyan-500',
      bgMuted: 'bg-cyan-600/20',
      bgMutedHover: 'hover:bg-cyan-600/30',
      bubble: 'bg-cyan-600/80',
      shadow: 'shadow-cyan-900/30',
      dot: 'bg-cyan-400',
    },
    emerald: {
      gradient: 'from-emerald-600 to-teal-600',
      gradientMuted: 'from-emerald-600/20 to-teal-600/20',
      border: 'border-emerald-700/30',
      borderActive: 'border-emerald-600/40',
      text: 'text-emerald-400',
      textLight: 'text-emerald-300',
      bg: 'bg-emerald-600',
      bgHover: 'hover:bg-emerald-500',
      bgMuted: 'bg-emerald-600/20',
      bgMutedHover: 'hover:bg-emerald-600/30',
      bubble: 'bg-emerald-600/80',
      shadow: 'shadow-emerald-900/30',
      dot: 'bg-emerald-400',
    },
    amber: {
      gradient: 'from-amber-600 to-orange-600',
      gradientMuted: 'from-amber-600/20 to-orange-600/20',
      border: 'border-amber-700/30',
      borderActive: 'border-amber-600/40',
      text: 'text-amber-400',
      textLight: 'text-amber-300',
      bg: 'bg-amber-600',
      bgHover: 'hover:bg-amber-500',
      bgMuted: 'bg-amber-600/20',
      bgMutedHover: 'hover:bg-amber-600/30',
      bubble: 'bg-amber-600/80',
      shadow: 'shadow-amber-900/30',
      dot: 'bg-amber-400',
    },
  }[accentColor] || accent.purple;

  const defaultSuggestions = suggestions || [
    'Resumo rapido do que esta acontecendo',
    'Tem algum alerta critico?',
    'O que devo priorizar agora?',
  ];

  // --- Session history ---
  const loadPastSessions = useCallback(async () => {
    setLoadingHistory(true);
    try {
      const sessions = await listChatSessions(10);
      setPastSessions(sessions.filter(s => s.surface === surface));
    } catch {
      // silent
    } finally {
      setLoadingHistory(false);
    }
  }, [surface]);

  const resumeSession = useCallback(async (sid) => {
    setLoadingHistory(true);
    try {
      const data = await getChatSession(sid);
      if (data?.messages) {
        setMessages(data.messages.map(m => ({
          role: m.role,
          content: m.content,
          contentType: m.content_type || 'text',
          agentKey: m.agent_key,
          blocks: Array.isArray(m.metadata_json?.blocks) ? m.metadata_json.blocks : null,
          metadata: m.metadata_json || {},
          timestamp: new Date(m.created_at),
        })));
        setSessionId(sid);
        setLastAgent(data.session?.routed_agent_key || null);
        setShowHistory(false);
        setExpanded(true);
      }
    } catch {
      // silent
    } finally {
      setLoadingHistory(false);
    }
  }, []);

  // --- Approve / Reject ---
  const handleApprove = useCallback(async (executionId) => {
    if (!executionId) return;
    try {
      const result = await approveAIExecution(executionId);
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: result?.insight || result?.response_preview || 'Acao aprovada e executada com sucesso.',
        contentType: 'text',
        agentKey: lastAgent,
        timestamp: new Date(),
      }]);
    } catch (err) {
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: `Erro ao aprovar: ${err?.response?.data?.message || err.message}`,
        contentType: 'error',
        timestamp: new Date(),
      }]);
    }
  }, [lastAgent]);

  const handleReject = useCallback(async (executionId, reason) => {
    if (!executionId) return;
    try {
      await rejectAIExecution(executionId, { reason });
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: 'Acao rejeitada.',
        contentType: 'text',
        agentKey: lastAgent,
        timestamp: new Date(),
      }]);
    } catch (err) {
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: `Erro ao rejeitar: ${err?.response?.data?.message || err.message}`,
        contentType: 'error',
        timestamp: new Date(),
      }]);
    }
  }, [lastAgent]);

  const handleAdaptiveAction = useCallback((item) => {
    if (!item) return;
    // Evidence file preview — intercept navigate to /files?highlight=
    if (item.action === 'navigate' && item.target?.startsWith('/files?highlight=')) {
      const fileId = item.target.split('highlight=')[1];
      if (fileId) {
        setPreviewFile({ file_id: fileId, ...item });
        return;
      }
    }
    if (item.action === 'navigate' && item.target) {
      navigate(item.target);
      return;
    }
    if (item.action === 'execute' && item.execution_id) {
      handleApprove(item.execution_id);
      return;
    }
    if (item.action === 'tool') {
      setInput(item.label || '');
      inputRef.current?.focus();
    }
  }, [navigate, handleApprove]);

  // --- Send message ---
  const sendMessage = async (overrideText) => {
    const safeOverride = typeof overrideText === 'string' ? overrideText : undefined;
    const text = (safeOverride ?? input ?? '').toString().trim();
    if (!text || loading) return;

    setMessages(prev => [...prev, { role: 'user', content: text, timestamp: new Date() }]);
    if (!safeOverride) setInput('');
    setLoading(true);
    setDeepAnalysisActive(false);
    setExpanded(true);

    // Detect potential deep analysis — show specialized loading after 3s
    let deepTimer = null;
    if (surface === 'documents') {
      deepTimer = setTimeout(() => setDeepAnalysisActive(true), 3000);
    }

    try {
      const result = await sendChatMessage({
        question: text,
        session_id: sessionId,
        context: {
          event_id: effectiveEventId || undefined,
          surface,
          locale: currentLocale,
          ...(surface === 'documents' ? { kb_mode: 'hybrid' } : {}),
          ...(extraContext || {}),
        },
      });

      if (result.session_id) setSessionId(result.session_id);
      if (result.agent_key) setLastAgent(result.agent_key);

      // Merge evidence into blocks if present
      let blocks = Array.isArray(result.blocks) ? [...result.blocks] : [];
      if (Array.isArray(result.evidence) && result.evidence.length > 0) {
        blocks.push({
          type: 'evidence',
          title: 'Fontes consultadas',
          citations: result.evidence.map((e) => ({
            file_id: e.file_id,
            file_name: e.file_name || e.original_name,
            category: e.category,
            excerpt: e.snippet || e.excerpt,
            relevance: e.relevance_score || e.relevance,
          })),
        });
      }

      setMessages(prev => [...prev, {
        role: 'assistant',
        content: result.insight || result.response || result.text_fallback || '',
        contentType: result.content_type || 'text',
        agentKey: result.agent_key,
        blocks: blocks.length > 0 ? blocks : null,
        metadata: {
          tool_calls: result.tool_calls || [],
          tool_results: result.tool_results || [],
          execution_id: result.execution_id,
          evidence: result.evidence || [],
        },
        timestamp: new Date(),
      }]);
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err.message || 'Erro ao enviar mensagem';
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: errorMsg,
        contentType: 'error',
        timestamp: new Date(),
      }]);
    } finally {
      if (deepTimer) clearTimeout(deepTimer);
      setDeepAnalysisActive(false);
      setLoading(false);
    }
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  const resetChat = () => {
    setMessages([]);
    setSessionId(null);
    setLastAgent(null);
  };

  return (
    <div
      className={`relative rounded-2xl border ${accent.border} overflow-hidden`}
      style={{
        background: 'linear-gradient(135deg, rgba(15,23,42,0.95), rgba(15,23,42,0.80))',
        backdropFilter: 'blur(16px)',
        WebkitBackdropFilter: 'blur(16px)',
      }}
    >
      {/* Glassmorphism top glow */}
      <div
        className="absolute inset-x-0 top-0 h-px opacity-30"
        style={{ background: `linear-gradient(90deg, transparent, var(--tw-gradient-from, #a855f7), transparent)` }}
      />

      {/* Header */}
      <div className="flex items-center justify-between px-5 py-4">
        <button
          type="button"
          onClick={() => setExpanded(v => !v)}
          className="flex items-center gap-3 flex-1 min-w-0"
        >
          <div className={`w-9 h-9 rounded-xl bg-gradient-to-br ${accent.gradient} flex items-center justify-center shadow-lg ${accent.shadow} flex-shrink-0`}>
            <Sparkles size={18} className="text-white" />
          </div>
          <div className="text-left min-w-0">
            <h3 className="text-sm font-semibold text-white truncate">
              {title || 'Assistente EnjoyFun'}
            </h3>
            {description && (
              <p className="text-[11px] text-gray-400 truncate">{description}</p>
            )}
            {!description && lastAgent && (
              <div className="flex items-center gap-1.5 text-[11px] text-gray-400">
                <span className={`w-1.5 h-1.5 rounded-full ${accent.dot}`} />
                <span>{AGENT_LABELS[lastAgent] || lastAgent}</span>
              </div>
            )}
          </div>
          <ChevronDown
            size={16}
            className={`text-gray-500 transition-transform flex-shrink-0 ${expanded ? 'rotate-180' : ''}`}
          />
        </button>
        <div className="flex items-center gap-1 ml-2">
          <button
            onClick={(e) => { e.stopPropagation(); setShowHistory(!showHistory); if (!showHistory) loadPastSessions(); }}
            className={`p-1.5 rounded-lg transition-colors ${showHistory ? accent.text : 'text-gray-500'} hover:text-white hover:bg-white/5`}
            title="Conversas anteriores"
          >
            <History size={15} />
          </button>
          <button
            onClick={(e) => { e.stopPropagation(); resetChat(); }}
            className="p-1.5 text-gray-500 hover:text-white hover:bg-white/5 rounded-lg transition-colors"
            title="Nova conversa"
          >
            <RotateCcw size={15} />
          </button>
        </div>
      </div>

      {/* Session History Panel */}
      {showHistory && (
        <div className="border-t border-gray-700/30 bg-gray-800/30 px-5 py-3 max-h-48 overflow-y-auto">
          <div className="text-[11px] font-medium text-gray-500 mb-2">Conversas anteriores</div>
          {loadingHistory && (
            <div className="flex items-center gap-2 text-xs text-gray-500 py-2">
              <Loader2 size={12} className="animate-spin" /> Carregando...
            </div>
          )}
          {!loadingHistory && pastSessions.length === 0 && (
            <div className="text-xs text-gray-500 py-2">Nenhuma conversa nesta area.</div>
          )}
          {pastSessions.map((s) => (
            <button
              key={s.id}
              onClick={() => resumeSession(s.id)}
              className="w-full text-left px-3 py-2 rounded-lg hover:bg-white/5 transition-colors mb-1 group"
            >
              <div className="flex items-center justify-between">
                <span className="text-xs text-gray-400 group-hover:text-white">
                  {AGENT_LABELS[s.routed_agent_key] || s.routed_agent_key || 'Conversa'}
                </span>
                <span className="text-[10px] text-gray-600">
                  {new Date(s.updated_at || s.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                </span>
              </div>
            </button>
          ))}
        </div>
      )}

      {/* Expandable body */}
      {expanded && (
        <>
          {/* Messages */}
          <div className="border-t border-gray-700/30 max-h-[420px] overflow-y-auto px-5 py-4 space-y-3 scrollbar-thin scrollbar-thumb-gray-700">
            {messages.length === 0 && !showHistory && (
              <div className="text-center py-6 px-4">
                <p className="text-xs text-gray-500 mb-3">Pergunte qualquer coisa sobre esta area.</p>
                <div className="space-y-1.5">
                  {defaultSuggestions.map((s) => (
                    <button
                      key={s}
                      onClick={() => sendMessage(s)}
                      className={`w-full text-left text-xs text-gray-400 ${accent.bgMuted} ${accent.bgMutedHover} ${accent.textLight} border ${accent.border} rounded-lg px-3 py-2 transition-colors`}
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {messages.map((msg, i) => (
              <div key={i} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[88%] rounded-2xl px-3.5 py-2.5 ${
                  msg.role === 'user'
                    ? `${accent.bubble} text-white rounded-br-md`
                    : 'bg-white/5 border border-gray-700/30 rounded-bl-md'
                }`}>
                  {msg.role === 'assistant' && msg.agentKey && (
                    <div className={`text-[10px] ${accent.text} font-medium mb-1`}>
                      {AGENT_LABELS[msg.agentKey] || msg.agentKey}
                    </div>
                  )}
                  {msg.role === 'user' ? (
                    <p className="text-sm">{msg.content}</p>
                  ) : msg.blocks && msg.blocks.length > 0 ? (
                    <AdaptiveUIRenderer blocks={msg.blocks} onAction={handleAdaptiveAction} />
                  ) : (
                    <AIResponseRenderer
                      content={msg.content}
                      contentType={msg.contentType}
                      metadata={msg.metadata}
                      onApprove={handleApprove}
                      onReject={handleReject}
                      actionParams={{
                        event_id: effectiveEventId || undefined,
                        ...(extraContext || {}),
                      }}
                    />
                  )}
                  <div className={`text-[9px] mt-1 ${msg.role === 'user' ? 'text-white/50' : 'text-gray-600'}`}>
                    {msg.timestamp?.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}
                  </div>
                </div>
              </div>
            ))}

            {loading && (
              <div className="flex justify-start">
                <div className="bg-white/5 border border-gray-700/30 rounded-2xl rounded-bl-md px-4 py-3">
                  {deepAnalysisActive ? (
                    <div className="space-y-1.5">
                      <div className={`flex items-center gap-2 text-xs ${accent.text}`}>
                        <Loader2 size={14} className="animate-spin" />
                        Consultando motor de analise profunda...
                      </div>
                      <p className="text-[10px] text-gray-500 leading-relaxed">
                        Isso pode levar alguns segundos para documentos longos.
                      </p>
                    </div>
                  ) : (
                    <div className={`flex items-center gap-2 text-xs text-gray-400`}>
                      <Loader2 size={14} className={`animate-spin ${accent.text}`} />
                      Analisando...
                    </div>
                  )}
                </div>
              </div>
            )}

            <div ref={messagesEndRef} />
          </div>

          {/* File preview panel — opens when clicking an evidence citation */}
          {previewFile && (
            <FilePreviewPanel
              fileId={previewFile.file_id}
              onClose={() => setPreviewFile(null)}
              onNavigate={() => { navigate(`/files?highlight=${previewFile.file_id}`); setPreviewFile(null); }}
              accent={accent}
            />
          )}
        </>
      )}

      {/* Input — always visible */}
      <div className="border-t border-gray-700/30 px-4 py-3">
        <div className="flex items-end gap-2">
          <textarea
            ref={inputRef}
            value={input}
            onChange={(e) => { setInput(e.target.value); if (!expanded) setExpanded(true); }}
            onKeyDown={handleKeyDown}
            onFocus={() => { if (!expanded) setExpanded(true); }}
            placeholder="Pergunte sobre esta area..."
            rows={1}
            className="flex-1 bg-white/5 border border-gray-700/40 rounded-xl px-3.5 py-2.5 text-sm text-gray-200 placeholder-gray-500 resize-none focus:border-gray-600 focus:ring-0 outline-none max-h-20 overflow-y-auto"
            style={{ minHeight: '38px' }}
            disabled={loading}
          />
          <button
            onClick={sendMessage}
            disabled={!input.trim() || loading}
            className={`p-2.5 ${accent.bg} ${accent.bgHover} disabled:bg-gray-700 disabled:cursor-not-allowed text-white rounded-xl transition-colors flex-shrink-0`}
          >
            {loading ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * FilePreviewPanel — Inline preview of a cited file's parsed data.
 * Fetches /organizer-files/{id}/parsed and shows excerpt + metadata.
 */
function FilePreviewPanel({ fileId, onClose, onNavigate, accent }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { default: api } = await import('../lib/api');
        const res = await api.get(`/organizer-files/${fileId}/parsed`);
        if (!cancelled) setData(res.data?.data || null);
      } catch {
        if (!cancelled) setError('Nao foi possivel carregar o preview.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [fileId]);

  return (
    <div className="border-t border-gray-700/30 bg-gray-900/60 px-4 py-3 max-h-52 overflow-y-auto">
      <div className="flex items-center justify-between mb-2">
        <div className="flex items-center gap-2">
          <FileText size={14} className={accent.text} />
          <span className="text-xs font-semibold text-gray-200">
            {data?.original_name || `Arquivo #${fileId}`}
          </span>
        </div>
        <div className="flex items-center gap-1">
          <button
            onClick={onNavigate}
            className="p-1 text-gray-500 hover:text-white transition-colors"
            title="Abrir pagina do arquivo"
          >
            <ExternalLink size={12} />
          </button>
          <button
            onClick={onClose}
            className="p-1 text-gray-500 hover:text-white transition-colors"
            title="Fechar preview"
          >
            ×
          </button>
        </div>
      </div>

      {loading && (
        <div className="flex items-center gap-2 text-xs text-gray-500 py-2">
          <Loader2 size={12} className="animate-spin" /> Carregando preview...
        </div>
      )}

      {error && <p className="text-xs text-red-400">{error}</p>}

      {!loading && data && (
        <div className="space-y-2">
          <div className="flex gap-3 text-[10px] text-gray-500">
            <span>Formato: {data.parsed_data?.format || data.file_type || '?'}</span>
            {data.parsed_data?.rows_count != null && <span>Linhas: {data.parsed_data.rows_count}</span>}
            <span>Status: {data.parsed_status}</span>
          </div>
          {data.parsed_data?.headers && (
            <div className="max-h-28 overflow-auto rounded-lg border border-gray-800 bg-gray-950 text-[10px]">
              <table className="w-full text-left">
                <thead>
                  <tr className="border-b border-gray-800 bg-gray-900">
                    {data.parsed_data.headers.slice(0, 6).map((h) => (
                      <th key={h} className="px-2 py-1 text-gray-500">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {(data.parsed_data.rows || []).slice(0, 5).map((row, i) => (
                    <tr key={i} className="border-b border-gray-800/30">
                      {data.parsed_data.headers.slice(0, 6).map((h) => (
                        <td key={h} className="px-2 py-1 text-gray-400">{row[h] ?? ''}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {!data.parsed_data?.headers && data.parsed_data && (
            <pre className="max-h-28 overflow-auto rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-[10px] text-gray-400">
              {JSON.stringify(data.parsed_data, null, 2).slice(0, 600)}
            </pre>
          )}
        </div>
      )}
    </div>
  );
}
