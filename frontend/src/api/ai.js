import api from "../lib/api";

export async function getOrganizerAIConfig() {
  const { data } = await api.get("/organizer-ai-config");
  return data?.data || {};
}

export async function updateOrganizerAIConfig(payload) {
  const { data } = await api.put("/organizer-ai-config", payload);
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
