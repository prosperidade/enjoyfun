import React, { useState } from 'react';
import { View, Text, TouchableOpacity, StyleSheet, Alert } from 'react-native';
import * as LocalAuthentication from 'expo-local-authentication';
import { colors, spacing, radius, typography } from '@/theme';
import type {
  ActionsBlock as ActionsBlockType,
  ActionItem,
  ActionStyle,
} from '@/lib/types';

export interface ActionsBlockProps {
  block: ActionsBlockType;
  onAction?: (item: ActionItem) => void | Promise<void>;
}

function buttonColors(style?: ActionStyle) {
  switch (style) {
    case 'danger':
      return { bg: colors.severity.critical, fg: '#FFFFFF', border: 'transparent' };
    case 'secondary':
      return { bg: 'transparent', fg: colors.textPrimary, border: colors.borderStrong };
    case 'primary':
    default:
      return { bg: colors.accent, fg: '#FFFFFF', border: 'transparent' };
  }
}

async function ensureBiometric(): Promise<boolean> {
  const has = await LocalAuthentication.hasHardwareAsync();
  if (!has) return true; // device without sensor: let backend enforce
  const enrolled = await LocalAuthentication.isEnrolledAsync();
  if (!enrolled) return true;
  const res = await LocalAuthentication.authenticateAsync({
    promptMessage: 'Autorize esta acao',
    cancelLabel: 'Cancelar',
    disableDeviceFallback: false,
  });
  return res.success;
}

export function ActionsBlock({ block, onAction }: ActionsBlockProps) {
  const [busy, setBusy] = useState<number | null>(null);

  async function handlePress(item: ActionItem, idx: number) {
    try {
      setBusy(idx);
      if (item.requires_biometric) {
        const ok = await ensureBiometric();
        if (!ok) {
          Alert.alert('Autenticacao cancelada');
          return;
        }
      }
      if (onAction) await onAction(item);
    } catch (err) {
      Alert.alert('Erro', err instanceof Error ? err.message : 'Falha ao executar acao');
    } finally {
      setBusy(null);
    }
  }

  return (
    <View style={styles.container}>
      {block.items.map((item, idx) => {
        const c = buttonColors(item.style);
        return (
          <TouchableOpacity
            key={idx}
            disabled={busy !== null}
            activeOpacity={0.8}
            style={[
              styles.button,
              { backgroundColor: c.bg, borderColor: c.border, borderWidth: c.border === 'transparent' ? 0 : 1 },
              busy === idx && { opacity: 0.6 },
            ]}
            onPress={() => handlePress(item, idx)}
          >
            <Text style={[styles.buttonText, { color: c.fg }]}>{item.label}</Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  button: {
    borderRadius: radius.md,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.md,
    alignItems: 'center',
  },
  buttonText: {
    ...typography.body,
    fontWeight: '600',
  },
});
