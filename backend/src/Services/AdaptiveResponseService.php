<?php
/**
 * AdaptiveResponseService.php
 * Converts orchestrator results into adaptive UI blocks consumed by
 * web/mobile/PWA renderers (Revolut AIR style).
 *
 * Shape: ['blocks' => [...], 'text_fallback' => '...']
 *
 * Gated by FEATURE_ADAPTIVE_UI at the controller level.
 */

namespace EnjoyFun\Services;

class AdaptiveResponseService
{
    private const CHART_KEYWORDS = [
        'kpi', 'snapshot', 'summary', 'breakdown', 'comparison', 'compare',
        'cost', 'finance', 'sales', 'demand', 'analytics', 'cross_module',
    ];

    private const SERIES_KEYWORDS = [
        'sales', 'revenue', 'tickets_sold', 'occupancy', 'checkins', 'attendance',
        'demand', 'by_day', 'by_hour', 'by_week', 'per_day', 'per_hour', 'trend',
        'timeseries', 'time_series', 'history',
    ];

    // Block type ordering — narrative first, visual data next, calls-to-action last.
    private const BLOCK_ORDER = [
        'insight'    => 10,
        'text'       => 15,
        'card_grid'  => 20,
        'chart'      => 30,
        'timeline'   => 35,
        'lineup'     => 40,
        'table'      => 45,
        'map'        => 50,
        'image'      => 55,
        'actions'    => 90,
    ];

