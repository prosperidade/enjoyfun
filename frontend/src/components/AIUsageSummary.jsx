import { useState, useEffect } from 'react';
import { Zap } from 'lucide-react';
import api from '../lib/api';

/**
 * Simple progress bar showing monthly AI usage/spending.
 * Fetches from the existing billing stats endpoint.
 */
export default function AIUsageSummary() {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        // Reuse the existing AI config endpoint which includes billing data
        const { data } = await api.get('/organizer-ai-config');
        if (!cancelled && data?.data) {
          setStats({
            current: data.data.billing?.month_total_brl || 0,
            cap: data.data.billing?.spending_cap_brl || 500,
            requests: data.data.billing?.month_requests || 0,
          });
        }
      } catch {
        // Silent fail — billing is non-critical info
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, []);

  if (loading || !stats) return null;

  const pct = stats.cap > 0 ? Math.min(100, (stats.current / stats.cap) * 100) : 0;
  const isWarning = pct > 80;
  const isCritical = pct > 95;

  return (
    <div className="bg-gray-800/40 border border-gray-700/40 rounded-xl p-4">
      <div className="flex items-center justify-between mb-2">
        <div className="flex items-center gap-2">
          <Zap size={14} className="text-purple-400" />
          <span className="text-xs font-medium text-gray-300">Uso da IA este mes</span>
        </div>
        <span className={`text-xs font-bold ${isCritical ? 'text-red-400' : isWarning ? 'text-amber-400' : 'text-purple-400'}`}>
          R$ {stats.current.toFixed(2)}
        </span>
      </div>

      <div className="w-full bg-gray-700/50 rounded-full h-2 overflow-hidden">
        <div
          className={`h-full rounded-full transition-all duration-500 ${
            isCritical ? 'bg-red-500' : isWarning ? 'bg-amber-500' : 'bg-purple-500'
          }`}
          style={{ width: `${pct}%` }}
        />
      </div>

      <div className="flex items-center justify-between mt-1.5">
        <span className="text-[10px] text-gray-500">{stats.requests} consultas</span>
        <span className="text-[10px] text-gray-500">Limite: R$ {stats.cap.toFixed(0)}</span>
      </div>
    </div>
  );
}
