# Setup do Projeto — EnjoyFun

> Stack: PHP puro · React + Vite · PostgreSQL  
> Shadcn/ui + Tailwind CSS para componentes React

---

## Estrutura de pastas sugerida

```
enjoyfun/
├── backend/                        ← PHP puro (API REST)
│   ├── public/
│   │   └── index.php               ← Entry point (router)
│   ├── src/
│   │   ├── Services/
│   │   │   ├── ArtistService.php
│   │   │   ├── TimelineService.php
│   │   │   ├── AlertCalculatorService.php
│   │   │   ├── PayableService.php
│   │   │   └── CardService.php
│   │   ├── Validators/
│   │   │   └── DocumentValidator.php
│   │   └── Jobs/
│   │       └── OverdueCheckerJob.php
│   ├── database/
│   │   └── migrations.sql          ← Rodar uma vez no banco
│   ├── .env
│   └── composer.json
│
└── frontend/                       ← React + Vite
    ├── src/
    │   ├── pages/
    │   │   ├── logistics/
    │   │   │   ├── ArtistList.tsx
    │   │   │   ├── ArtistDetail.tsx
    │   │   │   ├── Timeline.tsx
    │   │   │   └── Alerts.tsx
    │   │   └── financial/
    │   │       ├── Dashboard.tsx
    │   │       ├── Payables.tsx
    │   │       └── ByArtist.tsx
    │   ├── components/
    │   │   ├── ui/                 ← gerado pelo shadcn
    │   │   ├── AlertBadge.tsx
    │   │   ├── PayableStatusBadge.tsx
    │   │   └── TimelineVisual.tsx
    │   ├── services/               ← chamadas à API PHP
    │   │   ├── artists.ts
    │   │   ├── payables.ts
    │   │   └── api.ts              ← fetch base com auth
    │   └── main.tsx
    ├── index.html
    ├── vite.config.ts
    ├── tailwind.config.ts
    └── package.json
```

---

## 1. Banco de dados (PostgreSQL)

### Criar o banco

```bash
psql -U postgres
CREATE DATABASE enjoyfun_dev;
\q
```

### Rodar as migrations

```bash
psql -U postgres -d enjoyfun_dev -f backend/database/migrations.sql
```

### Verificar as tabelas criadas

```bash
psql -U postgres -d enjoyfun_dev -c "\dt"
```

Você deve ver as 25 tabelas listadas.

---

## 2. Backend PHP

### Instalar dependências via Composer

```bash
cd backend
composer require vlucas/phpdotenv   # variáveis de ambiente
composer require firebase/php-jwt   # autenticação JWT
```

### Configurar `.env`

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=enjoyfun_dev
DB_USER=postgres
DB_PASS=sua_senha

JWT_SECRET=sua_chave_secreta_aqui
APP_ENV=development
```

### Conexão PDO (reutilizar em todos os Services)

```php
<?php
// src/Database.php

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_NAME']
        );
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
```

### Router mínimo (public/index.php)

```php
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5173');  // porta do Vite
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Extrair organizer_id do JWT
function getOrganizerFromToken(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'UNAUTHORIZED']);
        exit;
    }
    $token = substr($auth, 7);
    try {
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($_ENV['JWT_SECRET'], 'HS256'));
        return $decoded->organizer_id;
    } catch (\Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'UNAUTHORIZED']);
        exit;
    }
}

// Roteamento simples — expanda conforme necessário
match(true) {
    $method === 'GET'  && $uri === '/api/v1/artists'  => require 'routes/artists_list.php',
    $method === 'POST' && $uri === '/api/v1/artists'  => require 'routes/artists_create.php',
    default => (function() {
        http_response_code(404);
        echo json_encode(['error' => 'NOT_FOUND']);
    })()
};
```

### Resposta JSON padrão

```php
<?php
function jsonResponse(mixed $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $code, string $message, int $status = 400, array $fields = []): void
{
    http_response_code($status);
    $body = ['error' => $code, 'message' => $message];
    if ($fields) $body['fields'] = $fields;
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
```

---

## 3. Frontend React + Vite

### Criar o projeto

```bash
npm create vite@latest frontend -- --template react-ts
cd frontend
npm install
```

### Instalar Tailwind CSS

```bash
npm install -D tailwindcss postcss autoprefixer
npx tailwindcss init -p
```

`tailwind.config.ts`:
```ts
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: { extend: {} },
  plugins: [],
}
```

`src/index.css`:
```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

