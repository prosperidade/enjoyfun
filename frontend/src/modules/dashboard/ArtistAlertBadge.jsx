import { useEffect, useState } from "react";
import { AlertTriangle, Siren } from "lucide-react";
import api from "../../lib/api";
import SectionHeader from "./SectionHeader";
import StatCard from "./StatCard";

function getTotal(response) {
  return Number(response?.data?.meta?.total || 0);
}

export default function ArtistAlertBadge({ eventId }) {
  const [counts, setCounts] = useState({ critical: 0, tight: 0 });
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!eventId) {
      setCounts({ critical: 0, tight: 0 });
      return;
    }

    setLoading(true);
    Promise.all([
      api.get("/artists/alerts", {
        params: { event_id: eventId, severity: "critical", status: "active", page: 1, per_page: 1 },
      }),
      api.get("/artists/alerts", {
        params: { event_id: eventId, severity: "high", status: "active", page: 1, per_page: 1 },
      }),
    ])
      .then(([criticalRes, tightRes]) => {
        setCounts({
          critical: getTotal(criticalRes),
          tight: getTotal(tightRes),
        });
      })
      .catch(() => {
        setCounts({ critical: 0, tight: 0 });
      })
      .finally(() => setLoading(false));
  }, [eventId]);

  if (!eventId) {
    return null;
  }

  return (
    <div className="space-y-6 border-t border-gray-800 pt-6">
      <SectionHeader
        icon={Siren}
        title="Alertas de Artistas"
        badge="Hub Artistas"
        iconClassName="text-rose-400"
        badgeClassName="bg-rose-500/15 text-rose-300"
        description="Leitura rápida das janelas críticas e de atenção do lineup do evento selecionado."
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <StatCard
          compact
          loading={loading}
          icon={Siren}
          label="Críticos"
          value={counts.critical.toLocaleString("pt-BR")}
          color="bg-red-600"
          subtitle="Alertas vermelhos ativos"
          to="/artists?severity=red"
          scopeEventId={eventId}
        />
        <StatCard
          compact
          loading={loading}
          icon={AlertTriangle}
          label="Atenção"
          value={counts.tight.toLocaleString("pt-BR")}
          color="bg-amber-600"
          subtitle="Alertas laranja ativos"
          to="/artists?severity=orange"
          scopeEventId={eventId}
        />
      </div>
    </div>
  );
}
