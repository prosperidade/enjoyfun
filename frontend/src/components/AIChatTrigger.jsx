import { Sparkles } from 'lucide-react';

/**
 * AIChatTrigger
 *
 * Replaces the legacy embedded AI assistants (ParkingAIAssistant,
 * ArtistAIAssistant, WorkforceAIAssistant). Dispatches a custom event
 * `enjoyfun:open-ai-chat` that UnifiedAIChat listens to, opening the
 * floating chat with the right context pre-filled.
 *
 * Props:
 *  - label?: string                — button label (default "Perguntar a IA")
 *  - title?: string                — heading shown above the button
 *  - description?: string          — short description below the heading
 *  - agentKey?: string             — pre-select an agent (e.g. "logistics")
 *  - surface?: string              — override the auto-detected surface
 *  - prefill?: string              — text to put in the input box
 *  - context?: object              — extra context merged into the chat payload
 *  - variant?: 'card' | 'inline'   — visual variant (default 'card')
 */
export default function AIChatTrigger({
  label = 'Perguntar a IA',
  title,
  description,
  agentKey,
  surface,
  prefill,
  context,
  variant = 'card',
}) {
  const open = () => {
    window.dispatchEvent(
      new CustomEvent('enjoyfun:open-ai-chat', {
        detail: {
          agent_key: agentKey,
          surface,
          prefill,
          context,
        },
      })
    );
  };

  if (variant === 'inline') {
    return (
      <button
        onClick={open}
        className="inline-flex items-center gap-2 px-4 py-2 bg-purple-600/20 hover:bg-purple-600/30 text-purple-300 hover:text-purple-200 text-sm font-medium rounded-lg border border-purple-700/30 transition-colors"
      >
        <Sparkles size={16} />
        {label}
      </button>
    );
  }

  return (
    <div className="bg-gradient-to-br from-purple-900/20 to-pink-900/10 border border-purple-800/30 rounded-2xl p-5">
      <div className="flex items-start gap-4">
        <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-lg shadow-purple-900/30 flex-shrink-0">
          <Sparkles size={20} className="text-white" />
        </div>
        <div className="flex-1">
          <h3 className="text-sm font-semibold text-white mb-1">
            {title || 'Assistente EnjoyFun'}
          </h3>
          {description && (
            <p className="text-xs text-gray-400 mb-3 leading-relaxed">{description}</p>
          )}
          <button
            onClick={open}
            className="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold rounded-lg transition-colors shadow-md shadow-purple-900/30"
          >
            <Sparkles size={14} />
            {label}
          </button>
        </div>
      </div>
    </div>
  );
}
