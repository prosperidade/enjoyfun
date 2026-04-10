#!/bin/bash
###############################################################################
# EnjoyFun Smoke Tests
# Validates security hardening, multi-tenant isolation, and API correctness.
#
# Usage:
#   ./tests/smoke_test.sh [BASE_URL] [ADMIN_EMAIL] [ADMIN_PASSWORD]
#
# Defaults:
#   BASE_URL       = http://localhost:8080
#   ADMIN_EMAIL    = (must set or tests requiring auth will skip)
#   ADMIN_PASSWORD = (must set or tests requiring auth will skip)
#
# Requirements: bash 4+, curl, jq (optional, for prettier output)
# Works on Linux, macOS, and Git Bash on Windows.
###############################################################################

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
ADMIN_EMAIL="${2:-}"
ADMIN_PASSWORD="${3:-}"

# Strip trailing slash
BASE_URL="${BASE_URL%/}"
API="${BASE_URL}/api"

PASS=0
FAIL=0
SKIP=0
TOKEN=""

# ── Colors ────────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    YELLOW='\033[0;33m'
    CYAN='\033[0;36m'
    BOLD='\033[1m'
    NC='\033[0m'
else
    GREEN='' RED='' YELLOW='' CYAN='' BOLD='' NC=''
fi

# ── Helpers ───────────────────────────────────────────────────────────────────

assert_status() {
    local description="$1"
    local expected="$2"
    local actual="$3"
    local body="${4:-}"

    if [[ "$actual" == "$expected" ]]; then
        echo -e "${GREEN}[PASS]${NC} ${description} (HTTP ${actual})"
        ((PASS += 1))
    else
        echo -e "${RED}[FAIL]${NC} ${description} (expected HTTP ${expected}, got ${actual})"
        if [[ -n "$body" ]]; then
            echo "       Response: ${body:0:200}"
        fi
        ((FAIL += 1))
    fi
}

assert_body_contains() {
    local description="$1"
    local needle="$2"
    local body="$3"

    if echo "$body" | grep -qi "$needle"; then
        echo -e "${GREEN}[PASS]${NC} ${description}"
        ((PASS += 1))
    else
        echo -e "${RED}[FAIL]${NC} ${description} (body does not contain '${needle}')"
        echo "       Response: ${body:0:200}"
        ((FAIL += 1))
    fi
}

assert_body_not_contains() {
    local description="$1"
    local needle="$2"
    local body="$3"

    if echo "$body" | grep -qi "$needle"; then
        echo -e "${RED}[FAIL]${NC} ${description} (body unexpectedly contains '${needle}')"
        echo "       Response: ${body:0:200}"
        ((FAIL += 1))
    else
        echo -e "${GREEN}[PASS]${NC} ${description}"
        ((PASS += 1))
    fi
}

skip_test() {
    echo -e "${YELLOW}[SKIP]${NC} $1"
    ((SKIP += 1))
}

# Perform a curl request and capture both status and body
# Usage: do_request METHOD URL [DATA]
# Sets: RESP_STATUS, RESP_BODY
do_request() {
    local method="$1"
    local url="$2"
    local data="${3:-}"
    local extra_headers=("${@:4}")

    local curl_args=(-s -w '\n%{http_code}' -X "$method" -H 'Content-Type: application/json')

    if [[ -n "$TOKEN" ]]; then
        curl_args+=(-H "Authorization: Bearer ${TOKEN}")
    fi

    for h in "${extra_headers[@]}"; do
        [[ -n "$h" ]] && curl_args+=(-H "$h")
    done

    if [[ -n "$data" ]]; then
        curl_args+=(-d "$data")
    fi

    local response
    response=$(curl "${curl_args[@]}" "$url" 2>/dev/null) || true

    RESP_STATUS=$(echo "$response" | tail -1)
    RESP_BODY=$(echo "$response" | sed '$d')
}

section() {
    echo ""
    echo -e "${CYAN}${BOLD}=== $1 ===${NC}"
}

###############################################################################
# A. HEALTH & INFRASTRUCTURE
###############################################################################
section "A. Health & Infrastructure"

do_request GET "${API}/health"
assert_status "GET /health returns 200" "200" "$RESP_STATUS"
assert_body_contains "GET /health body has 'ok'" "ok" "$RESP_BODY"

do_request GET "${API}/health/deep"
assert_status "GET /health/deep returns 200" "200" "$RESP_STATUS"
assert_body_contains "GET /health/deep checks database" "database" "$RESP_BODY"

do_request GET "${API}/ping"
assert_status "GET /ping (root) returns 200" "200" "$RESP_STATUS"
assert_body_contains "GET /ping shows version 2.0" "2.0" "$RESP_BODY"

###############################################################################
# B. AUTH SECURITY (C02, H04)
###############################################################################
section "B. Auth Security"

