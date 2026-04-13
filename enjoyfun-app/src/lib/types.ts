export type Severity = 'info' | 'success' | 'warn' | 'critical';

export type DeltaDirection = 'up' | 'down' | 'flat';

export type ColumnType = 'text' | 'number' | 'date' | 'bool' | 'currency';

export interface InsightBlock {
  type: 'insight';
  id: string;
  title: string;
  body: string;
  severity?: Severity;
  icon?: string;
}

export interface ChartDataPoint {
  [key: string]: string | number;
}

export interface ChartBlock {
  type: 'chart';
  id: string;
  title: string;
  chart_type: 'bar' | 'line' | 'pie' | 'area';
  data: ChartDataPoint[];
  x_key: string;
  y_key: string;
  unit?: string;
}

export interface TableColumn {
  key: string;
  label: string;
  type?: ColumnType;
}

export interface TableBlock {
  type: 'table';
  id: string;
  title?: string;
  columns: TableColumn[];
  rows: Record<string, unknown>[];
}

export interface CardItem {
  label: string;
  value: string;
  delta?: string;
  delta_direction?: DeltaDirection;
  icon?: string;
  note?: string;
}

export interface CardGridBlock {
  type: 'card_grid';
  id: string;
  cards: CardItem[];
}

export type ActionStyle = 'primary' | 'secondary' | 'danger';
export type ActionKind = 'navigate' | 'tool' | 'execute';

export interface ActionItem {
  label: string;
  style?: ActionStyle;
  action: ActionKind;
  target?: string;
  execution_id?: number;
  requires_biometric?: boolean;
}

export interface ActionsBlock {
  type: 'actions';
  id: string;
  items: ActionItem[];
}

export interface TextBlock {
  type: 'text';
  id: string;
  body: string;
}

export type TimelineStatus = 'upcoming' | 'done' | 'cancelled';

export interface TimelineEvent {
  at: string;
  label: string;
  description?: string;
  icon?: string;
  status?: TimelineStatus;
}

export interface TimelineBlock {
  type: 'timeline';
  id: string;
  title?: string;
  events: TimelineEvent[];
}

export interface LineupSlot {
  artist_name: string;
  start_at: string;
  end_at: string;
  image_url?: string | null;
}

export interface LineupStage {
  name: string;
  slots: LineupSlot[];
}

export interface LineupBlock {
  type: 'lineup';
  id: string;
  stages: LineupStage[];
}

export type MapMarkerKind = 'stage' | 'bar' | 'wc' | 'parking' | 'food' | 'entrance';

export interface MapMarker {
  lat: number;
  lng: number;
  label: string;
  kind?: MapMarkerKind;
}

export interface MapBlock {
  type: 'map';
  id: string;
  center: { lat: number; lng: number };
  zoom: number;
  markers: MapMarker[];
}

export interface ImageBlock {
  type: 'image';
  id: string;
  url: string;
  caption?: string;
  alt?: string;
}

export type Block =
  | InsightBlock
  | ChartBlock
  | TableBlock
  | CardGridBlock
  | ActionsBlock
  | TextBlock
  | TimelineBlock
  | LineupBlock
  | MapBlock
  | ImageBlock;

export interface AdaptiveMeta {
  tokens_in?: number;
  tokens_out?: number;
  latency_ms?: number;
  provider?: string;
  model?: string;
}

export interface ToolCallSummary {
  tool: string;
  duration_ms?: number | null;
  ok: boolean;
}

export type ChatSurface =
  | 'general'
  | 'dashboard'
  | 'parking'
  | 'bar'
  | 'workforce'
  | 'artists'
  | 'analytics'
  | 'finance'
  | 'messaging'
  | 'marketing'
  | 'customer';

export interface AdaptiveResponse {
  session_id: string;
  agent_key?: string;
  surface?: string;
  confidence?: number;
  outcome?: string;
  execution_id?: number | null;
  blocks: Block[];
  text_fallback?: string;
  tool_calls_summary?: ToolCallSummary[];
  meta?: AdaptiveMeta;
}

export type MessageRole = 'user' | 'assistant' | 'system';

export interface ChatMessage {
  id: string;
  role: MessageRole;
  text?: string;
  response?: AdaptiveResponse;
  toolCalls?: ToolCallSummary[];
  createdAt: number;
  loading?: boolean;
}

export interface ChatSession {
  id: string;
  title?: string;
  agent_key?: string;
  updated_at?: string;
}

export interface LoginUser {
  id: number | string;
  name: string;
  email: string;
  role: string;
  organizer_id?: number | string | null;
}

export interface LoginData {
  access_token: string;
  access_transport?: 'cookie' | 'body';
  refresh_token?: string;
  refresh_transport?: 'cookie' | 'body';
  expires_in?: number;
  hmac_key?: string;
  user: LoginUser;
}

export interface LoginResponse {
  success?: boolean;
  message?: string;
  data: LoginData;
}