    /**
     * Build adaptive blocks + text fallback from an orchestrator result.
     *
     * @param array $orchestratorResult Result of AIOrchestratorService::generateInsight()
     * @param array $context            Arbitrary controller context (agent_key, surface, insight, ...)
     * @return array{blocks: array<int, array<string, mixed>>, text_fallback: string}
     */
    public static function buildBlocks(array $orchestratorResult, array $context = []): array
    {
        $blocks = [];
        $counter = 1;
        $nextId = static function () use (&$counter): string {
            return 'b' . ($counter++);
        };

        $insightText = trim((string)(
            $context['insight']
            ?? $orchestratorResult['insight']
            ?? $orchestratorResult['response']
            ?? $orchestratorResult['content']
            ?? ''
        ));
        // Strip markdown tokens so the renderer shows clean text and TTS doesn't
        // read "asterisco asterisco" out loud.
        $insightText = self::stripMarkdown($insightText);

        $outcome = strtolower(trim((string)($orchestratorResult['outcome'] ?? 'completed')));
        $toolResults = is_array($orchestratorResult['tool_results'] ?? null)
            ? $orchestratorResult['tool_results']
            : [];
        $toolCalls = is_array($orchestratorResult['tool_calls'] ?? null)
            ? $orchestratorResult['tool_calls']
            : [];

        // 1) Leading narrative block — insight always (data-rich contexts prefer
        // a styled insight card over plain text; the renderer already handles it).
        if ($insightText !== '') {
            $blocks[] = [
                'type'     => 'insight',
                'id'       => $nextId(),
                'title'    => $outcome === 'approval_required' ? 'Aprovação necessária' : null,
                'body'     => $insightText,
                'severity' => $outcome === 'approval_required' ? 'warn' : 'info',
                'icon'     => $outcome === 'approval_required' ? 'alert-triangle' : 'sparkles',
            ];
        }

        // 2) Metadata-provided cards become a card_grid
        $metaCards = $context['cards'] ?? ($orchestratorResult['cards'] ?? null);
        if (is_array($metaCards) && !empty($metaCards)) {
            $cards = [];
            foreach ($metaCards as $card) {
                if (!is_array($card)) continue;
                $cards[] = self::normalizeCard($card);
            }
            if (!empty($cards)) {
                $blocks[] = [
                    'type'  => 'card_grid',
                    'id'    => $nextId(),
                    'cards' => $cards,
                ];
            }
        }

        // 3) Tool results — mirror AIResponseRenderer.jsx extraction logic
        foreach ($toolResults as $tr) {
            if (!is_array($tr)) continue;
            $toolName = (string)($tr['tool_name'] ?? $tr['name'] ?? '');
            $data = $tr['result'] ?? $tr['data'] ?? null;

            if (!is_array($data) || empty($data)) continue;

            // PII scrub on tool results before they become user-visible blocks
            if (class_exists('\\EnjoyFun\\Services\\AIPromptSanitizer')
                && method_exists('\\EnjoyFun\\Services\\AIPromptSanitizer', 'scrubPIIFromData')) {
                $data = \EnjoyFun\Services\AIPromptSanitizer::scrubPIIFromData($data);
            }

            // Drop scope/noise keys (event_id, sector_filter, *_id, *_filter)
            // so they don't pollute charts/card_grids as fake metrics.
            $data = self::filterNoiseKeys($data);
            if (empty($data)) continue;

            // Specialized block detection (before generic table/chart fallthrough)
            $specialBlock = self::tryBuildSpecializedBlock($nextId, $toolName, $data);
            if ($specialBlock !== null) {
                $blocks[] = $specialBlock;
                continue;
            }

            $firstKey = array_key_first($data);
            $isList = is_int($firstKey);

            // Table: list of objects (>= 1 row of associative data)
            if ($isList) {
                $first = $data[$firstKey] ?? null;
                if (is_array($first) && !empty($first)) {
                    $keys = array_keys($first);
                    $labelKey = null;
                    $valueKey = null;
                    foreach ($keys as $k) {
                        if ($labelKey === null && is_string($first[$k] ?? null)) {
                            $labelKey = $k;
                        }
                        if ($valueKey === null && is_numeric($first[$k] ?? null) && $k !== $labelKey) {
                            $valueKey = $k;
                        }
                    }

                    // Force chart when the tool name indicates a time/metric series
                    // (sales_by_day, revenue_per_hour, tickets_sold_trend, etc).
                    $forceChart = $labelKey !== null
                        && $valueKey !== null
                        && count($data) >= 2
                        && self::looksLikeSeriesTool($toolName);

                    if ($forceChart || (count($keys) === 2 && $labelKey !== null && $valueKey !== null && count($data) >= 2)) {
                        $blocks[] = self::buildChartBlock(
                            $nextId(),
                            self::prettyLabel($toolName),
                            array_map(
                                static fn($row) => [
                                    'label' => (string)($row[$labelKey] ?? ''),
                                    'value' => (float)($row[$valueKey] ?? 0),
                                ],
                                $data
                            )
                        );
                    } else {
                        $blocks[] = self::buildTableBlock(
                            $nextId(),
                            self::prettyLabel($toolName),
                            $data
                        );
                    }
                    continue;
                }
            }

            // Associative: may be a chart (key=>number pairs) or a card_grid (KPI-like)
            if (!$isList) {
                $numericEntries = [];
                foreach ($data as $k => $v) {
                    if (is_numeric($v)) {
                        $numericEntries[(string)$k] = (float)$v;
                    }
                }

                if (count($numericEntries) >= 2) {
                    $isChartTool = false;
                    $lcTool = strtolower($toolName);
                    foreach (self::CHART_KEYWORDS as $kw) {
                        if ($lcTool !== '' && str_contains($lcTool, $kw)) {
                            $isChartTool = true;
                            break;
                        }
                    }

                    // Mixed-scale KPIs: if the max value is 100x larger than
                    // the min (non-zero), charting them together is useless —
                    // force card_grid regardless of the tool name keyword.
                    $nonZero = array_filter($numericEntries, static fn($v) => $v != 0);
                    if (count($nonZero) >= 2) {
                        $maxVal = max(array_map('abs', $nonZero));
                        $minVal = min(array_map('abs', $nonZero));
                        if ($minVal > 0 && ($maxVal / $minVal) > 100) {
                            $isChartTool = false;
                        }
                    }

                    // 2-8 numeric entries on a KPI-ish tool → card_grid
                    if (!$isChartTool
                        && count($numericEntries) >= 2
                        && count($numericEntries) <= 8
                        && count($numericEntries) >= count($data) * 0.6) {
                        $blocks[] = [
                            'type'  => 'card_grid',
                            'id'    => $nextId(),
                            'title' => self::prettyLabel($toolName),
                            'cards' => array_map(
                                static fn($k, $v) => [
                                    'label' => self::prettyLabel((string)$k),
                                    'value' => self::formatNumber($v),
                                ],
                                array_keys($numericEntries),
                                array_values($numericEntries)
                            ),
                        ];
                        continue;
                    }

                    // Otherwise → chart
                    $entries = [];
                    foreach ($numericEntries as $label => $value) {
                        $entries[] = [
                            'label' => self::prettyLabel($label),
                            'value' => $value,
                        ];
                    }
                    $blocks[] = self::buildChartBlock(
                        $nextId(),
                        self::prettyLabel($toolName),
                        $entries
                    );
                }
            }
        }

        // 4) Approval actions — when orchestrator demands human gate
        if ($outcome === 'approval_required') {
            $executionId = $orchestratorResult['execution_id'] ?? $context['execution_id'] ?? null;
            $items = [];

            if ($executionId !== null) {
                $items[] = [
                    'label'              => 'Aprovar',
                    'style'              => 'primary',
                    'action'             => 'execute',
                    'execution_id'       => (int)$executionId,
                    'requires_biometric' => true,
                ];
                $items[] = [
                    'label'        => 'Rejeitar',
                    'style'        => 'danger',
                    'action'       => 'reject',
                    'execution_id' => (int)$executionId,
                ];
            }

            if (!empty($items)) {
                $block = [
                    'type'  => 'actions',
                    'id'    => $nextId(),
                    'items' => $items,
                ];
                if (!empty($toolCalls)) {
                    $names = [];
                    foreach ($toolCalls as $tc) {
                        $name = $tc['name'] ?? ($tc['function']['name'] ?? null);
                        if ($name) $names[] = (string)$name;
                    }
                    if (!empty($names)) {
                        $block['description'] = count($names) === 1
                            ? '1 ação precisa da sua aprovação: ' . $names[0]
                            : count($names) . ' ações precisam da sua aprovação';
                    }
                }
                $blocks[] = $block;
            }
        }

        // Safety net: never return empty blocks if insight was empty too
        if (empty($blocks)) {
            $blocks[] = [
                'type' => 'text',
                'id'   => $nextId(),
                'body' => $insightText !== '' ? $insightText : 'Sem conteúdo disponível.',
            ];
        }

        $ordered = self::reorderBlocks($blocks);

        return [
            'blocks'        => $ordered,
            'text_fallback' => self::buildTextFallback($ordered),
        ];
    }

