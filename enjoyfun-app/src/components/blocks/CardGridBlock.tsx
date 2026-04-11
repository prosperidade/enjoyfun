import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { CardGridBlock as CardGridBlockType, DeltaDirection } from '@/lib/types';

interface Props {
  block: CardGridBlockType;
}

function deltaColor(dir?: DeltaDirection): string {
  if (dir === 'up') return colors.deltaUp;
  if (dir === 'down') return colors.deltaDown;
  return colors.deltaFlat;
}

function deltaSymbol(dir?: DeltaDirection): string {
  if (dir === 'up') return '+';
  if (dir === 'down') return '-';
  return '';
}

export function CardGridBlock({ block }: Props) {
  return (
    <View style={styles.grid}>
      {block.cards.map((card, idx) => (
        <View key={idx} style={styles.cardSlot}>
          <View style={styles.cardInner}>
            <Text style={styles.label}>{card.label}</Text>
            <Text style={styles.value}>{card.value}</Text>
            {card.delta ? (
              <Text style={[styles.delta, { color: deltaColor(card.delta_direction) }]}>
                {deltaSymbol(card.delta_direction)}
                {card.delta}
              </Text>
            ) : null}
            {card.note ? <Text style={styles.note}>{card.note}</Text> : null}
          </View>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    marginHorizontal: -spacing.xs,
    marginBottom: spacing.sm,
  },
  cardSlot: {
    width: '50%',
    padding: spacing.xs,
  },
  cardInner: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.md,
  },
  label: {
    ...typography.caption,
    marginBottom: spacing.xs,
  },
  value: {
    ...typography.metric,
  },
  delta: {
    fontSize: 13,
    fontWeight: '600',
    marginTop: 2,
  },
  note: {
    ...typography.caption,
    marginTop: spacing.xs,
  },
});
