// Voice layer — STT via OpenAI Whisper + TTS via expo-speech.
//
// DIVIDA TECNICA: EXPO_PUBLIC_OPENAI_KEY vaza no bundle JS do cliente.
// Qualquer um com o APK pode extrair a chave. Pos D-Day, mover a chamada
// Whisper para um endpoint backend /ai/voice/transcribe que faz proxy
// com a chave servidora + rate limiting via AIRateLimitService.

import { useCallback } from 'react';
import {
  AudioModule,
  useAudioRecorder,
  RecordingPresets,
  setAudioModeAsync,
} from 'expo-audio';
import * as Speech from 'expo-speech';

const WHISPER_URL = 'https://api.openai.com/v1/audio/transcriptions';
const WHISPER_MODEL = 'whisper-1';

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
  const key = process.env.EXPO_PUBLIC_OPENAI_KEY;
  if (!key) {
    throw new Error('EXPO_PUBLIC_OPENAI_KEY nao configurado');
  }

  const form = new FormData();
  form.append('file', {
    uri,
    name: 'audio.m4a',
    type: 'audio/m4a',
  } as unknown as Blob);
  form.append('model', WHISPER_MODEL);
  const lang = locale.slice(0, 2);
  if (lang) form.append('language', lang);

  const res = await fetch(WHISPER_URL, {
    method: 'POST',
    headers: { Authorization: `Bearer ${key}` },
    body: form,
  });

  if (!res.ok) {
    // Technical detail goes to the JS console; callers should show a friendly message.
    const err = await res.text().catch(() => '');
    console.warn(`[voice] Whisper HTTP ${res.status}: ${err.slice(0, 200)}`);
    throw new Error('transcribe_failed');
  }

  const json = (await res.json()) as { text?: string };
  return (json.text ?? '').trim();
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