    private static function stripMarkdown(string $text): string
    {
        if ($text === '') return '';
        // Remove bold/italic markers (**bold**, __bold__, *italic*, _italic_)
        $text = preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $text) ?? $text;
        $text = preg_replace('/(?<!\w)[*_](.+?)[*_](?!\w)/s', '$1', $text) ?? $text;
        // Remove heading markers at line starts
        $text = preg_replace('/^\s*#{1,6}\s+/m', '', $text) ?? $text;
        // Remove list bullets (- * •) at line starts
        $text = preg_replace('/^\s*[-*•]\s+/m', '', $text) ?? $text;
        // Remove numbered list markers at line starts
        $text = preg_replace('/^\s*\d+[.)]\s+/m', '', $text) ?? $text;
        // Backticks inline code
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        // Collapse 3+ blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    // Keys that should be filtered from tool_results before the heuristics
    // decide between chart/card_grid — they describe scope, not metrics.
    private const NOISE_KEYS = [
        'event_id', 'organizer_id', 'user_id', 'operator_id', 'vendor_id',
        'sector_filter', 'type', 'kind', 'source',
    ];

    private static function filterNoiseKeys(array $data): array
    {
        if (array_is_list($data)) return $data;
        $filtered = [];
        foreach ($data as $k => $v) {
            $lc = strtolower((string)$k);
            if (in_array($lc, self::NOISE_KEYS, true)) continue;
            if (str_ends_with($lc, '_id') || str_ends_with($lc, '_filter')) continue;
            $filtered[$k] = $v;
        }
        return $filtered;
    }

    private static function looksLikeSeriesTool(string $toolName): bool
    {
        $lc = strtolower($toolName);
        if ($lc === '') return false;
        foreach (self::SERIES_KEYWORDS as $kw) {
            if (str_contains($lc, $kw)) return true;
        }
        return false;
    }

    private static function reorderBlocks(array $blocks): array
    {
        if (count($blocks) < 2) return $blocks;
        // usort is NOT stable for equal weights in PHP before 8.0; in 8.0+ it is.
        $withIndex = [];
        foreach ($blocks as $i => $b) {
            $withIndex[] = [
                'order' => self::BLOCK_ORDER[$b['type'] ?? ''] ?? 60,
                'idx'   => $i,
                'block' => $b,
            ];
        }
        usort($withIndex, static function ($a, $b) {
            return $a['order'] <=> $b['order'] ?: $a['idx'] <=> $b['idx'];
        });
        return array_map(static fn($entry) => $entry['block'], $withIndex);
    }

    private static function buildTableBlock(string $id, string $title, array $rows): array
    {
        $sample = $rows[array_key_first($rows)];
        $columns = [];
        foreach (array_keys($sample) as $key) {
            $columns[] = [
                'key'   => (string)$key,
                'label' => self::prettyLabel((string)$key),
                'type'  => self::inferColumnType((string)$key, $sample[$key] ?? null),
            ];
        }

        return [
            'type'    => 'table',
            'id'      => $id,
            'title'   => $title,
            'columns' => $columns,
            'rows'    => array_values($rows),
        ];
    }

