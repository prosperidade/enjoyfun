import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { ToolCallSummary } from '@/lib/types';

/** Human-readable labels for known tool names */
const TOOL_LABELS: Record<string, string> = {
  get_event_snapshot: 'Consultando dados do evento',
  get_sales_summary: 'Consultando vendas',
  get_parking_live_snapshot: 'Consultando estacionamento',
  get_workforce_status: 'Consultando equipe',
  get_financial_overview: 'Consultando financeiro',
  get_meal_service_status: 'Consultando refei\u00e7\u00f5es',
  get_ticket_stats: 'Consultando ingressos',
  get_card_stats: 'Consultando cart\u00f5es',
  get_artist_lineup: 'Consultando lineup',
  get_event_timeline: 'Consultando cronograma',
  search_documents: 'Pesquisando documentos',
  read_file_excerpt: 'Lendo arquivo',
  get_dashboard_kpis: 'Consultando KPIs',
  get_checkin_presence: 'Consultando presen\u00e7a',
  semantic_search: 'Busca sem\u00e2ntica',
  hybrid_search: 'Busca inteligente',
  recall_memories: 'Consultando mem\u00f3ria',
  get_messaging_stats: 'Consultando mensagens',
};

function labelForTool(toolName: string): string {
  if (TOOL_LABELS[toolName]) return TOOL_LABELS[toolName];
  // Fallback: humanize snake_case → "Get event snapshot"
  const words = toolName.replace(/_/g, ' ');
  return words.charAt(0).toUpperCase() + words.slice(1);
}

interface Props {
  tools: ToolCallSummary[];
}

export function ToolCallBadge({ tools }: Props) {
  if (!tools.length) return null;

  return (
    <View style={styles.container}>
      {tools.map((tc, idx) => {
        const label = labelForTool(tc.tool);
        const durationLabel = tc.duration_ms != null ? ` (${tc.duration_ms}ms)` : '';
        return (
          <View key={`${tc.tool}-${idx}`} style={[styles.badge, !tc.ok && styles.badgeError]}>
            <Text style={styles.icon}>{tc.ok ? '\u2699\uFE0F' : '\u26A0\uFE0F'}</Text>
            <Text style={[styles.label, !tc.ok && styles.labelError]} numberOfLines={1}>
              {label}{durationLabel}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.xs,
    marginBottom: spacing.sm,
  },
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.accentMuted,
    borderRadius: radius.full,
    paddingHorizontal: spacing.sm,
    paddingVertical: 3,
    gap: 4,
  },
  badgeError: {
    backgroundColor: 'rgba(248, 113, 113, 0.15)',
  },
  icon: {
    fontSize: 11,
  },
  label: {
    ...typography.caption,
    fontSize: 11,
    color: colors.accent,
  },
  labelError: {
    color: colors.severity.critical,
  },
});
