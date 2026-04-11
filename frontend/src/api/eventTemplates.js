import api from "../lib/api";

/**
 * Event Templates API
 * Manages system and organizer-custom event templates.
 */

/**
 * List all templates (system + organizer's custom).
 * @returns {Promise<Array>} templates
 */
export async function listEventTemplates() {
  const { data } = await api.get("/event-templates");
  return Array.isArray(data?.data) ? data.data : [];
}

/**
 * Get template detail with skills and agents.
 * @param {string} templateKey
 * @returns {Promise<Object|null>} template
 */
export async function getEventTemplate(templateKey) {
  const { data } = await api.get(`/event-templates/${templateKey}`);
  return data?.data || null;
}

/**
 * Apply a template to an event (sets event_type + activates skills).
 * @param {string} templateKey
 * @param {number} eventId
 * @returns {Promise<Object>} result with skills_activated and agents_recommended
 */
export async function applyEventTemplate(templateKey, eventId) {
  const { data } = await api.post(`/event-templates/${templateKey}/apply`, {
    event_id: eventId,
  });
  return data?.data || {};
}

/**
 * Clone a system template for organizer customization.
 * @param {string} templateKey
 * @param {string} [label] custom label
 * @returns {Promise<Object>} cloned template info
 */
export async function cloneEventTemplate(templateKey, label = "") {
  const { data } = await api.post(`/event-templates/${templateKey}/clone`, {
    label,
  });
  return data?.data || {};
}

/**
 * Toggle a skill in an organizer's custom template.
 * @param {string} templateKey
 * @param {string} skillKey
 * @param {boolean} enable
 * @returns {Promise<Object>} toggle result
 */
export async function toggleTemplateSkill(templateKey, skillKey, enable) {
  const { data } = await api.post(
    `/event-templates/${templateKey}/toggle-skill`,
    { skill_key: skillKey, enable }
  );
  return data?.data || {};
}
