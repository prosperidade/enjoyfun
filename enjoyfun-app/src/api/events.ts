import { apiClient } from './client';

export interface EventSummary {
  id: number;
  name: string;
  start_at?: string;
  end_at?: string;
  venue_name?: string;
  status?: string;
}

interface ListEventsResponse {
  data?: { events?: EventSummary[] } | EventSummary[];
}

export async function listEvents(): Promise<EventSummary[]> {
  try {
    const { data } = await apiClient.get<ListEventsResponse>('/events');
    const body = data?.data;
    if (Array.isArray(body)) return body;
    if (body && Array.isArray(body.events)) return body.events;
    return [];
  } catch {
    return [];
  }
}
