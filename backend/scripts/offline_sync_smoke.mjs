const defaultConfig = {
  baseUrl: process.env.OFFLINE_SYNC_SMOKE_BASE_URL || "http://localhost:8080/api",
  authEmail: process.env.OFFLINE_SYNC_SMOKE_AUTH_EMAIL || "admin@enjoyfun.com.br",
  authPassword: process.env.OFFLINE_SYNC_SMOKE_AUTH_PASSWORD || "123456",
  eventId: process.env.OFFLINE_SYNC_SMOKE_EVENT_ID ? Number(process.env.OFFLINE_SYNC_SMOKE_EVENT_ID) : 0,
};

const failures = [];
const syntheticLabel = "offline-sync-smoke";

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

function createSession() {
  return {
    token: "",
    cookies: new Map(),
    deviceId: `${syntheticLabel}-${Date.now()}`,
  };
}

function splitSetCookieHeader(rawHeader) {
  if (!rawHeader) {
    return [];
  }

  return rawHeader
    .split(/,(?=\s*[^;,=\s]+=[^;,]+)/)
    .map((value) => value.trim())
    .filter(Boolean);
}

function collectSetCookies(response) {
  if (typeof response.headers.getSetCookie === "function") {
    return response.headers.getSetCookie();
  }

  const header = response.headers.get("set-cookie");
  return splitSetCookieHeader(header);
}

function mergeSessionCookies(session, response) {
  const rawCookies = collectSetCookies(response);
  rawCookies.forEach((cookie) => {
    const pair = String(cookie).split(";", 1)[0] || "";
    const separatorIndex = pair.indexOf("=");
    if (separatorIndex <= 0) {
      return;
    }

    const name = pair.slice(0, separatorIndex).trim();
    const value = pair.slice(separatorIndex + 1).trim();
    if (name !== "" && value !== "") {
      session.cookies.set(name, value);
    }
  });
}

function buildCookieHeader(session) {
  return Array.from(session.cookies.entries())
    .map(([name, value]) => `${name}=${value}`)
    .join("; ");
}

async function apiRequest({
  session,
  method = "GET",
  path,
  query,
  body,
  expectedStatus,
  expectedStatuses,
}) {
  const headers = {
    Accept: "application/json",
    "X-Operational-Test": syntheticLabel,
    "X-Device-ID": session.deviceId,
  };

  if (session.token) {
    headers.Authorization = `Bearer ${session.token}`;
  }

  const cookieHeader = buildCookieHeader(session);
  if (cookieHeader) {
    headers.Cookie = cookieHeader;
  }

  const requestInit = { method, headers };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    requestInit.body = JSON.stringify(body);
  }

  const response = await fetch(buildUrl(defaultConfig.baseUrl, path, query), requestInit);
  mergeSessionCookies(session, response);

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

async function login(session) {
  const { json } = await apiRequest({
    session,
    method: "POST",
    path: "/auth/login",
    body: {
      email: defaultConfig.authEmail,
      password: defaultConfig.authPassword,
    },
    expectedStatus: 200,
  });

  assert(json?.success === true, "Login deve retornar success=true.");
  const accessToken = String(json?.data?.access_token || "").trim();
  if (accessToken) {
    session.token = accessToken;
  }

  const hasCookieSession = session.cookies.size > 0;
  assert(session.token || hasCookieSession, "Login deve retornar access_token ou cookie de sessao.");
  return true;
}

async function fetchEvents(session) {
  const { json } = await apiRequest({
    session,
    path: "/events",
    expectedStatus: 200,
  });

  assert(json?.success === true, "GET /events deve retornar success=true.");
  assert(Array.isArray(json?.data) && json.data.length > 0, "GET /events deve retornar ao menos um evento.");
  return json.data;
}

async function fetchCategories(session) {
  const { json } = await apiRequest({
    session,
    path: "/participants/categories",
    expectedStatus: 200,
  });

  assert(json?.success === true, "GET /participants/categories deve retornar success=true.");
  assert(Array.isArray(json?.data) && json.data.length > 0, "GET /participants/categories deve retornar categorias.");
  return json.data;
}

async function fetchTicketTypes(session, eventId) {
  const { json } = await apiRequest({
    session,
    path: "/tickets/types",
    query: { event_id: eventId },
    expectedStatus: 200,
  });

  assert(json?.success === true, `GET /tickets/types do evento ${eventId} deve retornar success=true.`);
  assert(Array.isArray(json?.data), `GET /tickets/types do evento ${eventId} deve retornar data[].`);
  return json.data;
}

