import api from './api';

/**
 * AI Action Catalog — frontend mirror of backend AIActionCatalogService.
 *
 * Fetches the catalog once per session (cached in module scope), resolves
 * action keys to concrete {url, cta_label} objects that AIActionButton
 * can render as clickable navigation.
 *
 * Usage:
 *   import { resolveAction, loadCatalog } from '@/lib/aiActionCatalog';
 *
 *   await loadCatalog();                                   // once, on app boot
 *   const action = resolveAction('open_promo_batch', {    // anywhere
 *     event_id: 1,
 *   });
 *   // => { action_key, label, cta_label, url, description }
 */

let catalogCache = null;
let loadPromise = null;

/**
 * Load the catalog from /api/ai/actions. Cached in module scope for the
 * lifetime of the tab. Safe to call multiple times — subsequent calls
 * return the same promise.
 */
export async function loadCatalog() {
  if (catalogCache) return catalogCache;
  if (loadPromise) return loadPromise;

  loadPromise = (async () => {
    try {
      const { data } = await api.get('/ai/actions');
      const actions = data?.data?.actions || [];
      catalogCache = indexByKey(actions);
      return catalogCache;
    } catch (err) {
      // Fail silently — buttons will just not render.
      // Console warning helps debugging without breaking the chat.
      // eslint-disable-next-line no-console
      console.warn('[aiActionCatalog] failed to load catalog:', err?.message);
      catalogCache = {};
      return catalogCache;
    } finally {
      loadPromise = null;
    }
  })();

  return loadPromise;
}

function indexByKey(actions) {
  const out = {};
  for (const action of actions) {
    if (action?.action_key) {
      out[action.action_key] = action;
    }
  }
  return out;
}

/**
 * Resolve an action key + params to a concrete navigable action.
 * Returns null when the key is unknown or required params are missing.
 *
 * @param {string} key — action_key from the catalog
 * @param {object} params — key-value map for {placeholder} substitution
 */
export function resolveAction(key, params = {}) {
  if (!catalogCache) return null;
  const entry = catalogCache[key];
  if (!entry) return null;

  const required = Array.isArray(entry.required_params) ? entry.required_params : [];
  for (const p of required) {
    if (params[p] === undefined || params[p] === null || params[p] === '') {
      return null;
    }
  }

  let url = entry.action_url || '';
  url = url.replace(/\{([a-zA-Z0-9_]+)\}/g, (_, name) => {
    const value = params[name];
    return value !== undefined && value !== null ? encodeURIComponent(String(value)) : '';
  });
  // Reject the result if any placeholder remained unfilled
  if (/\{[a-zA-Z0-9_]+\}/.test(url)) {
    return null;
  }

  return {
    action_key: entry.action_key,
    label: entry.label,
    cta_label: entry.cta_label,
    url,
    description: entry.description,
  };
}

/**
 * Synchronous: returns the cached catalog if already loaded, or null.
 * Useful for components that need to peek without triggering a fetch.
 */
export function getCachedCatalog() {
  return catalogCache;
}

/**
 * Test helper: reset the cache so tests can reload fresh.
 */
export function __resetCatalogCache() {
  catalogCache = null;
  loadPromise = null;
}
