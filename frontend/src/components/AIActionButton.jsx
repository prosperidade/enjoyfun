import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { resolveAction } from '../lib/aiActionCatalog';

/**
 * AIActionButton
 *
 * Inline clickable button rendered from `[action_key]` tags inside AI
 * chat responses. Uses the frontend mirror of AIActionCatalogService
 * (loaded via `loadCatalog()`) to resolve the action_key + params into
 * a concrete route, then navigates via react-router when clicked.
 *
 * When the catalog is not yet loaded or the key is unknown, renders a
 * small neutral pill as a fallback so the text stays readable.
 */
export default function AIActionButton({ actionKey, params = {} }) {
  const navigate = useNavigate();

  const resolved = useMemo(() => resolveAction(actionKey, params), [actionKey, params]);

  if (!resolved) {
    // Catalog not loaded yet, or missing required params. Render the raw
    // label so the response is still legible even if the button fails.
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-800/60 border border-gray-700/40 text-[11px] text-gray-400 font-mono">
        {actionKey}
      </span>
    );
  }

  const handleClick = (e) => {
    e.preventDefault();
    e.stopPropagation();
    const url = resolved.url || '';
    if (url.startsWith('http://') || url.startsWith('https://')) {
      window.open(url, '_blank', 'noopener,noreferrer');
      return;
    }
    navigate(url);
  };

  return (
    <button
      type="button"
      onClick={handleClick}
      title={resolved.description || resolved.label}
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-purple-600/20 hover:bg-purple-600/30 border border-purple-700/40 hover:border-purple-600/60 text-[11px] font-medium text-purple-300 hover:text-purple-200 transition-colors cursor-pointer align-middle"
    >
      <ArrowRight size={11} />
      {resolved.cta_label || resolved.label}
    </button>
  );
}
