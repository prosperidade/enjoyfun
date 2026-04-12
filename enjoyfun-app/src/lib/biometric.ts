import * as LocalAuthentication from 'expo-local-authentication';
import { t } from '@/lib/i18n';

export async function requireBiometric(): Promise<boolean> {
  const hasHw = await LocalAuthentication.hasHardwareAsync();
  if (!hasHw) return true; // no hardware = skip check, allow

  const enrolled = await LocalAuthentication.isEnrolledAsync();
  if (!enrolled) return true; // no biometrics enrolled = skip

  const result = await LocalAuthentication.authenticateAsync({
    promptMessage: t('biometric_required'),
    fallbackLabel: '',
    cancelLabel: t('cancel'),
    disableDeviceFallback: false,
  });

  return result.success;
}