    private static function buildChartBlock(string $id, string $title, array $entries, string $chartType = 'bar'): array
    {
        return [
            'type'       => 'chart',
            'id'         => $id,
            'title'      => $title,
            'chart_type' => $chartType,
            'data'       => $entries,
            'x_key'      => 'label',
            'y_key'      => 'value',
            'unit'       => null,
        ];
    }

    private static function normalizeCard(array $card): array
    {
        return [
            'label'           => (string)($card['label'] ?? ''),
            'value'           => isset($card['value']) ? (string)$card['value'] : '',
            'delta'           => isset($card['delta']) ? (string)$card['delta'] : null,
            'delta_direction' => $card['delta_direction'] ?? null,
            'icon'            => $card['icon'] ?? null,
            'note'            => $card['note'] ?? null,
        ];
    }

    private static function inferColumnType(string $key, mixed $sample): string
    {
        $lc = strtolower($key);
        if (str_contains($lc, 'cache') || str_contains($lc, 'price') || str_contains($lc, 'total')
            || str_contains($lc, 'amount') || str_contains($lc, 'valor') || str_contains($lc, 'cost')) {
            return 'currency';
        }
        if (str_contains($lc, 'date') || str_contains($lc, 'data') || str_contains($lc, '_at')) {
            return 'date';
        }
        if (str_contains($lc, 'pct') || str_contains($lc, 'percent')) {
            return 'percent';
        }
        if (is_bool($sample)) return 'boolean';
        if (is_numeric($sample)) return 'number';
        return 'text';
    }

