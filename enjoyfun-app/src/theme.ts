// EnjoyFun brand palette — lilac/violet identity
export const colors = {
  bg: '#1A0B2E',
  surface: '#2A1750',
  surfaceAlt: '#231445',
  accent: '#A78BFA',
  accentMuted: 'rgba(167, 139, 250, 0.15)',
  accentStrong: '#8B5CF6',
  textPrimary: '#FFFFFF',
  textSecondary: '#C4B5FD',
  textMuted: '#8B7BA8',
  border: 'rgba(167, 139, 250, 0.15)',
  borderStrong: 'rgba(167, 139, 250, 0.3)',
  glass: 'rgba(255, 255, 255, 0.04)',
  severity: {
    info: '#60A5FA',
    success: '#34D399',
    warn: '#FBBF24',
    critical: '#F87171',
  },
  deltaUp: '#34D399',
  deltaDown: '#F87171',
  deltaFlat: '#C4B5FD',
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 16,
  lg: 24,
  xl: 32,
  xxl: 48,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 16,
  xl: 20,
  full: 999,
} as const;

export const typography = {
  h1: { fontSize: 28, fontWeight: '700' as const, color: colors.textPrimary },
  h2: { fontSize: 22, fontWeight: '700' as const, color: colors.textPrimary },
  h3: { fontSize: 18, fontWeight: '600' as const, color: colors.textPrimary },
  body: { fontSize: 15, fontWeight: '400' as const, color: colors.textPrimary },
  bodyMuted: { fontSize: 14, fontWeight: '400' as const, color: colors.textSecondary },
  caption: { fontSize: 12, fontWeight: '500' as const, color: colors.textSecondary },
  metric: { fontSize: 24, fontWeight: '700' as const, color: colors.textPrimary },
} as const;

export const theme = { colors, spacing, radius, typography } as const;

export type Theme = typeof theme;
