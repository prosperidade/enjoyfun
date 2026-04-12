import React, { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import type {
  AdaptiveResponseV3,
  ApprovalRequest,
  EvidenceItem,
  Surface,
  ToolCallSummary,
} from '@/lib/aiSession';

export interface SessionResponseMeta {
  agentUsed?: string;
  routingTraceId?: string;
  toolCallsSummary?: ToolCallSummary[];
  evidence?: EvidenceItem[];
  approvalRequest?: ApprovalRequest | null;
  receivedAt: number;
}

export interface SessionRecord {
  surface: Surface;
  eventId: number | null;
  sessionId: string;
  lastResponseMeta?: SessionResponseMeta;
}

interface AISessionContextValue {
  getSession: (surface: Surface, eventId: number | null) => SessionRecord | undefined;
  recordResponse: (
    surface: Surface,
    eventId: number | null,
    response: AdaptiveResponseV3,
  ) => void;
  archiveSurface: (surface: Surface, eventId: number | null) => void;
  archiveEvent: (eventId: number | null) => void;
  archiveAll: () => void;
}

const AISessionContext = createContext<AISessionContextValue | null>(null);

function makeKey(surface: Surface, eventId: number | null): string {
  return `${surface}:${eventId ?? 'null'}`;
}

export function AISessionProvider({ children }: { children: React.ReactNode }) {
  const sessionsRef = useRef<Map<string, SessionRecord>>(new Map());
  const [version, setVersion] = useState(0);
  const bump = useCallback(() => setVersion((v) => v + 1), []);

  const getSession = useCallback(
    (surface: Surface, eventId: number | null) => sessionsRef.current.get(makeKey(surface, eventId)),
    // version dep ensures consumers re-read after mutations
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [version],
  );

  const recordResponse = useCallback(
    (surface: Surface, eventId: number | null, response: AdaptiveResponseV3) => {
      const key = makeKey(surface, eventId);
      const meta: SessionResponseMeta = {
        agentUsed: response.agent_used,
        routingTraceId: response.routing_trace_id,
        toolCallsSummary: response.tool_calls_summary,
        evidence: response.evidence,
        approvalRequest: response.approval_request ?? null,
        receivedAt: Date.now(),
      };
      sessionsRef.current.set(key, {
        surface,
        eventId,
        sessionId: response.session_id,
        lastResponseMeta: meta,
      });
      bump();
    },
    [bump],
  );

  const archiveSurface = useCallback(
    (surface: Surface, eventId: number | null) => {
      sessionsRef.current.delete(makeKey(surface, eventId));
      bump();
    },
    [bump],
  );

  const archiveEvent = useCallback(
    (eventId: number | null) => {
      const target = String(eventId ?? 'null');
      for (const key of Array.from(sessionsRef.current.keys())) {
        if (key.endsWith(`:${target}`)) sessionsRef.current.delete(key);
      }
      bump();
    },
    [bump],
  );

  const archiveAll = useCallback(() => {
    sessionsRef.current.clear();
    bump();
  }, [bump]);

  const value = useMemo<AISessionContextValue>(
    () => ({ getSession, recordResponse, archiveSurface, archiveEvent, archiveAll }),
    [getSession, recordResponse, archiveSurface, archiveEvent, archiveAll],
  );

  return <AISessionContext.Provider value={value}>{children}</AISessionContext.Provider>;
}

export function useAISession(): AISessionContextValue {
  const ctx = useContext(AISessionContext);
  if (!ctx) throw new Error('useAISession must be used within an AISessionProvider');
  return ctx;
}
