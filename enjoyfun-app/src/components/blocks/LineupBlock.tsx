import React, { useState } from 'react';
import { View, Text, Image, ScrollView, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { LineupBlock as LineupBlockType, LineupSlot } from '@/lib/types';

interface Props {
  block: LineupBlockType;
}

function formatTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(d);
}

function SlotCard({ slot }: { slot: LineupSlot }) {
  const [imgError, setImgError] = useState(false);
  return (
    <View style={styles.card}>
      {slot.image_url && !imgError ? (
        <Image
          source={{ uri: slot.image_url }}
          style={styles.image}
          onError={() => setImgError(true)}
        />
      ) : (
        <View style={[styles.image, styles.imagePlaceholder]}>
          <Text style={styles.placeholderText}>♪</Text>
        </View>
      )}
      <View style={styles.cardBody}>
        <Text style={styles.artistName} numberOfLines={1}>
          {slot.artist_name}
        </Text>
        <Text style={styles.slotTime}>
          {formatTime(slot.start_at)} – {formatTime(slot.end_at)}
        </Text>
      </View>
    </View>
  );
}

export function LineupBlock({ block }: Props) {
  const stages = block.stages ?? [];
  if (stages.length === 0) return null;

  return (
    <View style={styles.container}>
      {stages.map((stage, si) => (
        <View key={si} style={si > 0 ? styles.stageSpaced : undefined}>
          <Text style={styles.stageName}>{stage.name}</Text>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.slotsRow}>
            {stage.slots.map((slot, i) => (
              <SlotCard key={i} slot={slot} />
            ))}
          </ScrollView>
        </View>
      ))}
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
  stageSpaced: {
    marginTop: spacing.lg,
  },
  stageName: {
    ...typography.h3,
    marginBottom: spacing.sm,
  },
  slotsRow: {
    flexDirection: 'row',
  },
  card: {
    width: 150,
    marginRight: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: colors.border,
  },
  image: {
    width: '100%',
    height: 96,
    backgroundColor: colors.surfaceAlt,
  },
  imagePlaceholder: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  placeholderText: {
    fontSize: 32,
    color: colors.textMuted,
  },
  cardBody: {
    padding: spacing.sm,
  },
  artistName: {
    ...typography.body,
    fontWeight: '600',
    fontSize: 13,
  },
  slotTime: {
    ...typography.caption,
    fontFamily: 'monospace',
    marginTop: 2,
  },
});
