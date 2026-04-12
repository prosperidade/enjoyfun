import EventSource from 'react-native-sse';
import { getToken } from '@/lib/auth';

const DEFAULT_BASE = 'http://localhost:8080/api';
const BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? DEFAULT_BASE;

export type StreamEventType = 'token' | 'tool_start' | 'tool_done' | 'block' | 'done' | 'error';

export interface StreamOptions {
  sessionId: string;
  onToken?: (text: string) => void;
  onToolStart?: (toolName: string) => void;
  onToolDone?: (toolName: string, durationMs: number, ok: boolean) => void;
  onBlock?: (block: Record<string, unknown>) => void;
  onDone?: () => void;
  onError?: (error: string) => void;
}

function parseData(event: unknown): string | null {
  if (event && typeof event === 'object' && 'data' in event) {
    const d = (event as { data?: string | null }).data;
    return typeof d === 'string' ? d : null;
  }
  return null;
}

export function connectStream(opts: StreamOptions): () => void {
  let closed = false;
  let esRef: EventSource | null = null;

  const cleanup = () => {
    closed = true;
    esRef?.close();
  };

  (async () => {
    const token = await getToken();
    if (closed) return;

    const url = `${BASE_URL}/ai/chat/stream?session_id=${encodeURIComponent(opts.sessionId)}`;

    const es = new EventSource<'token' | 'tool_start' | 'tool_done' | 'block' | 'done'>(url, {
      headers: {
        Authorization: token ? `Bearer ${token}` : '',
        'X-Client': 'mobile',
      },
    });
    esRef = es;

    es.addEventListener('token', (event) => {
      if (closed) return;
      const raw = parseData(event);
      if (!raw) return;
      try {
        const parsed = JSON.parse(raw) as { text?: string };
        if (parsed.text) opts.onToken?.(parsed.text);
      } catch { /* skip malformed */ }
    });

    es.addEventListener('tool_start', (event) => {
      if (closed) return;
      const raw = parseData(event);
      if (!raw) return;
      try {
        const parsed = JSON.parse(raw) as { tool?: string };
        if (parsed.tool) opts.onToolStart?.(parsed.tool);
      } catch { /* skip */ }
    });

    es.addEventListener('tool_done', (event) => {
      if (closed) return;
      const raw = parseData(event);
      if (!raw) return;
      try {
        const parsed = JSON.parse(raw) as { tool?: string; duration_ms?: number; ok?: boolean };
        opts.onToolDone?.(parsed.tool ?? '', parsed.duration_ms ?? 0, parsed.ok ?? true);
      } catch { /* skip */ }
    });

    es.addEventListener('block', (event) => {
      if (closed) return;
      const raw = parseData(event);
      if (!raw) return;
      try {
        const parsed = JSON.parse(raw) as Record<string, unknown>;
        opts.onBlock?.(parsed);
      } catch { /* skip */ }
    });

    es.addEventListener('done', () => {
      if (!closed) opts.onDone?.();
      es.close();
    });

    es.addEventListener('error', (event) => {
      if (closed) return;
      const msg = typeof event === 'object' && event !== null && 'message' in event
        ? String((event as { message?: string }).message)
        : 'Stream error';
      opts.onError?.(msg);
      es.close();
    });
  })();

  return cleanup;
}
