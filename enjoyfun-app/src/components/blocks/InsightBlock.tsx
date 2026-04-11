import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { InsightBlock as InsightBlockType } from '@/lib/types';

interface Props {
  block: InsightBlockType;
}

function severityColor(sev?: InsightBlockType['severity']): string {
  switch (sev) {
    case 'success':
      return colors.severity.success;
    case 'warn':
      return colors.severity.warn;
    case 'critical':
      return colors.severity.critical;
    case 'info':
    default:
      return colors.severity.info;
  }
}

export function InsightBlock({ block }: Props) {
  const accent = severityColor(block.severity);
  return (
    <View style={[styles.container, { borderLeftColor: accent }]}>
      <Text style={styles.title}>{block.title}</Text>
      <Text style={styles.body}>{block.body}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    borderLeftWidth: 4,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  title: {
    ...typography.h3,
    marginBottom: spacing.xs,
  },
  body: {
    ...typography.bodyMuted,
    lineHeight: 20,
  },
});
