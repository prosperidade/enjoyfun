import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { ChatMessage, ActionItem } from '@/lib/types';
import { AdaptiveUIRenderer } from './AdaptiveUIRenderer';
import { SimpleMarkdown } from './SimpleMarkdown';

interface Props {
  message: ChatMessage;
  onAction?: (item: ActionItem) => void | Promise<void>;
}

export function MessageBubble({ message, onAction }: Props) {
  if (message.role === 'user') {
    return (
      <View style={styles.userRow}>
        <View style={styles.userBubble}>
          <Text style={styles.userText}>{message.text ?? ''}</Text>
        </View>
      </View>
    );
  }

  // assistant
  const blocks = message.response?.blocks;
  const hasBlocks = Array.isArray(blocks) && blocks.length > 0;
  const fallbackText = message.text ?? message.response?.text_fallback ?? '';

  return (
    <View style={styles.assistantRow}>
      {message.loading ? (
        <View style={styles.skeleton}>
          <View style={[styles.skelLine, { width: '60%' }]} />
          <View style={[styles.skelLine, { width: '90%' }]} />
          <View style={[styles.skelLine, { width: '75%' }]} />
        </View>
      ) : hasBlocks ? (
        <AdaptiveUIRenderer blocks={blocks} onAction={onAction} />
      ) : fallbackText ? (
        <SimpleMarkdown text={fallbackText} />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  userRow: {
    alignItems: 'flex-end',
    marginBottom: spacing.md,
    paddingHorizontal: spacing.md,
  },
  userBubble: {
    backgroundColor: colors.accent,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    maxWidth: '85%',
  },
  userText: {
    ...typography.body,
    color: '#FFFFFF',
  },
  assistantRow: {
    alignItems: 'stretch',
    marginBottom: spacing.md,
    paddingHorizontal: spacing.md,
  },
  assistantText: {
    ...typography.body,
  },
  skeleton: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.sm,
  },
  skelLine: {
    height: 12,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: radius.sm,
  },
});
