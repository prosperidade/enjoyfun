import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  View,
  Text,
  FlatList,
  StyleSheet,
  KeyboardAvoidingView,
  Platform,
  TouchableOpacity,
  Alert,
  Modal,
  Pressable,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { colors, spacing, typography, radius } from '@/theme';
import { ChatInput } from '@/components/ChatInput';
import { MessageBubble } from '@/components/MessageBubble';
import { SurfacePicker } from '@/components/SurfacePicker';
import { sendChatMessage } from '@/api/chat';
import { sendMessage as sendMessageV3, type Surface } from '@/lib/aiSession';
import { getEmbeddedV3Flag } from '@/lib/featureFlags';
import { useAISession } from '@/context/AISessionContext';
import { useEvent } from '@/context/EventContext';
import type { EventSummary } from '@/api/events';
import type { ChatMessage, ActionItem } from '@/lib/types';
import { t, currentLocale } from '@/lib/i18n';
import { speak, stopSpeaking } from '@/lib/voice';
import { clearAuth } from '@/lib/auth';
import type { RootStackParamList } from '../../App';

type Props = NativeStackScreenProps<RootStackParamList, 'Chat'>;

function uid(): string {
  return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

export function ChatScreen({ navigation }: Props) {
  const { activeEvent, events, selectEvent, refresh: refreshEvents } = useEvent();
  const aiSession = useAISession();
  const v3Enabled = getEmbeddedV3Flag();
  // EventProvider mounts before login and fails the first /events call while
  // there's no token. Re-fetch on ChatScreen mount since this screen only
  // renders after a successful login.
  useEffect(() => {
    if (events.length === 0) {
      refreshEvents();
    }
    // only on first mount
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const [surface, setSurface] = useState<Surface>('dashboard');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [legacySessionId, setLegacySessionId] = useState<string | undefined>(undefined);
  const [sending, setSending] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [ttsEnabled, setTtsEnabled] = useState(true);
  const ttsEnabledRef = useRef(true);
  useEffect(() => { ttsEnabledRef.current = ttsEnabled; }, [ttsEnabled]);
  useEffect(() => () => { stopSpeaking(); }, []);
  const listRef = useRef<FlatList<ChatMessage>>(null);
  const welcomeFiredRef = useRef(false);

  const handleSend = useCallback(
    async (text: string) => {
      const userMsg: ChatMessage = {
        id: uid(),
        role: 'user',
        text,
        createdAt: Date.now(),
      };
      const loadingMsg: ChatMessage = {
        id: uid(),
        role: 'assistant',
        createdAt: Date.now(),
        loading: true,
      };
      setMessages((prev) => [...prev, userMsg, loadingMsg]);
      setSending(true);
      try {
        const eventIdNum =
          activeEvent && activeEvent.id != null ? Number(activeEvent.id) : null;
        let res;
        if (v3Enabled) {
          const cached = aiSession.getSession(surface, eventIdNum);
          res = await sendMessageV3(surface, eventIdNum, text, {
            sessionId: cached?.sessionId,
            conversationMode: 'embedded',
            locale: currentLocale,
          });
          aiSession.recordResponse(surface, eventIdNum, res);
        } else {
          const context: Record<string, unknown> = { locale: currentLocale };
          if (eventIdNum != null) context.event_id = eventIdNum;
          const legacy = await sendChatMessage({
            question: text,
            session_id: legacySessionId,
            context,
          });
          if (legacy.session_id && !legacySessionId) setLegacySessionId(legacy.session_id);
          res = legacy;
        }
        setMessages((prev) =>
          prev.map((m) =>
            m.id === loadingMsg.id
              ? { ...m, loading: false, response: res, text: res.text_fallback }
              : m,
          ),
        );
        if (ttsEnabledRef.current && res.text_fallback) {
          speak(res.text_fallback, currentLocale);
        }
      } catch (err) {
        const errText = err instanceof Error ? err.message : 'Erro ao enviar mensagem';
        setMessages((prev) =>
          prev.map((m) =>
            m.id === loadingMsg.id
              ? { ...m, loading: false, text: errText }
              : m,
          ),
        );
      } finally {
        setSending(false);
      }
    },
    [v3Enabled, surface, activeEvent, legacySessionId, aiSession],
  );

  const toggleTts = useCallback(() => {
    setTtsEnabled((prev) => {
      if (prev) stopSpeaking();
      return !prev;
    });
  }, []);

  const handlePickEvent = useCallback(
    async (event: EventSummary) => {
      const prevId =
        activeEvent && activeEvent.id != null ? Number(activeEvent.id) : null;
      await selectEvent(event);
      setPickerOpen(false);
      setLegacySessionId(undefined);
      aiSession.archiveEvent(prevId);
      setMessages([]);
    },
    [selectEvent, activeEvent, aiSession],
  );

  const handleSurfaceChange = useCallback(
    (next: Surface) => {
      if (next === surface) return;
      setSurface(next);
      setMessages([]);
      welcomeFiredRef.current = false;
    },
    [surface],
  );

  useEffect(() => {
    if (welcomeFiredRef.current) return;
    if (!activeEvent || messages.length > 0 || sending) return;
    welcomeFiredRef.current = true;
    handleSend(t('welcome_prompt'));
  }, [activeEvent, messages.length, sending, handleSend]);

  const handleAction = useCallback(
    async (item: ActionItem) => {
      if (item.action === 'navigate' && item.target) {
        Alert.alert('Navegacao', `Abrir ${item.target}`);
        return;
      }
      if (item.action === 'execute' || item.action === 'tool') {
        Alert.alert('Executar', `${item.label} (execution_id=${item.execution_id ?? 'n/a'})`);
      }
    },
    [],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <TouchableOpacity
          style={styles.headerLeft}
          onPress={() => setPickerOpen(true)}
          disabled={events.length === 0}
        >
          <Text style={styles.headerTitle}>
            {activeEvent?.name ?? 'EnjoyFun'} {events.length > 0 ? '▾' : ''}
          </Text>
          <Text style={styles.headerSubtitle}>
            {activeEvent ? t('header_subtitle_touch') : t('header_subtitle_empty')}
          </Text>
        </TouchableOpacity>
        <View style={styles.headerSurface}>
          <SurfacePicker value={surface} onChange={handleSurfaceChange} />
        </View>
        <TouchableOpacity style={styles.headerIcon} onPress={toggleTts}>
          <Text style={styles.headerIconText}>{ttsEnabled ? '\u{1F50A}' : '\u{1F507}'}</Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={styles.headerBtn}
          onPress={() =>
            Alert.alert(t('logout'), t('confirm_logout'), [
              { text: t('cancel'), style: 'cancel' },
              {
                text: t('logout'),
                style: 'destructive',
                onPress: async () => {
                  await clearAuth();
                  navigation.reset({ index: 0, routes: [{ name: 'Login' }] });
                },
              },
            ])
          }
        >
          <Text style={styles.headerBtnText}>{t('logout')}</Text>
        </TouchableOpacity>
      </View>

      <Modal
        visible={pickerOpen}
        transparent
        animationType="slide"
        onRequestClose={() => setPickerOpen(false)}
      >
        <Pressable style={styles.modalBackdrop} onPress={() => setPickerOpen(false)}>
          <Pressable style={styles.modalSheet} onPress={(e) => e.stopPropagation()}>
            <Text style={styles.modalTitle}>{t('select_event')}</Text>
            <FlatList
              data={events}
              keyExtractor={(e) => String(e.id)}
              renderItem={({ item }) => (
                <TouchableOpacity
                  style={[
                    styles.eventRow,
                    activeEvent?.id === item.id && styles.eventRowActive,
                  ]}
                  onPress={() => handlePickEvent(item)}
                >
                  <Text style={styles.eventName}>{item.name}</Text>
                  {item.venue_name ? (
                    <Text style={styles.eventVenue}>{item.venue_name}</Text>
                  ) : null}
                </TouchableOpacity>
              )}
              ListEmptyComponent={
                <Text style={styles.modalEmpty}>{t('no_events')}</Text>
              }
            />
          </Pressable>
        </Pressable>
      </Modal>

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
              <Text style={styles.emptyTitle}>{t('empty_title')}</Text>
              <Text style={styles.emptyBody}>{t('empty_body')}</Text>
            </View>
          }
        />
        <ChatInput onSend={handleSend} disabled={sending} />
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  flex: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  headerLeft: {
    flex: 1,
    marginRight: spacing.sm,
  },
  headerSurface: {
    marginRight: spacing.xs,
  },
  headerTitle: {
    ...typography.h2,
  },
  headerSubtitle: {
    ...typography.caption,
  },
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.6)',
    justifyContent: 'flex-end',
  },
  modalSheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.lg,
    paddingBottom: spacing.xl,
    maxHeight: '70%',
  },
  modalTitle: {
    ...typography.h3,
    marginBottom: spacing.md,
  },
  eventRow: {
    padding: spacing.md,
    borderRadius: radius.md,
    marginBottom: spacing.xs,
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
  },
  eventRowActive: {
    borderColor: colors.accent,
  },
  eventName: {
    ...typography.body,
    fontWeight: '600',
  },
  eventVenue: {
    ...typography.caption,
    marginTop: 2,
  },
  modalEmpty: {
    ...typography.bodyMuted,
    textAlign: 'center',
    paddingVertical: spacing.xl,
  },
  headerIcon: {
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
    marginRight: spacing.xs,
  },
  headerIconText: {
    fontSize: 16,
  },
  headerBtn: {
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderRadius: radius.full,
    backgroundColor: colors.surface,
  },
  headerBtnText: {
    ...typography.body,
    color: colors.textPrimary,
  },
  listContent: {
    paddingVertical: spacing.md,
    flexGrow: 1,
  },
  empty: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.xxl,
  },
  emptyTitle: {
    ...typography.h2,
    marginBottom: spacing.sm,
    textAlign: 'center',
  },
  emptyBody: {
    ...typography.bodyMuted,
    textAlign: 'center',
  },
});
