<?php

namespace EnjoyFun\Services;

final class AIPromptCatalogService
{
    public static function listCatalog(): array
    {
        $catalog = [];
        foreach (self::agentCatalog() as $agentKey => $definition) {
            $catalog[] = array_merge(['agent_key' => $agentKey], $definition);
        }

        return $catalog;
    }

    public static function composeSystemPrompt(array $legacyConfig, ?array $agentExecution, string $surface): string
    {
        $agentKey = trim((string)($agentExecution['agent_key'] ?? 'management'));
        $catalog = self::agentCatalog()[$agentKey] ?? self::agentCatalog()['management'];
        $surfaceDefinition = self::surfaceCatalog()[$surface] ?? self::surfaceCatalog()['general'];

        $parts = [
            'Você é a camada de inteligência operacional da EnjoyFun. Responda em português do Brasil, com clareza, objetividade e foco prático.',
            "IDENTIDADE DO AGENTE:\n" . ($catalog['system_prompt'] ?? ''),
            "CONTRATO DA SUPERFICIE:\n" . ($surfaceDefinition['system_prompt'] ?? ''),
        ];

        $overridePrompt = self::resolveOverridePrompt($agentExecution);
        if ($overridePrompt !== '') {
            $parts[] = "OVERRIDE DO ORGANIZER PARA O AGENTE:\n{$overridePrompt}";
        }

        $legacyPrompt = trim((string)($legacyConfig['system_prompt'] ?? ''));
        if ($legacyPrompt !== '') {
            $parts[] = "PROMPT OPERACIONAL LEGADO DO ORGANIZER:\n{$legacyPrompt}";
        }

        return implode("\n\n", array_filter($parts, static fn(string $value): bool => trim($value) !== ''));
    }

    public static function buildUserPrompt(string $surface, array $context, string $question): string
    {
        return match ($surface) {
            'parking' => self::buildParkingPrompt($context, $question),
            'workforce' => self::buildWorkforcePrompt($context, $question),
            default => self::buildDefaultPrompt($context, $question),
        };
    }

    public static function getEndOfEventReportBlueprint(): array
    {
        return [
            'report_type' => 'end_of_event',
            'automation_trigger' => 'event.status=finished',
            'title_template' => 'Raio X final do evento',
            'objective' => 'Gerar um material consolidado de aprendizado operacional, executivo e tático que fique vivo para futuras execuções do organizer.',
            'sections' => [
                [
                    'section_key' => 'executive-summary',
                    'section_title' => 'Resumo executivo',
                    'agent_key' => 'management',
                    'required_fields' => ['visao_geral', 'resultado_final', 'principais_riscos', 'proximos_passos'],
                ],
                [
                    'section_key' => 'logistics-operations',
                    'section_title' => 'Operacao e logistica',
                    'agent_key' => 'logistics',
                    'required_fields' => ['gargalos', 'janelas_criticas', 'falhas_de_fluxo', 'melhorias_estruturais'],
                ],
                [
                    'section_key' => 'bar-performance',
                    'section_title' => 'Performance de bar e PDV',
                    'agent_key' => 'bar',
                    'required_fields' => ['mix', 'rupturas', 'ritmo', 'acoes_para_proxima_edicao'],
                ],
                [
                    'section_key' => 'commercial-demand',
                    'section_title' => 'Demanda comercial e marketing',
                    'agent_key' => 'marketing',
                    'required_fields' => ['origem_da_demanda', 'sinais_de_interesse', 'janelas_de_campanha', 'publicos_prioritarios'],
                ],
                [
                    'section_key' => 'suppliers-and-contracting',
                    'section_title' => 'Fornecedores e contratacao',
                    'agent_key' => 'contracting',
                    'required_fields' => ['fornecedores_destaque', 'fornecedores_de_risco', 'renovacoes_recomendadas', 'pendencias_contratuais'],
                ],
                [
                    'section_key' => 'participant-feedback',
                    'section_title' => 'Feedback de participantes e operacao',
                    'agent_key' => 'feedback',
                    'required_fields' => ['reclamacoes_recorrentes', 'elogios_recorrentes', 'impacto_no_fluxo', 'prioridade_de_correcao'],
                ],
            ],
        ];
    }

