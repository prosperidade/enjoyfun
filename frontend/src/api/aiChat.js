import api from "../lib/api";

/**
 * Send a message to the AI chat endpoint.
 * @param {Object} params
 * @param {string} params.question - The user's question
 * @param {string} [params.session_id] - Existing session ID for multi-turn
 * @param {Object} [params.context] - Context (event_id, surface, agent_key)
 * @returns {Promise<Object>} Chat response with session_id, insight, agent_key, etc.
 */
export async function sendChatMessage({ question, session_id, context }) {
  const { data } = await api.post("/ai/chat", {
    question,
    session_id,
    context,
  });
  return data?.data || {};
}

/**
 * List active chat sessions for the current user.
 * @param {number} [limit=20]
 */
export async function listChatSessions(limit = 20) {
  const { data } = await api.get("/ai/chat/sessions", { params: { limit } });
  return data?.data?.sessions || [];
}

/**
 * Get a chat session with full message history.
 * @param {string} sessionId
 */
export async function getChatSession(sessionId) {
  const { data } = await api.get(`/ai/chat/sessions/${sessionId}`);
  return data?.data || {};
}
