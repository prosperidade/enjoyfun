const defaultConfig = {
  baseUrl: process.env.WORKFORCE_BASE_URL || "http://localhost:8080/api",
  authEmail: process.env.WORKFORCE_AUTH_EMAIL || "admin@enjoyfun.com.br",
  authPassword: process.env.WORKFORCE_AUTH_PASSWORD || "123456",
  eventId: process.env.WORKFORCE_EVENT_ID ? Number(process.env.WORKFORCE_EVENT_ID) : 0,
};

const failures = [];

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function buildUrl(baseUrl, path, query = {}) {
  const url = new URL(path.replace(/^\//, ""), `${baseUrl.replace(/\/+$/, "")}/`);
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      url.searchParams.set(key, String(value));
    }
  });
  return url;
}

async function apiRequest({
  method = "GET",
  path,
  query,
  token,
  body,
  expectedStatus,
  expectedStatuses,
}) {
  const headers = { Accept: "application/json" };
  headers["X-Operational-Test"] = "workforce-contract";
  const requestInit = { method, headers };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    requestInit.body = JSON.stringify(body);
  }

  const response = await fetch(buildUrl(defaultConfig.baseUrl, path, query), requestInit);
  const text = await response.text();
  let json = null;

  try {
    json = text ? JSON.parse(text) : null;
  } catch (error) {
    throw new Error(`Resposta invalida em ${method} ${path}: ${error.message}\n${text}`);
  }

  const allowedStatuses = expectedStatuses || (expectedStatus ? [expectedStatus] : [200]);
  if (!allowedStatuses.includes(response.status)) {
    throw new Error(
      `Status inesperado em ${method} ${path}: ${response.status}. Resposta: ${JSON.stringify(json)}`
    );
  }

  return { status: response.status, json };
}

async function runStep(name, fn) {
  const startedAt = Date.now();
  try {
    const result = await fn();
    const elapsedMs = Date.now() - startedAt;
    console.log(`[PASS] ${name} (${elapsedMs}ms)`);
    return result;
  } catch (error) {
    const elapsedMs = Date.now() - startedAt;
    const message = `[FAIL] ${name} (${elapsedMs}ms) -> ${error.message}`;
    failures.push(message);
    console.error(message);
    return null;
  }
}

async function login() {
  const { json } = await apiRequest({
    method: "POST",
    path: "/auth/login",
    body: {
      email: defaultConfig.authEmail,
      password: defaultConfig.authPassword,
    },
    expectedStatus: 200,
  });

  assert(json?.success === true, "Login deve retornar success=true.");
  assert(typeof json?.data?.access_token === "string" && json.data.access_token.length > 20, "Login deve retornar access_token.");
  return json.data.access_token;
}

async function fetchEvents(token) {
  const { json } = await apiRequest({
    path: "/events",
    token,
    expectedStatus: 200,
  });

  assert(json?.success === true, "GET /events deve retornar success=true.");
  assert(Array.isArray(json?.data) && json.data.length > 0, "GET /events deve retornar ao menos um evento.");
  return json.data;
}

async function resolveAuditEvent(token, events) {
  if (defaultConfig.eventId > 0) {
    return { id: defaultConfig.eventId, name: `evento ${defaultConfig.eventId}` };
  }

  for (const event of events) {
    const { json } = await apiRequest({
      path: "/workforce/tree-status",
      query: { event_id: event.id },
      token,
      expectedStatus: 200,
    });
    const treeStatus = json?.data || {};
    if (treeStatus.tree_usable && Number(treeStatus.assignments_total || 0) > 0) {
      return event;
    }
  }

  throw new Error("Nenhum evento com arvore utilizavel e assignments ativos foi encontrado para a auditoria.");
}

async function fetchCategories(token) {
  const { json } = await apiRequest({
    path: "/participants/categories",
    token,
    expectedStatus: 200,
  });

  assert(json?.success === true, "GET /participants/categories deve retornar success=true.");
  assert(Array.isArray(json?.data) && json.data.length > 0, "GET /participants/categories deve retornar categorias.");
  return json.data;
}

