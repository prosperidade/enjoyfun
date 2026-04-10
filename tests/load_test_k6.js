// ============================================================================
// EnjoyFun — k6 Load Test Script
// Prova de carga para evento real (5000+ pessoas)
// ============================================================================
//
// Usage:
// k6 run --env BASE_URL=http://localhost:8080/api --env ADMIN_EMAIL=admin@test.com --env ADMIN_PASSWORD=xxx --env EVENT_ID=1 tests/load_test_k6.js
//
// Quick smoke (10 VUs, 30s):
// k6 run --vus 10 --duration 30s --env BASE_URL=http://localhost:8080/api --env ADMIN_EMAIL=admin@test.com --env ADMIN_PASSWORD=xxx --env EVENT_ID=1 tests/load_test_k6.js
//
// With HTML report:
// k6 run --out json=results.json --env BASE_URL=... tests/load_test_k6.js
// ============================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ── Custom metrics per endpoint ──────────────────────────────────────────────

const healthDuration      = new Trend('endpoint_health_duration', true);
const loginDuration       = new Trend('endpoint_login_duration', true);
const eventsDuration      = new Trend('endpoint_events_duration', true);
const ticketsDuration     = new Trend('endpoint_tickets_duration', true);
const scannerDumpDuration = new Trend('endpoint_scanner_dump_duration', true);
const workforceAssignDuration = new Trend('endpoint_workforce_assignments_duration', true);
const workforceSummaryDuration = new Trend('endpoint_workforce_summary_duration', true);
const scannerProcessDuration  = new Trend('endpoint_scanner_process_duration', true);
const barCheckoutDuration = new Trend('endpoint_bar_checkout_duration', true);
const parkingDuration     = new Trend('endpoint_parking_duration', true);
const mealsDuration       = new Trend('endpoint_meals_duration', true);

const errorRate = new Rate('errors');

// ── Scenarios & Thresholds ───────────────────────────────────────────────────

export const options = {
  scenarios: {
    // Cenario 1: Ramp-up gradual (simula abertura de portoes)
    ramp_up: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 10 },   // warm-up
        { duration: '1m',  target: 50 },   // portoes abrindo
        { duration: '2m',  target: 100 },  // pico
        { duration: '1m',  target: 50 },   // estabiliza
        { duration: '30s', target: 0 },    // encerramento
      ],
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500', 'p(99)<1500'],
    http_req_failed:   ['rate<0.05'],
    errors:            ['rate<0.05'],

    // Per-endpoint thresholds (critical paths)
    endpoint_scanner_process_duration: ['p(95)<800'],
    endpoint_bar_checkout_duration:    ['p(95)<800'],
    endpoint_health_duration:          ['p(95)<200'],
  },
};

// ── Environment variables ────────────────────────────────────────────────────

const BASE_URL       = __ENV.BASE_URL       || 'http://localhost:8080/api';
const ADMIN_EMAIL    = __ENV.ADMIN_EMAIL    || 'admin@test.com';
const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'password';
const EVENT_ID       = __ENV.EVENT_ID       || '1';

// Cookie name used by the backend (HttpOnly transport)
const ACCESS_COOKIE_NAME = __ENV.ACCESS_COOKIE_NAME || 'enjoyfun_access_token';

// ── Setup: authenticate once, share token across VUs ─────────────────────────

