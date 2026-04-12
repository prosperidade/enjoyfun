import React from 'react';
import { Linking, StyleSheet, Text } from 'react-native';
import { colors, typography } from '@/theme';

interface Props {
  text: string;
}

interface Segment {
  kind: 'text' | 'bold' | 'italic' | 'code' | 'link';
  value: string;
  href?: string;
}

const INLINE_RE =
  /(\*\*(.+?)\*\*)|(\*(.+?)\*)|(`(.+?)`)|(\[([^\]]+)\]\(([^)]+)\))/g;

function parseInline(line: string): Segment[] {
  const segments: Segment[] = [];
  let last = 0;
  let m: RegExpExecArray | null;
  INLINE_RE.lastIndex = 0;
  while ((m = INLINE_RE.exec(line)) !== null) {
    if (m.index > last) segments.push({ kind: 'text', value: line.slice(last, m.index) });
    if (m[2]) segments.push({ kind: 'bold', value: m[2] });
    else if (m[4]) segments.push({ kind: 'italic', value: m[4] });
    else if (m[6]) segments.push({ kind: 'code', value: m[6] });
    else if (m[8] && m[9]) segments.push({ kind: 'link', value: m[8], href: m[9] });
    last = m.index + m[0].length;
  }
  if (last < line.length) segments.push({ kind: 'text', value: line.slice(last) });
  return segments;
}

function renderSegment(seg: Segment, i: number) {
  switch (seg.kind) {
    case 'bold':
      return <Text key={i} style={styles.bold}>{seg.value}</Text>;
    case 'italic':
      return <Text key={i} style={styles.italic}>{seg.value}</Text>;
    case 'code':
      return <Text key={i} style={styles.code}>{seg.value}</Text>;
    case 'link':
      return (
        <Text key={i} style={styles.link} onPress={() => seg.href && Linking.openURL(seg.href)}>
          {seg.value}
        </Text>
      );
    default:
      return <Text key={i}>{seg.value}</Text>;
  }
}

export function SimpleMarkdown({ text }: Props) {
  const lines = text.split('\n');

  return (
    <Text style={styles.base}>
      {lines.map((line, li) => {
        const trimmed = line.trimStart();
        const isBullet = trimmed.startsWith('- ') || trimmed.startsWith('* ');
        const isNumbered = /^\d+\.\s/.test(trimmed);
        const isHeader = trimmed.startsWith('### ') || trimmed.startsWith('## ') || trimmed.startsWith('# ');

        let content = trimmed;
        let prefix = '';
        if (isBullet) { prefix = '  \u2022 '; content = trimmed.slice(2); }
        else if (isNumbered) { prefix = '  '; }
        else if (isHeader) { content = trimmed.replace(/^#+\s/, ''); }

        const segs = parseInline(isNumbered ? trimmed : content);

        return (
          <Text key={li}>
            {li > 0 ? '\n' : ''}
            {prefix ? <Text>{prefix}</Text> : null}
            {isHeader ? (
              <Text style={styles.heading}>{segs.map(renderSegment)}</Text>
            ) : (
              segs.map(renderSegment)
            )}
          </Text>
        );
      })}
    </Text>
  );
}

const styles = StyleSheet.create({
  base: {
    ...typography.body,
  },
  bold: {
    fontWeight: '700',
  },
  italic: {
    fontStyle: 'italic',
  },
  code: {
    fontFamily: 'monospace',
    backgroundColor: 'rgba(255,255,255,0.08)',
    paddingHorizontal: 4,
    borderRadius: 3,
    color: colors.accent,
  },
  link: {
    color: colors.accent,
    textDecorationLine: 'underline',
  },
  heading: {
    fontWeight: '700',
    fontSize: 16,
    color: colors.textPrimary,
  },
});
