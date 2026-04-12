import React from 'react';
import { Alert, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme';
import { t } from '@/lib/i18n';
import type { Surface } from '@/lib/aiSession';
import { SurfacePicker } from './SurfacePicker';

interface Props {
  eventName?: string;
  hasEvents: boolean;
  surface: Surface;
  agentUsed?: string;
  ttsEnabled: boolean;
  onPickEvent: () => void;
  onSurfaceChange: (surface: Surface) => void;
  onToggleTts: () => void;
  onClearChat: () => void;
  onLogout: () => void;
}

export function ChatHeader({
  eventName,
  hasEvents,
  surface,
  agentUsed,
  ttsEnabled,
  onPickEvent,
  onSurfaceChange,
  onToggleTts,
  onClearChat,
  onLogout,
}: Props) {
  return (
    <View style={styles.header}>
      <TouchableOpacity
        style={styles.headerLeft}
        onPress={onPickEvent}
        disabled={!hasEvents}
      >
        <Text style={styles.headerTitle} numberOfLines={1}>
          {eventName ?? 'EnjoyFun'} {hasEvents ? '\u25BE' : ''}
        </Text>
        <View style={styles.subtitleRow}>
          <Text style={styles.headerSubtitle}>
            {eventName ? t('header_subtitle_touch') : t('header_subtitle_empty')}
          </Text>
          {agentUsed ? (
            <View style={styles.agentBadge}>
              <Text style={styles.agentBadgeText}>{agentUsed}</Text>
            </View>
          ) : null}
        </View>
      </TouchableOpacity>
      <SurfacePicker value={surface} onChange={onSurfaceChange} />
      <TouchableOpacity style={styles.iconBtn} onPress={onClearChat}>
        <Text style={styles.iconText}>{'\u{1F5D1}'}</Text>
      </TouchableOpacity>
      <TouchableOpacity style={styles.iconBtn} onPress={onToggleTts}>
        <Text style={styles.iconText}>{ttsEnabled ? '\u{1F50A}' : '\u{1F507}'}</Text>
      </TouchableOpacity>
      <TouchableOpacity
        style={styles.logoutBtn}
        onPress={() =>
          Alert.alert(t('logout'), t('confirm_logout'), [
            { text: t('cancel'), style: 'cancel' },
            { text: t('logout'), style: 'destructive', onPress: onLogout },
          ])
        }
      >
        <Text style={styles.logoutText}>{t('logout')}</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    gap: spacing.xs,
  },
  headerLeft: {
    flex: 1,
    marginRight: spacing.xs,
  },
  headerTitle: {
    ...typography.body,
    fontWeight: '700',
  },
  subtitleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
  },
  headerSubtitle: {
    ...typography.caption,
  },
  agentBadge: {
    backgroundColor: colors.accentMuted,
    paddingHorizontal: spacing.xs + 2,
    paddingVertical: 1,
    borderRadius: radius.sm,
  },
  agentBadgeText: {
    ...typography.caption,
    color: colors.accent,
    fontSize: 10,
    fontWeight: '600',
  },
  iconBtn: {
    width: 34,
    height: 34,
    borderRadius: 17,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
  },
  iconText: {
    fontSize: 14,
  },
  logoutBtn: {
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: radius.full,
    backgroundColor: colors.surface,
  },
  logoutText: {
    ...typography.caption,
    color: colors.textPrimary,
  },
});
