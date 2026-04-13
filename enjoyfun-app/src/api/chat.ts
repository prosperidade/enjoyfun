import { apiClient } from './client';
import type { AdaptiveResponse, ChatSession, ChatSurface } from '@/lib/types';

export interface SendChatMessageInput {
  question: string;
  session_id?: string;
  surface?: ChatSurface;
  event_id?: number | null;
  locale?: string;
  agent_key?: string;
}

interface ApiEnvelope<T> {
  success?: boolean;
  message?: string;
  data: T;
}

export async function sendChatMessage(input: SendChatMessageInput): Promise<AdaptiveResponse> {
  const { data: body } = await apiClient.post<ApiEnvelope<AdaptiveResponse>>('/ai/chat', input);
  const payload = body?.data;
  if (!payload) {
    throw new Error('Resposta vazia do servidor');
  }
  return payload;
}

export async function listSessions(): Promise<ChatSession[]> {
  const { data: body } = await apiClient.get<ApiEnvelope<{ sessions: ChatSession[] }>>('/ai/chat/sessions');
  return body?.data?.sessions ?? [];
}
