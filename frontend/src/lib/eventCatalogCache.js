const EVENT_CATALOG_CACHE_KEY = "enjoyfun_event_catalog_v1";

function canUseStorage() {
  return typeof window !== "undefined" && Boolean(window.localStorage);
}

export function readEventCatalogCache() {
  if (!canUseStorage()) {
    return { data: [], savedAt: null };
  }

  try {
    const raw = window.localStorage.getItem(EVENT_CATALOG_CACHE_KEY);
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

export function writeEventCatalogCache(events = []) {
  if (!canUseStorage()) {
    return;
  }

  try {
    window.localStorage.setItem(
      EVENT_CATALOG_CACHE_KEY,
      JSON.stringify({
        data: Array.isArray(events) ? events : [],
        saved_at: new Date().toISOString(),
      })
    );
  } catch {
    // Cache local é best-effort.
  }
}
