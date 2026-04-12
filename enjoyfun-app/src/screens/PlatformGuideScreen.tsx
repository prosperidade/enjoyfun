import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  View,
  Text,
  FlatList,
  StyleSheet,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  TouchableOpacity,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { colors, spacing, typography, radius } from '@/theme';
import { ChatInput } from '@/components/ChatInput';
import { MessageBubble } from '@/components/MessageBubble';
import { ToolActivityIndicator } from '@/components/ToolActivityIndicator';
import { sendPlatformGuideMessage, type ToolCallSummary } from '@/lib/aiSession';
import { getEmbeddedV3Flag } from '@/lib/featureFlags';
import { useAISession } from '@/context/AISessionContext';
import type { ChatMessage, ActionItem } from '@/lib/types';
import { t, currentLocale } from '@/lib/i18n';
import type { RootStackParamList } from '../../App';

type Props = NativeStackScreenProps<RootStackParamList, 'PlatformGuide'>;

function uid(): string {
  return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

const GUIDE_PILLS = ['pill_guide_setup', 'pill_guide_gateway', 'pill_guide_branding'];

export function PlatformGuideScreen({ navigation }: Props) {
  const v3Enabled = getEmbeddedV3Flag();
  const aiSession = useAISession();
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [sending, setSending] = useState(false);
  const [lastToolCalls, setLastToolCalls] = useState<ToolCallSummary[] | undefined>(undefined);
  const listRef = useRef<FlatList<ChatMessage>>(null);
  const welcomeFiredRef = useRef(false);

  const handleSend = useCallback(
    async (text: string) => {
      if (!v3Enabled) return;
      const userMsg: ChatMessage = { id: uid(), role: 'user', text, createdAt: Date.now() };
      const loadingMsg: ChatMessage = { id: uid(), role: 'assistant', createdAt: Date.now(), loading: true };
      setMessages((prev) => [...prev, userMsg, loadingMsg]);
      setSending(true);
      setLastToolCalls(undefined);
      try {
        const cached = aiSession.getSession('platform_guide', null);
        const res = await sendPlatformGuideMessage(text, {
          sessionId: cached?.sessionId,
          locale: currentLocale,
        });
        aiSession.recordResponse('platform_guide', null, res);
        setLastToolCalls(res.tool_calls_summary);
        setMessages((prev) =>
          prev.map((m) =>
            m.id === loadingMsg.id
              ? { ...m, loading: false, response: res, text: res.text_fallback }
              : m,
          ),
        );
      } catch (err) {
        const errText = err instanceof Error ? err.message : 'Erro ao enviar mensagem';
        setMessages((prev) =>
          prev.map((m) =>
            m.id === loadingMsg.id ? { ...m, loading: false, text: errText } : m,
          ),
        );
      } finally {
        setSending(false);
      }
    },
    [v3Enabled, aiSession],
  );

  useEffect(() => {
    if (welcomeFiredRef.current || !v3Enabled) return;
    welcomeFiredRef.current = true;
    handleSend(t('platform_guide_welcome'));
  }, [v3Enabled, handleSend]);

  const handleAction = useCallback(async (item: ActionItem) => {
    if (item.action === 'navigate' && item.target) {
      navigation.navigate(item.target as keyof RootStackParamList);
    }
  }, [navigation]);

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backBtn}>
          <Text style={styles.backText}>{'\u2190'}</Text>
        </TouchableOpacity>
        <View style={styles.headerCenter}>
          <Text style={styles.headerTitle}>{t('platform_guide')}</Text>
          <Text style={styles.headerSubtitle}>{t('platform_guide_subtitle')}</Text>
        </View>
      </View>

      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        keyboardVerticalOffset={Platform.OS === 'ios' ? 0 : 24}
      >
        <FlatList
          ref={listRef}
          data={messages}
          keyExtractor={(m) => m.id}
          renderItem={({ item }) => (
            <MessageBubble message={item} onAction={handleAction} />
          )}
          contentContainerStyle={styles.listContent}
          keyboardShouldPersistTaps="handled"
          initialNumToRender={12}
          onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })}
          ListEmptyComponent={
            <View style={styles.empty}>
              <Text style={styles.emptyIcon}>{'\u{1F4DA}'}</Text>
              <Text style={styles.emptyTitle}>{t('platform_guide')}</Text>
              <Text style={styles.emptyBody}>{t('platform_guide_empty')}</Text>
            </View>
          }
        />
        {sending && <ToolActivityIndicator loading={sending} toolCallsSummary={lastToolCalls} />}
        {!sending && messages.length === 0 && (
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.pills}
            keyboardShouldPersistTaps="handled"
          >
            {GUIDE_PILLS.map((key) => (
              <TouchableOpacity
                key={key}
                style={styles.pill}
                onPress={() => handleSend(t(key))}
              >
                <Text style={styles.pillText}>{t(key)}</Text>
              </TouchableOpacity>
            ))}
          </ScrollView>
        )}
        <ChatInput onSend={handleSend} disabled={sending} />
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  backBtn: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing.md,
  },
  backText: { color: colors.textPrimary, fontSize: 18 },
  headerCenter: { flex: 1 },
  headerTitle: { ...typography.h3 },
  headerSubtitle: { ...typography.caption },
  listContent: { paddingVertical: spacing.md, flexGrow: 1 },
  empty: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.xxl,
  },
  emptyIcon: { fontSize: 48, marginBottom: spacing.md },
  emptyTitle: { ...typography.h2, marginBottom: spacing.sm, textAlign: 'center' },
  emptyBody: { ...typography.bodyMuted, textAlign: 'center' },
  pills: {
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
  pillText: { ...typography.caption, color: colors.accent },
});
