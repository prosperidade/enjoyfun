// Voice layer — STT via backend proxy + TTS via expo-speech.
// Sprint 5: EXPO_PUBLIC_OPENAI_KEY removed from bundle. All transcription
// goes through POST /ai/voice/transcribe on the backend which holds the key
// server-side with rate limiting via AIRateLimitService.

import { useCallback } from 'react';
import {
  AudioModule,
  useAudioRecorder,
  RecordingPresets,
  setAudioModeAsync,
} from 'expo-audio';
import * as Speech from 'expo-speech';
import { apiClient } from '@/api/client';

export interface VoiceRecorderApi {
  isRecording: boolean;
  startRecording: () => Promise<boolean>;
  stopRecording: () => Promise<string | null>;
  cancelRecording: () => Promise<void>;
}

export function useVoiceRecorder(): VoiceRecorderApi {
  const recorder = useAudioRecorder(RecordingPresets.HIGH_QUALITY);

  const startRecording = useCallback(async (): Promise<boolean> => {
    const perm = await AudioModule.requestRecordingPermissionsAsync();
    if (!perm.granted) return false;

    try {
      await setAudioModeAsync({ allowsRecording: true, playsInSilentMode: true });
      await recorder.prepareToRecordAsync();
      recorder.record();
      return true;
    } catch {
      await setAudioModeAsync({ allowsRecording: false, playsInSilentMode: true }).catch(() => undefined);
      return false;
    }
  }, [recorder]);

  const stopRecording = useCallback(async (): Promise<string | null> => {
    if (!recorder.isRecording) return null;
    try {
      await recorder.stop();
      return recorder.uri ?? null;
    } finally {
      await setAudioModeAsync({ allowsRecording: false, playsInSilentMode: true }).catch(() => undefined);
    }
  }, [recorder]);

  const cancelRecording = useCallback(async (): Promise<void> => {
    if (!recorder.isRecording) return;
    try {
      await recorder.stop();
    } catch {
      // best-effort on unmount / interruption
    } finally {
      await setAudioModeAsync({ allowsRecording: false, playsInSilentMode: true }).catch(() => undefined);
    }
  }, [recorder]);

  return {
    isRecording: recorder.isRecording,
    startRecording,
    stopRecording,
    cancelRecording,
  };
}

export async function transcribe(uri: string, locale: string): Promise<string> {
  const form = new FormData();
  form.append('file', {
    uri,
    name: 'audio.m4a',
    type: 'audio/m4a',
  } as unknown as Blob);
  const lang = locale.slice(0, 2);
  if (lang) form.append('language', lang);

  const { data } = await apiClient.post<{ data?: { text?: string } }>(
    '/ai/voice/transcribe',
    form,
    { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 30000 },
  );

  const text = data?.data?.text ?? '';
  if (!text) throw new Error('transcribe_failed');
  return text.trim();
}

export function speak(text: string, locale: string): void {
  if (!text) return;
  // Stop any in-flight utterance before starting a new one so rapid consecutive
  // replies don't overlap.
  Speech.stop();
  Speech.speak(text, { language: locale, rate: 1.0, pitch: 1.0 });
}

export function stopSpeaking(): void {
  Speech.stop();
}