export function setup() {
  const loginRes = http.post(
    `${BASE_URL}/auth/login`,
    JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
    {
      headers: { 'Content-Type': 'application/json' },
      tags: { endpoint: 'setup_login' },
    }
  );

  const loginOk = check(loginRes, {
    'setup: login status 200': (r) => r.status === 200,
  });

  if (!loginOk) {
    console.error(`Setup login failed: ${loginRes.status} — ${loginRes.body}`);
    // Try to extract token from body as fallback (non-cookie transport)
  }

  // Extract token: prefer body (setup has no cookie jar persistence to VUs)
  let accessToken = '';
  try {
    const parsed = JSON.parse(loginRes.body);
    accessToken = parsed.data?.access_token || parsed.access_token || '';
  } catch (_) {
    // noop
  }

  // Also try cookie
  if (!accessToken) {
    const cookies = loginRes.cookies || {};
    const tokenCookie = cookies[ACCESS_COOKIE_NAME];
    if (tokenCookie && tokenCookie.length > 0) {
      accessToken = tokenCookie[0].value || '';
    }
  }

  if (!accessToken) {
    console.warn('Setup: no access token obtained. Authenticated endpoints will fail.');
  }

  return { accessToken };
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function authHeaders(token) {
  const h = { 'Content-Type': 'application/json' };
  if (token) {
    // Send both cookie and Authorization header for maximum compatibility
    h['Authorization'] = `Bearer ${token}`;
    h['Cookie'] = `${ACCESS_COOKIE_NAME}=${token}`;
  }
  return h;
}

// Weighted random endpoint selector
// Returns an index based on cumulative weights
function weightedRandom(weights) {
  const total = weights.reduce((a, b) => a + b, 0);
  let r = Math.random() * total;
  for (let i = 0; i < weights.length; i++) {
    r -= weights[i];
    if (r <= 0) return i;
  }
  return weights.length - 1;
}

// Generate a fake ticket token for scanner process
function fakeTicketToken() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let token = '';
  for (let i = 0; i < 32; i++) {
    token += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return token;
}

// ── Endpoint definitions ─────────────────────────────────────────────────────

const ENDPOINTS = [
  // 0: GET /health (5%)
  {
    name: 'health',
    weight: 5,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/health`, {
        headers: authHeaders(null), // no auth needed
        tags: { endpoint: 'GET_health' },
      });
      healthDuration.add(res.timings.duration);
      check(res, {
        'health: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 1: POST /auth/login (5%)
  {
    name: 'auth_login',
    weight: 5,
    fn: (_token) => {
      const res = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
        {
          headers: { 'Content-Type': 'application/json' },
          tags: { endpoint: 'POST_auth_login' },
        }
      );
      loginDuration.add(res.timings.duration);
      check(res, {
        'login: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 2: GET /events (10%)
  {
    name: 'events',
    weight: 10,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/events`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_events' },
      });
      eventsDuration.add(res.timings.duration);
      check(res, {
        'events: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 3: GET /tickets?event_id=X (15%)
  {
    name: 'tickets',
    weight: 15,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/tickets?event_id=${EVENT_ID}`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_tickets' },
      });
      ticketsDuration.add(res.timings.duration);
      check(res, {
        'tickets: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 4: GET /scanner/dump?event_id=X (10%)
  {
    name: 'scanner_dump',
    weight: 10,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/scanner/dump?event_id=${EVENT_ID}`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_scanner_dump' },
      });
      scannerDumpDuration.add(res.timings.duration);
      check(res, {
        'scanner_dump: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 5: GET /workforce/assignments?event_id=X (10%)
  {
    name: 'workforce_assignments',
    weight: 10,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/workforce/assignments?event_id=${EVENT_ID}`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_workforce_assignments' },
      });
      workforceAssignDuration.add(res.timings.duration);
      check(res, {
        'workforce_assignments: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 6: GET /workforce/summary?event_id=X (10%)
  {
    name: 'workforce_summary',
    weight: 10,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/workforce/summary?event_id=${EVENT_ID}`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_workforce_summary' },
      });
      workforceSummaryDuration.add(res.timings.duration);
      check(res, {
        'workforce_summary: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 7: POST /scanner/process (15%) — validacao de ingresso (scan)
  {
    name: 'scanner_process',
    weight: 15,
    fn: (token) => {
      const res = http.post(
        `${BASE_URL}/scanner/process`,
        JSON.stringify({
          token: fakeTicketToken(),
          mode: 'portaria',
          event_id: parseInt(EVENT_ID, 10),
        }),
        {
          headers: authHeaders(token),
          tags: { endpoint: 'POST_scanner_process' },
        }
      );
      scannerProcessDuration.add(res.timings.duration);
      // Scanner may return 404/422 for fake tokens — that's expected.
      // We only count 5xx as errors.
      check(res, {
        'scanner_process: not 5xx': (r) => r.status < 500,
      });
      errorRate.add(res.status >= 500);
    },
  },

  // 8: POST /bar/checkout (10%) — checkout POS
  {
    name: 'bar_checkout',
    weight: 10,
    fn: (token) => {
      const res = http.post(
        `${BASE_URL}/bar/checkout`,
        JSON.stringify({
          event_id: parseInt(EVENT_ID, 10),
          card_uid: 'LOADTEST_' + Math.floor(Math.random() * 100000),
          items: [
            { product_id: 1, qty: 1, unit_price: 10.0 },
          ],
        }),
        {
          headers: authHeaders(token),
          tags: { endpoint: 'POST_bar_checkout' },
        }
      );
      barCheckoutDuration.add(res.timings.duration);
      // Checkout may fail for non-existent cards/products — we only flag 5xx
      check(res, {
        'bar_checkout: not 5xx': (r) => r.status < 500,
      });
      errorRate.add(res.status >= 500);
    },
  },

  // 9: GET /parking?event_id=X (5%)
  {
    name: 'parking',
    weight: 5,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/parking?event_id=${EVENT_ID}`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_parking' },
      });
      parkingDuration.add(res.timings.duration);
      check(res, {
        'parking: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },

  // 10: GET /meals?event_id=X (5%)
  {
    name: 'meals',
    weight: 5,
    fn: (token) => {
      const res = http.get(`${BASE_URL}/meals?event_id=${EVENT_ID}`, {
        headers: authHeaders(token),
        tags: { endpoint: 'GET_meals' },
      });
      mealsDuration.add(res.timings.duration);
      check(res, {
        'meals: status 200': (r) => r.status === 200,
      });
      errorRate.add(res.status !== 200);
    },
  },
];

const WEIGHTS = ENDPOINTS.map((e) => e.weight);

// ── Main VU loop ─────────────────────────────────────────────────────────────

export default function (data) {
  const token = data.accessToken;
  const idx = weightedRandom(WEIGHTS);
  ENDPOINTS[idx].fn(token);

  // Simulate real user think time (200ms-1s)
  sleep(0.2 + Math.random() * 0.8);
}

// ── Teardown ─────────────────────────────────────────────────────────────────

export function teardown(data) {
  console.log('=== Load test complete ===');
  console.log(`Base URL: ${BASE_URL}`);
  console.log(`Event ID: ${EVENT_ID}`);
  console.log('Check k6 output above for per-endpoint metrics (endpoint_* trends).');
}
