import React, { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { colors, radius, spacing, typography } from '@/theme';
import {
  confirmApproval,
  cancelApproval,
  listPendingApprovals,
  type PendingApproval,
} from '@/lib/aiSession';
import { requireBiometric } from '@/lib/biometric';
import { t } from '@/lib/i18n';
import type { RootStackParamList } from '../../App';

type Props = NativeStackScreenProps<RootStackParamList, 'Approvals'>;

export function ApprovalsPanelScreen({ navigation }: Props) {
  const [items, setItems] = useState<PendingApproval[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      setItems(await listPendingApprovals());
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { refresh(); }, [refresh]);

  async function handleConfirm(item: PendingApproval) {
    if (item.requires_biometric) {
      const ok = await requireBiometric();
      if (!ok) return;
    }
    setBusy(item.request_id);
    try {
      await confirmApproval(item.request_id);
      setItems((prev) => prev.filter((a) => a.request_id !== item.request_id));
    } catch (err) {
      Alert.alert('Erro', err instanceof Error ? err.message : 'Falha');
    } finally {
      setBusy(null);
    }
  }

  async function handleCancel(requestId: string) {
    setBusy(requestId);
    try {
      await cancelApproval(requestId);
      setItems((prev) => prev.filter((a) => a.request_id !== requestId));
    } catch (err) {
      Alert.alert('Erro', err instanceof Error ? err.message : 'Falha');
    } finally {
      setBusy(null);
    }
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <Text style={styles.backText}>{'\u2190'}</Text>
        </TouchableOpacity>
        <Text style={styles.headerTitle}>{t('approvals_title')}</Text>
        <TouchableOpacity onPress={refresh} style={styles.refreshBtn}>
          <Text style={styles.refreshText}>{'\u21BB'}</Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <View style={styles.center}>
          <ActivityIndicator color={colors.accent} />
        </View>
      ) : (
        <FlatList
          data={items}
          keyExtractor={(a) => a.request_id}
          contentContainerStyle={styles.list}
          renderItem={({ item }) => {
            const isBusy = busy === item.request_id;
            return (
              <View style={styles.card}>
                <Text style={styles.summary}>{item.summary}</Text>
                <Text style={styles.skill}>{item.skill_key}</Text>
                {item.params_preview ? (
                  <Text style={styles.params}>{item.params_preview}</Text>
                ) : null}
                <Text style={styles.date}>{item.created_at}</Text>
                <View style={styles.actions}>
                  <TouchableOpacity
                    style={styles.cancelBtn}
                    onPress={() => handleCancel(item.request_id)}
                    disabled={isBusy}
                  >
                    <Text style={styles.cancelText}>{t('cancel')}</Text>
                  </TouchableOpacity>
                  <TouchableOpacity
                    style={styles.confirmBtn}
                    onPress={() => handleConfirm(item)}
                    disabled={isBusy}
                  >
                    {isBusy ? (
                      <ActivityIndicator size="small" color="#000" />
                    ) : (
                      <Text style={styles.confirmText}>
                        {item.requires_biometric ? '\u{1F512} ' : ''}{t('approval_confirm')}
                      </Text>
                    )}
                  </TouchableOpacity>
                </View>
              </View>
            );
          }}
          ListEmptyComponent={
            <View style={styles.center}>
              <Text style={styles.empty}>{t('approvals_empty')}</Text>
            </View>
          }
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  backBtn: {
    width: 36, height: 36, borderRadius: 18,
    backgroundColor: colors.surface,
    alignItems: 'center', justifyContent: 'center',
    marginRight: spacing.md,
  },
  backText: { color: colors.textPrimary, fontSize: 18 },
  headerTitle: { ...typography.h3, flex: 1 },
  refreshBtn: {
    width: 36, height: 36, borderRadius: 18,
    backgroundColor: colors.surface,
    alignItems: 'center', justifyContent: 'center',
  },
  refreshText: { color: colors.textPrimary, fontSize: 18 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  list: { padding: spacing.md, gap: spacing.md },
  card: {
    backgroundColor: colors.surfaceAlt,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.md,
  },
  summary: { ...typography.body, fontWeight: '600', marginBottom: spacing.xs },
  skill: { ...typography.caption, color: colors.textMuted, marginBottom: spacing.xs },
  params: {
    ...typography.caption,
    color: colors.textSecondary,
    backgroundColor: colors.glass,
    padding: spacing.sm,
    borderRadius: radius.sm,
    fontFamily: 'monospace',
    marginBottom: spacing.xs,
  },
  date: { ...typography.caption, color: colors.textMuted, marginBottom: spacing.md },
  actions: { flexDirection: 'row', gap: spacing.sm },
  cancelBtn: {
    flex: 1, paddingVertical: spacing.sm, borderRadius: radius.md,
    borderWidth: 1, borderColor: colors.border, alignItems: 'center',
  },
  cancelText: { ...typography.body, color: colors.textSecondary },
  confirmBtn: {
    flex: 1, paddingVertical: spacing.sm, borderRadius: radius.md,
    backgroundColor: colors.severity.warn, alignItems: 'center',
  },
  confirmText: { ...typography.body, fontWeight: '700', color: '#000000' },
  empty: { ...typography.bodyMuted, textAlign: 'center' },
});
