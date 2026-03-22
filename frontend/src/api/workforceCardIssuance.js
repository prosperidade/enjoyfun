import api from "../lib/api";

const DEFAULT_SOURCE_MODULE = "workforce_bulk";

const normalizeParticipantIds = (participantIds = []) =>
  Array.from(
    new Set(
      (Array.isArray(participantIds) ? participantIds : [])
        .map((value) => Number(value || 0))
        .filter((value) => Number.isInteger(value) && value > 0)
    )
  );

const buildPayload = ({
  eventId,
  participantIds,
  managerEventRoleId = null,
  initialBalance = 0,
  sourceContext = null,
  notes = "",
  sourceModule = DEFAULT_SOURCE_MODULE,
  idempotencyKey = "",
} = {}) => {
  const payload = {
    event_id: Number(eventId || 0),
    participant_ids: normalizeParticipantIds(participantIds),
    source_module: String(sourceModule || DEFAULT_SOURCE_MODULE).trim() || DEFAULT_SOURCE_MODULE,
  };

  if (Number(managerEventRoleId) > 0) {
    payload.manager_event_role_id = Number(managerEventRoleId);
  }

   if (Number.isFinite(Number(initialBalance)) && Number(initialBalance) >= 0) {
    payload.initial_balance = Number(initialBalance);
  }

  if (sourceContext && typeof sourceContext === "object" && !Array.isArray(sourceContext)) {
    payload.source_context = sourceContext;
  }

  if (String(notes || "").trim()) {
    payload.notes = String(notes).trim();
  }

  if (String(idempotencyKey || "").trim()) {
    payload.idempotency_key = String(idempotencyKey).trim();
  }

  return payload;
};

export async function previewWorkforceCardIssuanceApi(options = {}) {
  const payload = buildPayload(options);
  const { data } = await api.post("/workforce/card-issuance/preview", payload);
  return data.data;
}

export async function issueWorkforceCardIssuanceApi(options = {}) {
  const payload = buildPayload(options);
  const { data } = await api.post("/workforce/card-issuance/issue", payload);
  return data.data;
}
