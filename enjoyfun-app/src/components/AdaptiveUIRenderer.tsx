import React from 'react';
import { View } from 'react-native';
import type { Block, ActionItem } from '@/lib/types';
import { InsightBlock } from './blocks/InsightBlock';
import { ChartBlock } from './blocks/ChartBlock';
import { TableBlock } from './blocks/TableBlock';
import { CardGridBlock } from './blocks/CardGridBlock';
import { ActionsBlock } from './blocks/ActionsBlock';
import { TextBlock } from './blocks/TextBlock';
import { TimelineBlock } from './blocks/TimelineBlock';
import { LineupBlock } from './blocks/LineupBlock';
import { MapBlock } from './blocks/MapBlock';
import { ImageBlock } from './blocks/ImageBlock';
import { TutorialStepsBlock, type TutorialStepsBlockData } from './blocks/TutorialStepsBlock';
import { EvidenceBlock, type EvidenceBlockData } from './blocks/EvidenceBlock';
import { ApprovalCardBlock, type ApprovalCardBlockData } from './blocks/ApprovalCardBlock';

type ExtendedBlock = Block | TutorialStepsBlockData | EvidenceBlockData | ApprovalCardBlockData;

export interface AdaptiveUIRendererProps {
  blocks: ExtendedBlock[];
  onAction?: (item: ActionItem) => void | Promise<void>;
  onApprovalConfirm?: (requestId: string, requiresBiometric: boolean) => Promise<void>;
  onApprovalCancel?: (requestId: string) => Promise<void>;
}

export function AdaptiveUIRenderer({ blocks, onAction, onApprovalConfirm, onApprovalCancel }: AdaptiveUIRendererProps) {
  if (!Array.isArray(blocks) || blocks.length === 0) {
    return null;
  }
  return (
    <View>
      {blocks.map((block) => {
        switch (block.type) {
          case 'insight':
            return <InsightBlock key={block.id} block={block} />;
          case 'chart':
            return <ChartBlock key={block.id} block={block} />;
          case 'table':
            return <TableBlock key={block.id} block={block} />;
          case 'card_grid':
            return <CardGridBlock key={block.id} block={block} />;
          case 'actions':
            return <ActionsBlock key={block.id} block={block} onAction={onAction} />;
          case 'text':
            return <TextBlock key={block.id} block={block} />;
          case 'timeline':
            return <TimelineBlock key={block.id} block={block} />;
          case 'lineup':
            return <LineupBlock key={block.id} block={block} />;
          case 'map':
            return <MapBlock key={block.id} block={block} />;
          case 'image':
            return <ImageBlock key={block.id} block={block} />;
          case 'tutorial_steps':
            return <TutorialStepsBlock key={block.id} block={block as TutorialStepsBlockData} />;
          case 'evidence':
            return <EvidenceBlock key={block.id} block={block as EvidenceBlockData} />;
          case 'approval_request':
            return (
              <ApprovalCardBlock
                key={block.id}
                block={block as ApprovalCardBlockData}
                onConfirm={onApprovalConfirm}
                onCancel={onApprovalCancel}
              />
            );
          default:
            return null;
        }
      })}
    </View>
  );
}
