import React from 'react';
import { Linking, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme';

export interface EvidenceItem {
  type?: string;
  file_id?: string | number;
  snippet?: string;
  score?: number;
  url?: string;
  title?: string;
}

export interface EvidenceBlockData {
  type: 'evidence';
  id: string;
  items: EvidenceItem[];
}

interface Props {
  block: EvidenceBlockData;
}

export function EvidenceBlock({ block }: Props) {
  if (!block.items || block.items.length === 0) return null;

  return (
    <View style={styles.container}>
      {block.items.map((item, i) => (
        <TouchableOpacity
          key={`${block.id}-${i}`}
          style={styles.card}
          onPress={() => {
            if (item.url) Linking.openURL(item.url);
          }}
          disabled={!item.url}
          accessibilityLabel={item.title ?? item.snippet ?? 'Evidencia'}
        >
          <View style={styles.header}>
            <Text style={styles.icon}>{'\u{1F4CE}'}</Text>
            <Text style={styles.title} numberOfLines={1}>
              {item.title ?? `Documento ${item.file_id ?? i + 1}`}
            </Text>
            {item.score != null && (
              <Text style={styles.score}>{Math.round(item.score * 100)}%</Text>
            )}
          </View>
          {item.snippet ? (
            <Text style={styles.snippet} numberOfLines={3}>
              {item.snippet}
            </Text>
          ) : null}
          {item.url ? (
            <Text style={styles.link}>Abrir documento</Text>
          ) : null}
        </TouchableOpacity>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: spacing.sm,
    paddingVertical: spacing.sm,
  },
  card: {
    backgroundColor: colors.glass,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.md,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  icon: {
    fontSize: 16,
  },
  title: {
    ...typography.body,
    fontWeight: '600',
    flex: 1,
  },
  score: {
    ...typography.caption,
    color: colors.accent,
    fontWeight: '700',
  },
  snippet: {
    ...typography.bodyMuted,
    marginTop: spacing.xs,
    fontStyle: 'italic',
  },
  link: {
    ...typography.caption,
    color: colors.accent,
    marginTop: spacing.sm,
  },
});
