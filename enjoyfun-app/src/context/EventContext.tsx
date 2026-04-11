import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { listEvents, type EventSummary } from '@/api/events';

interface EventContextValue {
  activeEvent: EventSummary | null;
  events: EventSummary[];
  loading: boolean;
  refresh: () => Promise<void>;
  selectEvent: (event: EventSummary) => Promise<void>;
}

const STORAGE_KEY = 'active_event_id';
const EventContext = createContext<EventContextValue | null>(null);

export function EventProvider({ children }: { children: React.ReactNode }) {
  const [events, setEvents] = useState<EventSummary[]>([]);
  const [activeEvent, setActiveEvent] = useState<EventSummary | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const list = await listEvents();
      setEvents(list);
      const storedId = await SecureStore.getItemAsync(STORAGE_KEY);
      const picked =
        (storedId && list.find((e) => String(e.id) === storedId)) ||
        list[0] ||
        null;
      setActiveEvent(picked);
    } finally {
      setLoading(false);
    }
  }, []);

  const selectEvent = useCallback(async (event: EventSummary) => {
    setActiveEvent(event);
    await SecureStore.setItemAsync(STORAGE_KEY, String(event.id));
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const value = useMemo<EventContextValue>(
    () => ({ activeEvent, events, loading, refresh, selectEvent }),
    [activeEvent, events, loading, refresh, selectEvent],
  );

  return <EventContext.Provider value={value}>{children}</EventContext.Provider>;
}

export function useEvent(): EventContextValue {
  const ctx = useContext(EventContext);
  if (!ctx) throw new Error('useEvent must be used within an EventProvider');
  return ctx;
}
