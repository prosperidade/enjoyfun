import { apiClient } from '@/api/client';
import type { Block } from '@/lib/types';

export type Surface =
  | 'dashboard'
  | 'bar'
  | 'food'
  | 'shop'
  | 'parking'
  | 'artists'
  | 'workforce'
  | 'finance'
  | 'documents'
  | 'tickets'
  | 'platform_guide';

export type ConversationMode =
  | 'embedded'
  | 'global_help'
  | 'admin_preview'
  | 'whatsapp'
  | 'api';

export interface ToolCallSummary {
  tool: string;
  duration_ms?: number;
  ok?: boolean;
}

export interface EvidenceItem {
  type: string;
  file_id?: string | number;
  snippet?: string;
  score?: number;
  url?: string;
}

export interface ApprovalRequest {
  request_id: string;
  summary: string;
  skill_key: string;
  params_preview?: string;
}

export interface AdaptiveResponseV3 {
  session_id: string;
  agent_used?: string;
  blocks: Block[];
  text_fallback: string;
  tool_calls_summary?: ToolCallSummary[];
  evidence?: EvidenceItem[];
  approval_request?: ApprovalRequest | null;
  routing_trace_id?: string;
  meta?: Record<string, unknown>;
}

export interface SendMessageOptions {
  agentKey?: string;
  conversationMode?: ConversationMode;
  contextData?: Record<string, unknown>;
  locale?: string;
  sessionId?: string;
  signal?: AbortSignal;
}

interface ChatRequestV3 {
  message: string;
  surface: Surface;
  event_id: number | null;
  agent_key?: string | null;
  conversation_mode: ConversationMode;
  context_data: Record<string, unknown>;
  locale: string;
  stream: false;
  session_id?: string;
}

interface ApiEnvelope<T> {
  success?: boolean;
  message?: string;
  data: T;
}

const DEFAULT_LOCALE = 'pt-BR';

export async function sendMessage(
  surface: Surface,
  eventId: number | null,
  message: string,
  opts: SendMessageOptions = {},
): Promise<AdaptiveResponseV3> {
  const payload: ChatRequestV3 = {
    message,
    surface,
    event_id: eventId,
    agent_key: opts.agentKey ?? null,
    conversation_mode: opts.conversationMode ?? 'embedded',
    context_data: opts.contextData ?? {},
    locale: opts.locale ?? DEFAULT_LOCALE,
    stream: false,
  };
  if (opts.sessionId) payload.session_id = opts.sessionId;

  const { data: body } = await apiClient.post<ApiEnvelope<AdaptiveResponseV3>>(
    '/ai/chat',
    payload,
    { signal: opts.signal },
  );
  const data = body?.data;
  if (!data) throw new Error('Resposta vazia do servidor');
  return {
    ...data,
    text_fallback: data.text_fallback ?? '',
    blocks: data.blocks ?? [],
  };
}

export async function sendPlatformGuideMessage(
  message: string,
  opts: Omit<SendMessageOptions, 'conversationMode' | 'agentKey'> = {},
): Promise<AdaptiveResponseV3> {
  return sendMessage('platform_guide', null, message, {
    ...opts,
    conversationMode: 'global_help',
    agentKey: 'platform_guide',
  });
}
