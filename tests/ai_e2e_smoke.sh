#!/usr/bin/env bash
# BE-S6-D1: AI E2E Smoke Test — 6 surfaces + health + approvals
# Usage: bash tests/ai_e2e_smoke.sh [BASE_URL] [TOKEN]
# Default: http://localhost:8000 with no auth (dev mode)

set -euo pipefail

BASE="${1:-http://localhost:8000}"
TOKEN="${2:-}"
AUTH=""
if [ -n "$TOKEN" ]; then AUTH="-H \"Authorization: Bearer $TOKEN\""; fi

PASS=0
FAIL=0

check() {
    local name="$1" method="$2" path="$3" expected_code="$4" body="${5:-}"
    local url="${BASE}${path}"
    local cmd="curl -s -o /dev/null -w '%{http_code}' -X $method"
    if [ -n "$AUTH" ]; then cmd="$cmd $AUTH"; fi
    if [ -n "$body" ]; then cmd="$cmd -H 'Content-Type: application/json' -d '$body'"; fi
    cmd="$cmd '$url'"

    local code
    code=$(eval $cmd 2>/dev/null || echo "000")

    if [ "$code" = "$expected_code" ]; then
        echo "  PASS [$code] $name"
        PASS=$((PASS + 1))
    else
        echo "  FAIL [$code] $name (expected $expected_code)"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== AI E2E Smoke Test ==="
echo "Base: $BASE"
echo ""

# Health
echo "-- Health --"
check "GET /api/health" GET "/api/health" "200"
check "GET /api/ai/health" GET "/api/ai/health" "200"

# Chat — 6 surfaces
echo ""
echo "-- Chat (6 surfaces) --"
for surface in bar parking workforce artists documents platform_guide; do
    check "POST /ai/chat surface=$surface" POST "/api/ai/chat" "200" \
        "{\"message\":\"teste smoke $surface\",\"surface\":\"$surface\",\"conversation_mode\":\"embedded\"}"
done

# Approvals
echo ""
echo "-- Approvals --"
check "GET /ai/approvals/pending" GET "/api/ai/approvals/pending" "200"

# Voice (without audio — should return 422)
echo ""
echo "-- Voice --"
check "POST /ai/voice/transcribe (no audio)" POST "/api/ai/voice/transcribe" "422"

# SSE (placeholder)
echo ""
echo "-- SSE --"
check "GET /ai/chat/stream (no session)" GET "/api/ai/chat/stream" "422"

echo ""
echo "=== Results: $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ] && echo "ALL PASS" || echo "SOME FAILED"
exit $FAIL
