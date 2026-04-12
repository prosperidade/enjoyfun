import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme';

export interface TutorialStep {
  step: number;
  title: string;
  description?: string;
  action?: string;
}

export interface TutorialStepsBlockData {
  type: 'tutorial_steps';
  id: string;
  title?: string;
  steps: TutorialStep[];
}

interface Props {
  block: TutorialStepsBlockData;
}

export function TutorialStepsBlock({ block }: Props) {
  return (
    <View style={styles.container}>
      {block.title ? <Text style={styles.title}>{block.title}</Text> : null}
      {block.steps.map((step, i) => (
        <View key={`${block.id}-${step.step ?? i}`} style={styles.stepRow}>
          <View style={styles.stepNumber}>
            <Text style={styles.stepNumberText}>{step.step ?? i + 1}</Text>
          </View>
          <View style={styles.stepContent}>
            <Text style={styles.stepTitle}>{step.title}</Text>
            {step.description ? (
              <Text style={styles.stepDesc}>{step.description}</Text>
            ) : null}
            {step.action ? (
              <Text style={styles.stepAction}>{step.action}</Text>
            ) : null}
          </View>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingVertical: spacing.sm,
  },
  title: {
    ...typography.h3,
    marginBottom: spacing.md,
  },
  stepRow: {
    flexDirection: 'row',
    marginBottom: spacing.md,
    alignItems: 'flex-start',
  },
  stepNumber: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: colors.accentMuted,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: spacing.md,
    marginTop: 2,
  },
  stepNumberText: {
    ...typography.caption,
    color: colors.accent,
    fontWeight: '700',
  },
  stepContent: {
    flex: 1,
  },
  stepTitle: {
    ...typography.body,
    fontWeight: '600',
  },
  stepDesc: {
    ...typography.bodyMuted,
    marginTop: 2,
  },
  stepAction: {
    ...typography.caption,
    color: colors.accent,
    marginTop: spacing.xs,
    fontStyle: 'italic',
  },
});
