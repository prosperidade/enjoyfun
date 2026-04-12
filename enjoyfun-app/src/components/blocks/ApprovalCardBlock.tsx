import React, { useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme';
import { t } from '@/lib/i18n';

export interface ApprovalRequestData {
  request_id: string;
  summary: string;
  skill_key: string;
  params_preview?: string;
  requires_biometric?: boolean;
}

export interface ApprovalCardBlockData {
  type: 'approval_request';
  id: string;
  approval: ApprovalRequestData;
}

interface Props {
  block: ApprovalCardBlockData;
  onConfirm?: (requestId: string, requiresBiometric: boolean) => Promise<void>;
  onCancel?: (requestId: string) => Promise<void>;
}

export function ApprovalCardBlock({ block, onConfirm, onCancel }: Props) {
  const { approval } = block;
  const [busy, setBusy] = useState(false);
  const [resolved, setResolved] = useState<'confirmed' | 'cancelled' | null>(null);

  async function handleConfirm() {
    if (busy || resolved) return;
    setBusy(true);
    try {
      await onConfirm?.(approval.request_id, approval.requires_biometric ?? false);
      setResolved('confirmed');
    } catch (err) {
      Alert.alert('Erro', err instanceof Error ? err.message : 'Falha ao confirmar');
    } finally {
      setBusy(false);
    }
  }

  async function handleCancel() {
    if (busy || resolved) return;
    setBusy(true);
    try {
      await onCancel?.(approval.request_id);
      setResolved('cancelled');
    } catch (err) {
      Alert.alert('Erro', err instanceof Error ? err.message : 'Falha ao cancelar');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.card}>
      <View style={styles.header}>
        <Text style={styles.icon}>{'\u26A0\uFE0F'}</Text>
        <Text style={styles.title}>{t('approval_required')}</Text>
      </View>
      <Text style={styles.summary}>{approval.summary}</Text>
      <Text style={styles.skill}>{approval.skill_key}</Text>
      {approval.params_preview ? (
        <Text style={styles.params}>{approval.params_preview}</Text>
      ) : null}
      {resolved ? (
        <View style={styles.resolvedRow}>
          <Text style={[styles.resolvedText, resolved === 'confirmed' ? styles.confirmedText : styles.cancelledText]}>
            {resolved === 'confirmed' ? t('approval_confirmed') : t('approval_cancelled')}
          </Text>
        </View>
      ) : (
        <View style={styles.actions}>
          <TouchableOpacity style={styles.cancelBtn} onPress={handleCancel} disabled={busy}>
            <Text style={styles.cancelBtnText}>{t('cancel')}</Text>
          </TouchableOpacity>
          <TouchableOpacity style={styles.confirmBtn} onPress={handleConfirm} disabled={busy}>
            {busy ? (
              <ActivityIndicator size="small" color="#FFFFFF" />
            ) : (
              <Text style={styles.confirmBtnText}>
                {approval.requires_biometric ? '\u{1F512} ' : ''}{t('approval_confirm')}
              </Text>
            )}
          </TouchableOpacity>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surfaceAlt,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.severity.warn,
    padding: spacing.md,
    marginVertical: spacing.xs,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  icon: { fontSize: 18 },
  title: { ...typography.body, fontWeight: '700', color: colors.severity.warn },
  summary: { ...typography.body, marginBottom: spacing.xs },
  skill: { ...typography.caption, color: colors.textMuted, marginBottom: spacing.xs },
  params: {
    ...typography.caption,
    color: colors.textSecondary,
    backgroundColor: colors.glass,
    padding: spacing.sm,
    borderRadius: radius.sm,
    fontFamily: 'monospace',
    marginBottom: spacing.md,
  },
  actions: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: spacing.sm,
  },
  cancelBtn: {
    flex: 1,
    paddingVertical: spacing.sm,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
  },
  cancelBtnText: { ...typography.body, color: colors.textSecondary },
  confirmBtn: {
    flex: 1,
    paddingVertical: spacing.sm,
    borderRadius: radius.md,
    backgroundColor: colors.severity.warn,
    alignItems: 'center',
  },
  confirmBtnText: { ...typography.body, fontWeight: '700', color: '#000000' },
  resolvedRow: { marginTop: spacing.sm, alignItems: 'center' },
  resolvedText: { ...typography.body, fontWeight: '600' },
  confirmedText: { color: colors.severity.success },
  cancelledText: { color: colors.textMuted },
});
