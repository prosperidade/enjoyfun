---
name: k6-load-testing
description: >
  Testes de carga com k6 para EnjoyFun. Use ao criar load tests, validar
  performance de endpoints, ou preparar para D-Day.
  Trigger: load test, k6, performance, stress test, carga, throughput.
---

# K6 Load Testing — EnjoyFun

## Estrutura
```
tests/
└── load_test_k6.js
```

## Template
```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [
    { duration: '1m', target: 50 },   // ramp up
    { duration: '3m', target: 50 },   // sustain
    { duration: '1m', target: 200 },  // spike (D-Day)
    { duration: '1m', target: 0 },    // ramp down
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'],  // 95% < 500ms
    http_req_failed: ['rate<0.01'],    // <1% falha
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.AUTH_TOKEN;

export default function () {
  const headers = { Authorization: `Bearer ${TOKEN}` };

  // Dashboard executivo (endpoint mais pesado)
  const dash = http.get(`${BASE_URL}/api/dashboard/executive`, { headers });
  check(dash, { 'dashboard 200': (r) => r.status === 200 });

  // Validação de ticket (endpoint mais crítico no D-Day)
  const validate = http.post(`${BASE_URL}/api/tickets/validate`,
    JSON.stringify({ qr_code: `test-${__VU}-${__ITER}` }),
    { headers: { ...headers, 'Content-Type': 'application/json' } }
  );
  check(validate, { 'validate 200|409': (r) => [200, 409].includes(r.status) });

  sleep(1);
}
```

## Executar
```bash
k6 run tests/load_test_k6.js --env BASE_URL=http://localhost:8000 --env AUTH_TOKEN=xxx
```

## Endpoints Críticos D-Day
1. `/api/tickets/validate` — pico de check-ins
2. `/api/cashless/debit` — pico do bar
3. `/api/dashboard/executive` — organizador monitorando
4. `/api/ai/ask` — agentes respondendo
