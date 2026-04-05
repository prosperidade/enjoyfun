#!/bin/bash
# ==============================================================================
# EnjoyFun — Credential Rotation Script
# ==============================================================================
# PURPOSE:
#   Generate fresh secrets for all auto-generatable credentials and print
#   step-by-step instructions for the ones that require manual rotation
#   (third-party API keys).
#
# USAGE:
#   chmod +x scripts/rotate_credentials.sh
#   ./scripts/rotate_credentials.sh
#
# OUTPUT:
#   A ready-to-paste .env block with new values.  Copy the block into your
#   backend/.env file, then follow the MANUAL STEPS at the bottom.
#
# WARNING:
#   - Changing JWT_SECRET invalidates ALL active sessions / refresh tokens.
#     Users will need to log in again.
#   - Changing DB_PASS requires updating the PostgreSQL role first.
#     The script prints the ALTER USER command for you.
# ==============================================================================

set -euo pipefail

# --- helpers ------------------------------------------------------------------
generate_hex()  { openssl rand -hex  "$1" 2>/dev/null; }
generate_alnum() {
  # Generate alphanumeric string of $1 characters
  openssl rand -base64 $(( $1 * 2 )) 2>/dev/null | tr -dc 'a-zA-Z0-9' | head -c "$1"
}

echo "============================================================"
echo "  EnjoyFun — Credential Rotation"
echo "  $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "============================================================"
echo ""

# --- 1. JWT_SECRET (256-bit / 64 hex chars) -----------------------------------
NEW_JWT_SECRET=$(generate_hex 32)

# --- 2. Database password (24 alphanumeric chars) -----------------------------
NEW_DB_PASS=$(generate_alnum 24)

# --- 3. OTP_PEPPER (256-bit) --------------------------------------------------
NEW_OTP_PEPPER=$(generate_hex 32)

# --- 4. MESSAGING_CREDENTIALS_KEY (256-bit) -----------------------------------
NEW_MESSAGING_KEY=$(generate_hex 32)

# --- 5. SENSITIVE_DATA_KEY (256-bit) ------------------------------------------
NEW_SENSITIVE_KEY=$(generate_hex 32)

# --- 6. FINANCE_CREDENTIALS_KEY (256-bit) -------------------------------------
NEW_FINANCE_KEY=$(generate_hex 32)

# --- Print .env block ---------------------------------------------------------
echo "=== GENERATED CREDENTIALS (paste into backend/.env) ====================="
echo ""
cat <<ENVBLOCK
# --- Database (PostgreSQL) ---------------------------------------------------
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=enjoyfun
DB_USER=postgres
DB_PASS=$NEW_DB_PASS

# --- JWT / Crypto Secrets ----------------------------------------------------
JWT_SECRET=$NEW_JWT_SECRET
JWT_EXPIRY=3600
JWT_REFRESH=2592000

# --- Dedicated crypto keys ---------------------------------------------------
OTP_PEPPER=$NEW_OTP_PEPPER
MESSAGING_CREDENTIALS_KEY=$NEW_MESSAGING_KEY
SENSITIVE_DATA_KEY=$NEW_SENSITIVE_KEY
FINANCE_CREDENTIALS_KEY=$NEW_FINANCE_KEY

# --- AI Providers (replace with new keys — see MANUAL STEPS below) -----------
GEMINI_API_KEY=REPLACE_ME
OPENAI_API_KEY=REPLACE_ME
OPENAI_MODEL=gpt-4o-mini

# --- Application -------------------------------------------------------------
APP_ENV=production
APP_DEBUG=false
CORS_ALLOWED_ORIGINS=https://your-production-domain.com

# --- Features ----------------------------------------------------------------
FEATURE_WORKFORCE_BULK_CARD_ISSUANCE=true
ENVBLOCK

echo ""
echo "=== DATABASE PASSWORD CHANGE ============================================"
echo ""
echo "Run this in psql BEFORE updating the .env file:"
echo ""
echo "  ALTER USER postgres PASSWORD '$NEW_DB_PASS';"
echo ""
echo "Or via command line:"
echo ""
echo "  psql -h 127.0.0.1 -p 5432 -U postgres -c \"ALTER USER postgres PASSWORD '$NEW_DB_PASS';\""
echo ""

echo "=== MANUAL STEPS REQUIRED ==============================================="
echo ""
echo "1. GEMINI_API_KEY"
echo "   - Go to https://console.cloud.google.com/apis/credentials"
echo "   - Revoke the old key, create a new one"
echo "   - Paste the new key into GEMINI_API_KEY in your .env"
echo ""
echo "2. OPENAI_API_KEY"
echo "   - Go to https://platform.openai.com/api-keys"
echo "   - Revoke the old key, create a new one"
echo "   - Paste the new key into OPENAI_API_KEY in your .env"
echo ""
echo "3. ASAAS_API_KEY (when payment gateway is live)"
echo "   - Go to https://www.asaas.com/customerConfig/apiAccess"
echo "   - Revoke the old key, create a new one"
echo "   - Paste the new key into ASAAS_API_KEY in your .env"
echo ""
echo "4. Evolution API / WhatsApp credentials (if applicable)"
echo "   - Rotate credentials in your Evolution API dashboard"
echo "   - Update MESSAGING_CREDENTIALS_KEY above encrypts these at rest"
echo ""

echo "=== SESSION IMPACT ======================================================="
echo ""
echo "  JWT_SECRET was rotated.  ALL active access tokens and refresh tokens"
echo "  are now INVALID.  Every user will need to log in again."
echo "  This is expected and acceptable for a credential rotation."
echo ""

echo "=== POST-ROTATION CHECKLIST ============================================="
echo ""
echo "  [ ] 1. ALTER USER postgres PASSWORD in PostgreSQL"
echo "  [ ] 2. Copy .env block above into backend/.env"
echo "  [ ] 3. Replace GEMINI_API_KEY with new key"
echo "  [ ] 4. Replace OPENAI_API_KEY with new key"
echo "  [ ] 5. Verify .env is in .gitignore (git status should NOT show .env)"
echo "  [ ] 6. Restart backend server"
echo "  [ ] 7. Smoke test: POST /auth/login and verify JWT is issued"
echo "  [ ] 8. Smoke test: GET /health returns 200"
echo "  [ ] 9. Log this rotation in docs/progresso18.md or audit log"
echo ""
echo "============================================================"
echo "  Rotation values generated at $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "============================================================"
