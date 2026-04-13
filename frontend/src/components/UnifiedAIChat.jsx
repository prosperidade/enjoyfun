import { useState, useRef, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { X, Send, Loader2, RotateCcw, Sparkles, ChevronDown, History } from 'lucide-react';
import { sendChatMessage, listChatSessions, getChatSession } from '../api/aiChat';
import { approveAIExecution, rejectAIExecution } from '../api/ai';
import AIResponseRenderer from './AIResponseRenderer';
import { loadCatalog } from '../lib/aiActionCatalog';
import { currentLocale } from '../lib/i18n';
import { useEventScope } from '../context/EventScopeContext';
import AdaptiveUIRenderer from './AdaptiveUIRenderer';

// Platform Guide — fixed surface, no route detection
const SURFACE = 'general';

const AGENT_LABELS = {
  platform_guide: 'Guia da Plataforma',
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
};

export default function UnifiedAIChat({ eventId: eventIdProp, eventName: eventNameProp }) {
  const eventScope = useEventScope();
  const effectiveEventId = eventIdProp || eventScope?.eventId || '';
  const activeEvent = eventScope?.activeEvent ?? null;
  const effectiveEventName = eventNameProp || activeEvent?.name || '';
  const availableEvents = eventScope?.events ?? [];
  const [showEventPicker, setShowEventPicker] = useState(false);
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [sessionId, setSessionId] = useState(null);
  const [lastAgent, setLastAgent] = useState(null);
  const [hasUnread, setHasUnread] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const [pastSessions, setPastSessions] = useState([]);
  const [loadingHistory, setLoadingHistory] = useState(false);

  const messagesEndRef = useRef(null);
  const inputRef = useRef(null);
  const navigate = useNavigate();

  // Auto-scroll to bottom on new messages
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Focus input when chat opens
  useEffect(() => {
    if (isOpen) {
      setTimeout(() => inputRef.current?.focus(), 100);
      setHasUnread(false);
    }
  }, [isOpen]);

  // Load the AI action catalog once on first chat open — used by
  // AIResponseRenderer to parse [action_key] tags into clickable buttons.
  useEffect(() => {
    if (isOpen) {
      loadCatalog().catch(() => { /* silent — AIActionButton has fallback */ });
    }
  }, [isOpen]);

  // Auto-welcome removed — opening the chat should NOT trigger a canned question.
  // The empty state already shows 3 suggestion pills that the user can click if they want.

  // Override state populated by `enjoyfun:open-ai-chat` events from triggers
  // (AIChatTrigger, AIAssistants page, embedded buttons, etc.)
  const [surfaceOverride, setSurfaceOverride] = useState(null);
  const [extraContext, setExtraContext] = useState(null);

  // Listen for custom event from AIAssistants page / AIChatTrigger buttons
  useEffect(() => {
    const handler = (e) => {
      setIsOpen(true);
      const detail = e.detail || {};

      if (detail.agent_key) {
        setLastAgent(detail.agent_key);
      }
      if (detail.surface) {
        setSurfaceOverride(detail.surface);
      }
      if (detail.context) {
        setExtraContext(detail.context);
      }
      if (detail.prefill) {
        setInput(detail.prefill);
      }
    };
    window.addEventListener('enjoyfun:open-ai-chat', handler);
    return () => window.removeEventListener('enjoyfun:open-ai-chat', handler);
  }, []);

  // Load past sessions when history panel opens
  const loadPastSessions = useCallback(async () => {
    setLoadingHistory(true);
    try {
      const sessions = await listChatSessions(10);
      setPastSessions(sessions);
    } catch {
      // silent
    } finally {
      setLoadingHistory(false);
    }
  }, []);

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
      }
    } catch {
      // silent
    } finally {
      setLoadingHistory(false);
    }
  }, []);

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

  const handleAdaptiveAction = useCallback((item) => {
    if (!item) return;
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

  const sendMessage = async (overrideText) => {
    // Guard: when wired to onClick={sendMessage}, React passes a SyntheticEvent.
    // Only treat overrideText as a real value when it's a string.
    const safeOverride = typeof overrideText === 'string' ? overrideText : undefined;
    const text = (safeOverride ?? input ?? '').toString().trim();
    if (!text || loading) return;

    const userMsg = {
      role: 'user',
      content: text,
      timestamp: new Date(),
    };

    setMessages(prev => [...prev, userMsg]);
    if (!overrideText) setInput('');
    setLoading(true);

    try {
      const result = await sendChatMessage({
        question: text,
        session_id: sessionId,
        context: {
          event_id: effectiveEventId || undefined,
          surface: surfaceOverride || SURFACE,
          locale: currentLocale,
          ...(extraContext || {}),
        },
      });

      if (result.session_id) setSessionId(result.session_id);
      if (result.agent_key) setLastAgent(result.agent_key);

      const assistantMsg = {
        role: 'assistant',
        content: result.insight || result.response || result.text_fallback || '',
        contentType: result.content_type || 'text',
        agentKey: result.agent_key,
        blocks: Array.isArray(result.blocks) ? result.blocks : null,
        metadata: {
          tool_calls: result.tool_calls || [],
          tool_results: result.tool_results || [],
          execution_id: result.execution_id,
        },
        timestamp: new Date(),
      };

      setMessages(prev => [...prev, assistantMsg]);

      if (!isOpen) setHasUnread(true);
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err.message || 'Erro ao enviar mensagem';
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: errorMsg,
        contentType: 'error',
        timestamp: new Date(),
      }]);
    } finally {
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
    setSurfaceOverride(null);
    setExtraContext(null);
  };

  return (
    <>
      {/* Floating button */}
      {!isOpen && (
        <button
          onClick={() => setIsOpen(true)}
          className="fixed bottom-6 right-6 z-50 w-14 h-14 bg-gradient-to-br from-purple-600 to-pink-600 rounded-full flex items-center justify-center shadow-lg shadow-purple-900/40 hover:shadow-purple-900/60 hover:scale-105 transition-all duration-200 group"
          title="Guia da Plataforma"
        >
          <Sparkles size={24} className="text-white group-hover:rotate-12 transition-transform" />
          {hasUnread && (
            <span className="absolute -top-0.5 -right-0.5 w-4 h-4 bg-pink-500 rounded-full border-2 border-gray-950 animate-pulse" />
          )}
        </button>
      )}

      {/* Chat panel */}
      {isOpen && (
        <div className="fixed bottom-0 right-0 z-50 w-full sm:w-[420px] h-[85vh] sm:h-[600px] sm:bottom-6 sm:right-6 flex flex-col bg-gray-900 border border-gray-700/60 sm:rounded-2xl shadow-2xl shadow-black/40 overflow-hidden">

          {/* Header */}
          <div className="flex items-center justify-between px-4 py-3 bg-gray-800/80 border-b border-gray-700/50">
            <div className="flex items-center gap-3">
              <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center">
                <Sparkles size={16} className="text-white" />
              </div>
              <div>
                <h3 className="text-sm font-semibold text-white">Guia da Plataforma</h3>
                <div className="text-[10px] text-gray-400 flex items-center gap-1.5">
                  {lastAgent && (
                    <>
                      <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                      <span>{AGENT_LABELS[lastAgent] || lastAgent}</span>
                      <span className="text-gray-600">•</span>
                    </>
                  )}
                  <button
                    type="button"
                    onClick={() => setShowEventPicker((v) => !v)}
                    className="hover:text-white transition-colors flex items-center gap-1"
                    title="Trocar de evento"
                  >
                    <span>{effectiveEventName || 'Sem evento (visao geral)'}</span>
                    {availableEvents.length > 0 && <ChevronDown size={10} />}
                  </button>
                </div>
              </div>
            </div>
            <div className="flex items-center gap-1">
              <button
                onClick={() => { setShowHistory(!showHistory); if (!showHistory) loadPastSessions(); }}
                className={`p-1.5 hover:text-white hover:bg-gray-700/50 rounded-lg transition-colors ${showHistory ? 'text-purple-400' : 'text-gray-400'}`}
                title="Conversas anteriores"
              >
                <History size={16} />
              </button>
              <button
                onClick={resetChat}
                className="p-1.5 text-gray-400 hover:text-white hover:bg-gray-700/50 rounded-lg transition-colors"
                title="Nova conversa"
              >
                <RotateCcw size={16} />
              </button>
              <button
                onClick={() => setIsOpen(false)}
                className="p-1.5 text-gray-400 hover:text-white hover:bg-gray-700/50 rounded-lg transition-colors"
              >
                <X size={18} />
              </button>
            </div>
          </div>

          {/* Event Picker Panel */}
          {showEventPicker && availableEvents.length > 0 && (
            <div className="border-b border-gray-700/50 bg-gray-800/40 px-4 py-3 max-h-64 overflow-y-auto">
              <div className="text-xs font-medium text-gray-400 mb-2">Selecionar evento</div>
              <div className="space-y-1">
                <button
                  type="button"
                  onClick={() => {
                    eventScope?.setEventId('');
                    resetChat();
                    setShowEventPicker(false);
                  }}
                  className={`w-full text-left px-3 py-2 rounded-lg text-xs transition-colors ${
                    !effectiveEventId
                      ? 'bg-purple-600/20 text-purple-300 border border-purple-600/40'
                      : 'text-gray-400 hover:bg-gray-700/40 border border-transparent'
                  }`}
                >
                  Sem evento (visao geral)
                </button>
                {availableEvents.map((ev) => {
                  const isActive = String(ev.id) === String(effectiveEventId);
                  return (
                    <button
                      key={ev.id}
                      type="button"
                      onClick={() => {
                        eventScope?.setEventId(ev.id);
                        resetChat();
                        setShowEventPicker(false);
                      }}
                      className={`w-full text-left px-3 py-2 rounded-lg text-xs transition-colors ${
                        isActive
                          ? 'bg-purple-600/20 text-purple-300 border border-purple-600/40'
                          : 'text-gray-300 hover:bg-gray-700/40 border border-transparent'
                      }`}
                    >
                      <div className="font-medium">{ev.name}</div>
                      {ev.venue_name && <div className="text-[10px] text-gray-500 mt-0.5">{ev.venue_name}</div>}
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* Session History Panel */}
          {showHistory && (
            <div className="border-b border-gray-700/50 bg-gray-800/40 px-4 py-3 max-h-48 overflow-y-auto">
              <div className="text-xs font-medium text-gray-400 mb-2">Conversas anteriores</div>
              {loadingHistory && (
                <div className="flex items-center gap-2 text-xs text-gray-500 py-2">
                  <Loader2 size={12} className="animate-spin" /> Carregando...
                </div>
              )}
              {!loadingHistory && pastSessions.length === 0 && (
                <div className="text-xs text-gray-500 py-2">Nenhuma conversa anterior.</div>
              )}
              {pastSessions.map((s) => (
                <button
                  key={s.id}
                  onClick={() => resumeSession(s.id)}
                  className="w-full text-left px-3 py-2 rounded-lg hover:bg-gray-700/50 transition-colors mb-1 group"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-xs text-gray-300 group-hover:text-white">
                      {AGENT_LABELS[s.routed_agent_key] || s.routed_agent_key || 'Conversa'}
                    </span>
                    <span className="text-[10px] text-gray-500">
                      {new Date(s.updated_at || s.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                    </span>
                  </div>
                  {s.surface && (
                    <span className="text-[10px] text-gray-500">{s.surface}</span>
                  )}
                </button>
              ))}
            </div>
          )}

          {/* Messages */}
          <div className="flex-1 overflow-y-auto px-4 py-3 space-y-4 scrollbar-thin scrollbar-thumb-gray-700">
            {messages.length === 0 && !showHistory && (
              <div className="flex flex-col items-center justify-center h-full text-center px-6">
                <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-600/20 to-pink-600/20 border border-purple-700/30 flex items-center justify-center mb-4">
                  <Sparkles size={24} className="text-purple-400" />
                </div>
                <h4 className="text-sm font-semibold text-white mb-1">Guia da Plataforma</h4>
                <p className="text-xs text-gray-400 mb-4">
                  Tire duvidas sobre funcionalidades, fluxos e configuracoes da EnjoyFun.
                </p>
                <div className="space-y-2 w-full">
                  {[
                    'Como funciona o cashless?',
                    'Como cadastrar workforce no evento?',
                    'O que cada modulo faz?',
                  ].map((suggestion) => (
                    <button
                      key={suggestion}
                      onClick={() => { setInput(suggestion); inputRef.current?.focus(); }}
                      className="w-full text-left text-xs text-gray-300 bg-gray-800/50 hover:bg-gray-800 border border-gray-700/40 rounded-lg px-3 py-2 transition-colors"
                    >
                      {suggestion}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {messages.map((msg, i) => (
              <div key={i} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[85%] rounded-2xl px-3.5 py-2.5 ${
                  msg.role === 'user'
                    ? 'bg-purple-600/80 text-white rounded-br-md'
                    : 'bg-gray-800/70 border border-gray-700/40 rounded-bl-md'
                }`}>
                  {msg.role === 'assistant' && msg.agentKey && (
                    <div className="text-[10px] text-purple-400 font-medium mb-1">
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
                  <div className={`text-[9px] mt-1 ${msg.role === 'user' ? 'text-purple-200/60' : 'text-gray-500'}`}>
                    {msg.timestamp?.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}
                  </div>
                </div>
              </div>
            ))}

            {loading && (
              <div className="flex justify-start">
                <div className="bg-gray-800/70 border border-gray-700/40 rounded-2xl rounded-bl-md px-4 py-3">
                  <div className="flex items-center gap-2 text-xs text-gray-400">
                    <Loader2 size={14} className="animate-spin text-purple-400" />
                    Analisando...
                  </div>
                </div>
              </div>
            )}

            <div ref={messagesEndRef} />
          </div>

          {/* Input */}
          <div className="p-3 border-t border-gray-700/50 bg-gray-800/40">
            <div className="flex items-end gap-2">
              <textarea
                ref={inputRef}
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Pergunte sobre a plataforma..."
                rows={1}
                className="flex-1 bg-gray-800 border border-gray-700 rounded-xl px-3.5 py-2.5 text-sm text-gray-200 placeholder-gray-500 resize-none focus:border-purple-600 focus:ring-0 outline-none max-h-24 overflow-y-auto"
                style={{ minHeight: '40px' }}
                disabled={loading}
              />
              <button
                onClick={sendMessage}
                disabled={!input.trim() || loading}
                className="p-2.5 bg-purple-600 hover:bg-purple-500 disabled:bg-gray-700 disabled:cursor-not-allowed text-white rounded-xl transition-colors flex-shrink-0"
              >
                {loading ? <Loader2 size={18} className="animate-spin" /> : <Send size={18} />}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
