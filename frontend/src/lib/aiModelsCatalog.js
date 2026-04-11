export const AI_MODELS_CATALOG = {
  openai: [
    { id: 'gpt-5.4', label: 'GPT-5.4', input_cost_per_1m: 5.00, output_cost_per_1m: 20.00, description: 'Geração mais recente, estado da arte' },
    { id: 'gpt-5-mini', label: 'GPT-5 mini', input_cost_per_1m: 0.50, output_cost_per_1m: 2.00, description: 'Rápido e barato, geração 5' },
    { id: 'gpt-4o', label: 'GPT-4o', input_cost_per_1m: 2.50, output_cost_per_1m: 10.00, description: 'Multimodal, geração anterior' },
    { id: 'gpt-4o-mini', label: 'GPT-4o mini', input_cost_per_1m: 0.15, output_cost_per_1m: 0.60, description: 'Mais barato, ótimo custo-benefício' },
    { id: 'o1-mini', label: 'o1-mini', input_cost_per_1m: 3.00, output_cost_per_1m: 12.00, description: 'Raciocínio passo-a-passo' },
  ],
  gemini: [
    { id: 'gemini-3.1-pro', label: 'Gemini 3.1 Pro', input_cost_per_1m: 2.50, output_cost_per_1m: 15.00, description: 'Geração mais recente, multimodal top' },
    { id: 'gemini-3.1-flash', label: 'Gemini 3.1 Flash', input_cost_per_1m: 0.40, output_cost_per_1m: 3.00, description: 'Rápido e barato, geração 3' },
    { id: 'gemini-2.5-pro', label: 'Gemini 2.5 Pro', input_cost_per_1m: 1.25, output_cost_per_1m: 10.00, description: 'Geração anterior, contexto longo' },
    { id: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash', input_cost_per_1m: 0.30, output_cost_per_1m: 2.50, description: 'Geração anterior, multimodal' },
  ],
  claude: [
    { id: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6', input_cost_per_1m: 3.00, output_cost_per_1m: 15.00, description: 'Geração mais recente, excelente raciocínio' },
    { id: 'claude-opus-4-6', label: 'Claude Opus 4.6', input_cost_per_1m: 15.00, output_cost_per_1m: 75.00, description: 'Top de linha, análise profunda' },
    { id: 'claude-haiku-4-5', label: 'Claude Haiku 4.5', input_cost_per_1m: 1.00, output_cost_per_1m: 5.00, description: 'Rápido e econômico, geração 4' },
    { id: 'claude-sonnet-4-5', label: 'Claude Sonnet 4.5', input_cost_per_1m: 3.00, output_cost_per_1m: 15.00, description: 'Geração anterior, estável' },
  ],
};

export function getModelsForProvider(providerKey) {
  return AI_MODELS_CATALOG[providerKey] || [];
}

export function findModelMeta(providerKey, modelId) {
  return getModelsForProvider(providerKey).find((m) => m.id === modelId) || null;
}

export function formatModelOptionLabel(model) {
  const inCost = `$${model.input_cost_per_1m.toFixed(2)}/1M in`;
  const outCost = `$${model.output_cost_per_1m.toFixed(2)}/1M out`;
  return `${model.label} — ${model.description} (${inCost} / ${outCost})`;
}

export const SUPPORTED_PROVIDER_KEYS = ['openai', 'gemini', 'claude'];

export const PROVIDER_LABELS = {
  openai: 'OpenAI',
  gemini: 'Google Gemini',
  claude: 'Anthropic Claude',
};
