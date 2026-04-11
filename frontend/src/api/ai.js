import api from "../lib/api";

export async function getOrganizerAIConfig() {
  const { data } = await api.get("/organizer-ai-config");
  return data?.data || {};
}

export async function updateOrganizerAIConfig(payload) {
  const { data } = await api.put("/organizer-ai-config", payload);
  return data?.data || {};
}

export async function getOrganizerAIDna() {
  const { data } = await api.get("/organizer-ai-dna");
  return data?.data || {};
}

export async function updateOrganizerAIDna(payload) {
  const { data } = await api.put("/organizer-ai-dna", payload);
  return data?.data || {};
}

export async function listOrganizerAIProviders() {
  const { data } = await api.get("/organizer-ai-providers");
  return Array.isArray(data?.data) ? data.data : [];
}

export async function getOrganizerAIProvider(provider) {
  const { data } = await api.get(`/organizer-ai-providers/${provider}`);
  return data?.data || null;
}

export async function updateOrganizerAIProvider(provider, payload) {
  const { data } = await api.put(`/organizer-ai-providers/${provider}`, payload);
  return data?.data || null;
}

export async function testOrganizerAIProvider(provider) {
  const { data } = await api.post(
    `/organizer-ai-providers/${provider}/test`,
    {},
    { timeout: 20000 }
  );
  return data?.data || null;
}

export async function listOrganizerAIAgents() {
  const { data } = await api.get("/organizer-ai-agents");
  return Array.isArray(data?.data) ? data.data : [];
}

export async function getOrganizerAIAgent(agentKey) {
  const { data } = await api.get(`/organizer-ai-agents/${agentKey}`);
  return data?.data || null;
}

export async function updateOrganizerAIAgent(agentKey, payload) {
  const { data } = await api.put(`/organizer-ai-agents/${agentKey}`, payload);
  return data?.data || null;
}

export async function updateAgentProvider(agentKey, { provider, model }) {
  const payload = {};
  payload.provider = provider ?? null;
  payload.model = model ?? null;
  const { data } = await api.put(`/organizer-ai-agents/${agentKey}`, payload);
  return data?.data || null;
}

export async function listAIExecutions(params = {}) {
  const { data } = await api.get("/ai/executions", { params });
  return Array.isArray(data?.data) ? data.data : [];
}

export async function getAIBlueprint() {
  const { data } = await api.get("/ai/blueprint");
  return data?.data || {};
}

export async function listAIMemories(params = {}) {
  const { data } = await api.get("/ai/memories", { params });
  return Array.isArray(data?.data) ? data.data : [];
}

export async function listAIReports(params = {}) {
  const { data } = await api.get("/ai/reports", { params });
  return Array.isArray(data?.data) ? data.data : [];
}

export async function queueAIEndOfEventReport(eventId) {
  const { data } = await api.post("/ai/reports/end-of-event", { event_id: eventId });
  return data?.data || null;
}

export async function approveAIExecution(executionId, payload = {}) {
  const { data } = await api.post(`/ai/executions/${executionId}/approve`, payload);
  return data?.data || null;
}

export async function rejectAIExecution(executionId, payload = {}) {
  const { data } = await api.post(`/ai/executions/${executionId}/reject`, payload);
  return data?.data || null;
}
