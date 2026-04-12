# MemPalace Sidecar — EnjoyFun Hub
# BE-S6-A1: Long-term memory service with 19 rooms (1 per platform module).
# See: docs/adr_emas_architecture_v1.md
#
# Build: docker build -t enjoyfun-mempalace docker/mempalace/
# Run:   docker-compose up mempalace

FROM node:20-alpine AS base

WORKDIR /app

# MemPalace is an MCP-compatible memory server.
# When the official package is available, replace with: npm install mempalace
# For now, we use a lightweight in-memory store with persistence to a JSON file.

RUN npm init -y && npm install express cors uuid

COPY server.js /app/server.js

EXPOSE 3100

ENV MEMPALACE_PORT=3100
ENV MEMPALACE_WING=enjoyfun_hub
ENV MEMPALACE_DATA_DIR=/data
ENV MEMPALACE_ROOMS="events,tickets,cards,bar,food,shop,parking,workforce,meals,artists,messaging,branding,finance,files,ai,superadmin,platform_guide,documents,analytics"

VOLUME /data

CMD ["node", "server.js"]
