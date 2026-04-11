import React from 'react';
import { View, Text, StyleSheet, Dimensions } from 'react-native';
import { colors, spacing, radius, typography } from '@/theme';
import type { ChartBlock as ChartBlockType } from '@/lib/types';

interface Props {
  block: ChartBlockType;
}

// react-native-chart-kit is loaded defensively so the app boots even if the
// dep is missing on first install. Falls back to a textual list otherwise.
let ChartKit: any = null;
try {
  // eslint-disable-next-line @typescript-eslint/no-var-requires
  ChartKit = require('react-native-chart-kit');
} catch {
  ChartKit = null;
}

const chartWidth = Dimensions.get('window').width - spacing.md * 4;
const chartHeight = 220;

const chartConfig = {
  backgroundGradientFrom: colors.glass,
  backgroundGradientTo: colors.glass,
  decimalPlaces: 0,
  color: (opacity = 1) => `rgba(233, 69, 96, ${opacity})`,
  labelColor: () => colors.textSecondary,
  propsForBackgroundLines: { stroke: 'rgba(255,255,255,0.05)' },
  propsForLabels: { fontSize: 10 },
};

export function ChartBlock({ block }: Props) {
  const data = block.data ?? [];
  const labels = data.map((row) => String(row[block.x_key] ?? ''));
  const values = data.map((row) => Number(row[block.y_key] ?? 0));

  const renderChart = () => {
    if (!ChartKit || data.length === 0) return null;
    const { BarChart, LineChart, PieChart } = ChartKit;
    const common = {
      width: chartWidth,
      height: chartHeight,
      chartConfig,
      style: { borderRadius: radius.md },
    };

    if (block.chart_type === 'pie') {
      const palette = [colors.accent, '#7C3AED', '#3B82F6', '#10B981', '#F59E0B', '#EF4444'];
      const pieData = data.map((row, idx) => ({
        name: String(row[block.x_key] ?? ''),
        population: Number(row[block.y_key] ?? 0),
        color: palette[idx % palette.length],
        legendFontColor: colors.textSecondary,
        legendFontSize: 11,
      }));
      return (
        <PieChart
          {...common}
          data={pieData}
          accessor="population"
          backgroundColor="transparent"
          paddingLeft="8"
          absolute
        />
      );
    }

    const ChartComponent = block.chart_type === 'line' || block.chart_type === 'area' ? LineChart : BarChart;
    return (
      <ChartComponent
        {...common}
        data={{ labels, datasets: [{ data: values }] }}
        fromZero
        withInnerLines
        yAxisLabel=""
        yAxisSuffix={block.unit ? ` ${block.unit}` : ''}
      />
    );
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>{block.title}</Text>
      {ChartKit && data.length > 0 ? (
        <View style={styles.chartWrap}>{renderChart()}</View>
      ) : (
        <View style={styles.fallback}>
          {data.map((row, idx) => (
            <Text key={idx} style={styles.fallbackRow}>
              {String(row[block.x_key])}: {Number(row[block.y_key] ?? 0)}
              {block.unit ? ` ${block.unit}` : ''}
            </Text>
          ))}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  title: {
    ...typography.h3,
    marginBottom: spacing.sm,
  },
  chartWrap: {
    alignItems: 'center',
  },
  fallback: {
    paddingVertical: spacing.sm,
  },
  fallbackRow: {
    ...typography.bodyMuted,
    marginBottom: 2,
  },
});