    private static function prettyLabel(string $raw): string
    {
        $s = trim(str_replace('_', ' ', $raw));
        return $s === '' ? '' : mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    private static function formatNumber(float $value): string
    {
        if ($value >= 1000) {
            return number_format($value, 0, ',', '.');
        }
        if (fmod($value, 1.0) === 0.0) {
            return (string)(int)$value;
        }
        return number_format($value, 2, ',', '.');
    }

    private static function tryBuildSpecializedBlock(callable $nextId, string $toolName, array $data): ?array
    {
        $lcTool = strtolower($toolName);
        $firstKey = array_key_first($data);
        $isList = is_int($firstKey);

        // image: object with single url-like field
        if (!$isList) {
            $url = $data['image_url'] ?? $data['photo_url'] ?? $data['url'] ?? null;
            if (is_string($url) && preg_match('#^https?://#i', $url)) {
                return [
                    'type'    => 'image',
                    'id'      => $nextId(),
                    'url'     => $url,
                    'caption' => isset($data['caption']) ? (string)$data['caption'] : (isset($data['title']) ? (string)$data['title'] : null),
                    'alt'     => isset($data['alt']) ? (string)$data['alt'] : null,
                ];
            }
        }

        // map: any row with lat+lng (or latitude+longitude)
        $hasGeo = false;
        $geoRows = [];
        if ($isList) {
            foreach ($data as $row) {
                if (!is_array($row)) continue;
                $lat = $row['lat'] ?? $row['latitude'] ?? null;
                $lng = $row['lng'] ?? $row['lon'] ?? $row['longitude'] ?? null;
                if (is_numeric($lat) && is_numeric($lng)) {
                    $hasGeo = true;
                    $geoRows[] = [
                        'lat'   => (float)$lat,
                        'lng'   => (float)$lng,
                        'label' => (string)($row['label'] ?? $row['name'] ?? $row['title'] ?? ''),
                        'kind'  => isset($row['kind']) ? (string)$row['kind'] : null,
                    ];
                }
            }
        }
        if ($hasGeo && !empty($geoRows)) {
            $avgLat = array_sum(array_column($geoRows, 'lat')) / count($geoRows);
            $avgLng = array_sum(array_column($geoRows, 'lng')) / count($geoRows);
            return [
                'type'    => 'map',
                'id'      => $nextId(),
                'center'  => ['lat' => $avgLat, 'lng' => $avgLng],
                'zoom'    => 15,
                'markers' => $geoRows,
            ];
        }

        // lineup: list with stage + artist + start_at
        if ($isList) {
            $sample = $data[$firstKey];
            if (is_array($sample)
                && (isset($sample['artist_name']) || isset($sample['artist']))
                && (isset($sample['start_at']) || isset($sample['start']))) {
                $stages = [];
                foreach ($data as $row) {
                    if (!is_array($row)) continue;
                    $stageName = (string)($row['stage'] ?? $row['palco'] ?? 'Principal');
                    if (!isset($stages[$stageName])) {
                        $stages[$stageName] = ['name' => $stageName, 'slots' => []];
                    }
                    $stages[$stageName]['slots'][] = [
                        'artist_name' => (string)($row['artist_name'] ?? $row['artist'] ?? ''),
                        'start_at'    => (string)($row['start_at'] ?? $row['start'] ?? ''),
                        'end_at'      => (string)($row['end_at'] ?? $row['end'] ?? ''),
                        'image_url'   => $row['image_url'] ?? $row['photo_url'] ?? null,
                    ];
                }
                return [
                    'type'   => 'lineup',
                    'id'     => $nextId(),
                    'stages' => array_values($stages),
                ];
            }
        }

        // timeline: list with at/time + label, when tool name suggests schedule/agenda
        $timelineKeywords = ['schedule', 'agenda', 'timeline', 'cronograma', 'roteiro'];
        $isTimelineTool = false;
        foreach ($timelineKeywords as $kw) {
            if ($lcTool !== '' && str_contains($lcTool, $kw)) {
                $isTimelineTool = true;
                break;
            }
        }
        if ($isList && $isTimelineTool) {
            $events = [];
            foreach ($data as $row) {
                if (!is_array($row)) continue;
                $at = $row['at'] ?? $row['time'] ?? $row['start_at'] ?? null;
                $label = $row['label'] ?? $row['title'] ?? $row['name'] ?? null;
                if ($at && $label) {
                    $events[] = [
                        'at'          => (string)$at,
                        'label'       => (string)$label,
                        'description' => isset($row['description']) ? (string)$row['description'] : null,
                        'icon'        => isset($row['icon']) ? (string)$row['icon'] : 'clock',
                        'status'      => isset($row['status']) ? (string)$row['status'] : 'upcoming',
                    ];
                }
            }
            if (!empty($events)) {
                return [
                    'type'   => 'timeline',
                    'id'     => $nextId(),
                    'title'  => self::prettyLabel($toolName),
                    'events' => $events,
                ];
            }
        }

        return null;
    }

    private static function buildTextFallback(array $blocks): string
    {
        $raw = self::buildRawTextFallback($blocks);
        return self::stripMarkdown($raw);
    }

    private static function buildRawTextFallback(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            switch ($b['type'] ?? '') {
                case 'insight':
                case 'text':
                    $line = trim((string)($b['title'] ?? ''));
                    if ($line !== '') $parts[] = $line;
                    $body = trim((string)($b['body'] ?? ''));
                    if ($body !== '') $parts[] = $body;
                    break;

                case 'chart':
                    if (!empty($b['title'])) $parts[] = (string)$b['title'];
                    foreach (($b['data'] ?? []) as $d) {
                        $parts[] = '- ' . ($d['label'] ?? '') . ': ' . ($d['value'] ?? '');
                    }
                    break;

                case 'table':
                    if (!empty($b['title'])) $parts[] = (string)$b['title'];
                    $rowCount = count($b['rows'] ?? []);
                    $parts[] = "({$rowCount} registros)";
                    break;

                case 'card_grid':
                    if (!empty($b['title'])) $parts[] = (string)$b['title'];
                    foreach (($b['cards'] ?? []) as $c) {
                        $parts[] = '- ' . ($c['label'] ?? '') . ': ' . ($c['value'] ?? '');
                    }
                    break;

                case 'actions':
                    if (!empty($b['description'])) $parts[] = (string)$b['description'];
                    foreach (($b['items'] ?? []) as $it) {
                        if (!empty($it['label'])) $parts[] = '[' . $it['label'] . ']';
                    }
                    break;

                case 'timeline':
                    if (!empty($b['title'])) $parts[] = (string)$b['title'];
                    foreach (($b['events'] ?? []) as $ev) {
                        $parts[] = '- ' . ($ev['at'] ?? '') . ' ' . ($ev['label'] ?? '');
                    }
                    break;

                case 'lineup':
                    foreach (($b['stages'] ?? []) as $st) {
                        $parts[] = (string)($st['name'] ?? '');
                        foreach (($st['slots'] ?? []) as $sl) {
                            $parts[] = '  ' . ($sl['start_at'] ?? '') . ' ' . ($sl['artist_name'] ?? '');
                        }
                    }
                    break;

                case 'map':
                    $count = count($b['markers'] ?? []);
                    $parts[] = "(mapa com {$count} pontos)";
                    break;

                case 'image':
                    if (!empty($b['caption'])) $parts[] = (string)$b['caption'];
                    break;
            }
        }
        return trim(implode("\n", $parts));
    }
}