    private static function buildParkingPrompt(array $context, string $question): string
    {
        return sprintf(
            "SUPERFICIE: PARKING\nEVENTO: %s\nSTATUS DO EVENTO: %s\nINICIO: %s\nFIM: %s\nREGISTROS TOTAIS: %d\nVEICULOS NO LOCAL: %d\nPENDENTES DE BIP: %d\nSAIDAS REGISTRADAS: %d\nENTRADAS NA ULTIMA HORA: %d\nSAIDAS NA ULTIMA HORA: %d\nMIX DE VEICULOS (JSON): %s\nREGISTROS RECENTES (JSON): %s\n\nTAREFAS:\n1. Ler o fluxo atual de entrada/saida e detectar gargalos.\n2. Destacar filas ou anomalias provaveis com base nos pendentes e no ritmo recente.\n3. Sugerir ate 3 acoes operacionais praticas para a portaria.\n4. Se o contexto estiver fraco, declarar explicitamente quais dados faltam.\n\nPERGUNTA DO OPERADOR: %s",
            (string)($context['event_name'] ?? 'Evento nao identificado'),
            (string)($context['event_status'] ?? 'desconhecido'),
            (string)($context['event_starts_at'] ?? 'n/d'),
            (string)($context['event_ends_at'] ?? 'n/d'),
            (int)($context['records_total'] ?? 0),
            (int)($context['parked_total'] ?? 0),
            (int)($context['pending_total'] ?? 0),
            (int)($context['exited_total'] ?? 0),
            (int)($context['entries_last_hour'] ?? 0),
            (int)($context['exits_last_hour'] ?? 0),
            self::encodeJsonFragment($context['vehicle_mix'] ?? []),
            self::encodeJsonFragment($context['recent_records'] ?? []),
            $question
        );
    }

