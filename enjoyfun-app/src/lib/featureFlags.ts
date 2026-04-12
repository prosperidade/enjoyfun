import Constants from 'expo-constants';

type ExtraFlags = {
  embeddedV3?: boolean;
};

function readExtra(): ExtraFlags {
  const extra = (Constants.expoConfig?.extra ?? (Constants.manifest as { extra?: ExtraFlags } | null)?.extra) as
    | ExtraFlags
    | undefined;
  return extra ?? {};
}

function readEnv(name: string): boolean | undefined {
  const raw = process.env[name];
  if (raw === undefined) return undefined;
  const v = String(raw).toLowerCase();
  if (v === '1' || v === 'true' || v === 'on' || v === 'yes') return true;
  if (v === '0' || v === 'false' || v === 'off' || v === 'no') return false;
  return undefined;
}

export function getEmbeddedV3Flag(): boolean {
  const fromExtra = readExtra().embeddedV3;
  if (typeof fromExtra === 'boolean') return fromExtra;
  const fromEnv = readEnv('EXPO_PUBLIC_FEATURE_AI_EMBEDDED_V3');
  if (typeof fromEnv === 'boolean') return fromEnv;
  return false;
}
