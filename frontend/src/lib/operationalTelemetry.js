import api from "./api";

function normalizeTelemetryDetails(details = {}) {
  if (!details || typeof details !== "object" || Array.isArray(details)) {
    return { value: String(details ?? "") };
  }

  return Object.fromEntries(
    Object.entries(details).map(([key, value]) => {
      if (value instanceof Error) {
        return [key, value.message];
      }
      if (typeof value === "string") {
        return [key, value.slice(0, 500)];
      }
      if (typeof value === "number" || typeof value === "boolean" || value === null) {
        return [key, value];
      }
      return [key, JSON.stringify(value).slice(0, 500)];
    })
  );
}

export async function reportOperationalTelemetry(eventType, { eventId = null, details = {} } = {}) {
  if (!eventType) return;

  try {
    await api.post("/health/telemetry", {
      event_type: eventType,
      event_id: Number(eventId || 0) > 0 ? Number(eventId) : undefined,
      details: normalizeTelemetryDetails(details),
    });
  } catch (error) {
    console.error("Operational telemetry failed", error);
  }
}
