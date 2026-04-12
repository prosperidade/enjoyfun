import React, { useEffect, useRef, useState } from 'react';
import {
  View,
  TextInput,
  TouchableOpacity,
  Text,
  StyleSheet,
  ActivityIndicator,
  Animated,
  Alert,
} from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import { useVoiceRecorder, transcribe } from '@/lib/voice';
import { t, currentLocale } from '@/lib/i18n';

interface Props {
  onSend: (text: string) => void;
  disabled?: boolean;
}

type MicState = 'idle' | 'listening' | 'processing';

export function ChatInput({ onSend, disabled }: Props) {
  const [value, setValue] = useState('');
  const [micState, setMicState] = useState<MicState>('idle');
  const recorder = useVoiceRecorder();
  const pulse = useRef(new Animated.Value(1)).current;
  const busyRef = useRef(false);

  useEffect(() => {
    if (micState !== 'listening') {
      pulse.setValue(1);
      return;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1.18, duration: 550, useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 1, duration: 550, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [micState, pulse]);

  // Release recorder + audio session if the component unmounts mid-recording.
  useEffect(() => {
    return () => {
      recorder.cancelRecording();
    };
    // recorder is stable across renders (expo-audio hook)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleSend() {
    const trimmed = value.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setValue('');
  }

  async function handleMicPress() {
    if (disabled || busyRef.current) return;
    busyRef.current = true;
    try {
      if (micState === 'idle') {
        const ok = await recorder.startRecording();
        if (!ok) {
          Alert.alert(t('mic_permission_denied'));
          return;
        }
        setMicState('listening');
        return;
      }

      if (micState === 'listening') {
        setMicState('processing');
        const uri = await recorder.stopRecording();
        if (!uri) {
          setMicState('idle');
          return;
        }
        try {
          const text = await transcribe(uri, currentLocale);
          if (text) onSend(text);
        } catch {
          Alert.alert(t('transcribe_error'));
        } finally {
          setMicState('idle');
        }
      }
    } finally {
      busyRef.current = false;
    }
  }

  async function handleMicLongPress() {
    if (micState !== 'listening' || busyRef.current) return;
    busyRef.current = true;
    try {
      await recorder.cancelRecording();
      setMicState('idle');
    } finally {
      busyRef.current = false;
    }
  }

  const micBg = micState === 'listening' ? colors.accent : colors.surface;

  return (
    <View style={styles.container}>
      <Animated.View style={{ transform: [{ scale: micState === 'listening' ? pulse : 1 }] }}>
        <TouchableOpacity
          style={[styles.micBtn, { backgroundColor: micBg }]}
          onPress={handleMicPress}
          onLongPress={handleMicLongPress}
          delayLongPress={400}
          disabled={disabled || micState === 'processing'}
          activeOpacity={0.8}
        >
          {micState === 'processing' ? (
            <ActivityIndicator color={colors.accent} size="small" />
          ) : (
            <Text style={styles.micIcon}>{'\u{1F3A4}'}</Text>
          )}
        </TouchableOpacity>
      </Animated.View>
      {micState === 'listening' && (
        <View style={styles.recordingHint}>
          <Text style={styles.recordingText}>{t('recording')}</Text>
        </View>
      )}
      <TextInput
        style={styles.input}
        placeholder={t('input_placeholder')}
        placeholderTextColor={colors.textMuted}
        value={value}
        onChangeText={setValue}
        multiline
        editable={!disabled && micState !== 'listening'}
        onSubmitEditing={handleSend}
        blurOnSubmit={false}
      />
      <TouchableOpacity
        style={[styles.sendBtn, (!value.trim() || disabled) && { opacity: 0.4 }]}
        activeOpacity={0.8}
        onPress={handleSend}
        disabled={!value.trim() || disabled}
      >
        <Text style={styles.sendText}>{t('send')}</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.bg,
    gap: spacing.sm,
  },
  input: {
    flex: 1,
    ...typography.body,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    maxHeight: 120,
    color: colors.textPrimary,
  },
  micBtn: {
    width: 42,
    height: 42,
    borderRadius: 21,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  micIcon: {
    fontSize: 18,
  },
  sendBtn: {
    backgroundColor: colors.accent,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendText: {
    color: '#FFFFFF',
    fontWeight: '600',
  },
  recordingHint: {
    justifyContent: 'center',
    paddingBottom: spacing.xs,
  },
  recordingText: {
    ...typography.caption,
    color: colors.accent,
  },
});
