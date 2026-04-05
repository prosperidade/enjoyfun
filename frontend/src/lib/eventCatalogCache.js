const EVENT_CATALOG_CACHE_PREFIX = "enjoyfun_event_catalog_v1";
const EVENT_CATALOG_LEGACY_KEY = "enjoyfun_event_catalog_v1";

function canUseStorage() {
  return typeof window !== "undefined" && Boolean(window.localStorage);
}

function resolveCacheKey(eventId) {
  if (eventId && Number(eventId) > 0) {
    return `${EVENT_CATALOG_CACHE_PREFIX}_${eventId}`;
  }
  return EVENT_CATALOG_CACHE_PREFIX;
}

export function readEventCatalogCache(eventId) {
  if (!canUseStorage()) {
    return { data: [], savedAt: null };
  }

  try {
    const key = resolveCacheKey(eventId);
    let raw = window.localStorage.getItem(key);

    // Backward compatibility: try the legacy unscoped key if scoped key is empty
    if (!raw && eventId && key !== EVENT_CATALOG_LEGACY_KEY) {
      raw = window.localStorage.getItem(EVENT_CATALOG_LEGACY_KEY);
      if (raw) {
        // Migrate legacy data to scoped key and remove legacy entry
        window.localStorage.setItem(key, raw);
        window.localStorage.removeItem(EVENT_CATALOG_LEGACY_KEY);
      }
    }

    if (!raw) {
      return { data: [], savedAt: null };
    }

    const parsed = JSON.parse(raw);
    return {
      data: Array.isArray(parsed?.data) ? parsed.data : [],
      savedAt: parsed?.saved_at || null,
    };
  } catch {
    return { data: [], savedAt: null };
  }
}

export function writeEventCatalogCache(events = [], eventId) {
  if (!canUseStorage()) {
    return;
  }

  try {
    const key = resolveCacheKey(eventId);
    window.localStorage.setItem(
      key,
      JSON.stringify({
        data: Array.isArray(events) ? events : [],
        saved_at: new Date().toISOString(),
      })
    );
  } catch {
    // Cache local é best-effort.
  }
}