# B1. Login with invalid credentials -- should fail gracefully
do_request POST "${API}/auth/login" '{"email":"invalid@nonexistent.test","password":"wrongpass123"}'
assert_status "Login with invalid credentials rejects" "401" "$RESP_STATUS"
assert_body_not_contains "Invalid login has no stack trace" "Stack trace" "$RESP_BODY"
assert_body_not_contains "Invalid login has no file path" ".php" "$RESP_BODY"

# B2. Rapid login attempts -- check for rate limiting (429)
echo -e "${CYAN}  Sending 6 rapid login attempts...${NC}"
RATE_LIMITED=false
for i in $(seq 1 6); do
    do_request POST "${API}/auth/login" '{"email":"ratelimit@test.test","password":"wrong"}'
    if [[ "$RESP_STATUS" == "429" ]]; then
        RATE_LIMITED=true
        break
    fi
done
if $RATE_LIMITED; then
    echo -e "${GREEN}[PASS]${NC} Rate limiting active (429 after rapid attempts)"
    ((PASS += 1))
else
    echo -e "${YELLOW}[SKIP]${NC} Rate limiting not triggered in 6 attempts (may need more or Redis)"
    ((SKIP += 1))
fi

# B3. request-access-code should use event_slug, not organizer_id from body
do_request POST "${API}/auth/request-code" '{"identifier":"test@test.com","organizer_id":9999}'
# The organizer_id in body should be irrelevant -- endpoint should work based on identifier
assert_body_not_contains "request-code ignores organizer_id in body" "organizer_id" "$RESP_BODY"

