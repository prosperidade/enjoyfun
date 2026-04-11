export const AGENT_RECOMMENDATIONS = {
  marketing: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Conteudo criativo e copy persuasivo' },
  logistics: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Raciocinio sobre cronogramas e dependencias' },
  management: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Analise estrategica e sintese executiva' },
  bar: { provider: 'openai', model: 'gpt-5-mini', reason: 'Operacional rapido e barato' },
  contracting: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Negociacao e leitura de contratos' },
  feedback: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Sintese qualitativa de sinais' },
  data_analyst: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Cruzamento analitico e deteccao de padroes' },
  content: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Textos e copy persuasivo' },
  media: { provider: 'gemini', model: 'gemini-3.1-pro', reason: 'Multimodal nativo, briefing visual' },
  documents: { provider: 'gemini', model: 'gemini-3.1-pro', reason: 'Parsing de planilhas e documentos' },
  artists: { provider: 'claude', model: 'claude-sonnet-4-6', reason: 'Contexto longo e negociacao' },
  artists_travel: { provider: 'openai', model: 'gpt-5-mini', reason: 'Operacional rapido com tool calling' },
};

export function getRecommendation(agentKey) {
  return AGENT_RECOMMENDATIONS[agentKey] || null;
}

export function isRecommended(agentKey, provider, modelId) {
  const rec = getRecommendation(agentKey);
  if (!rec) return false;
  return rec.provider === provider && rec.model === modelId;
}
