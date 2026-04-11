import React, { useState } from 'react';
import { View, Image, Text, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { ImageBlock as ImageBlockType } from '@/lib/types';

interface Props {
  block: ImageBlockType;
}

export function ImageBlock({ block }: Props) {
  const [errored, setErrored] = useState(false);
  if (!block.url) return null;

  return (
    <View style={styles.container}>
      {errored ? (
        <View style={[styles.image, styles.placeholder]}>
          <Text style={styles.placeholderText}>⌧</Text>
        </View>
      ) : (
        <Image
          source={{ uri: block.url }}
          style={styles.image}
          resizeMode="cover"
          onError={() => setErrored(true)}
          accessibilityLabel={block.alt ?? block.caption ?? ''}
        />
      )}
      {block.caption ? <Text style={styles.caption}>{block.caption}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    overflow: 'hidden',
    marginBottom: spacing.sm,
  },
  image: {
    width: '100%',
    aspectRatio: 16 / 9,
    backgroundColor: colors.surfaceAlt,
  },
  placeholder: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  placeholderText: {
    fontSize: 48,
    color: colors.textMuted,
  },
  caption: {
    ...typography.caption,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
});