### Instalar Shadcn/ui

```bash
npx shadcn@latest init
```

Aceitar os defaults (Tailwind, React, TypeScript).

Depois instalar componentes conforme precisar:
```bash
npx shadcn@latest add button
npx shadcn@latest add table
npx shadcn@latest add dialog
npx shadcn@latest add drawer
npx shadcn@latest add badge
npx shadcn@latest add card
npx shadcn@latest add input
npx shadcn@latest add select
npx shadcn@latest add toast
```

### Instalar dependências extras

```bash
npm install axios                          # chamadas HTTP
npm install react-router-dom              # navegação
npm install @tanstack/react-query         # cache e estado de server
npm install recharts                      # gráficos do dashboard
npm install date-fns                      # formatação de datas
npm install react-hook-form zod           # formulários + validação
npm install @hookform/resolvers           # integração react-hook-form + zod
```

### Configurar proxy Vite → PHP (vite.config.ts)

```ts
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',   // servidor PHP
        changeOrigin: true,
      }
    }
  }
})
```

### Cliente HTTP base (src/services/api.ts)

```ts
import axios from 'axios'

const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'Content-Type': 'application/json' },
})

// Injetar token JWT em todas as requisições
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Tratar erros globalmente
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api
```

### Exemplo de service (src/services/artists.ts)

```ts
import api from './api'

export const artistsService = {
  list: (eventId: string, params?: Record<string, string>) =>
    api.get(`/events/${eventId}/artists`, { params }),

  get: (eventId: string, eventArtistId: string) =>
    api.get(`/events/${eventId}/artists/${eventArtistId}`),

  create: (eventId: string, data: unknown) =>
    api.post(`/events/${eventId}/artists`, data),

  update: (eventId: string, eventArtistId: string, data: unknown) =>
    api.patch(`/events/${eventId}/artists/${eventArtistId}`, data),

  remove: (eventId: string, eventArtistId: string) =>
    api.delete(`/events/${eventId}/artists/${eventArtistId}`),
}
```

---

## 4. Rodar o projeto

### Iniciar o servidor PHP

```bash
cd backend
php -S localhost:8000 -t public
```

### Iniciar o Vite (frontend)

```bash
cd frontend
npm run dev
```

Acesse: `http://localhost:5173`

---

## 5. Cron job — contas vencidas

Adicionar ao crontab do servidor:

```bash
crontab -e

# Marcar contas vencidas todo dia às 06:00
0 6 * * * php /var/www/enjoyfun/backend/jobs/overdue_checker.php >> /var/log/enjoyfun_cron.log 2>&1
```

`backend/jobs/overdue_checker.php`:
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';

$job = new OverdueCheckerJob(getDB());
$updated = $job->run();
echo date('Y-m-d H:i:s') . " — {$updated} contas marcadas como vencidas.\n";
```

---

## 6. Checklist de setup completo

### Banco de dados
- [ ] PostgreSQL instalado e rodando
- [ ] Banco `enjoyfun_dev` criado
- [ ] `migrations.sql` executado
- [ ] 25 tabelas visíveis no `\dt`
- [ ] Views criadas (`v_event_financial_summary`, `v_cost_by_category`, etc.)

### Backend PHP
- [ ] `composer install` rodado
- [ ] `.env` configurado com credenciais do banco e JWT_SECRET
- [ ] `php -S localhost:8000 -t public` funcionando
- [ ] `GET /api/v1/artists` retornando JSON

### Frontend React + Vite
- [ ] `npm install` rodado
- [ ] `npm run dev` rodando em `localhost:5173`
- [ ] Proxy `/api` apontando para `localhost:8000`
- [ ] Shadcn/ui inicializado
- [ ] Componentes básicos instalados (button, table, badge, card, dialog)

### Cron
- [ ] Job de overdue configurado no crontab

---

*Setup do Projeto · EnjoyFun · PHP + React + Vite + PostgreSQL*
