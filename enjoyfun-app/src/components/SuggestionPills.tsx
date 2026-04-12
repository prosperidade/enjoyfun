import React from 'react';
import { ScrollView, StyleSheet, Text, TouchableOpacity } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme';
import { t } from '@/lib/i18n';
import type { Surface } from '@/lib/aiSession';

const PILLS_BY_SURFACE: Record<Surface, string[]> = {
  dashboard: ['pill_dashboard_revenue', 'pill_dashboard_kpis', 'pill_dashboard_alerts'],
  documents: ['pill_documents_list', 'pill_documents_search'],
  bar: ['pill_bar_sales', 'pill_bar_stock', 'pill_bar_top'],
  food: ['pill_food_sales', 'pill_food_stock'],
  shop: ['pill_shop_sales', 'pill_shop_top'],
  parking: ['pill_parking_flow', 'pill_parking_capacity'],
  artists: ['pill_artists_lineup', 'pill_artists_next'],
  workforce: ['pill_workforce_coverage', 'pill_workforce_gaps'],
  tickets: ['pill_tickets_sales', 'pill_tickets_demand'],
  finance: ['pill_finance_overview', 'pill_finance_suppliers'],
  platform_guide: [],
};

interface Props {
  surface: Surface;
  onPress: (text: string) => void;
  disabled?: boolean;
}

export function SuggestionPills({ surface, onPress, disabled }: Props) {
  const keys = PILLS_BY_SURFACE[surface] ?? [];
  if (keys.length === 0) return null;

  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={styles.container}
      keyboardShouldPersistTaps="handled"
    >
      {keys.map((key) => {
        const label = t(key);
        return (
          <TouchableOpacity
            key={key}
            style={[styles.pill, disabled && styles.pillDisabled]}
            onPress={() => !disabled && onPress(label)}
            accessibilityLabel={label}
          >
            <Text style={styles.pillText} numberOfLines={1}>
              {label}
            </Text>
          </TouchableOpacity>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    gap: spacing.sm,
  },
  pill: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs + 2,
    borderRadius: radius.full,
    backgroundColor: colors.accentMuted,
    borderWidth: 1,
    borderColor: colors.border,
  },
  pillDisabled: { opacity: 0.5 },
  pillText: {
    ...typography.caption,
    color: colors.accent,
  },
});
