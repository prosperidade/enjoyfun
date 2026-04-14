---
name: docker-compose
description: >
  Docker e docker-compose para infra EnjoyFun (PostgreSQL, Redis, MemPalace sidecar).
  Use ao configurar containers, docker-compose, ou ambiente de deploy.
  Trigger: Docker, container, docker-compose, deploy, infra, sidecar.
---

# Docker Compose — EnjoyFun

## Serviços
```yaml
services:
  postgres:
    image: postgres:18
    environment:
      POSTGRES_PASSWORD: ${DB_PASS}
      POSTGRES_DB: enjoyfun
    volumes:
      - pgdata:/var/lib/postgresql/data
    # IMPORTANTE: scram-sha-256 por padrão no container

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  backend:
    build: ./backend
    depends_on: [postgres, redis]
    env_file: ./backend/.env

  frontend:
    build: ./frontend
    ports:
      - "5173:5173"
```

## Regras
- `pg_hba.conf`: `scram-sha-256` em produção (container já usa por padrão)
- Volumes nomeados para persistência (`pgdata:`)
- `.env` via `env_file`, nunca `environment` com valores literais
- Health checks em services críticos
- MemPalace sidecar: container separado com ChromaDB (Sprint 6)
