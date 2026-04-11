<?php

namespace EnjoyFun\Services;

use PDO;
use RuntimeException;

require_once __DIR__ . '/SecretCryptoService.php';
require_once __DIR__ . '/AIAgentRegistryService.php';

final class AIProviderConfigService
{
    private const SUPPORTED_PROVIDERS = ['openai', 'gemini', 'claude'];
    private const DEFAULT_MODELS = [
        'openai' => 'gpt-4o-mini',
        'gemini' => 'gemini-2.5-flash',
        'claude' => 'claude-3-5-sonnet-latest',
    ];
    private const DEFAULT_APPROVAL_MODE = 'confirm_write';

    public static function listSupportedProviders(): array
    {
        return self::SUPPORTED_PROVIDERS;
    }

    public static function listProviders(PDO $db, int $organizerId): array
    {
        $providers = [];
        foreach (self::providerMetadata() as $provider => $metadata) {
            $providers[$provider] = [
                'provider' => $provider,
                'label' => $metadata['label'],
                'supports_tool_use' => $metadata['supports_tool_use'],
                'model' => self::DEFAULT_MODELS[$provider] ?? null,
                'base_url' => null,
                'is_active' => false,
                'is_default' => false,
                'is_configured' => false,
                'settings' => [],
                'source' => 'catalog',
            ];
        }

        if (!self::tableExists($db, 'organizer_ai_providers')) {
            return array_values($providers);
        }

        $stmt = $db->prepare("
            SELECT provider, model, base_url, is_active, is_default, encrypted_api_key, settings_json
            FROM public.organizer_ai_providers
            WHERE organizer_id = :organizer_id
            ORDER BY provider
        ");
        $stmt->execute([':organizer_id' => $organizerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $provider = self::normalizeProvider($row['provider'] ?? null);
            if ($provider === null) {
                continue;
            }

            $providers[$provider] = [
                'provider' => $provider,
                'label' => self::providerMetadata()[$provider]['label'],
                'supports_tool_use' => self::providerMetadata()[$provider]['supports_tool_use'],
                'model' => self::nullableTrimmedString($row['model'] ?? null) ?? (self::DEFAULT_MODELS[$provider] ?? null),
                'base_url' => self::nullableTrimmedString($row['base_url'] ?? null),
                'is_active' => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'is_default' => filter_var($row['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'is_configured' => trim((string)($row['encrypted_api_key'] ?? '')) !== '',
                'settings' => self::decodeJsonArray($row['settings_json'] ?? null),
                'source' => 'tenant',
            ];
        }

        return array_values($providers);
    }

    public static function getProvider(PDO $db, int $organizerId, string $provider): array
    {
        $normalizedProvider = self::normalizeProvider($provider);
        if ($normalizedProvider === null) {
            throw new RuntimeException('Provider de IA invalido. Use openai, gemini ou claude.', 422);
        }

        foreach (self::listProviders($db, $organizerId) as $item) {
            if (($item['provider'] ?? null) === $normalizedProvider) {
                return $item;
            }
        }

        throw new RuntimeException('Provider de IA nao encontrado.', 404);
    }

    public static function upsertProvider(PDO $db, int $organizerId, string $provider, array $payload): array
    {
        if (!self::tableExists($db, 'organizer_ai_providers')) {
            throw new RuntimeException('Schema de providers de IA não materializado. Aplique a migration 038_ai_orchestrator_foundation.sql.', 409);
        }

        $normalizedProvider = self::normalizeProvider($provider);
        if ($normalizedProvider === null) {
            throw new RuntimeException('Provider de IA inválido. Use openai, gemini ou claude.', 422);
        }

        $existing = self::getProviderRow($db, $organizerId, $normalizedProvider);
        $apiKey = array_key_exists('api_key', $payload)
            ? trim((string)($payload['api_key'] ?? ''))
            : null;

        $encryptedApiKey = $existing['encrypted_api_key'] ?? '';
        if ($apiKey !== null) {
            $apiKey = $apiKey !== ''
                ? self::validateProviderApiKey($normalizedProvider, $apiKey, true)
                : '';
            $encryptedApiKey = $apiKey !== ''
                ? SecretCryptoService::encrypt($apiKey, self::providerScope($organizerId, $normalizedProvider))
                : '';
        }

        $model = self::nullableTrimmedString($payload['model'] ?? null)
            ?? self::nullableTrimmedString($existing['model'] ?? null)
            ?? (self::DEFAULT_MODELS[$normalizedProvider] ?? null);
        $baseUrl = self::nullableTrimmedString($payload['base_url'] ?? null)
            ?? self::nullableTrimmedString($existing['base_url'] ?? null);
        $isActive = array_key_exists('is_active', $payload)
            ? filter_var($payload['is_active'], FILTER_VALIDATE_BOOLEAN)
            : filter_var($existing['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $isDefault = array_key_exists('is_default', $payload)
            ? filter_var($payload['is_default'], FILTER_VALIDATE_BOOLEAN)
            : filter_var($existing['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $settings = $payload['settings'] ?? self::decodeJsonArray($existing['settings_json'] ?? null);
        if (!is_array($settings)) {
            $settings = [];
        }

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            if ($isDefault) {
                $db->prepare("
                    UPDATE public.organizer_ai_providers
                    SET is_default = FALSE, updated_at = NOW()
                    WHERE organizer_id = :organizer_id
                      AND provider <> :provider
                ")->execute([
                    ':organizer_id' => $organizerId,
                    ':provider' => $normalizedProvider,
                ]);
            }

            $stmt = $db->prepare("
                INSERT INTO public.organizer_ai_providers (
                    organizer_id,
                    provider,
                    encrypted_api_key,
                    model,
                    base_url,
                    is_active,
                    is_default,
                    settings_json,
                    created_at,
                    updated_at
                ) VALUES (
                    :organizer_id,
                    :provider,
                    :encrypted_api_key,
                    :model,
                    :base_url,
                    :is_active,
                    :is_default,
                    :settings_json,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (organizer_id, provider) DO UPDATE SET
                    encrypted_api_key = EXCLUDED.encrypted_api_key,
                    model = EXCLUDED.model,
                    base_url = EXCLUDED.base_url,
                    is_active = EXCLUDED.is_active,
                    is_default = EXCLUDED.is_default,
                    settings_json = EXCLUDED.settings_json,
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':organizer_id' => $organizerId,
                ':provider' => $normalizedProvider,
                ':encrypted_api_key' => $encryptedApiKey,
                ':model' => $model,
                ':base_url' => $baseUrl,
                ':is_active' => self::postgresBool($isActive),
                ':is_default' => self::postgresBool($isDefault),
                ':settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            ]);

            if ($ownsTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return self::getProviderPublicPayload($db, $organizerId, $normalizedProvider);
    }

    public static function resolveRuntime(PDO $db, int $organizerId, string $provider, array $fallback = []): array
    {
        $normalizedProvider = self::normalizeProvider($provider) ?? 'openai';
        $row = self::tableExists($db, 'organizer_ai_providers')
            ? self::getProviderRow($db, $organizerId, $normalizedProvider)
            : null;

        $apiKey = '';
        $source = 'env';
        $isActive = true;

        if ($row) {
            $isActive = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if (!$isActive) {
                throw new RuntimeException("O provider {$normalizedProvider} está desativado para este organizador.", 409);
            }

            $encryptedApiKey = trim((string)($row['encrypted_api_key'] ?? ''));
            if ($encryptedApiKey !== '') {
                try {
                    $apiKey = SecretCryptoService::decrypt($encryptedApiKey, self::providerScope($organizerId, $normalizedProvider));
                    $source = 'tenant';
                } catch (\Throwable $e) {
                    error_log("[AIProviderConfigService] Falha ao descriptografar API key do provider {$normalizedProvider} para organizer {$organizerId}: {$e->getMessage()}. Fallback para env.");
                    $apiKey = '';
                }
            }
        }

        if ($apiKey === '') {
            $apiKey = trim((string)($fallback['api_key'] ?? self::envApiKey($normalizedProvider)));
        }
        if ($apiKey === '') {
            throw new RuntimeException("API Key do provider {$normalizedProvider} não configurada.", 503);
        }
        $apiKey = self::validateProviderApiKey($normalizedProvider, $apiKey, false);

        $model = self::nullableTrimmedString($row['model'] ?? null)
            ?? self::nullableTrimmedString($fallback['model'] ?? null)
            ?? self::envModel($normalizedProvider)
            ?? (self::DEFAULT_MODELS[$normalizedProvider] ?? null);
        $baseUrl = self::nullableTrimmedString($row['base_url'] ?? null)
            ?? self::nullableTrimmedString($fallback['base_url'] ?? null)
            ?? self::envBaseUrl($normalizedProvider);

        return [
            'provider' => $normalizedProvider,
            'api_key' => $apiKey,
            'model' => $model,
            'base_url' => $baseUrl,
            'source' => $source,
            'is_active' => $isActive,
        ];
    }

    public static function listAgents(PDO $db, int $organizerId): array
    {
        // V2: delegate to AIAgentRegistryService when feature flag is on
        if (AIAgentRegistryService::isEnabled()) {
            $registryAgents = AIAgentRegistryService::listAgentsForOrganizer($db, $organizerId);
            if (!empty($registryAgents)) {
                // Convert registry format to the expected payload format
                $agents = [];
                foreach ($registryAgents as $ra) {
                    $agents[] = [
                        'agent_key'              => $ra['agent_key'],
                        'label'                  => $ra['label'],
                        'label_friendly'         => $ra['label_friendly'] ?? $ra['label'],
                        'description'            => $ra['description'] ?? null,
                        'surfaces'               => $ra['surfaces'] ?? [],
                        'supports_write_actions'  => $ra['supports_write'] ?? false,
                        'is_enabled'             => $ra['is_enabled'] ?? true,
                        'approval_mode'          => $ra['approval_mode'] ?? 'confirm_write',
                        'provider'               => $ra['tenant_provider'] ?? $ra['default_provider'] ?? null,
                        'config_json'            => $ra['config_json'] ?? [],
                        'source'                 => $ra['source'] ?? 'catalog',
                        'icon_key'               => $ra['icon_key'] ?? null,
                        'display_order'          => $ra['display_order'] ?? 100,
                        'tenant_configured'      => $ra['tenant_configured'] ?? false,
                    ];
                }
                return $agents;
            }
        }

        $catalog = self::agentMetadata();
        $configs = self::listAgentConfigs($db, $organizerId);
        $agents = [];

        foreach ($catalog as $agentKey => $metadata) {
            $config = $configs[$agentKey] ?? null;
            $agents[] = self::buildAgentPayload($agentKey, $metadata, $config);
            unset($configs[$agentKey]);
        }

        foreach ($configs as $agentKey => $config) {
            $agents[] = self::buildAgentPayload(
                $agentKey,
                [
                    'label' => self::humanizeAgentKey($agentKey),
                    'description' => null,
                    'surfaces' => [],
                    'supports_write_actions' => false,
                ],
                $config
            );
        }

        return $agents;
    }

    public static function getAgent(PDO $db, int $organizerId, string $agentKey): array
    {
        $normalizedAgentKey = trim((string)$agentKey);
        if ($normalizedAgentKey === '') {
            throw new RuntimeException('agent_key e obrigatorio.', 422);
        }

        foreach (self::listAgents($db, $organizerId) as $agent) {
            if (($agent['agent_key'] ?? null) === $normalizedAgentKey) {
                return $agent;
            }
        }

        throw new RuntimeException('Agente de IA nao encontrado.', 404);
    }

    public static function listAgentConfigs(PDO $db, int $organizerId): array
    {
        if (!self::tableExists($db, 'organizer_ai_agents')) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT agent_key, provider, is_enabled, approval_mode, config_json, updated_at
            FROM public.organizer_ai_agents
            WHERE organizer_id = :organizer_id
            ORDER BY agent_key
        ");
        $stmt->execute([':organizer_id' => $organizerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $configs = [];
        foreach ($rows as $row) {
            $configs[(string)$row['agent_key']] = [
                'agent_key' => (string)$row['agent_key'],
                'provider' => self::normalizeProvider($row['provider'] ?? null),
                'is_enabled' => filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'approval_mode' => self::normalizeApprovalMode($row['approval_mode'] ?? null),
                'config' => self::decodeJsonArray($row['config_json'] ?? null),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $configs;
    }

    public static function upsertAgent(PDO $db, int $organizerId, string $agentKey, array $payload): array
    {
        if (!self::tableExists($db, 'organizer_ai_agents')) {
            throw new RuntimeException('Schema de agentes de IA não materializado. Aplique a migration 038_ai_orchestrator_foundation.sql.', 409);
        }

        $normalizedAgentKey = trim((string)$agentKey);
        if ($normalizedAgentKey === '') {
            throw new RuntimeException('agent_key é obrigatório.', 422);
        }

        $existing = self::listAgentConfigs($db, $organizerId)[$normalizedAgentKey] ?? null;
        $provider = $existing['provider'] ?? null;
        if (array_key_exists('provider', $payload)) {
            $rawProvider = trim((string)($payload['provider'] ?? ''));
            if ($rawProvider === '') {
                $provider = null;
            } else {
                $provider = self::normalizeProvider($rawProvider);
                if ($provider === null) {
                    throw new RuntimeException('Provider de IA invalido. Use openai, gemini ou claude.', 422);
                }
            }
        }
        $isEnabled = array_key_exists('is_enabled', $payload)
            ? filter_var($payload['is_enabled'], FILTER_VALIDATE_BOOLEAN)
            : ($existing['is_enabled'] ?? true);
        $approvalMode = array_key_exists('approval_mode', $payload)
            ? self::normalizeApprovalMode($payload['approval_mode'] ?? null)
            : ($existing['approval_mode'] ?? self::DEFAULT_APPROVAL_MODE);
        $config = $payload['config'] ?? ($existing['config'] ?? []);
        if (!is_array($config)) {
            $config = [];
        }
        if (array_key_exists('model', $payload)) {
            $rawModel = trim((string)($payload['model'] ?? ''));
            if ($rawModel === '') {
                unset($config['model']);
            } else {
                $config['model'] = $rawModel;
            }
        }

        $stmt = $db->prepare("
            INSERT INTO public.organizer_ai_agents (
                organizer_id,
                agent_key,
                provider,
                is_enabled,
                approval_mode,
                config_json,
                created_at,
                updated_at
            ) VALUES (
                :organizer_id,
                :agent_key,
                :provider,
                :is_enabled,
                :approval_mode,
                :config_json,
                NOW(),
                NOW()
            )
            ON CONFLICT (organizer_id, agent_key) DO UPDATE SET
                provider = EXCLUDED.provider,
                is_enabled = EXCLUDED.is_enabled,
                approval_mode = EXCLUDED.approval_mode,
                config_json = EXCLUDED.config_json,
                updated_at = NOW()
        ");
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':agent_key' => $normalizedAgentKey,
            ':provider' => $provider,
            ':is_enabled' => self::postgresBool($isEnabled),
            ':approval_mode' => $approvalMode,
            ':config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
        ]);

        return self::getAgent($db, $organizerId, $normalizedAgentKey);
    }

    public static function normalizeApprovalMode(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['manual_confirm', 'confirm_write', 'auto_read_only'], true)
            ? $normalized
            : self::DEFAULT_APPROVAL_MODE;
    }

    private static function getProviderPublicPayload(PDO $db, int $organizerId, string $provider): array
    {
        $row = self::getProviderRow($db, $organizerId, $provider);
        if (!$row) {
            throw new RuntimeException('Provider não encontrado no escopo do organizador.', 404);
        }

        return [
            'provider' => $provider,
            'label' => self::providerMetadata()[$provider]['label'],
            'supports_tool_use' => self::providerMetadata()[$provider]['supports_tool_use'],
            'model' => self::nullableTrimmedString($row['model'] ?? null) ?? (self::DEFAULT_MODELS[$provider] ?? null),
            'base_url' => self::nullableTrimmedString($row['base_url'] ?? null),
            'is_active' => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_default' => filter_var($row['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_configured' => trim((string)($row['encrypted_api_key'] ?? '')) !== '',
            'settings' => self::decodeJsonArray($row['settings_json'] ?? null),
            'source' => 'tenant',
        ];
    }

    private static function getProviderRow(PDO $db, int $organizerId, string $provider): ?array
    {
        $stmt = $db->prepare("
            SELECT provider, encrypted_api_key, model, base_url, is_active, is_default, settings_json
            FROM public.organizer_ai_providers
            WHERE organizer_id = :organizer_id
              AND provider = :provider
            LIMIT 1
        ");
        $stmt->execute([
            ':organizer_id' => $organizerId,
            ':provider' => $provider,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function envApiKey(string $provider): string
    {
        return match ($provider) {
            'openai' => trim((string)(getenv('OPENAI_API_KEY') ?: '')),
            'gemini' => trim((string)(getenv('GEMINI_API_KEY') ?: '')),
            'claude' => trim((string)(getenv('ANTHROPIC_API_KEY') ?: '')),
            default => '',
        };
    }

    private static function envModel(string $provider): ?string
    {
        $value = match ($provider) {
            'openai' => getenv('OPENAI_MODEL') ?: null,
            'gemini' => getenv('GEMINI_MODEL') ?: null,
            'claude' => getenv('CLAUDE_MODEL') ?: getenv('ANTHROPIC_MODEL') ?: null,
            default => null,
        };

        return self::nullableTrimmedString($value);
    }

    private static function envBaseUrl(string $provider): ?string
    {
        $value = match ($provider) {
            'openai' => getenv('OPENAI_BASE_URL') ?: null,
            'gemini' => getenv('GEMINI_BASE_URL') ?: null,
            'claude' => getenv('ANTHROPIC_BASE_URL') ?: null,
            default => null,
        };

        return self::nullableTrimmedString($value);
    }

    private static function validateProviderApiKey(string $provider, string $apiKey, bool $saving): string
    {
        $normalized = trim($apiKey);
        if ($normalized === '') {
            return '';
        }

        $lower = strtolower($normalized);
        $action = $saving ? 'salvar' : 'usar';
        if (
            str_contains($lower, 'sqlstate')
            || str_contains($lower, 'incorrect api key')
            || str_contains($lower, 'erro:')
            || str_contains($lower, 'error:')
        ) {
            throw new RuntimeException(
                "A chave do provider {$provider} está inválida. O sistema tentou {$action} um texto de erro em vez da API key real. Cole somente o segredo do provider.",
                422
            );
        }

        if (preg_match('/\s/u', $normalized) === 1) {
            throw new RuntimeException(
                "A chave do provider {$provider} está inválida. O valor contém espaços ou quebras de linha e não parece um segredo real.",
                422
            );
        }

        $isValid = match ($provider) {
            'openai' => str_starts_with($normalized, 'sk-'),
            'claude' => str_starts_with($normalized, 'sk-ant-'),
            'gemini' => preg_match('/^[A-Za-z0-9_-]{20,}$/', $normalized) === 1,
            default => true,
        };

        if (!$isValid) {
            $hint = match ($provider) {
                'openai' => 'A chave da OpenAI deve começar com sk-.',
                'claude' => 'A chave da Anthropic deve começar com sk-ant-.',
                'gemini' => 'A chave do Gemini deve ser a API key bruta do Google AI Studio.',
                default => 'Informe o segredo bruto do provider.',
            };

            throw new RuntimeException(
                "A chave do provider {$provider} está inválida. {$hint}",
                422
            );
        }

        return $normalized;
    }

    private static function providerScope(int $organizerId, string $provider): string
    {
        return 'ai_provider:' . $organizerId . ':' . $provider;
    }

    private static function normalizeProvider(mixed $provider): ?string
    {
        $normalized = strtolower(trim((string)$provider));
        return in_array($normalized, self::SUPPORTED_PROVIDERS, true) ? $normalized : null;
    }

    private static function providerMetadata(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI',
                'supports_tool_use' => true,
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'supports_tool_use' => true,
            ],
            'claude' => [
                'label' => 'Anthropic Claude',
                'supports_tool_use' => true,
            ],
        ];
    }

    private static function agentMetadata(): array
    {
        return [
            'marketing' => [
                'label' => 'Agente de Marketing',
                'description' => 'Apoio para campanhas, demanda e comunicacao comercial do evento.',
                'surfaces' => ['dashboard', 'tickets', 'messaging', 'customer'],
                'supports_write_actions' => false,
            ],
            'logistics' => [
                'label' => 'Agente de Logistica',
                'description' => 'Leitura operacional para filas, abastecimento e contingencias do evento.',
                'surfaces' => ['parking', 'meals-control', 'workforce', 'events'],
                'supports_write_actions' => false,
            ],
            'management' => [
                'label' => 'Agente de Gestao',
                'description' => 'Leitura executiva de KPIs, risco e performance geral do evento.',
                'surfaces' => ['dashboard', 'analytics', 'finance'],
                'supports_write_actions' => false,
            ],
            'bar' => [
                'label' => 'Agente de Bar e Estoque',
                'description' => 'Apoio operacional para demanda, estoque e mix de produtos do PDV.',
                'surfaces' => ['bar', 'food', 'shop'],
                'supports_write_actions' => false,
            ],
            'contracting' => [
                'label' => 'Agente de Contratacao',
                'description' => 'Suporte para fornecedores, artistas e contratos no backoffice.',
                'surfaces' => ['artists', 'finance', 'settings'],
                'supports_write_actions' => false,
            ],
            'feedback' => [
                'label' => 'Agente de Feedback',
                'description' => 'Consolida sinais de participantes e operacao para apontar melhorias.',
                'surfaces' => ['messaging', 'customer', 'analytics'],
                'supports_write_actions' => false,
            ],
            'data_analyst' => [
                'label' => 'Agente Analista de Dados',
                'description' => 'Cruza dados de multiplos modulos para gerar insights analiticos, detectar padroes e anomalias.',
                'surfaces' => ['dashboard', 'analytics', 'finance'],
                'supports_write_actions' => false,
            ],
            'content' => [
                'label' => 'Agente de Conteudo',
                'description' => 'Gera textos profissionais: posts, descricoes, campanhas, comunicados e copy para eventos.',
                'surfaces' => ['messaging', 'marketing', 'customer'],
                'supports_write_actions' => false,
            ],
            'media' => [
                'label' => 'Agente de Midia Visual',
                'description' => 'Cria prompts de imagem, briefings visuais, especificacoes de midia e storyboards.',
                'surfaces' => ['marketing'],
                'supports_write_actions' => false,
            ],
            'documents' => [
                'label' => 'Agente de Documentos e Planilhas',
                'description' => 'Le arquivos do organizador (planilhas, custos) e transforma em categorias financeiras organizadas.',
                'surfaces' => ['finance'],
                'supports_write_actions' => true,
            ],
            'artists' => [
                'label' => 'Agente de Artistas',
                'description' => 'Analisa logistica, timeline, alertas, custos e equipe de cada artista do evento.',
                'surfaces' => ['artists'],
                'supports_write_actions' => false,
            ],
            'artists_travel' => [
                'label' => 'Agente de Viagens de Artistas',
                'description' => 'Organiza passagens, hoteis, transfers e fechamento logistico completo de artistas.',
                'surfaces' => ['artists'],
                'supports_write_actions' => true,
            ],
        ];
    }

    private static function buildAgentPayload(string $agentKey, array $metadata, ?array $config): array
    {
        return [
            'agent_key' => $agentKey,
            'label' => $metadata['label'] ?? self::humanizeAgentKey($agentKey),
            'description' => $metadata['description'] ?? null,
            'surfaces' => array_values(array_filter($metadata['surfaces'] ?? [], static fn(mixed $surface): bool => is_string($surface) && trim($surface) !== '')),
            'supports_write_actions' => filter_var($metadata['supports_write_actions'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'provider' => $config['provider'] ?? null,
            'is_enabled' => $config['is_enabled'] ?? false,
            'approval_mode' => $config['approval_mode'] ?? self::DEFAULT_APPROVAL_MODE,
            'config' => $config['config'] ?? [],
            'updated_at' => $config['updated_at'] ?? null,
            'source' => $config ? 'tenant' : 'catalog',
        ];
    }

    private static function humanizeAgentKey(string $agentKey): string
    {
        $normalized = trim(str_replace(['_', '-'], ' ', $agentKey));
        return $normalized !== '' ? ucwords($normalized) : 'Agente';
    }

    private static function tableExists(PDO $db, string $tableName): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $tableName]);

        return (bool)$stmt->fetchColumn();
    }

    private static function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function nullableTrimmedString(mixed $value): ?string
    {
        $trimmed = trim((string)$value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private static function postgresBool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }
}
