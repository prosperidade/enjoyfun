// Voice layer — STT via backend voice proxy + TTS via expo-speech.
//
// Transcricao de audio usa POST /ai/voice/transcribe no backend, que faz
// proxy para OpenAI Whisper com a chave servidora + rate limiting via
// AIRateLimitService. Nenhuma API key externa e exposta no bundle mobile.

import { useCallback } from 'react';
import {
  AudioModule,
  useAudioRecorder,
  RecordingPresets,
  setAudioModeAsync,
} from 'expo-audio';
import * as Speech from 'expo-speech';
import { getToken } from '@/lib/auth';

const VOICE_PROXY_URL = `${process.env.EXPO_PUBLIC_API_URL ?? 'http://10.0.2.2:8000'}/api/ai/voice/transcribe`;

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
  const token = await getToken();
  if (!token) {
    throw new Error('Token de autenticacao nao encontrado. Faca login novamente.');
  }

  const form = new FormData();
  form.append('file', {
    uri,
    name: 'audio.m4a',
    type: 'audio/m4a',
  } as unknown as Blob);
  const lang = locale.slice(0, 2);
  if (lang) form.append('language', lang);

  const res = await fetch(VOICE_PROXY_URL, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'X-Client': 'mobile',
    },
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
