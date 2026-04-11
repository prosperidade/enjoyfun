import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import api from "../lib/api";
import { useAuth } from "./AuthContext";

const EventScopeContext = createContext(null);

const EVENT_SCOPE_STORAGE_KEY = "enjoyfun_selected_event_id";

function normalizeEventId(value) {
  const normalized = String(value ?? "").trim();
  if (!normalized) {
    return "";
  }

  const numericValue = Number(normalized);
  if (!Number.isFinite(numericValue) || numericValue <= 0) {
    return "";
  }

  return String(numericValue);
}

function isEventScopedPath(pathname = "") {
  return (
    pathname === "/" ||
    pathname.startsWith("/analytics") ||
    pathname.startsWith("/artists") ||
    pathname.startsWith("/finance") ||
    pathname.startsWith("/tickets") ||
    pathname.startsWith("/participants") ||
    pathname.startsWith("/cards") ||
    pathname.startsWith("/scanner") ||
    pathname.startsWith("/parking") ||
    pathname.startsWith("/guests") ||
    pathname.startsWith("/meals-control") ||
    pathname.startsWith("/bar") ||
    pathname.startsWith("/food") ||
    pathname.startsWith("/shop")
  );
}

function buildSearchWithEventId(search = "", eventId = "") {
  const params = new URLSearchParams(search);
  const normalizedEventId = normalizeEventId(eventId);

  if (normalizedEventId) {
    params.set("event_id", normalizedEventId);
  } else {
    params.delete("event_id");
  }

  const nextSearch = params.toString();
  return nextSearch ? `?${nextSearch}` : "";
}

function splitToParts(to) {
  const [pathnameAndSearch, hashPart = ""] = String(to || "").split("#");
  const [pathnamePart = "", searchPart = ""] = pathnameAndSearch.split("?");

  return {
    pathname: pathnamePart || "",
    search: searchPart ? `?${searchPart}` : "",
    hash: hashPart ? `#${hashPart}` : "",
  };
}

function readStoredEventId() {
  if (typeof window === "undefined" || !window.sessionStorage) {
    return "";
  }

  try {
    return normalizeEventId(window.sessionStorage.getItem(EVENT_SCOPE_STORAGE_KEY));
  } catch {
    return "";
  }
}

function writeStoredEventId(eventId) {
  if (typeof window === "undefined" || !window.sessionStorage) {
    return;
  }

  try {
    if (eventId) {
      window.sessionStorage.setItem(EVENT_SCOPE_STORAGE_KEY, eventId);
    } else {
      window.sessionStorage.removeItem(EVENT_SCOPE_STORAGE_KEY);
    }
  } catch {
    // noop
  }
}

export function EventScopeProvider({ children }) {
  const location = useLocation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(false);
  const [eventId, setEventIdState] = useState(() => {
    if (typeof window === "undefined") {
      return "";
    }

    const routeEventId = normalizeEventId(
      new URLSearchParams(window.location.search).get("event_id")
    );

    return routeEventId || readStoredEventId();
  });

  // Load events list once authenticated. Events list drives the header dropdown
  // and the auto-select default so the AI chat never falls back to aggregated
  // (empty) metrics on the dashboard.
  useEffect(() => {
    if (!user) {
      setEvents([]);
      return;
    }
    let cancelled = false;
    setEventsLoading(true);
    api
      .get("/events", { params: { per_page: 100 } })
      .then((r) => {
        if (cancelled) return;
        const payload = r?.data?.data;
        const list = Array.isArray(payload)
          ? payload
          : Array.isArray(payload?.events)
            ? payload.events
            : [];
        setEvents(list);
        if (!eventId && list.length > 0) {
          const firstId = normalizeEventId(list[0].id);
          if (firstId) {
            setEventIdState(firstId);
            writeStoredEventId(firstId);
          }
        }
      })
      .catch(() => {
        if (!cancelled) setEvents([]);
      })
      .finally(() => {
        if (!cancelled) setEventsLoading(false);
      });
    return () => { cancelled = true; };
    // eventId intentionally excluded — we only auto-select on first load
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user]);

  const activeEvent = useMemo(
    () => events.find((e) => String(e.id) === String(eventId)) ?? null,
    [events, eventId],
  );

  const setEventId = useCallback((value, options = {}) => {
    const nextEventId = normalizeEventId(value);
    setEventIdState(nextEventId);
    writeStoredEventId(nextEventId);

    if (options.updateUrl === false || !isEventScopedPath(location.pathname)) {
      return;
    }

    const nextSearch = buildSearchWithEventId(location.search, nextEventId);
    if (nextSearch === location.search) {
      return;
    }

    navigate(
      {
        pathname: location.pathname,
        search: nextSearch,
        hash: location.hash,
      },
      { replace: options.replace ?? true }
    );
  }, [location.hash, location.pathname, location.search, navigate]);

  useEffect(() => {
    const routeEventId = normalizeEventId(
      new URLSearchParams(location.search).get("event_id")
    );

    if (routeEventId) {
      if (routeEventId !== eventId) {
        setEventIdState(routeEventId);
      }
      writeStoredEventId(routeEventId);
      return;
    }

    if (!eventId) {
      writeStoredEventId("");
      return;
    }

    writeStoredEventId(eventId);

    if (!isEventScopedPath(location.pathname)) {
      return;
    }

    const nextSearch = buildSearchWithEventId(location.search, eventId);
    if (nextSearch === location.search) {
      return;
    }

    navigate(
      {
        pathname: location.pathname,
        search: nextSearch,
        hash: location.hash,
      },
      { replace: true }
    );
  }, [eventId, location.hash, location.pathname, location.search, navigate]);

  const buildScopedPath = useCallback((to, overrideEventId = eventId) => {
    if (typeof to !== "string" || !to) {
      return to;
    }

    if (/^(https?:)?\/\//i.test(to)) {
      return to;
    }

    const { pathname, search, hash } = splitToParts(to);
    if (!isEventScopedPath(pathname)) {
      return to;
    }

    return `${pathname}${buildSearchWithEventId(search, overrideEventId)}${hash}`;
  }, [eventId]);

  const value = useMemo(() => ({
    buildScopedPath,
    eventId,
    hasEventId: Boolean(eventId),
    isEventScopedPath,
    setEventId,
    events,
    eventsLoading,
    activeEvent,
  }), [buildScopedPath, eventId, setEventId, events, eventsLoading, activeEvent]);

  return (
    <EventScopeContext.Provider value={value}>
      {children}
    </EventScopeContext.Provider>
  );
}

export function useEventScope() {
  const context = useContext(EventScopeContext);

  if (!context) {
    throw new Error("useEventScope must be used within EventScopeProvider.");
  }

  return context;
}
