import React, { useState } from 'react';
import { View, Text, TextInput, Switch, TouchableOpacity, StyleSheet } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { FormBlock as FormBlockType, FormField, ActionItem, FormFieldOption } from '@/lib/types';

interface Props {
  block: FormBlockType;
  onAction?: (item: ActionItem) => void | Promise<void>;
}

function EnumPicker({
  options,
  value,
  onChange,
}: {
  options: FormFieldOption[];
  value: string | number | undefined;
  onChange: (val: string | number) => void;
}) {
  return (
    <View style={styles.enumContainer}>
      {options.map((opt, i) => {
        const isSelected = value === opt.value;
        return (
          <TouchableOpacity
            key={i}
            style={[styles.enumChip, isSelected && styles.enumChipSelected]}
            onPress={() => onChange(opt.value)}
            activeOpacity={0.8}
          >
            <Text style={[styles.enumChipText, isSelected && styles.enumChipTextSelected]}>
              {opt.label}
            </Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

export function FormBlock({ block, onAction }: Props) {
  const [formData, setFormData] = useState<Record<string, any>>(() => {
    const initial: Record<string, any> = {};
    block.fields.forEach((f) => {
      initial[f.id] = f.default_value ?? (f.type === 'boolean' ? false : '');
    });
    return initial;
  });

  const updateField = (id: string, value: any) => {
    setFormData((prev) => ({ ...prev, [id]: value }));
  };

  const submit = () => {
    if (onAction) {
      onAction({
        label: block.submit_label || 'Enviar',
        action: 'execute',
        target: block.action,
        payload: formData,
      });
    }
  };

  return (
    <View style={styles.container}>
      {block.title && <Text style={styles.title}>{block.title}</Text>}
      {block.description && <Text style={styles.description}>{block.description}</Text>}

      <View style={styles.fieldsContainer}>
        {block.fields.map((field) => (
          <View key={field.id} style={styles.fieldWrapper}>
            <Text style={styles.fieldLabel}>
              {field.label} {field.required && <Text style={styles.requiredStar}>*</Text>}
            </Text>
            {field.description && <Text style={styles.fieldHint}>{field.description}</Text>}

            {field.type === 'boolean' ? (
              <View style={styles.switchWrapper}>
                <Switch
                  value={Boolean(formData[field.id])}
                  onValueChange={(val) => updateField(field.id, val)}
                  trackColor={{ false: colors.border, true: colors.accentStrong }}
                  thumbColor="#fff"
                />
              </View>
            ) : field.type === 'enum' && field.options ? (
              <EnumPicker
                options={field.options}
                value={formData[field.id]}
                onChange={(val) => updateField(field.id, val)}
              />
            ) : (
              <TextInput
                style={[
                  styles.input,
                  field.format === 'textarea' && styles.inputTextarea,
                ]}
                value={String(formData[field.id] ?? '')}
                onChangeText={(val) => updateField(field.id, val)}
                placeholderTextColor={colors.textMuted}
                placeholder={field.label}
                secureTextEntry={field.format === 'password'}
                multiline={field.format === 'textarea'}
                keyboardType={
                  field.type === 'number'
                    ? 'numeric'
                    : field.format === 'email'
                    ? 'email-address'
                    : 'default'
                }
              />
            )}
          </View>
        ))}
      </View>

      <TouchableOpacity style={styles.submitBtn} onPress={submit} activeOpacity={0.8}>
        <Text style={styles.submitBtnText}>{block.submit_label || 'Enviar dados'}</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.surfaceAlt,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  title: {
    ...typography.h3,
    marginBottom: spacing.xs,
  },
  description: {
    ...typography.bodyMuted,
    marginBottom: spacing.md,
  },
  fieldsContainer: {
    gap: spacing.md,
  },
  fieldWrapper: {
    flexDirection: 'column',
    gap: spacing.xs,
  },
  fieldLabel: {
    ...typography.caption,
    color: colors.textPrimary,
    fontWeight: '600',
  },
  requiredStar: {
    color: colors.severity.critical,
  },
  fieldHint: {
    ...typography.caption,
    fontSize: 10,
    marginTop: -2,
    marginBottom: 4,
  },
  input: {
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 10,
    ...typography.body,
    color: colors.textPrimary,
  },
  inputTextarea: {
    minHeight: 80,
    textAlignVertical: 'top',
  },
  switchWrapper: {
    alignSelf: 'flex-start',
  },
  enumContainer: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.xs,
  },
  enumChip: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    borderRadius: radius.full,
  },
  enumChipSelected: {
    backgroundColor: colors.accentStrong,
    borderColor: colors.accentStrong,
  },
  enumChipText: {
    ...typography.caption,
    color: colors.textMuted,
  },
  enumChipTextSelected: {
    color: '#FFF',
    fontWeight: '600',
  },
  submitBtn: {
    backgroundColor: colors.accent,
    borderRadius: radius.full,
    paddingVertical: 12,
    alignItems: 'center',
    marginTop: spacing.md,
  },
  submitBtnText: {
    color: '#FFF',
    fontWeight: '700',
    fontSize: 14,
  },
});
