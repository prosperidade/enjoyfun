import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { TimelineBlock as TimelineBlockType, TimelineStatus } from '@/lib/types';

interface Props {
  block: TimelineBlockType;
}

const statusColor: Record<TimelineStatus, string> = {
  upcoming: colors.accent,
  done: colors.severity.success,
  cancelled: colors.textMuted,
};

function formatDateTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d);
}

export function TimelineBlock({ block }: Props) {
  const events = block.events ?? [];
  if (events.length === 0) return null;

  return (
    <View style={styles.container}>
      {block.title ? <Text style={styles.title}>{block.title}</Text> : null}
      <View style={styles.timeline}>
        <View style={styles.rail} />
        {events.map((ev, i) => {
          const color = statusColor[ev.status ?? 'upcoming'];
          return (
            <View key={i} style={styles.item}>
              <View style={[styles.dot, { backgroundColor: color, borderColor: color }]} />
              <View style={styles.itemContent}>
                <Text style={styles.time}>{formatDateTime(ev.at)}</Text>
                <Text style={styles.label}>{ev.label}</Text>
                {ev.description ? <Text style={styles.description}>{ev.description}</Text> : null}
              </View>
            </View>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  title: {
    ...typography.h3,
    marginBottom: spacing.md,
  },
  timeline: {
    position: 'relative',
    paddingLeft: spacing.lg,
  },
  rail: {
    position: 'absolute',
    left: 5,
    top: 6,
    bottom: 6,
    width: 1,
    backgroundColor: colors.border,
  },
  item: {
    position: 'relative',
    marginBottom: spacing.md,
  },
  dot: {
    position: 'absolute',
    left: -spacing.lg + 1,
    top: 4,
    width: 10,
    height: 10,
    borderRadius: 5,
    borderWidth: 2,
  },
  itemContent: {
    flex: 1,
  },
  time: {
    ...typography.caption,
    fontFamily: 'monospace',
    color: colors.textSecondary,
  },
  label: {
    ...typography.body,
    fontWeight: '600',
    marginTop: 2,
  },
  description: {
    ...typography.caption,
    marginTop: 2,
    color: colors.textSecondary,
  },
});