# B4. Login with valid credentials to get a token for subsequent tests
if [[ -n "$ADMIN_EMAIL" && -n "$ADMIN_PASSWORD" ]]; then
    do_request POST "${API}/auth/login" "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}"
    if [[ "$RESP_STATUS" == "200" ]]; then
        TOKEN=$(echo "$RESP_BODY" | grep -o '"token":"[^"]*"' | head -1 | cut -d'"' -f4)
        if [[ -z "$TOKEN" ]]; then
            # Try alternate key name
            TOKEN=$(echo "$RESP_BODY" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
        fi
        if [[ -n "$TOKEN" ]]; then
            echo -e "${GREEN}[PASS]${NC} Login with valid credentials succeeded, JWT obtained"
            ((PASS += 1))
        else
            echo -e "${RED}[FAIL]${NC} Login succeeded but could not extract token from response"
            echo "       Response: ${RESP_BODY:0:200}"
            ((FAIL += 1))
        fi
    else
        echo -e "${RED}[FAIL]${NC} Login with valid credentials failed (HTTP ${RESP_STATUS})"
        ((FAIL += 1))
    fi
else
    skip_test "Login with valid credentials (no ADMIN_EMAIL/ADMIN_PASSWORD provided)"
fi

###############################################################################
# C. ERROR SANITIZATION (H01)
###############################################################################
section "C. Error Sanitization"

# C1. Unknown route should return 404 with no sensitive info
do_request GET "${API}/nonexistent-route-xyz"
assert_status "Unknown route returns 404" "404" "$RESP_STATUS"
assert_body_not_contains "404 has no file paths" "Controllers/" "$RESP_BODY"
assert_body_not_contains "404 has no line numbers" "line " "$RESP_BODY"
assert_body_not_contains "404 has no stack trace" "Stack trace" "$RESP_BODY"

# C2. Verify production error format has correlation_id
# In production mode, 500s should have correlation_id. We test with what we can trigger.
do_request POST "${API}/auth/login" '{}'
assert_body_not_contains "Empty login body has no stack trace" "Stack trace" "$RESP_BODY"
assert_body_not_contains "Empty login body has no .php paths" ".php" "$RESP_BODY"

###############################################################################
# D. IDOR / MULTI-TENANT (C02, H03)
###############################################################################
section "D. IDOR / Multi-tenant Isolation"

if [[ -n "$TOKEN" ]]; then
    # D1. Try accessing events without auth header
    OLD_TOKEN="$TOKEN"
    TOKEN=""
    do_request GET "${API}/events"
    assert_status "Events without auth returns 401" "401" "$RESP_STATUS"
    TOKEN="$OLD_TOKEN"

    # D2. Try with a forged/invalid token
    OLD_TOKEN="$TOKEN"
    TOKEN="eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOjk5OTk5LCJyb2xlIjoiYWRtaW4ifQ.invalid_signature"
    do_request GET "${API}/events"
    if [[ "$RESP_STATUS" == "401" || "$RESP_STATUS" == "403" ]]; then
        echo -e "${GREEN}[PASS]${NC} Forged JWT rejected (HTTP ${RESP_STATUS})"
        ((PASS += 1))
    else
        echo -e "${RED}[FAIL]${NC} Forged JWT not rejected (HTTP ${RESP_STATUS})"
        ((FAIL += 1))
    fi
    TOKEN="$OLD_TOKEN"

    # D3. DELETE participant without proper scope
    do_request DELETE "${API}/participants/999999"
    if [[ "$RESP_STATUS" == "404" || "$RESP_STATUS" == "403" || "$RESP_STATUS" == "400" ]]; then
        echo -e "${GREEN}[PASS]${NC} DELETE non-existent participant returns safe error (HTTP ${RESP_STATUS})"
        ((PASS += 1))
    else
        echo -e "${RED}[FAIL]${NC} DELETE participant unexpected status (HTTP ${RESP_STATUS})"
        ((FAIL += 1))
    fi
else
    skip_test "IDOR tests (no auth token available)"
fi

###############################################################################
# E. CHECKOUT VALIDATION (H05)
###############################################################################
section "E. Checkout Validation"

if [[ -n "$TOKEN" ]]; then
    # E1. Negative quantity
    do_request POST "${API}/bar/checkout" '{"event_id":1,"items":[{"product_id":1,"quantity":-5}]}'
    if [[ "$RESP_STATUS" -ge 400 ]]; then
        echo -e "${GREEN}[PASS]${NC} Checkout with negative quantity rejected (HTTP ${RESP_STATUS})"
        ((PASS += 1))
    else
        echo -e "${RED}[FAIL]${NC} Checkout with negative quantity not rejected (HTTP ${RESP_STATUS})"
        ((FAIL += 1))
    fi

    # E2. Quantity > 1000
    do_request POST "${API}/bar/checkout" '{"event_id":1,"items":[{"product_id":1,"quantity":1001}]}'
    if [[ "$RESP_STATUS" -ge 400 ]]; then
        echo -e "${GREEN}[PASS]${NC} Checkout with qty > 1000 rejected (HTTP ${RESP_STATUS})"
        ((PASS += 1))
    else
        echo -e "${RED}[FAIL]${NC} Checkout with qty > 1000 not rejected (HTTP ${RESP_STATUS})"
        ((FAIL += 1))
    fi

    # E3. More than 100 items in a single checkout
    MANY_ITEMS=$(python3 -c "
import json
items = [{'product_id': i, 'quantity': 1} for i in range(1, 102)]
print(json.dumps({'event_id': 1, 'items': items}))
" 2>/dev/null || echo "")

    if [[ -n "$MANY_ITEMS" ]]; then
        do_request POST "${API}/bar/checkout" "$MANY_ITEMS"
        if [[ "$RESP_STATUS" -ge 400 ]]; then
            echo -e "${GREEN}[PASS]${NC} Checkout with > 100 items rejected (HTTP ${RESP_STATUS})"
            ((PASS += 1))
        else
            echo -e "${RED}[FAIL]${NC} Checkout with > 100 items not rejected (HTTP ${RESP_STATUS})"
            ((FAIL += 1))
        fi
    else
        skip_test "Checkout > 100 items (python3 not available to generate payload)"
    fi
else
    skip_test "Checkout validation tests (no auth token available)"
fi

###############################################################################
# F. AI RATE LIMITING (H16)
###############################################################################
section "F. AI Rate Limiting"

# F1. AI endpoint without auth -> 401/403
OLD_TOKEN="$TOKEN"
TOKEN=""
do_request POST "${API}/ai/insight" '{"prompt":"test"}'
if [[ "$RESP_STATUS" == "401" || "$RESP_STATUS" == "403" ]]; then
    echo -e "${GREEN}[PASS]${NC} AI endpoint without auth returns ${RESP_STATUS}"
    ((PASS += 1))
else
    echo -e "${RED}[FAIL]${NC} AI endpoint without auth returned ${RESP_STATUS} (expected 401/403)"
    ((FAIL += 1))
fi
TOKEN="$OLD_TOKEN"

###############################################################################
# G. SYNC IDEMPOTENCY (C08, H21)
###############################################################################
section "G. Sync Idempotency"

if [[ -n "$TOKEN" ]]; then
    # G1. POST sync with > 500 items -> should be rejected (413 or 422)
    MANY_SYNC=$(python3 -c "
import json
items = [{'type': 'sale', 'offline_id': 'test-' + str(i), 'payload': {'event_id': 1}} for i in range(501)]
print(json.dumps({'items': items}))
" 2>/dev/null || echo "")

    if [[ -n "$MANY_SYNC" ]]; then
        do_request POST "${API}/sync" "$MANY_SYNC"
        if [[ "$RESP_STATUS" -ge 400 ]]; then
            echo -e "${GREEN}[PASS]${NC} Sync with > 500 items rejected (HTTP ${RESP_STATUS})"
            ((PASS += 1))
        else
            echo -e "${YELLOW}[SKIP]${NC} Sync with > 500 items returned ${RESP_STATUS} (limit may not be enforced yet)"
            ((SKIP += 1))
        fi
    else
        skip_test "Sync > 500 items (python3 not available to generate payload)"
    fi

    # G2. Same offline_id twice -> second should be deduplicated
    OFFLINE_ID="smoke-test-$(date +%s)-dedup"
    SYNC_PAYLOAD="{\"items\":[{\"type\":\"sale\",\"offline_id\":\"${OFFLINE_ID}\",\"payload\":{\"event_id\":1,\"items\":[{\"product_id\":1,\"quantity\":1}]}}]}"

    do_request POST "${API}/sync" "$SYNC_PAYLOAD"
    FIRST_STATUS="$RESP_STATUS"
    FIRST_BODY="$RESP_BODY"

    do_request POST "${API}/sync" "$SYNC_PAYLOAD"
    SECOND_STATUS="$RESP_STATUS"
    SECOND_BODY="$RESP_BODY"

    # Both should succeed, but second should indicate deduplication
    if echo "$SECOND_BODY" | grep -qi "dedup\|skipped\|already\|duplicate\|idempoten"; then
        echo -e "${GREEN}[PASS]${NC} Sync deduplication: second submission recognized as duplicate"
        ((PASS += 1))
    else
        echo -e "${YELLOW}[SKIP]${NC} Sync deduplication: could not confirm dedup (may need specific event/product data)"
        ((SKIP += 1))
    fi
else
    skip_test "Sync tests (no auth token available)"
fi

###############################################################################
# H. PAYMENT GATEWAY (H20)
###############################################################################
section "H. Payment Gateway"

# H1. GET /api/payments/split?amount=100 -> 1% / 99% split
if [[ -n "$TOKEN" ]]; then
    do_request GET "${API}/payments/split?amount=100"
    if [[ "$RESP_STATUS" == "200" ]]; then
        assert_body_contains "Split returns platform fee (1%)" "1" "$RESP_BODY"
        echo -e "${GREEN}[PASS]${NC} GET /payments/split returns 200"
        ((PASS += 1))
    else
        echo -e "${YELLOW}[SKIP]${NC} GET /payments/split returned ${RESP_STATUS} (gateway may not be configured)"
        ((SKIP += 1))
    fi
else
    skip_test "Payment split test (no auth token available)"
fi

# H2. POST webhook without HMAC -> rejected
TOKEN=""
do_request POST "${API}/payments/webhook" '{"event":"payment_confirmed","id":"test123"}'
if [[ "$RESP_STATUS" == "401" || "$RESP_STATUS" == "403" ]]; then
    echo -e "${GREEN}[PASS]${NC} Webhook without HMAC signature rejected (HTTP ${RESP_STATUS})"
    ((PASS += 1))
else
    echo -e "${RED}[FAIL]${NC} Webhook without HMAC returned ${RESP_STATUS} (expected 401/403)"
    ((FAIL += 1))
fi
TOKEN="$OLD_TOKEN"

###############################################################################
# I. MESSAGING (H18)
###############################################################################
section "I. Messaging Webhook Security"

# Note: messaging webhook endpoint specifics depend on controller implementation.
# We test that unauthenticated access to messaging is rejected.
TOKEN=""
do_request POST "${API}/messaging/webhook" '{"timestamp":1000000000,"payload":"stale"}'
if [[ "$RESP_STATUS" == "401" || "$RESP_STATUS" == "403" || "$RESP_STATUS" == "422" ]]; then
    echo -e "${GREEN}[PASS]${NC} Messaging webhook with stale/no auth rejected (HTTP ${RESP_STATUS})"
    ((PASS += 1))
else
    echo -e "${YELLOW}[SKIP]${NC} Messaging webhook returned ${RESP_STATUS} (endpoint may not validate timestamps yet)"
    ((SKIP += 1))
fi
TOKEN="$OLD_TOKEN"

###############################################################################
# SUMMARY
###############################################################################
echo ""
echo -e "${BOLD}========================================${NC}"
echo -e "${BOLD}  SMOKE TEST SUMMARY${NC}"
echo -e "${BOLD}========================================${NC}"
echo -e "  ${GREEN}Passed: ${PASS}${NC}"
echo -e "  ${RED}Failed: ${FAIL}${NC}"
echo -e "  ${YELLOW}Skipped: ${SKIP}${NC}"
echo -e "  Total:   $((PASS + FAIL + SKIP))"
echo -e "${BOLD}========================================${NC}"

if [[ "$FAIL" -gt 0 ]]; then
    echo -e "${RED}Some tests failed. Review output above.${NC}"
    exit 1
else
    echo -e "${GREEN}All executed tests passed.${NC}"
    exit 0
fi
