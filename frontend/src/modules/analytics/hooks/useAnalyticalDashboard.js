import { useEffect, useState } from "react";
import { useEventScope } from "../../../context/EventScopeContext";
import api from "../../../lib/api";

export function useAnalyticalDashboard() {
  const { eventId, setEventId: setScopedEventId } = useEventScope();
  const [analytics, setAnalytics] = useState(null);
  const [error, setError] = useState("");
  const [events, setEvents] = useState([]);
  const [compareEventId, setCompareEventId] = useState("");
  const [groupBy, setGroupBy] = useState("hour");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let isMounted = true;

    api
      .get("/events")
      .then((response) => {
        if (isMounted) {
          setEvents(response.data?.data || []);
        }
      })
      .catch((error) => {
        console.error("Erro ao carregar eventos do analitico", error);
      });

    return () => {
      isMounted = false;
    };
  }, []);

  useEffect(() => {
    let isMounted = true;

    const fetchAnalytics = async () => {
      setLoading(true);

      try {
        setError("");
        const query = new URLSearchParams();
        if (eventId) {
          query.set("event_id", eventId);
        }
        if (groupBy) {
          query.set("group_by", groupBy);
        }
        if (compareEventId) {
          query.set("compare_event_id", compareEventId);
        }

        const suffix = query.toString() ? `?${query.toString()}` : "";
        const response = await api.get(`/analytics/dashboard${suffix}`);
        if (isMounted) {
          setAnalytics(response.data?.data || null);
        }
      } catch (error) {
        console.error("Erro ao carregar dashboard analitico", error);
        if (isMounted) {
          setError("Nao foi possivel carregar a leitura analitica com a base atual.");
          setAnalytics(null);
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    fetchAnalytics();

    return () => {
      isMounted = false;
    };
  }, [compareEventId, eventId, groupBy]);

  const handleEventChange = (nextEventId) => {
    setScopedEventId(nextEventId);
    setCompareEventId((currentCompareEventId) => {
      if (!nextEventId || String(currentCompareEventId) === String(nextEventId)) {
        return "";
      }

      return currentCompareEventId;
    });
  };

  return {
    analytics,
    compareEventId,
    error,
    eventId,
    events,
    groupBy,
    loading,
    setCompareEventId,
    setEventId: handleEventChange,
    setGroupBy,
  };
}
