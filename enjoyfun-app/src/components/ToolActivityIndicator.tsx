import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, typography } from '@/theme';
import { t } from '@/lib/i18n';
import type { ToolCallSummary } from '@/lib/aiSession';

function labelForTool(toolName: string): string {
  const prefixes: [string, string][] = [
    ['get_bar_sales', 'tool_prefix_get_bar_sales'],
    ['get_pos_sales', 'tool_prefix_get_pos_sales'],
    ['get_stock', 'tool_prefix_get_stock'],
    ['get_parking', 'tool_prefix_get_parking'],
    ['get_artist', 'tool_prefix_get_artist'],
    ['get_workforce', 'tool_prefix_get_workforce'],
    ['get_shift', 'tool_prefix_get_shift'],
    ['get_event_kpi', 'tool_prefix_get_event_kpi'],
    ['get_finance', 'tool_prefix_get_finance'],
    ['get_supplier', 'tool_prefix_get_supplier'],
    ['get_ticket', 'tool_prefix_get_ticket'],
    ['find_events', 'tool_prefix_find_events'],
    ['read_organizer', 'tool_prefix_read_organizer'],
    ['search_documents', 'tool_prefix_search_documents'],
    ['list_documents', 'tool_prefix_list_documents'],
  ];
  for (const [prefix, key] of prefixes) {
    if (toolName.startsWith(prefix)) return t(key);
  }
  return t('tool_prefix_default');
}

interface Props {
  loading?: boolean;
  toolCallsSummary?: ToolCallSummary[];
}

export function ToolActivityIndicator({ loading, toolCallsSummary }: Props) {
  if (loading && (!toolCallsSummary || toolCallsSummary.length === 0)) {
    return (
      <View style={styles.container}>
        <ActivityIndicator size="small" color={colors.accent} />
        <Text style={styles.label}>{t('tool_searching')}</Text>
      </View>
    );
  }

  if (!toolCallsSummary || toolCallsSummary.length === 0) return null;

  return (
    <View style={styles.container}>
      {toolCallsSummary.map((tc, i) => (
        <View key={`${tc.tool}-${i}`} style={styles.row}>
          {tc.ok === undefined ? (
            <ActivityIndicator size="small" color={colors.accent} />
          ) : (
            <Text style={[styles.status, tc.ok ? styles.ok : styles.err]}>
              {tc.ok ? '\u2713' : '\u2717'}
            </Text>
          )}
          <Text style={styles.label}>{labelForTool(tc.tool)}</Text>
          {tc.duration_ms != null && (
            <Text style={styles.duration}>{tc.duration_ms}ms</Text>
          )}
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    gap: spacing.xs,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  label: {
    ...typography.caption,
    color: colors.textSecondary,
    flex: 1,
  },
  status: {
    fontSize: 14,
    fontWeight: '700',
    width: 18,
    textAlign: 'center',
  },
  ok: { color: colors.severity.success },
  err: { color: colors.severity.critical },
  duration: {
    ...typography.caption,
    color: colors.textMuted,
  },
});
