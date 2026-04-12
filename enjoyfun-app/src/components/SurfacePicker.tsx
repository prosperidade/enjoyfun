import React, { useState } from 'react';
import {
  Modal,
  Pressable,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { colors, radius, spacing, typography } from '@/theme';
import type { Surface } from '@/lib/aiSession';

export interface SurfaceOption {
  key: Surface;
  label: string;
  icon: string;
  description?: string;
}

export const SURFACE_OPTIONS: SurfaceOption[] = [
  { key: 'dashboard', label: 'Painel do evento', icon: '\u{1F4CA}', description: 'KPIs, vendas e visao geral' },
  { key: 'documents', label: 'Documentos', icon: '\u{1F4C4}', description: 'Arquivos do organizador' },
  { key: 'bar', label: 'Bar', icon: '\u{1F37A}', description: 'Vendas e estoque de bebidas' },
  { key: 'food', label: 'Alimentacao', icon: '\u{1F354}', description: 'Pratos e refeicoes' },
  { key: 'shop', label: 'Loja', icon: '\u{1F6CD}', description: 'Mercadorias e produtos' },
  { key: 'parking', label: 'Estacionamento', icon: '\u{1F697}', description: 'Fluxo e capacidade' },
  { key: 'artists', label: 'Artistas', icon: '\u{1F3B5}', description: 'Lineup e logistica' },
  { key: 'workforce', label: 'Equipes', icon: '\u{1F465}', description: 'Cobertura de turnos' },
  { key: 'tickets', label: 'Ingressos', icon: '\u{1F3AB}', description: 'Vendas por lote e canal' },
  { key: 'finance', label: 'Financeiro', icon: '\u{1F4B0}', description: 'Orcamento e pagamentos' },
];

interface Props {
  value: Surface;
  options?: SurfaceOption[];
  onChange: (surface: Surface) => void;
  disabled?: boolean;
}

export function SurfacePicker({
  value,
  options = SURFACE_OPTIONS,
  onChange,
  disabled,
}: Props) {
  const [open, setOpen] = useState(false);
  const current = options.find((o) => o.key === value) ?? options[0];

  return (
    <View>
      <TouchableOpacity
        style={[styles.trigger, disabled && styles.triggerDisabled]}
        onPress={() => !disabled && setOpen(true)}
        accessibilityLabel="Trocar contexto da IA"
      >
        <Text style={styles.triggerIcon}>{current.icon}</Text>
        <Text style={styles.triggerLabel} numberOfLines={1}>
          {current.label}
        </Text>
        <Text style={styles.triggerCaret}>{'\u25BE'}</Text>
      </TouchableOpacity>

      <Modal
        visible={open}
        transparent
        animationType="fade"
        onRequestClose={() => setOpen(false)}
      >
        <Pressable style={styles.backdrop} onPress={() => setOpen(false)}>
          <Pressable style={styles.sheet} onPress={(e) => e.stopPropagation()}>
            <Text style={styles.sheetTitle}>Escolha o contexto</Text>
            {options.map((opt) => {
              const active = opt.key === value;
              return (
                <TouchableOpacity
                  key={opt.key}
                  style={[styles.row, active && styles.rowActive]}
                  onPress={() => {
                    setOpen(false);
                    if (opt.key !== value) onChange(opt.key);
                  }}
                >
                  <Text style={styles.rowIcon}>{opt.icon}</Text>
                  <View style={styles.rowText}>
                    <Text style={styles.rowLabel}>{opt.label}</Text>
                    {opt.description ? (
                      <Text style={styles.rowDesc}>{opt.description}</Text>
                    ) : null}
                  </View>
                  {active ? <Text style={styles.rowCheck}>{'\u2713'}</Text> : null}
                </TouchableOpacity>
              );
            })}
          </Pressable>
        </Pressable>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  trigger: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: radius.full,
    backgroundColor: colors.surfaceAlt,
    borderWidth: 1,
    borderColor: colors.border,
    maxWidth: 200,
  },
  triggerDisabled: { opacity: 0.5 },
  triggerIcon: { fontSize: 14, marginRight: spacing.xs },
  triggerLabel: {
    ...typography.caption,
    color: colors.textPrimary,
    flexShrink: 1,
  },
  triggerCaret: {
    color: colors.textSecondary,
    marginLeft: spacing.xs,
    fontSize: 12,
  },
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.6)',
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
  },
  sheet: {
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.lg,
    borderWidth: 1,
    borderColor: colors.border,
  },
  sheetTitle: {
    ...typography.h3,
    marginBottom: spacing.md,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.sm,
    borderRadius: radius.md,
    marginBottom: spacing.xs,
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  rowActive: { borderColor: colors.accent },
  rowIcon: { fontSize: 22, marginRight: spacing.md },
  rowText: { flex: 1 },
  rowLabel: { ...typography.body, fontWeight: '600' },
  rowDesc: { ...typography.caption, marginTop: 2 },
  rowCheck: { color: colors.accent, fontSize: 18, marginLeft: spacing.sm },
});
