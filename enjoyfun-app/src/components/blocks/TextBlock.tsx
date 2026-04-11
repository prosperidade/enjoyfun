import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors, spacing, typography } from '@/theme';
import type { TextBlock as TextBlockType } from '@/lib/types';

interface Props {
  block: TextBlockType;
}

// Minimal markdown: **bold**, line breaks, "- " list markers.
function renderLine(line: string, key: string) {
  const parts = line.split(/(\*\*[^*]+\*\*)/g).filter(Boolean);
  return (
    <Text key={key} style={styles.line}>
      {parts.map((part, idx) => {
        if (/^\*\*[^*]+\*\*$/.test(part)) {
          return (
            <Text key={idx} style={styles.bold}>
              {part.slice(2, -2)}
            </Text>
          );
        }
        return <Text key={idx}>{part}</Text>;
      })}
    </Text>
  );
}

export function TextBlock({ block }: Props) {
  const lines = (block.body ?? '').split('\n');
  return (
    <View style={styles.container}>
      {lines.map((raw, idx) => {
        if (raw.trim().startsWith('- ')) {
          return (
            <View key={idx} style={styles.bulletRow}>
              <Text style={styles.bullet}>{'\u2022'}</Text>
              {renderLine(raw.replace(/^\s*-\s+/, ''), `l${idx}`)}
            </View>
          );
        }
        if (raw.trim() === '') return <View key={idx} style={{ height: spacing.sm }} />;
        return renderLine(raw, `l${idx}`);
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    marginBottom: spacing.sm,
  },
  line: {
    ...typography.body,
    lineHeight: 22,
  },
  bold: {
    fontWeight: '700',
    color: colors.textPrimary,
  },
  bulletRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    alignItems: 'flex-start',
  },
  bullet: {
    ...typography.body,
    color: colors.accent,
    marginTop: 2,
  },
});