function pickStaffCategory(categories) {
  return categories.find((category) => String(category?.type || "").toLowerCase() === "staff") || categories[0];
}

function requireArrayResponse(json, label) {
  assert(json?.success === true, `${label} deve retornar success=true.`);
  assert(Array.isArray(json?.data), `${label} deve retornar data[]`);
  return json.data;
}

function createUniqueParticipantPayload(eventId, categoryId) {
  const suffix = `${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  return {
    event_id: eventId,
    category_id: categoryId,
    name: `Auditoria Workforce ${suffix}`,
    email: `auditoria.${suffix}@enjoyfun.local`,
    document: `WF${suffix.replace(/[^0-9a-z]/gi, "").slice(0, 12)}`,
    phone: "41999999999",
  };
}

async function main() {
  console.log(`[INFO] Base URL: ${defaultConfig.baseUrl}`);

  const token = await runStep("Autenticacao", login);
  if (!token) {
    process.exit(1);
  }

  const events = await runStep("Listagem de eventos", () => fetchEvents(token));
  const event = await runStep("Resolucao do evento de auditoria", () => resolveAuditEvent(token, events || []));
  const categories = await runStep("Listagem de categorias", () => fetchCategories(token));

  if (!event || !categories) {
    process.exit(1);
  }

  console.log(`[INFO] Evento escolhido: ${event.id} - ${event.name}`);

  await runStep("Contrato GET /workforce/tree-status", async () => {
    const { json } = await apiRequest({
      path: "/workforce/tree-status",
      query: { event_id: event.id },
      token,
      expectedStatus: 200,
    });
    assert(json?.success === true, "tree-status deve retornar success=true.");
    assert(Number(json?.data?.event_id || 0) === Number(event.id), "tree-status deve refletir o event_id consultado.");
    assert(typeof json?.data?.tree_usable === "boolean", "tree-status deve retornar tree_usable boolean.");
    assert(Number(json?.data?.assignments_total || 0) > 0, "tree-status deve indicar assignments_total > 0.");
  });

  await runStep("Contrato GET /workforce/roles", async () => {
    const { json } = await apiRequest({
      path: "/workforce/roles",
      query: { event_id: event.id },
      token,
      expectedStatus: 200,
    });
    const rows = requireArrayResponse(json, "GET /workforce/roles");
    assert(rows.length > 0, "GET /workforce/roles deve retornar pelo menos um cargo.");
    const sample = rows[0] || {};
    assert(Number(sample.id || 0) > 0, "Cargo deve retornar id.");
    assert(String(sample.name || "").trim() !== "", "Cargo deve retornar name.");
    assert(["managerial", "operational"].includes(String(sample.cost_bucket || "")), "Cargo deve retornar cost_bucket valido.");
  });

  await runStep("Contrato GET /workforce/event-roles", async () => {
    const { json } = await apiRequest({
      path: "/workforce/event-roles",
      query: { event_id: event.id },
      token,
      expectedStatus: 200,
    });
    const rows = requireArrayResponse(json, "GET /workforce/event-roles");
    assert(rows.length > 0, "GET /workforce/event-roles deve retornar pelo menos um cargo estrutural.");
    const rootRow = rows.find((row) => !Number(row?.parent_event_role_id || 0));
    assert(rootRow, "GET /workforce/event-roles deve retornar pelo menos uma raiz estrutural.");
    assert(Number(rootRow.event_id || 0) === Number(event.id), "Cargo estrutural deve retornar event_id coerente.");
    assert(String(rootRow.public_id || "").trim() !== "", "Cargo estrutural deve retornar public_id.");
  });

  await runStep("Contrato GET /workforce/managers", async () => {
    const { json } = await apiRequest({
      path: "/workforce/managers",
      query: { event_id: event.id },
      token,
      expectedStatus: 200,
    });
    const rows = requireArrayResponse(json, "GET /workforce/managers");
    assert(rows.length > 0, "GET /workforce/managers deve retornar pelo menos um gerente.");
    const sample = rows[0] || {};
    assert(Number(sample.role_id || 0) > 0, "Gerente deve retornar role_id.");
    assert(String(sample.role_name || "").trim() !== "", "Gerente deve retornar role_name.");
    assert(String(sample.sector || "").trim() !== "", "Gerente deve retornar sector.");
  });

  await runStep("Contrato GET /workforce/assignments", async () => {
    const { json } = await apiRequest({
      path: "/workforce/assignments",
      query: { event_id: event.id },
      token,
      expectedStatus: 200,
    });
    const rows = requireArrayResponse(json, "GET /workforce/assignments");
    assert(rows.length > 0, "GET /workforce/assignments deve retornar assignments.");
    const sample = rows[0] || {};
    assert(Number(sample.id || 0) > 0, "Assignment deve retornar id.");
    assert(Number(sample.event_id || 0) === Number(event.id), "Assignment deve respeitar o event_id filtrado.");
    assert(Number(sample.participant_id || 0) > 0, "Assignment deve retornar participant_id.");
    assert(String(sample.role_name || "").trim() !== "", "Assignment deve retornar role_name.");
  });

  await runStep("Contrato POST /sync com falha rastreavel", async () => {
    const offlineId = `wf-sync-invalid-${Date.now()}`;
    const { status, json } = await apiRequest({
      method: "POST",
      path: "/sync",
      token,
      body: {
        items: [
          {
            offline_id: offlineId,
            payload_type: "sale",
            payload: {
              client_schema_version: 2,
              event_id: event.id,
              sector: "invalid_sector",
              total_amount: 10,
              items: [
                {
                  product_id: 1,
                  quantity: 1,
                  unit_price: 10,
                  subtotal: 10,
                },
              ],
            },
          },
        ],
      },
      expectedStatuses: [207],
    });

    assert(status === 207, "POST /sync com payload invalido deve retornar 207.");
    assert(json?.success === true, "POST /sync parcial deve retornar success=true.");
    assert(Array.isArray(json?.data?.failed_ids), "POST /sync parcial deve retornar failed_ids.");
    assert(json.data.failed_ids.includes(offlineId), "POST /sync parcial deve listar o offline_id falho.");
    assert(Array.isArray(json?.data?.errors) && json.data.errors.length > 0, "POST /sync parcial deve retornar errors.");
  });

  const staffCategory = pickStaffCategory(categories);
  const participantPayload = createUniqueParticipantPayload(event.id, Number(staffCategory?.id || 0));
  let createdParticipantId = 0;

  await runStep("Contrato POST /participants", async () => {
    assert(Number(staffCategory?.id || 0) > 0, "E necessario uma categoria valida para criar participante.");
    const { status, json } = await apiRequest({
      method: "POST",
      path: "/participants",
      token,
      body: participantPayload,
      expectedStatuses: [201],
    });

    assert(status === 201, "POST /participants deve retornar 201.");
    assert(json?.success === true, "POST /participants deve retornar success=true.");
    createdParticipantId = Number(json?.data?.participant_id || 0);
    assert(createdParticipantId > 0, "POST /participants deve retornar participant_id.");
    assert(String(json?.data?.qr_token || "").trim() !== "", "POST /participants deve retornar qr_token.");
  });

  await runStep("Contrato DELETE /participants/:id", async () => {
    assert(createdParticipantId > 0, "Participante de teste precisa existir antes do delete.");
    const { json } = await apiRequest({
      method: "DELETE",
      path: `/participants/${createdParticipantId}`,
      token,
      expectedStatus: 200,
    });

    assert(json?.success === true, "DELETE /participants/:id deve retornar success=true.");
  });

  if (failures.length > 0) {
    console.error("\nResumo das falhas:");
    failures.forEach((failure) => console.error(`- ${failure}`));
    process.exit(1);
  }

  console.log("\n[OK] Workforce contract check concluido com sucesso.");
}

main().catch((error) => {
  console.error(`[FATAL] ${error.message}`);
  process.exit(1);
});
