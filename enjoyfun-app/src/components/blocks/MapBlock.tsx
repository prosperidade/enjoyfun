import React from 'react';
import { View, Text, TouchableOpacity, ScrollView, StyleSheet, Linking, Alert } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { MapBlock as MapBlockType, MapMarker } from '@/lib/types';

interface Props {
  block: MapBlockType;
}

const kindLabel: Record<string, string> = {
  stage: 'Palco',
  bar: 'Bar',
  wc: 'Banheiro',
  parking: 'Estacionamento',
  food: 'Alimentação',
  entrance: 'Entrada',
};

function openExternalMap(center: { lat: number; lng: number }, zoom: number) {
  const url = `https://www.openstreetmap.org/?mlat=${center.lat}&mlon=${center.lng}#map=${zoom}/${center.lat}/${center.lng}`;
  Linking.openURL(url).catch(() => Alert.alert('Erro', 'Não foi possível abrir o mapa.'));
}

function MarkerRow({ marker }: { marker: MapMarker }) {
  return (
    <View style={styles.markerRow}>
      <View style={[styles.markerDot, { backgroundColor: colors.accent }]} />
      <Text style={styles.markerLabel} numberOfLines={1}>
        {marker.label}
      </Text>
      {marker.kind ? <Text style={styles.markerKind}>{kindLabel[marker.kind] ?? marker.kind}</Text> : null}
    </View>
  );
}

export function MapBlock({ block }: Props) {
  const markers = block.markers ?? [];
  const center = block.center;
  const zoom = block.zoom ?? 15;

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerText}>Mapa do local</Text>
        <Text style={styles.headerMeta}>{markers.length} pontos</Text>
      </View>
      <TouchableOpacity
        style={styles.preview}
        activeOpacity={0.85}
        onPress={() => openExternalMap(center, zoom)}
      >
        <View style={styles.previewGrid} />
        <View style={styles.previewPin}>
          <Text style={styles.previewPinText}>◉</Text>
        </View>
        <Text style={styles.previewCoords}>
          {center.lat.toFixed(4)}, {center.lng.toFixed(4)}
        </Text>
        <View style={styles.previewCta}>
          <Text style={styles.previewCtaText}>Abrir no mapa ↗</Text>
        </View>
      </TouchableOpacity>
      {markers.length > 0 && (
        <ScrollView style={styles.markerList} nestedScrollEnabled>
          {markers.map((m, i) => (
            <MarkerRow key={i} marker={m} />
          ))}
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    overflow: 'hidden',
    marginBottom: spacing.sm,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  headerText: {
    ...typography.body,
    fontWeight: '600',
  },
  headerMeta: {
    ...typography.caption,
  },
  preview: {
    height: 180,
    backgroundColor: colors.surfaceAlt,
    alignItems: 'center',
    justifyContent: 'center',
    position: 'relative',
  },
  previewGrid: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: colors.surfaceAlt,
    opacity: 0.6,
  },
  previewPin: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.accentMuted,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.xs,
  },
  previewPinText: {
    color: colors.accent,
    fontSize: 24,
  },
  previewCoords: {
    ...typography.caption,
    fontFamily: 'monospace',
  },
  previewCta: {
    marginTop: spacing.sm,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    borderRadius: radius.full,
    backgroundColor: colors.accent,
  },
  previewCtaText: {
    color: '#FFFFFF',
    fontSize: 12,
    fontWeight: '700',
  },
  markerList: {
    maxHeight: 140,
  },
  markerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    gap: spacing.sm,
  },
  markerDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  markerLabel: {
    ...typography.caption,
    color: colors.textPrimary,
    flex: 1,
  },
  markerKind: {
    ...typography.caption,
    color: colors.textMuted,
    fontSize: 10,
  },
});
