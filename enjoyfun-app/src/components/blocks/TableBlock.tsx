import React from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { TableBlock as TableBlockType, TableColumn } from '@/lib/types';

interface Props {
  block: TableBlockType;
}

const currencyFmt = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const dateFmt = new Intl.DateTimeFormat('pt-BR');
const numberFmt = new Intl.NumberFormat('pt-BR');

function formatCell(value: unknown, col: TableColumn): string {
  if (value === null || value === undefined) return '-';
  switch (col.type) {
    case 'currency':
      return currencyFmt.format(Number(value));
    case 'number':
      return numberFmt.format(Number(value));
    case 'date': {
      const d = typeof value === 'string' || typeof value === 'number' ? new Date(value) : value;
      return d instanceof Date && !isNaN(d.getTime()) ? dateFmt.format(d) : String(value);
    }
    case 'bool':
      return value ? 'Sim' : 'Nao';
    case 'text':
    default:
      return String(value);
  }
}

export function TableBlock({ block }: Props) {
  const minColWidth = 120;
  return (
    <View style={styles.container}>
      {block.title ? <Text style={styles.title}>{block.title}</Text> : null}
      <ScrollView horizontal showsHorizontalScrollIndicator={false}>
        <View>
          <View style={styles.headerRow}>
            {block.columns.map((col) => (
              <Text key={col.key} style={[styles.headerCell, { minWidth: minColWidth }]}>
                {col.label}
              </Text>
            ))}
          </View>
          {block.rows.map((row, rIdx) => (
            <View
              key={rIdx}
              style={[styles.row, rIdx % 2 === 1 && { backgroundColor: 'rgba(255,255,255,0.02)' }]}
            >
              {block.columns.map((col) => (
                <Text key={col.key} style={[styles.cell, { minWidth: minColWidth }]}>
                  {formatCell(row[col.key], col)}
                </Text>
              ))}
            </View>
          ))}
        </View>
      </ScrollView>
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
    marginBottom: spacing.sm,
  },
  headerRow: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    paddingBottom: spacing.sm,
  },
  headerCell: {
    ...typography.caption,
    color: colors.textSecondary,
    textTransform: 'uppercase',
    paddingHorizontal: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    paddingVertical: spacing.sm,
  },
  cell: {
    ...typography.body,
    paddingHorizontal: spacing.sm,
  },
});