    private static function buildWorkforcePrompt(array $context, string $question): string
    {
        $selectedManager = is_array($context['selected_manager_context'] ?? null) ? $context['selected_manager_context'] : [];
        $treeStatus = is_array($context['workforce_tree_status'] ?? null) ? $context['workforce_tree_status'] : [];
        $timeline = is_array($context['workforce_timeline'] ?? null)
            ? $context['workforce_timeline']
            : (is_array($context['workforce_structure'] ?? null) ? $context['workforce_structure'] : []);
        $treeSnapshot = is_array($context['workforce_tree_snapshot'] ?? null) ? $context['workforce_tree_snapshot'] : [];
        $leadershipDigest = is_array($context['workforce_leadership_digest'] ?? null)
            ? $context['workforce_leadership_digest']
            : [];
        $focusSector = is_array($context['workforce_focus_sector'] ?? null) ? $context['workforce_focus_sector'] : null;
        $effectiveFocus = is_array($context['workforce_effective_focus'] ?? null)
            ? $context['workforce_effective_focus']
            : null;
        $selectedManagerTree = is_array($context['workforce_selected_manager_tree'] ?? null)
            ? $context['workforce_selected_manager_tree']
            : null;
        $selectedManagerConfig = is_array($context['selected_manager_operational_config'] ?? null)
            ? $context['selected_manager_operational_config']
            : null;
        $costSnapshot = is_array($context['workforce_cost_snapshot'] ?? null) ? $context['workforce_cost_snapshot'] : [];
        $focusCosts = is_array($context['workforce_focus_costs'] ?? null) ? $context['workforce_focus_costs'] : null;
        $attentionSectors = is_array($context['workforce_attention_sectors'] ?? null) ? $context['workforce_attention_sectors'] : [];
        $mealExecutionSnapshot = is_array($context['workforce_meal_execution_snapshot'] ?? null)
            ? $context['workforce_meal_execution_snapshot']
            : [];
        $focusMealExecution = is_array($context['workforce_focus_meal_execution'] ?? null)
            ? $context['workforce_focus_meal_execution']
            : null;

        return sprintf(
            "SUPERFICIE: WORKFORCE\nEVENTO: %s\nSTATUS DO EVENTO: %s\nINICIO: %s\nFIM: %s\nMEMBROS NO EVENTO: %d\nASSIGNMENTS CARREGADOS: %d\nASSIGNMENTS GERENCIAIS VINCULADOS: %d\nASSIGNMENTS OPERACIONAIS VINCULADOS: %d\nLIDERANCAS CONFIGURADAS: %d\nLIDERANCAS PREENCHIDAS: %d\nLIDERANCAS PENDENTES: %d\nTIME PLANEJADO (TREE): %d\nTIME PREENCHIDO (TREE): %d\nASSIGNMENTS COM ROOT MANAGER: %d\nASSIGNMENTS SEM VINCULO: %d\nSETORES ATIVOS: %d\nMANAGER ROOTS: %d\nCOORDENACOES/SUPERVISOES: %d\nTREE USABLE: %s\nTREE READY: %s\nSOURCE PREFERENCE: %s\nFOCO SOLICITADO: %s\nFONTE DO FOCO EFETIVO: %s\nBLOCKERS (JSON): %s\nTIMELINE DO EVENTO (JSON): %s\nARVORE WORKFORCE (JSON): %s\nDIGEST DE LIDERANCA (JSON): %s\nSETORES PRIORITARIOS (JSON): %s\nSETORES (JSON): %s\nCARGOS MAIS FREQUENTES (JSON): %s\nASSIGNMENTS RECENTES (JSON): %s\nFOCO EFETIVO (JSON): %s\nFOCO DE SETOR (JSON): %s\nGERENTE/FOCO SELECIONADO (JSON): %s\nESTRUTURA DO FOCO SELECIONADO (JSON): %s\nCONFIGURACAO OPERACIONAL DO FOCO (JSON): %s\nCUSTOS WORKFORCE (JSON): %s\nCUSTOS DO FOCO (JSON): %s\nREFEICOES EXECUTADAS WORKFORCE (JSON): %s\nREFEICOES EXECUTADAS NO FOCO (JSON): %s\n\nTAREFAS:\n1. Separar claramente pessoas em assignments carregados versus liderancas configuradas na arvore; nunca tratar essas contagens como equivalentes.\n2. Ler cobertura, hierarquia, lacunas de lideranca, binds e distribuicao por setor priorizando o bucket do event_role quando existir.\n3. Cruzar custos, refeicoes planejadas, refeicoes servidas, quantidade de turnos, horas por turno e configuracao operacional do Workforce.\n4. Usar DIGEST DE LIDERANCA e FOCO EFETIVO para citar nominalmente gerente, coordenadores e supervisores por setor, inclusive quando nao houver foco manual.\n5. Explicar qualquer ambiguidade real de contagem, por exemplo: gerente configurado na arvore sem assignment proprio, coordenador presente em assignments e lideranca estrutural, ou bindings faltantes.\n6. Propor ate 3 acoes praticas e objetivas para corrigir cobertura, estrutura, custo ou distribuicao.\n7. Se algum dado estiver ausente, declarar explicitamente o que falta.\n\nPERGUNTA DO OPERADOR: %s",
            (string)($context['event_name'] ?? 'Evento nao identificado'),
            (string)($context['event_status'] ?? 'desconhecido'),
            (string)($context['event_starts_at'] ?? 'n/d'),
            (string)($context['event_ends_at'] ?? 'n/d'),
            (int)($context['members_total'] ?? 0),
            (int)($context['assignments_total'] ?? 0),
            (int)($context['managerial_assignments_total'] ?? 0),
            (int)($context['operational_assignments_total'] ?? 0),
            (int)($context['leadership_positions_total'] ?? 0),
            (int)($context['leadership_filled_total'] ?? 0),
            (int)($context['leadership_placeholder_total'] ?? 0),
            (int)($context['planned_workforce_total'] ?? 0),
            (int)($context['filled_workforce_total'] ?? 0),
            (int)($context['assignments_with_root_manager'] ?? 0),
            (int)($context['assignments_missing_bindings'] ?? 0),
            (int)($context['active_sectors_count'] ?? 0),
            (int)($treeStatus['manager_roots_count'] ?? 0),
            (int)($treeStatus['managerial_child_roles_count'] ?? 0),
            !empty($treeStatus['tree_usable']) ? 'sim' : 'nao',
            !empty($treeStatus['tree_ready']) ? 'sim' : 'nao',
            (string)($treeStatus['source_preference'] ?? 'legacy'),
            (string)($context['focus_sector_requested'] ?? 'nenhum'),
            (string)($context['focus_sector_source'] ?? 'none'),
            self::encodeJsonFragment($treeStatus['activation_blockers'] ?? []),
            self::encodeJsonFragment($timeline),
            self::encodeJsonFragment($treeSnapshot),
            self::encodeJsonFragment($leadershipDigest),
            self::encodeJsonFragment($attentionSectors),
            self::encodeJsonFragment($context['workforce_sectors'] ?? []),
            self::encodeJsonFragment($context['workforce_top_roles'] ?? []),
            self::encodeJsonFragment($context['workforce_recent_assignments'] ?? []),
            self::encodeJsonFragment($effectiveFocus ?? []),
            self::encodeJsonFragment($focusSector ?? []),
            self::encodeJsonFragment($selectedManager),
            self::encodeJsonFragment($selectedManagerTree ?? []),
            self::encodeJsonFragment($selectedManagerConfig ?? []),
            self::encodeJsonFragment($costSnapshot),
            self::encodeJsonFragment($focusCosts ?? []),
            self::encodeJsonFragment($mealExecutionSnapshot),
            self::encodeJsonFragment($focusMealExecution ?? []),
            $question
        );
    }