async function resolveSmokeEvent(session, events) {
  if (defaultConfig.eventId > 0) {
    const ticketTypes = await fetchTicketTypes(session, defaultConfig.eventId);
    assert(ticketTypes.length > 0, `O evento ${defaultConfig.eventId} precisa ter ao menos um ticket_type.`);
    const event = events.find((row) => Number(row?.id || 0) === defaultConfig.eventId) || {
      id: defaultConfig.eventId,
      name: `Evento ${defaultConfig.eventId}`,
    };
    return { event, ticketType: ticketTypes[0] };
  }

  for (const event of events) {
    const ticketTypes = await fetchTicketTypes(session, Number(event?.id || 0));
    if (ticketTypes.length > 0) {
      return { event, ticketType: ticketTypes[0] };
    }
  }

  throw new Error("Nenhum evento com ticket_type disponivel foi encontrado para a smoke offline.");
}

function pickParticipantCategory(categories) {
  return categories.find((category) => String(category?.type || "").toLowerCase() === "staff") || categories[0];
}

function createMarkers(eventId) {
  const suffix = `${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  const safeSuffix = suffix.replace(/[^0-9a-z]/gi, "").toUpperCase();
  return {
    suffix,
    eventId,
    guestName: `Smoke Guest ${suffix}`,
    guestEmail: `smoke.guest.${suffix}@enjoyfun.local`,
    participantName: `Smoke Participant ${suffix}`,
    participantEmail: `smoke.participant.${suffix}@enjoyfun.local`,
    participantDocument: `SMK${safeSuffix.slice(0, 10)}`,
    ticketHolder: `Smoke Ticket ${suffix}`,
    plate: `SMK${safeSuffix.slice(-4)}7`,
  };
}

function buildOfflineItem(prefix, type, payload) {
  return {
    offline_id: `${prefix}-${type}-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`,
    payload_type: type,
    created_offline_at: new Date().toISOString(),
    payload,
  };
}

async function createTicket(session, eventId, ticketTypeId, holderName) {
  const { status, json } = await apiRequest({
    session,
    method: "POST",
    path: "/tickets",
    body: {
      event_id: eventId,
      ticket_type_id: ticketTypeId,
      holder_name: holderName,
    },
    expectedStatuses: [201],
  });

  assert(status === 201, "POST /tickets deve retornar 201.");
  assert(json?.success === true, "POST /tickets deve retornar success=true.");
  assert(String(json?.data?.qr_token || "").trim() !== "", "POST /tickets deve retornar qr_token.");
  return {
    qrToken: String(json.data.qr_token),
    orderReference: String(json.data.order_reference || ""),
  };
}

async function createGuest(session, eventId, ticketTypeId, holderName, holderEmail) {
  const { status, json } = await apiRequest({
    session,
    method: "POST",
    path: "/guests",
    body: {
      event_id: eventId,
      ticket_type_id: ticketTypeId,
      holder_name: holderName,
      holder_email: holderEmail,
    },
    expectedStatuses: [201],
  });

  assert(status === 201, "POST /guests deve retornar 201.");
  assert(json?.success === true, "POST /guests deve retornar success=true.");
  assert(String(json?.data?.qr_token || "").trim() !== "", "POST /guests deve retornar qr_token.");
  return {
    qrToken: String(json.data.qr_token),
  };
}

async function createParticipant(session, eventId, categoryId, markers) {
  const { status, json } = await apiRequest({
    session,
    method: "POST",
    path: "/participants",
    body: {
      event_id: eventId,
      category_id: categoryId,
      name: markers.participantName,
      email: markers.participantEmail,
      document: markers.participantDocument,
      phone: "41999999999",
    },
    expectedStatuses: [201],
  });

  assert(status === 201, "POST /participants deve retornar 201.");
  assert(json?.success === true, "POST /participants deve retornar success=true.");
  assert(Number(json?.data?.participant_id || 0) > 0, "POST /participants deve retornar participant_id.");
  assert(String(json?.data?.qr_token || "").trim() !== "", "POST /participants deve retornar qr_token.");
  return {
    id: Number(json.data.participant_id),
    qrToken: String(json.data.qr_token),
  };
}

async function syncItems(session, items, expectedStatuses = [200]) {
  const { status, json } = await apiRequest({
    session,
    method: "POST",
    path: "/sync",
    body: { items },
    expectedStatuses,
  });

  assert(json?.success === true, "POST /sync deve retornar success=true.");
  return { status, json };
}

async function findGuestByEmail(session, eventId, email) {
  const { json } = await apiRequest({
    session,
    path: "/guests",
    query: {
      event_id: eventId,
      search: email,
      limit: 20,
    },
    expectedStatus: 200,
  });

  const rows = json?.data?.items;
  assert(Array.isArray(rows), "GET /guests deve retornar data.items[].");
  return rows.find((row) => String(row?.email || "").toLowerCase() === String(email).toLowerCase()) || null;
}

async function findParticipantById(session, eventId, participantId) {
  const { json } = await apiRequest({
    session,
    path: "/participants",
    query: { event_id: eventId },
    expectedStatus: 200,
  });

  assert(Array.isArray(json?.data), "GET /participants deve retornar data[].");
  return json.data.find((row) => Number(row?.participant_id || 0) === participantId) || null;
}

async function findParkingByPlate(session, eventId, plate, status = "") {
  const query = { event_id: eventId };
  if (status) {
    query.status = status;
  }

  const { json } = await apiRequest({
    session,
    path: "/parking",
    query,
    expectedStatus: 200,
  });

  assert(Array.isArray(json?.data), "GET /parking deve retornar data[].");
  return json.data.find((row) => String(row?.license_plate || "").toUpperCase() === String(plate).toUpperCase()) || null;
}

async function fetchTicketByQrToken(session, qrToken) {
  const { json } = await apiRequest({
    session,
    path: `/tickets/${encodeURIComponent(qrToken)}`,
    expectedStatus: 200,
  });

  assert(json?.success === true, "GET /tickets/:token deve retornar success=true.");
  return json.data || null;
}

async function deleteGuestById(session, guestId) {
  const { json } = await apiRequest({
    session,
    method: "DELETE",
    path: `/guests/${guestId}`,
    expectedStatus: 200,
  });

  assert(json?.success === true, "DELETE /guests/:id deve retornar success=true.");
}

async function deleteParticipantById(session, participantId) {
  const { json } = await apiRequest({
    session,
    method: "DELETE",
    path: `/participants/${participantId}`,
    expectedStatus: 200,
  });

  assert(json?.success === true, "DELETE /participants/:id deve retornar success=true.");
}

async function main() {
  const session = createSession();
  const cleanupState = {
    guestId: 0,
    participantId: 0,
  };

  try {
    console.log(`[INFO] Base URL: ${defaultConfig.baseUrl}`);
    console.log(`[INFO] Synthetic label: ${syntheticLabel}`);

    const authenticated = await runStep("Autenticacao", () => login(session));
    const events = await runStep("Listagem de eventos", () => fetchEvents(session));
    const categories = await runStep("Listagem de categorias", () => fetchCategories(session));

    if (!authenticated || !events || !categories) {
      return false;
    }

    const resolved = await runStep("Resolucao do evento e ticket_type", () => resolveSmokeEvent(session, events));
    if (!resolved) {
      return false;
    }

    const category = pickParticipantCategory(categories);
    assert(Number(category?.id || 0) > 0, "A smoke precisa de uma categoria valida de participante.");

    const event = resolved.event;
    const ticketType = resolved.ticketType;
    const markers = createMarkers(Number(event.id || 0));

    console.log(`[INFO] Evento escolhido: ${event.id} - ${event.name}`);
    console.log(`[INFO] Ticket type escolhido: ${ticketType.id} - ${ticketType.name}`);

    const createdTicket = await runStep("Criacao de ticket sintetico", () =>
      createTicket(session, Number(event.id), Number(ticketType.id), markers.ticketHolder)
    );
    const createdGuest = await runStep("Criacao de guest sintetico", () =>
      createGuest(session, Number(event.id), Number(ticketType.id), markers.guestName, markers.guestEmail)
    );
    const createdParticipant = await runStep("Criacao de participant sintetico", async () => {
      const participant = await createParticipant(session, Number(event.id), Number(category.id), markers);
      cleanupState.participantId = participant.id;
      return participant;
    });

    if (!createdTicket || !createdGuest || !createdParticipant) {
      return false;
    }

    const createdGuestRow = await runStep("Resolucao do guest para cleanup", async () => {
      const row = await findGuestByEmail(session, Number(event.id), markers.guestEmail);
      assert(row && Number(row.id || 0) > 0, "Guest sintetico nao foi encontrado na listagem.");
      cleanupState.guestId = Number(row.id);
      return row;
    });

    if (!createdGuestRow) {
      return false;
    }

    const parkingEntryResult = await runStep("Sync positivo de parking_entry", async () => {
      const item = buildOfflineItem("smoke-offline-sync", "parking_entry", {
        event_id: Number(event.id),
        license_plate: markers.plate,
        vehicle_type: "car",
      });

      const { status, json } = await syncItems(session, [item], [200]);
      assert(status === 200, "parking_entry via /sync deve retornar 200.");
      assert(Number(json?.data?.processed || 0) === 1, "parking_entry via /sync deve processar 1 item.");
      return item;
    });

    const parkingRecordPending = await runStep("Resolucao do parking pendente", async () => {
      const row = await findParkingByPlate(session, Number(event.id), markers.plate, "pending");
      assert(row && Number(row.id || 0) > 0, "Registro de parking pendente nao foi encontrado.");
      assert(String(row.status || "").toLowerCase() === "pending", "Registro de parking deve nascer como pending.");
      return row;
    });

    if (!parkingEntryResult || !parkingRecordPending) {
      return false;
    }

    await runStep("Sync positivo de ticket/guest/participant/parking_validate", async () => {
      const batch = [
        buildOfflineItem("smoke-offline-sync", "ticket_validate", {
          event_id: Number(event.id),
          token: createdTicket.qrToken,
        }),
        buildOfflineItem("smoke-offline-sync", "guest_validate", {
          event_id: Number(event.id),
          token: createdGuest.qrToken,
          mode: "portaria",
        }),
        buildOfflineItem("smoke-offline-sync", "participant_validate", {
          event_id: Number(event.id),
          token: createdParticipant.qrToken,
          mode: "portaria",
        }),
        buildOfflineItem("smoke-offline-sync", "parking_validate", {
          event_id: Number(event.id),
          parking_id: Number(parkingRecordPending.id),
          qr_token: String(parkingRecordPending.qr_token || ""),
          action: "entry",
        }),
      ];

      const { status, json } = await syncItems(session, batch, [200]);
      assert(status === 200, "Batch positivo via /sync deve retornar 200.");
      assert(Number(json?.data?.processed || 0) === batch.length, "Batch positivo via /sync deve processar todos os itens.");
      assert(Number(json?.data?.failed || 0) === 0, "Batch positivo via /sync nao deve retornar falhas.");
    });

    await runStep("Verificacao de status apos batch positivo", async () => {
      const ticket = await fetchTicketByQrToken(session, createdTicket.qrToken);
      assert(String(ticket?.status || "").toLowerCase() === "used", "Ticket sintetico deve ficar com status used.");

      const guest = await findGuestByEmail(session, Number(event.id), markers.guestEmail);
      assert(guest && String(guest.status || "").toLowerCase() === "presente", "Guest sintetico deve ficar com status presente.");

      const participant = await findParticipantById(session, Number(event.id), createdParticipant.id);
      assert(participant && String(participant.status || "").toLowerCase() === "present", "Participant sintetico deve ficar com status present.");

      const parking = await findParkingByPlate(session, Number(event.id), markers.plate);
      assert(parking && String(parking.status || "").toLowerCase() === "parked", "Parking sintetico deve ficar com status parked.");
    });

    await runStep("Sync positivo de parking_exit", async () => {
      const item = buildOfflineItem("smoke-offline-sync", "parking_exit", {
        event_id: Number(event.id),
        parking_id: Number(parkingRecordPending.id),
      });

      const { status, json } = await syncItems(session, [item], [200]);
      assert(status === 200, "parking_exit via /sync deve retornar 200.");
      assert(Number(json?.data?.processed || 0) === 1, "parking_exit via /sync deve processar 1 item.");
    });

    await runStep("Verificacao final do parking", async () => {
      const row = await findParkingByPlate(session, Number(event.id), markers.plate, "exited");
      assert(row && String(row.status || "").toLowerCase() === "exited", "Parking sintetico deve terminar como exited.");
    });

    await runStep("Cleanup de guest e participant sinteticos", async () => {
      await cleanupSyntheticResources(session, cleanupState);
    });

    if (failures.length > 0) {
      console.error("\nResumo das falhas:");
      failures.forEach((failure) => console.error(`- ${failure}`));
      return false;
    }

    console.log("\n[OK] Smoke offline /sync concluida com sucesso.");
    console.log("[INFO] Artefatos preservados como evidencia: ticket comercial usado, registro de parking exited e trilha em offline_queue.");
    return true;
  } finally {
    await cleanupSyntheticResources(session, cleanupState);
  }
}

async function cleanupSyntheticResources(session, cleanupState) {
  if (cleanupState.guestId > 0) {
    try {
      await deleteGuestById(session, cleanupState.guestId);
    } catch (error) {
      console.error(`[WARN] Falha ao remover guest sintetico ${cleanupState.guestId}: ${error.message}`);
    } finally {
      cleanupState.guestId = 0;
    }
  }

  if (cleanupState.participantId > 0) {
    try {
      await deleteParticipantById(session, cleanupState.participantId);
    } catch (error) {
      console.error(`[WARN] Falha ao remover participant sintetico ${cleanupState.participantId}: ${error.message}`);
    } finally {
      cleanupState.participantId = 0;
    }
  }
}

main()
  .then((ok) => {
    process.exit(ok ? 0 : 1);
  })
  .catch((error) => {
    console.error(`[FATAL] ${error.message}`);
    process.exit(1);
  });
