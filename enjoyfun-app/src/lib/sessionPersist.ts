import AsyncStorage from '@react-native-async-storage/async-storage';
import type { ChatMessage } from '@/lib/types';
import type { Surface } from '@/lib/aiSession';

const PREFIX = 'chat_history:';
const MAX_MESSAGES = 50;

function storageKey(surface: Surface, eventId: number | null): string {
  return `${PREFIX}${surface}:${eventId ?? 'null'}`;
}

export async function saveHistory(
  surface: Surface,
  eventId: number | null,
  messages: ChatMessage[],
): Promise<void> {
  const key = storageKey(surface, eventId);
  const trimmed = messages.slice(-MAX_MESSAGES).map((m) => ({
    id: m.id,
    role: m.role,
    text: m.text,
    createdAt: m.createdAt,
  }));
  await AsyncStorage.setItem(key, JSON.stringify(trimmed));
}

export async function loadHistory(
  surface: Surface,
  eventId: number | null,
): Promise<ChatMessage[]> {
  const key = storageKey(surface, eventId);
  const raw = await AsyncStorage.getItem(key);
  if (!raw) return [];
  try {
    return JSON.parse(raw) as ChatMessage[];
  } catch {
    return [];
  }
}

export async function clearHistory(
  surface: Surface,
  eventId: number | null,
): Promise<void> {
  await AsyncStorage.removeItem(storageKey(surface, eventId));
}

export async function clearAllHistory(): Promise<void> {
  const keys = await AsyncStorage.getAllKeys();
  const chatKeys = keys.filter((k) => k.startsWith(PREFIX));
  if (chatKeys.length > 0) await AsyncStorage.multiRemove(chatKeys);
}