    private static function buildDefaultPrompt(array $context, string $question): string
    {
        return sprintf(
            "SUPERFICIE: %s\nSETOR EM ANALISE: %s\nPERIODO: %s\nFATURAMENTO TOTAL: R$ %s\nITENS VENDIDOS: %s und\nTOP PRODUTOS (JSON): %s\nESTOQUE CRITICO (JSON): %s\nCONTEXTO BRUTO (JSON): %s\n\nTAREFAS:\n1. Avaliar o estado operacional do modulo.\n2. Explicar os sinais mais relevantes com linguagem objetiva.\n3. Propor ate 3 proximas acoes dentro do evento.\n4. Declarar qualquer ausencia importante de dados.\n\nPERGUNTA DO OPERADOR: %s",
            strtoupper((string)($context['surface'] ?? 'GENERAL')),
            strtoupper((string)($context['sector'] ?? 'N/A')),
            (string)($context['time_filter'] ?? 'N/A'),
            (string)($context['total_revenue'] ?? '0'),
            (string)($context['total_items'] ?? '0'),
            self::encodeJsonFragment($context['top_products'] ?? []),
            self::encodeJsonFragment($context['stock_levels'] ?? []),
            self::encodeJsonFragment($context),
            $question
        );
    }

    private static function resolveOverridePrompt(?array $agentExecution): string
    {
        if ($agentExecution === null) {
            return '';
        }

        $config = is_array($agentExecution['config'] ?? null) ? $agentExecution['config'] : [];
        foreach (['system_prompt', 'instructions', 'prompt'] as $field) {
            $value = trim((string)($config[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function agentCatalog(): array
    {
        return [
            'marketing' => [
                'label' => 'Agente de Marketing',
                'surfaces' => ['dashboard', 'tickets', 'messaging', 'customer'],
                'system_prompt' => 'Especialista em demanda, conversao, comportamento de publico e sinais comerciais do evento. Nunca invente canais ou resultados nao observaveis no contexto.',
                'report_goal' => 'Consolidar demanda, sinais de audiencia, horarios fortes e oportunidades comerciais para a proxima edicao.',
            ],
            'logistics' => [
                'label' => 'Agente de Logistica',
                'surfaces' => ['parking', 'meals-control', 'workforce', 'events'],
                'system_prompt' => 'Especialista em fluxo operacional, filas, cobertura, deslocamento, abastecimento e contingencias de evento. Priorize leitura de gargalos e medidas executaveis no curto prazo.',
                'report_goal' => 'Consolidar gargalos, janelas de atrito, incidentes recorrentes e recomendacoes de estrutura para a proxima operacao.',
            ],
            'management' => [
                'label' => 'Agente de Gestao',
                'surfaces' => ['dashboard', 'analytics', 'finance', 'general'],
                'system_prompt' => 'Especialista em sintese executiva e decisao. Seu papel e organizar sinais em prioridades, riscos, impacto e proximo passo.',
                'report_goal' => 'Fechar a leitura executiva final do evento, conectando operacao, demanda, financeiro e experiencia.',
            ],
            'bar' => [
                'label' => 'Agente de Bar e Estoque',
                'surfaces' => ['bar', 'food', 'shop'],
                'system_prompt' => 'Especialista em PDV, ruptura, ritmo de venda, mix de produtos e operacao de estoque em evento.',
                'report_goal' => 'Consolidar performance de PDV, ruptura, mix e oportunidades operacionais/comerciais para a proxima edicao.',
            ],
            'contracting' => [
                'label' => 'Agente de Contratacao',
                'surfaces' => ['artists', 'finance', 'settings'],
                'system_prompt' => 'Especialista em fornecedores, artistas, contratos, risco de entrega e continuidade operacional.',
                'report_goal' => 'Mapear contratos, fornecedores criticos, performance de parceiros e necessidades de renegociacao.',
            ],
            'feedback' => [
                'label' => 'Agente de Feedback',
                'surfaces' => ['messaging', 'customer', 'analytics'],
                'system_prompt' => 'Especialista em consolidar reclamacoes, elogios, friccoes de jornada e sinais de experiencia do participante e da operacao.',
                'report_goal' => 'Organizar sinais de experiencia e feedback em prioridades claras para correcao e manutencao.',
            ],
        ];
    }

    private static function surfaceCatalog(): array
    {
        return [
            'general' => [
                'system_prompt' => 'Responda com base no contexto disponivel e assuma explicitamente quando os dados forem insuficientes.',
            ],
            'parking' => [
                'system_prompt' => 'A tela representa a portaria de estacionamento. Leia fluxo de entrada, saida, pendencias de bip e ocupacao corrente. Nao transforme isso em analise de vendas.',
            ],
            'bar' => [
                'system_prompt' => 'A tela representa um setor de PDV. Leia ritmo, mix, ruptura e proximas acoes dentro do evento.',
            ],
            'food' => [
                'system_prompt' => 'A tela representa um setor de PDV de alimentacao. Observe demanda, fila, ruptura e aproveitamento do mix.',
            ],
            'shop' => [
                'system_prompt' => 'A tela representa uma loja/merchandise. Observe conversao, mix, reposicao e itens de baixa tracao.',
            ],
            'meals-control' => [
                'system_prompt' => 'A tela representa o controle operacional de refeicoes. Observe janelas, saldo, cobertura e consumo fora do esperado.',
            ],
            'workforce' => [
                'system_prompt' => 'A tela representa o modulo de workforce. Observe cobertura, hierarquia, lacunas e equilibrio de operacao.',
            ],
            'events' => [
                'system_prompt' => 'A tela representa a agenda e o ciclo de vida dos eventos. Observe viradas de status, calendario e riscos de transicao.',
            ],
        ];
    }

    private static function encodeJsonFragment(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '[]';
    }
}
