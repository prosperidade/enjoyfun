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
import { sendChatMessage } from '@/api/chat';
import { useEvent } from '@/context/EventContext';
import type { EventSummary } from '@/api/events';
import type { ChatMessage, ChatSurface, ActionItem } from '@/lib/types';
import { t, currentLocale } from '@/lib/i18n';
import { speak, stopSpeaking } from '@/lib/voice';
import type { RootStackParamList } from '../../App';

type Props = NativeStackScreenProps<RootStackParamList, 'Chat'>;

function uid(): string {
  return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

const SURFACE_LABELS: Partial<Record<ChatSurface, string>> = {
  general: 'surface_general',
  dashboard: 'surface_dashboard',
  parking: 'surface_parking',
  bar: 'surface_bar',
  artists: 'surface_artists',
  workforce: 'surface_workforce',
  finance: 'surface_finance',
};

export function ChatScreen({ navigation, route }: Props) {
  const routeSurface = route.params?.surface;

  const { activeEvent, events, selectEvent, refresh: refreshEvents } = useEvent();
  useEffect(() => {
    if (events.length === 0) {
      refreshEvents();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [surface, setSurface] = useState<ChatSurface>(routeSurface ?? 'dashboard');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [sessionId, setSessionId] = useState<string | undefined>(undefined);
  const [sending, setSending] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [ttsEnabled, setTtsEnabled] = useState(true);
  const ttsEnabledRef = useRef(true);
  useEffect(() => { ttsEnabledRef.current = ttsEnabled; }, [ttsEnabled]);
  useEffect(() => () => { stopSpeaking(); }, []);
  const listRef = useRef<FlatList<ChatMessage>>(null);

  // Reset session when surface changes via route params
  useEffect(() => {
    if (routeSurface && routeSurface !== surface) {
      setSurface(routeSurface);
      setSessionId(undefined);
      setMessages([]);
      welcomeFiredRef.current = false;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [routeSurface]);

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
        const res = await sendChatMessage({
          question: text,
          session_id: sessionId,
          surface,
          event_id: activeEvent?.id ?? null,
          locale: currentLocale,
        });
        if (res.session_id) setSessionId(res.session_id);
        setMessages((prev) =>
          prev.map((m) =>
            m.id === loadingMsg.id
              ? {
                  ...m,
                  loading: false,
                  response: res,
                  text: res.text_fallback,
                  toolCalls: res.tool_calls_summary,
                }
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
    [sessionId, activeEvent, surface],
  );

  const toggleTts = useCallback(() => {
    setTtsEnabled((prev) => {
      if (prev) stopSpeaking();
      return !prev;
    });
  }, []);

  const handlePickEvent = useCallback(
    async (event: EventSummary) => {
      await selectEvent(event);
      setPickerOpen(false);
      setSessionId(undefined);
      setMessages([]);
      welcomeFiredRef.current = false;
    },
    [selectEvent],
  );

  const handleSwitchToGeneral = useCallback(() => {
    setSurface('general');
    setSessionId(undefined);
    setMessages([]);
    welcomeFiredRef.current = false;
  }, []);

  const handleNewSession = useCallback(() => {
    setSessionId(undefined);
    setMessages([]);
    welcomeFiredRef.current = false;
  }, []);

  const welcomeFiredRef = useRef(false);
  useEffect(() => {
    if (welcomeFiredRef.current) return;
    if (messages.length > 0 || sending) return;
    // For general surface, fire welcome without needing an event
    if (surface === 'general') {
      welcomeFiredRef.current = true;
      handleSend(t('welcome_prompt_general'));
      return;
    }
    // For event-scoped surfaces, wait until an event is selected
    if (!activeEvent) return;
    welcomeFiredRef.current = true;
    handleSend(t('welcome_prompt'));
  }, [activeEvent, messages.length, sending, handleSend, surface]);

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

  const surfaceLabelKey = SURFACE_LABELS[surface];
  const surfaceLabel = surfaceLabelKey ? t(surfaceLabelKey as any) : '';

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <TouchableOpacity
          style={styles.headerLeft}
          onPress={() => setPickerOpen(true)}
          disabled={events.length === 0}
        >
          <Text style={styles.headerTitle}>
            {activeEvent?.name ?? 'EnjoyFun'} {events.length > 0 ? '\u25BE' : ''}
          </Text>
          <Text style={styles.headerSubtitle}>
            {surfaceLabel || (activeEvent ? t('header_subtitle_touch') : t('header_subtitle_empty'))}
          </Text>
        </TouchableOpacity>

        {/* Sparkle — Platform Guide (surface=general) */}
        <TouchableOpacity
          style={[styles.headerIcon, surface === 'general' && styles.headerIconActive]}
          onPress={handleSwitchToGeneral}
        >
          <Text style={styles.headerIconText}>{'\u2728'}</Text>
        </TouchableOpacity>

        {/* New session */}
        <TouchableOpacity style={styles.headerIcon} onPress={handleNewSession}>
          <Text style={styles.headerIconText}>{'\u{1F504}'}</Text>
        </TouchableOpacity>

        <TouchableOpacity style={styles.headerIcon} onPress={toggleTts}>
          <Text style={styles.headerIconText}>{ttsEnabled ? '\u{1F50A}' : '\u{1F507}'}</Text>
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
    marginLeft: spacing.xs,
  },
  headerIconActive: {
    backgroundColor: colors.accentMuted,
    borderWidth: 1,
    borderColor: colors.accent,
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
